<?php
// src/Services/RetentionService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * GDPR data-retention anonymisation.
 *
 * Reads two independent settings from `app_settings`:
 *   - `data_retention_days`      → window for general operational data
 *     (comment content, moderation log free text, appeal text, webhook
 *     payloads, and the identity of social users with no recorded violation)
 *   - `violation_retention_days` → window for the identity of social users
 *     who *have* a recorded violation/ban (`violation_count` > 0 or a row
 *     in `ban_records`); typically longer than the general window, since
 *     abuse/recidivism history has its own retention justification (GDPR
 *     art. 5.1.e still requires a bounded period, just not the same one).
 *     0/unset → falls back to `data_retention_days` (same window for everyone).
 *   - 0 on both → feature fully disabled, no-op.
 *
 * The violation-count itself (an integer, no PII) is never anonymised or
 * deleted by either window — it is a statistical/audit column, like AI
 * decisions and severities.
 *
 * Anonymisation = PII columns are emptied / replaced with placeholders.
 * Statistical / audit columns (AI decision, severity, timestamps, ban counts)
 * are preserved so dashboards and learning data keep working.
 *
 * Idempotent: a second run on the same data is a no-op (rows already
 * anonymised are filtered out by content checks).
 *
 * Run from CLI via bin/retention-purge.php (cron). Never from a web request.
 */
class RetentionService
{
    public const ANON_PLACEHOLDER = '[anonymised]';

    /** Marker used in UNIQUE columns where we need a non-null but anonymous value. */
    private const ANON_PREFIX = 'anon_';

    /**
     * Run the purge.
     *
     * @return array{
     *     skipped: bool,
     *     reason?: string,
     *     retention_days?: int,
     *     violation_retention_days?: int,
     *     cutoff?: ?string,
     *     violation_cutoff?: ?string,
     *     anonymised?: array<string,int>,
     *     started_at?: string,
     *     finished_at?: string,
     *     duration_ms?: int,
     * }
     */
    public function purge(): array
    {
        $startedAt = microtime(true);
        $startedAtStr = date('Y-m-d H:i:s', (int) $startedAt);

        $settings = DB::table('app_settings')
            ->whereIn('key', ['data_retention_days', 'violation_retention_days'])
            ->pluck('value', 'key');

        $days           = (int) ($settings['data_retention_days'] ?? 0);
        $violationDays  = (int) ($settings['violation_retention_days'] ?? 0);
        $effectiveViolationDays = $violationDays > 0 ? $violationDays : $days;

        if ($days <= 0 && $effectiveViolationDays <= 0) {
            return [
                'skipped'    => true,
                'reason'     => 'data_retention_days and violation_retention_days are both 0 (disabled)',
                'started_at' => $startedAtStr,
            ];
        }

        $cutoff          = $days > 0 ? date('Y-m-d H:i:s', strtotime("-{$days} days")) : null;
        $violationCutoff = $effectiveViolationDays > 0 ? date('Y-m-d H:i:s', strtotime("-{$effectiveViolationDays} days")) : null;

        $counts = [
            'comments'        => $cutoff !== null ? $this->anonymiseComments($cutoff) : 0,
            'social_users'    => $this->anonymiseSocialUsers($cutoff, $violationCutoff),
            'moderation_log'  => $cutoff !== null ? $this->anonymiseModerationLog($cutoff) : 0,
            'appeal_records'  => $cutoff !== null ? $this->anonymiseAppealRecords($cutoff) : 0,
            'webhook_events'  => $cutoff !== null ? $this->anonymiseWebhookEvents($cutoff) : 0,
        ];

        $finishedAt = microtime(true);
        $result = [
            'skipped'                  => false,
            'retention_days'           => $days,
            'violation_retention_days' => $effectiveViolationDays,
            'cutoff'                   => $cutoff,
            'violation_cutoff'         => $violationCutoff,
            'anonymised'               => $counts,
            'started_at'               => $startedAtStr,
            'finished_at'              => date('Y-m-d H:i:s', (int) $finishedAt),
            'duration_ms'              => (int) (($finishedAt - $startedAt) * 1000),
        ];

        $this->recordRun($result);
        return $result;
    }

    // ──────────────────────────────────────────────────────────────────
    // Per-table anonymisation
    // ──────────────────────────────────────────────────────────────────

    private function anonymiseComments(string $cutoff): int
    {
        $rows = DB::table('comments')
            ->where('received_at', '<', $cutoff)
            ->where('content', '!=', self::ANON_PLACEHOLDER)
            ->select('id')
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            DB::table('comments')->where('id', $row->id)->update([
                'content'             => self::ANON_PLACEHOLDER,
                'platform_comment_id' => self::ANON_PREFIX . 'c_' . $row->id,
                'appeal_token'        => null,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * Anonymises social_users identity fields, using a different cutoff for
     * users with a recorded violation/ban vs. users with none — see the
     * class docblock. Either cutoff may be null, meaning that group is left
     * untouched (its window is disabled).
     */
    private function anonymiseSocialUsers(?string $generalCutoff, ?string $violationCutoff): int
    {
        $count = 0;
        if ($generalCutoff !== null) {
            $count += $this->anonymiseSocialUsersBatch($generalCutoff, hasViolation: false);
        }
        if ($violationCutoff !== null) {
            $count += $this->anonymiseSocialUsersBatch($violationCutoff, hasViolation: true);
        }
        return $count;
    }

    private function anonymiseSocialUsersBatch(string $cutoff, bool $hasViolation): int
    {
        $hasBanRecord = function ($query) {
            $query->select(DB::raw(1))
                ->from('ban_records')
                ->whereColumn('ban_records.social_user_id', 'social_users.id');
        };

        // Use updated_at so that users still active (recent ban/violation)
        // are kept fully identified.
        $rows = DB::table('social_users')
            ->where('updated_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('display_name', '!=', self::ANON_PLACEHOLDER)
                  ->orWhereNull('display_name');
            })
            ->where(function ($q) use ($hasViolation, $hasBanRecord) {
                if ($hasViolation) {
                    $q->where('violation_count', '>', 0)->orWhereExists($hasBanRecord);
                } else {
                    $q->where(function ($q2) {
                        $q2->where('violation_count', '<=', 0)->orWhereNull('violation_count');
                    })->whereNotExists($hasBanRecord);
                }
            })
            ->select('id')
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            DB::table('social_users')->where('id', $row->id)->update([
                'display_name'     => self::ANON_PLACEHOLDER,
                'profile_url'      => null,
                'platform_user_id' => self::ANON_PREFIX . 'u_' . $row->id,
                'notes'            => null,
            ]);
            $count++;
        }
        return $count;
    }

    private function anonymiseModerationLog(string $cutoff): int
    {
        // Keep AI decision, categories, severity, latency, model, final_action,
        // confidence — these are statistically useful and contain no PII.
        // Strip the free-text fields that may quote the original comment or
        // contain moderator notes.
        return DB::table('moderation_log')
            ->where('created_at', '<', $cutoff)
            ->where(function ($q) {
                $q->whereNotNull('ai_reason')
                  ->orWhereNotNull('ai_public_reason')
                  ->orWhereNotNull('ai_fact_check_draft')
                  ->orWhereNotNull('ai_fact_check_sources')
                  ->orWhereNotNull('ai_whataboutism_draft')
                  ->orWhereNotNull('human_note')
                  ->orWhereNotNull('removal_reply_text')
                  ->orWhereNotNull('appeal_text');
            })
            ->update([
                'ai_reason'             => null,
                'ai_public_reason'      => null,
                'ai_fact_check_draft'   => null,
                'ai_fact_check_sources' => null,
                'ai_whataboutism_draft' => null,
                'human_note'            => null,
                'removal_reply_text'    => null,
                'appeal_text'           => null,
            ]);
    }

    private function anonymiseAppealRecords(string $cutoff): int
    {
        return DB::table('appeal_records')
            ->where('submitted_at', '<', $cutoff)
            ->where(function ($q) {
                $q->whereNotNull('appeal_text')
                  ->orWhereNotNull('reviewer_note');
            })
            ->update([
                'appeal_text'   => null,
                'reviewer_note' => null,
            ]);
    }

    private function anonymiseWebhookEvents(string $cutoff): int
    {
        // Webhook payload contains the raw FB JSON (commenter id, name, message).
        // We zero it and keep only the routing metadata (page_id, event_type,
        // processed flag, timestamp) so debugging stats remain meaningful.
        return DB::table('webhook_events')
            ->where('received_at', '<', $cutoff)
            ->where('payload', '!=', '{}')
            ->update([
                'payload' => '{}',
                'error'   => null,
            ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Run-history bookkeeping
    // ──────────────────────────────────────────────────────────────────

    private function recordRun(array $result): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'last_retention_run'],
            [
                'value'      => json_encode($result, JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Returns the parsed `last_retention_run` row, or null if it has never run.
     */
    public function lastRun(): ?array
    {
        $raw = DB::table('app_settings')
            ->where('key', 'last_retention_run')
            ->value('value');

        if (!$raw) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
