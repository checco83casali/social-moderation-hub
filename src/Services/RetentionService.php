<?php
// src/Services/RetentionService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * GDPR data-retention anonymisation.
 *
 * Reads the `data_retention_days` setting from `app_settings`:
 *   - 0 (default)  → feature disabled, no-op
 *   - N (>0)       → every row older than N days has its PII anonymised
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
     *     cutoff?: string,
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

        $days = (int) (DB::table('app_settings')
            ->where('key', 'data_retention_days')
            ->value('value') ?? 0);

        if ($days <= 0) {
            return [
                'skipped'    => true,
                'reason'     => 'data_retention_days is 0 (disabled)',
                'started_at' => $startedAtStr,
            ];
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $counts = [
            'comments'        => $this->anonymiseComments($cutoff),
            'social_users'    => $this->anonymiseSocialUsers($cutoff),
            'moderation_log'  => $this->anonymiseModerationLog($cutoff),
            'appeal_records'  => $this->anonymiseAppealRecords($cutoff),
            'webhook_events'  => $this->anonymiseWebhookEvents($cutoff),
        ];

        $finishedAt = microtime(true);
        $result = [
            'skipped'        => false,
            'retention_days' => $days,
            'cutoff'         => $cutoff,
            'anonymised'     => $counts,
            'started_at'     => $startedAtStr,
            'finished_at'    => date('Y-m-d H:i:s', (int) $finishedAt),
            'duration_ms'    => (int) (($finishedAt - $startedAt) * 1000),
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

    private function anonymiseSocialUsers(string $cutoff): int
    {
        // Use updated_at so that users still active (recent ban/violation)
        // are kept fully identified.
        $rows = DB::table('social_users')
            ->where('updated_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('display_name', '!=', self::ANON_PLACEHOLDER)
                  ->orWhereNull('display_name');
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
