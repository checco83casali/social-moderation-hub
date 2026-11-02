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
 *     who *have* a recorded violation/ban (`violation_count` > 0, a row in
 *     `ban_records` or a comment in VIOLATION_STATUSES) AND for the content of
 *     the violating comments themselves (hidden/removed, with their moderation
 *     log free text and appeals); typically longer than the general window, since
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

    /**
     * Stati di un commento che costituisce una violazione (schermate "Commenti nascosti" e
     * rimossi, segnalabili, ricorsi): contenuto e dati collegati seguono la finestra delle
     * violazioni, come l'identità del loro autore, non quella generale.
     */
    public const VIOLATION_STATUSES = [
        'hidden', 'hidden_reportable', 'escalated_reportable', 'removed', 'reported_legal', 'appeal_pending',
    ];

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
            // Commenti, log e ricorsi: finestra generale, ma quella delle violazioni per i
            // commenti nascosti/rimossi (VIOLATION_STATUSES).
            'comments'        => $this->bothWindows($cutoff, $violationCutoff, $this->anonymiseComments(...)),
            'social_users'    => $this->anonymiseSocialUsers($cutoff, $violationCutoff),
            'moderation_log'  => $this->bothWindows($cutoff, $violationCutoff, $this->anonymiseModerationLog(...)),
            'appeal_records'  => $this->bothWindows($cutoff, $violationCutoff, $this->anonymiseAppealRecords(...)),
            'webhook_events'  => $cutoff !== null ? $this->anonymiseWebhookEvents($cutoff) : 0,
            // Audit: stessa finestra delle violazioni; scaduta quella, le righe vengono ELIMINATE.
            'audit_log'       => $violationCutoff !== null ? $this->purgeAuditLog($violationCutoff) : 0,
            // Riassunti dei post (contesto per l'AI): eliminati con la finestra generale.
            'post_contexts'   => $cutoff !== null ? $this->purgePostContexts($cutoff) : 0,
            // Note di addestramento AI e relative sessioni: eliminate con la finestra generale.
            'ai_training'     => $cutoff !== null ? $this->purgeAiTraining($cutoff) : 0,
            // Menzioni su post di altre pagine: eliminate con la finestra generale.
            'mentions'        => $cutoff !== null ? $this->purgeMentions($cutoff) : 0,
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

    /**
     * Applica $fn ai commenti "normali" con la soglia generale e a quelli in violazione
     * con la soglia delle violazioni; una soglia null lascia intatto il suo gruppo.
     *
     * @param callable(string, bool): int $fn
     */
    private function bothWindows(?string $generalCutoff, ?string $violationCutoff, callable $fn): int
    {
        return ($generalCutoff !== null ? $fn($generalCutoff, false) : 0)
             + ($violationCutoff !== null ? $fn($violationCutoff, true) : 0);
    }

    /**
     * Sottoquery degli id dei commenti nel gruppo indicato (in violazione o no).
     *
     * @return \Closure(\Illuminate\Database\Query\Builder): void
     */
    private function commentGroup(bool $violation): \Closure
    {
        return function ($q) use ($violation): void {
            $q->select('id')->from('comments');
            $violation
                ? $q->whereIn('status', self::VIOLATION_STATUSES)
                : $q->whereNotIn('status', self::VIOLATION_STATUSES);
        };
    }

    private function anonymiseComments(string $cutoff, bool $violation): int
    {
        $rows = DB::table('comments')
            ->where('received_at', '<', $cutoff)
            ->where(function ($q) use ($violation) {
                $violation
                    ? $q->whereIn('status', self::VIOLATION_STATUSES)
                    : $q->whereNotIn('status', self::VIOLATION_STATUSES);
            })
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
        // Chi ha un commento nascosto/rimosso è un utente "con violazioni" anche se il
        // contatore non è salito (es. auto-hide AI in attesa di revisione legale).
        $hasViolatingComment = function ($query) {
            $query->select(DB::raw(1))
                ->from('comments')
                ->whereColumn('comments.social_user_id', 'social_users.id')
                ->whereIn('comments.status', self::VIOLATION_STATUSES);
        };

        // Use updated_at so that users still active (recent ban/violation)
        // are kept fully identified.
        $rows = DB::table('social_users')
            ->where('updated_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('display_name', '!=', self::ANON_PLACEHOLDER)
                  ->orWhereNull('display_name');
            })
            ->where(function ($q) use ($hasViolation, $hasBanRecord, $hasViolatingComment) {
                if ($hasViolation) {
                    $q->where('violation_count', '>', 0)->orWhereExists($hasBanRecord)->orWhereExists($hasViolatingComment);
                } else {
                    $q->where(function ($q2) {
                        $q2->where('violation_count', '<=', 0)->orWhereNull('violation_count');
                    })->whereNotExists($hasBanRecord)->whereNotExists($hasViolatingComment);
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

    private function anonymiseModerationLog(string $cutoff, bool $violation): int
    {
        // Keep AI decision, categories, severity, latency, model, final_action,
        // confidence — these are statistically useful and contain no PII.
        // Strip the free-text fields that may quote the original comment or
        // contain moderator notes.
        return DB::table('moderation_log')
            ->where('created_at', '<', $cutoff)
            ->whereIn('comment_id', $this->commentGroup($violation))
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

    private function anonymiseAppealRecords(string $cutoff, bool $violation): int
    {
        return DB::table('appeal_records')
            ->where('submitted_at', '<', $cutoff)
            ->whereIn('comment_id', $this->commentGroup($violation))
            ->where(function ($q) {
                $q->whereNotNull('appeal_text')
                  ->orWhereNotNull('reviewer_note');
            })
            ->update([
                'appeal_text'   => null,
                'reviewer_note' => null,
            ]);
    }

    /** Elimina le righe del registro di audit più vecchie della finestra di conservazione. */
    private function purgeAuditLog(string $cutoff): int
    {
        try {
            return (int) DB::table('audit_log')->where('created_at', '<', $cutoff)->delete();
        } catch (\Throwable) {
            return 0;   // tabella non ancora migrata
        }
    }

    /** Elimina note e sessioni di training AI più vecchie della finestra di conservazione. */
    private function purgeAiTraining(string $cutoff): int
    {
        try {
            $n = (int) DB::table('ai_training_notes')->where('created_at', '<', $cutoff)->delete();
            // Sessioni chiuse da prima della soglia: il riassunto può citare casi reali.
            DB::table('ai_training_sessions')->where('status', '!=', 'active')->where('started_at', '<', $cutoff)->delete();
            return $n;
        } catch (\Throwable) {
            return 0;   // tabelle non ancora migrate
        }
    }

    /** Elimina le menzioni su post di altre pagine più vecchie della finestra di conservazione. */
    private function purgeMentions(string $cutoff): int
    {
        try {
            return (int) DB::table('mentions')->where('received_at', '<', $cutoff)->delete();
        } catch (\Throwable) {
            return 0;   // tabella non ancora migrata
        }
    }

    /** Elimina i riassunti dei post non più aggiornati entro la finestra di conservazione. */
    private function purgePostContexts(string $cutoff): int
    {
        try {
            return (int) DB::table('post_contexts')->where('updated_at', '<', $cutoff)->delete();
        } catch (\Throwable) {
            return 0;   // tabella non ancora migrata
        }
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
                'debug'   => null, // IP, header e esito del debug webhook
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
