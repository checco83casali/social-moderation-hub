<?php
// src/Services/ModerationService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Monolog\Logger;

/**
 * Orchestrates the full moderation lifecycle:
 *   receive comment → enrich with Meta metadata → AI pipeline → persist → ban check → outcome
 */
class ModerationService
{
    public function __construct(
        private readonly ClaudeService    $claude,
        private readonly BanService       $ban,
        private readonly MetaGraphService $meta,
        private ?Logger                   $logger = null,
    ) {}

    // ──────────────────────────────────────────────────────────────────
    // Dev mode helper
    // ──────────────────────────────────────────────────────────────────

    /**
     * Returns true when dev_mode is enabled in app_settings.
     * In dev mode the AI pipeline runs normally but no real actions
     * are executed: no Facebook deletes, no violation increments, no bans.
     * Comments are tagged with status 'dev_flagged' instead of 'removed'.
     */
    // ──────────────────────────────────────────────────────────────────
    // Ban notification helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Posta una risposta di notifica ban attivo sul commento rimosso.
     * Chiamato quando scatta il ban oppure quando un utente bannato tenta di postare.
     */
    private function postBanNotificationReply(
        string  $platformCommentId,
        string  $displayName,
        string  $duration,
        ?string $expiresAt,
        string  $pageToken,
    ): void {
        try {
            $default = "Ciao {nome}, il tuo commento è stato rimosso e il tuo account è stato temporaneamente sospeso dalla pagina per {durata}.\n\n"
                     . "Potrai tornare a commentare il {scadenza}.";

            $template = DB::table('app_settings')
                ->where('key', 'ban_notification_template')
                ->value('value') ?? $default;

            $expiresFormatted = $expiresAt
                ? (new \DateTime($expiresAt))->format('d/m/Y \a\l\l\e H:i')
                : 'data da definire';

            $message = str_replace(
                ['{nome}', '{durata}', '{scadenza}'],
                [$displayName ?: 'utente', $duration, $expiresFormatted],
                $template,
            );

            $this->meta->replyToComment($platformCommentId, $message, $pageToken);
        } catch (\Throwable $e) {
            $this->logger?->warning('[BanNotification] Could not post ban reply: ' . $e->getMessage());
        }
    }

    /**
     * Formatta la durata del ban in italiano leggibile.
     * Es: "1 ora", "7 giorni", "30 giorni"
     */
    private function formatBanDuration(int $banLevel, array $cfg): string
    {
        if ($banLevel <= 1) {
            $hours = $cfg['hours_1'];
            return $hours === 1 ? '1 ora' : "{$hours} ore";
        }
        if ($banLevel === 2) {
            $days = $cfg['days_2'];
            return $days === 1 ? '1 giorno' : "{$days} giorni";
        }
        $days = $cfg['days_3'];
        return $days === 1 ? '1 giorno' : "{$days} giorni";
    }

    private function isDevMode(): bool
    {
        try {
            $val = DB::table('app_settings')->where('key', 'dev_mode')->value('value');
            return (bool)(int)($val ?? '0');
        } catch (\Throwable) {
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Entry point: process a raw comment from a webhook
    // ──────────────────────────────────────────────────────────────────

    public function processWebhookComment(array $webhookComment, array $page): array
    {
        $verb = $webhookComment['verb'] ?? 'add';

        // ── Handle edited comments separately ────────────────────────
        if ($verb === 'edited') {
            return $this->processEditedComment($webhookComment, $page);
        }

        // 1. Resolve commenter identity BEFORE upserting.
        //    Meta's feed webhook often omits `from` for regular users (privacy / PPCA
        //    gating), which previously caused every comment to collapse onto the same
        //    social_user row (platform_user_id=''). The page token is always allowed
        //    to read `from.{id,name}` on comments belonging to its own page via the
        //    comment endpoint — so we fall back to that whenever either field is
        //    missing from the webhook payload.
        $fromId   = $webhookComment['from']['id']   ?? '';
        $fromName = $webhookComment['from']['name'] ?? '';

        if (empty($fromId) || empty($fromName)) {
            $commentData = $this->meta->getComment(
                $webhookComment['id'],
                $page['page_access_token']
            );
            $fromId   = $fromId   ?: ($commentData['from']['id']   ?? '');
            $fromName = $fromName ?: ($commentData['from']['name'] ?? '');
        }

        // Last-resort: if Meta refuses to disclose identity at all, we still persist
        // the comment but under a stable synthetic id derived from the platform
        // comment id, so different commenters don't collide on a single row.
        $identityResolved = !empty($fromId);
        if (!$identityResolved) {
            $this->logger?->warning(
                "Webhook comment {$webhookComment['id']}: Meta did not disclose 'from' — " .
                "check pages_read_engagement / pages_manage_metadata on the page token"
            );
            $fromId = 'unknown_' . hash('sha256', (string) $webhookComment['id']);
        }

        // 2. Upsert the social user with the resolved identity
        $socialUser = $this->upsertSocialUser(
            platformUserId: $fromId,
            displayName:    $fromName ?: null,
            platform:       'facebook',
        );

        // 3. Check if user is already banned → auto-remove without calling AI
        if ($this->ban->isUserBanned($socialUser['id'])) {
            $platformCommentId = $webhookComment['id'];

            // Notifica l'utente che non può postare e quando scade il ban
            if (!$this->isDevMode() && !empty($page['page_access_token'])) {
                $banStatus = $this->ban->getUserBanStatus($socialUser['id']);
                $cfg       = $this->ban->getConfig();
                $banLevel  = $this->ban->countUserBans($socialUser['id']);
                $duration  = $this->formatBanDuration($banLevel, $cfg);

                $this->postBanNotificationReply(
                    platformCommentId: $platformCommentId,
                    displayName:       $socialUser['display_name'] ?? '',
                    duration:          $duration,
                    expiresAt:         $banStatus['expires_at'] ?? null,
                    pageToken:         $page['page_access_token'],
                );
            }

            if (!$this->isDevMode()) {
                $this->meta->hideComment($platformCommentId, $page['page_access_token']);
            } else {
                $this->logger?->info("[DEV MODE] Skipped auto-hide for banned user {$socialUser['id']}");
            }
            return ['action' => 'auto_hidden_banned_user', 'user_id' => $socialUser['id'], 'dev_mode' => $this->isDevMode()];
        }

        // 4. Deduplicate
        $commentHash = hash('sha256', $webhookComment['message'] ?? '');
        $existingId  = DB::table('comments')
            ->where('content_hash', $commentHash)
            ->where('social_user_id', $socialUser['id'])
            ->value('id');

        if ($existingId) {
            return ['action' => 'duplicate_skipped', 'comment_id' => $existingId];
        }

        // 5. Persist comment
        $commentId = DB::table('comments')->insertGetId([
            'platform'            => 'facebook',
            'platform_comment_id' => $webhookComment['id'],
            'platform_post_id'    => $webhookComment['post_id'] ?? '',
            'page_id'             => $page['id'],
            'social_user_id'      => $socialUser['id'],
            'content'             => $webhookComment['message'] ?? '',
            'content_hash'        => $commentHash,
            'status'              => 'pending',
            'received_at'         => date('Y-m-d H:i:s'),
        ]);

        // 6. Load active policy
        $policy = DB::table('policies')->where('is_active', 1)->first();
        if (!$policy) {
            $this->logger?->error('No active moderation policy found.');
            return ['action' => 'error_no_policy', 'comment_id' => $commentId];
        }

        // 7. Enrich with Meta account metadata (best-effort, non-blocking).
        //    Skip when we only have a synthetic id — Graph would 400 on it anyway.
        $accountMeta = $identityResolved
            ? $this->fetchAccountMeta($fromId, $page['page_access_token'])
            : [];

        // 8. Build enriched context for Claude
        $commentContext = $this->buildCommentContext(
            $webhookComment['message'] ?? '',
            $socialUser,
            $accountMeta,
        );

        // 9. Run AI moderation pipeline
        // Read reason_max_words from app_settings (default 40)
        $reasonMaxWords = 40;
        try {
            $rmw = DB::table('app_settings')->where('key', 'reason_max_words')->value('value');
            if ($rmw !== null) $reasonMaxWords = max(10, (int) $rmw);
        } catch (\Throwable) {}

        // Operator-editable moderation rules (the technical block is appended in ClaudeService).
        $moderationPrompt = $policy->moderation_prompt;

        // Per-page AI threshold / fact-check overrides (Pro). null = use global.
        [$pgHaiku, $pgSonnet, $pgFactCheck] = $this->pageThresholdOverrides((int) ($page['id'] ?? 0));

        $result = $this->claude->moderate(
            commentText:      $commentContext,
            moderationPrompt: $moderationPrompt,
            reasonMaxWords:   $reasonMaxWords,
            haikuThreshold:   $pgHaiku,
            sonnetThreshold:  $pgSonnet,
            factCheckEnabled: $pgFactCheck,
        );

        // 10. Persist moderation log
        $logId = DB::table('moderation_log')->insertGetId([
            'comment_id'              => $commentId,
            'stage'                   => $result->stage,
            'policy_id'               => $policy->id,
            'ai_decision'             => $result->decision,
            'ai_confidence'           => $result->confidence,
            'ai_reason'               => $result->reason,
            'ai_public_reason'        => $result->publicReason,
            'ai_categories'           => json_encode($result->categories),
            'ai_severity'             => $result->severity,
            'ai_model'                => $result->model,
            'ai_latency_ms'           => $result->latencyMs,
            'ai_fact_check_draft'     => $result->factCheckDraft,
            'ai_fact_check_sources'   => json_encode($result->factCheckSources),
            'ai_fact_check_confidence'=> $result->factCheckConfidence > 0.0 ? $result->factCheckConfidence : null,
            'ai_fact_check_latency_ms'=> $result->factCheckLatencyMs > 0    ? $result->factCheckLatencyMs  : null,
            'ai_editorial_category'   => $result->editorialCategory,
            'ai_fact_check_suggested' => $result->factCheckSuggested ? 1 : 0,
            'final_action'           => match(true) {
                $result->stage === 'human'                                         => 'pending_human',
                in_array($result->decision, ['hide','reportable'], true)           => 'hidden',
                default                                                            => 'approved',
            },
            'created_at'             => date('Y-m-d H:i:s'),
        ]);

        // 11. Act on AI decision
        return match (true) {
            $result->stage === 'human'         => $this->escalateToHuman($commentId, $result, logId: $logId),
            // Illegal / reportable content is NEVER deleted: it is hidden, the author
            // is notified, and it lands in the Segnalazioni queue for legal handling.
            $result->decision === 'reportable' => $this->escalateToHuman($commentId, $result, reportable: true, logId: $logId),
            $result->decision === 'hide'       => $this->executeHide(
                $commentId, $socialUser, $page, $result, $logId, decidedBy: 'ai'
            ),
            default                            => $this->approveComment($commentId, $result, $page, $socialUser, $logId),
        };
    }

    // ──────────────────────────────────────────────────────────────────
    // Human decision (called from the admin panel)
    // ──────────────────────────────────────────────────────────────────

    public function applyHumanDecision(
        int    $commentId,
        string $decision,
        int    $adminUserId,
        string $note   = '',
        bool   $silent = false,
    ): array {
        $comment    = DB::table('comments')->find($commentId);
        if (!$comment) return ['error' => 'Comment not found'];

        $page       = DB::table('connected_pages')->find($comment->page_id);
        $socialUser = DB::table('social_users')->find($comment->social_user_id);
        $log        = DB::table('moderation_log')
            ->where('comment_id', $commentId)
            ->orderByDesc('id')
            ->first();

        // Guard: if no log exists yet (e.g. manual test record), create a minimal one
        if (!$log) {
            $activePolicy = DB::table('policies')->where('is_active', 1)->value('id') ?? 1;
            $logId = DB::table('moderation_log')->insertGetId([
                'comment_id'   => $commentId,
                'stage'        => 'human',
                'policy_id'    => $activePolicy,
                'ai_decision'  => null,
                'final_action' => 'pending_human',
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            $log = DB::table('moderation_log')->find($logId);
        }

        DB::table('moderation_log')->where('id', $log->id)->update([
            'human_user_id'    => $adminUserId,
            'human_decision'   => $decision,
            'human_note'       => $note,
            'human_decided_at' => date('Y-m-d H:i:s'),
            'final_action'     => match($decision) {
                'hide'    => 'hidden',
                'unhide'  => 'approved',
                default   => 'approved',
            },
        ]);

        if ($decision === 'hide') {
            $result = new ModerationResult(
                stage: 'human', decision: 'hide', confidence: 1.0,
                reason: $note, categories: [], model: 'human',
            );
            return $this->executeHide(
                $commentId, (array) $socialUser, (array) $page,
                $result, $log->id, decidedBy: 'human', adminUserId: $adminUserId,
                silent: $silent,
            );
        }

        // keep_hidden: commento già nascosto da AI (escalated_reportable),
        // confermato dal moderatore — non rieseguire hide su FB, non incrementare violations
        if ($decision === 'keep_hidden') {
            DB::table('comments')->where('id', $commentId)->update([
                'status'       => 'hidden_reportable',
                'processed_at' => date('Y-m-d H:i:s'),
            ]);
            DB::table('moderation_log')->where('id', $log->id)->update([
                'final_action' => 'hidden',
            ]);
            return ['action' => 'kept_hidden', 'comment_id' => $commentId];
        }

        if ($decision === 'unhide') {
            $platformId = $comment->platform_comment_id ?? '';
            if ($platformId && $page) {
                $this->meta->unhideComment($platformId, $page->page_access_token);
            }
            DB::table('comments')->where('id', $commentId)->update([
                'status'       => 'approved',
                'processed_at' => date('Y-m-d H:i:s'),
            ]);
            // Log appeal accepted
            DB::table('moderation_log')->insertGetId([
                'comment_id'       => $commentId,
                'stage'            => 'human',
                'policy_id'        => $log->policy_id ?? 1,
                'human_user_id'    => $adminUserId,
                'human_decision'   => 'unhide',
                'human_note'       => $note ?: 'Appeal accepted — comment restored',
                'human_decided_at' => date('Y-m-d H:i:s'),
                'final_action'     => 'approved',
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
            return ['action' => 'unhidden_appeal_accepted', 'comment_id' => $commentId];
        }

        DB::table('comments')->where('id', $commentId)->update([
            'status'       => 'approved',
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
        return ['action' => 'approved_by_human', 'comment_id' => $commentId];
    }

    // ──────────────────────────────────────────────────────────────────
    // Meta account metadata enrichment
    // ──────────────────────────────────────────────────────────────────

    /**
     * Fetch public profile metadata from Graph API.
     * Returns an array with account_age_days, fan_count, verified, etc.
     * Never throws — returns empty array on failure.
     */
    private function fetchAccountMeta(string $platformUserId, string $pageToken): array
    {
        try {
            $data = $this->meta->getUserPublicProfile($platformUserId, $pageToken);
            if (empty($data)) return [];

            $createdTime = $data['created_time'] ?? null;
            $ageDays = $createdTime
                ? (int) round((time() - strtotime($createdTime)) / 86400)
                : null;

            return [
                'name'             => $data['name'] ?? null,   // display name from Graph API
                'account_age_days' => $ageDays,
                'fan_count'        => $data['fan_count'] ?? null,
                'verified'         => $data['verified'] ?? false,
                'profile_url'      => $data['link'] ?? null,
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Build an enriched text context for Claude.
     *
     * PRIVACY - no personal data is sent to the Anthropic API:
     *   - The real platform_user_id and display_name are NEVER included.
     *   - The commenter is identified only by a short, session-scoped pseudonym
     *     (e.g. "User-a3f9c2") derived via HMAC keyed on APP_SECRET,
     *     so it cannot be reversed by Anthropic.
     *   - account_age_days and fan_count are bucketed into labels,
     *     not transmitted as raw numbers.
     *   - profile_url is never transmitted.
     *   - The comment text itself may contain personal data typed by the user
     *     - that is inherent to content moderation and unavoidable.
     */
    private function buildCommentContext(string $message, array $socialUser, array $meta): string
    {
        $pseudonym = $this->pseudonymizeUserId((string) ($socialUser['platform_user_id'] ?? ''));

        $lines   = [];
        $lines[] = "COMMENT TO EVALUATE:";
        $lines[] = "\"{$message}\"";
        $lines[] = "";
        $lines[] = "USER CONTEXT (internal signal only - do not disclose to public):";
        $lines[] = "- User reference: {$pseudonym}";
        $lines[] = "- Violations on record: " . ($socialUser['violation_count'] ?? 0);
        $lines[] = "- Current ban status: " . ($socialUser['ban_status'] ?? 'clean');

        if (!empty($meta)) {
            if (isset($meta['account_age_days'])) {
                $age      = (int) $meta['account_age_days'];
                $ageLabel = match (true) {
                    $age < 30  => 'very new - higher scam risk',
                    $age < 180 => 'recent (under 6 months)',
                    $age < 730 => 'established (6 months to 2 years)',
                    default    => 'long-standing (over 2 years)',
                };
                $lines[] = "- Account age: {$ageLabel}";
            }
            if (isset($meta['fan_count'])) {
                $fans      = (int) $meta['fan_count'];
                $fansLabel = match (true) {
                    $fans === 0  => 'none',
                    $fans < 100  => 'very few (under 100)',
                    $fans < 1000 => 'moderate (100 to 999)',
                    default      => 'large (1000+)',
                };
                $lines[] = "- Followers/fans: {$fansLabel}";
            }
            if (isset($meta['verified'])) {
                $lines[] = "- Account verified: " . ($meta['verified'] ? 'yes' : 'no');
            }
        }

        $lines[] = "";
        $lines[] = "Evaluate only the comment text. User context is a supplementary risk signal.";

        return implode("\n", $lines);
    }

    /**
     * Derives a short, irreversible pseudonym for a platform user ID.
     *
     * Uses HMAC-SHA256 keyed on APP_SECRET so:
     *   - The mapping is deterministic within this installation (same user = same token).
     *   - It cannot be reversed by any third party (including Anthropic) without the key.
     *   - Different installations produce different pseudonyms for the same real user.
     *
     * Output example: "User-a3f9c2" (6 hex chars = 16 million values, safe for
     * the comment volumes expected in social moderation).
     */
    private function pseudonymizeUserId(string $platformUserId): string
    {
        $secret = $_ENV['APP_SECRET'] ?? 'fallback-secret';
        $hash   = hash_hmac('sha256', $platformUserId, $secret);
        return 'User-' . substr($hash, 0, 6);
    }

    // ──────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────

    private function upsertSocialUser(string $platformUserId, ?string $displayName, string $platform): array
    {
        $user = DB::table('social_users')
            ->where('platform', $platform)
            ->where('platform_user_id', $platformUserId)
            ->first();

        if ($user) {
            // Update display_name if we now have a value and the stored one is empty
            if (!empty($displayName) && empty($user->display_name)) {
                DB::table('social_users')->where('id', $user->id)->update([
                    'display_name' => $displayName,
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
                $user->display_name = $displayName;
            }
            return (array) $user;
        }

        $id = DB::table('social_users')->insertGetId([
            'platform'         => $platform,
            'platform_user_id' => $platformUserId,
            'display_name'     => $displayName,
            'ban_status'       => 'clean',
            'violation_count'  => 0,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        return (array) DB::table('social_users')->find($id);
    }

    /**
     * Per-page AI threshold overrides (Pro feature `per_page_thresholds`).
     * Returns [haiku, sonnet, factCheckEnabled]; each value is null when not set
     * for the page, so ClaudeService falls back to the global settings.
     */
    private function pageThresholdOverrides(int $pageId): array
    {
        try {
            $ps = DB::table('page_settings')->where('page_id', $pageId)->first();
        } catch (\Throwable) {
            return [null, null, null]; // table not migrated yet
        }
        if (!$ps) return [null, null, null];

        return [
            $ps->haiku_confidence_threshold  !== null ? (float) $ps->haiku_confidence_threshold  : null,
            $ps->sonnet_confidence_threshold !== null ? (float) $ps->sonnet_confidence_threshold : null,
            isset($ps->fact_check_enabled)   ? (bool) $ps->fact_check_enabled : null,
        ];
    }

    private function escalateToHuman(int $commentId, ModerationResult $result, bool $reportable = false, int $logId = 0): array
    {
        $status = $reportable ? 'escalated_reportable' : 'escalated_human';

        if ($reportable) {
            // Potentially illegal content: NEVER deleted. Hide it from the public and
            // notify the author, then leave it in the Segnalazioni queue for a human
            // to assess legal action.
            $this->hideAndNotifyReportable($commentId, $result, $logId);
            $this->logger?->warning("Comment #{$commentId} flagged as REPORTABLE — hidden + queued for legal review");
        } else {
            $this->logger?->info("Comment #{$commentId} escalated to human review");
        }

        DB::table('comments')->where('id', $commentId)->update(['status' => $status]);

        return [
            'action'     => $reportable ? 'escalated_reportable' : 'escalated_human',
            'comment_id' => $commentId,
            'stage'      => $result->stage,
        ];
    }

    /**
     * Reportable (potentially illegal) content is never deleted. Hide it from the
     * public timeline and notify the author with the reason + appeal link — exactly
     * like a hide — but the comment keeps the 'escalated_reportable' status so it
     * stays in the Segnalazioni queue for a human to assess legal action.
     */
    private function hideAndNotifyReportable(int $commentId, ModerationResult $result, int $logId = 0): void
    {
        if ($this->isDevMode()) {
            $this->logger?->info("[DEV MODE] Would hide+notify reportable comment #{$commentId}");
            return;
        }

        $comment = DB::table('comments')->find($commentId);
        if (!$comment) return;
        $page    = DB::table('connected_pages')->find($comment->page_id);
        if (!$page) return;
        $socialUser = DB::table('social_users')->find($comment->social_user_id);

        $platformId  = $comment->platform_comment_id ?? '';
        $displayName = $socialUser->display_name ?? 'utente';

        // Notify the author (reportable template + appeal link) while the comment exists.
        $appealToken = $this->generateAppealToken($commentId, (int) $comment->social_user_id);
        $this->postHideReply(
            platformCommentId: $platformId,
            displayName:       $displayName,
            publicReason:      $result->publicReason ?? $result->reason ?? '',
            appealToken:       $appealToken,
            pageToken:         $page->page_access_token,
            reportable:        true,
            logId:             $logId,
        );

        // Hide from the public timeline (NOT deleted).
        if ($platformId) {
            $this->meta->hideComment($platformId, $page->page_access_token);
        }

        DB::table('comments')->where('id', $commentId)->update(['appeal_token' => $appealToken]);
    }

    private function approveComment(int $commentId, ModerationResult $result, array $page = [], array $socialUser = [], int $logId = 0): array
    {
        // ── Auto-pubblica risposta fact-check se confidenza >= soglia ────
        if (
            $result->factCheckSuggested
            && $result->factCheckDraft !== null
            && $result->factCheckConfidence > 0.0
        ) {
            $threshold = 0.90;
            try {
                $t = DB::table('app_settings')->where('key', 'fact_check_auto_publish_threshold')->value('value');
                if ($t !== null) $threshold = (float) $t;
            } catch (\Throwable) {}

            if ($result->factCheckConfidence >= $threshold) {
                // Pubblica automaticamente la risposta su Facebook
                $autoPublished = false;
                if (!$this->isDevMode() && !empty($page['page_access_token'])) {
                    $comment = DB::table('comments')->find($commentId);
                    if ($comment) {
                        $autoPublished = (bool) $this->meta->replyToComment(
                            $comment->platform_comment_id,
                            $result->factCheckDraft,
                            $page['page_access_token'],
                        );
                    }
                }

                if ($logId) {
                    DB::table('moderation_log')->where('id', $logId)->update([
                        'final_action'       => 'auto_fact_checked',
                        'removal_reply_sent' => $autoPublished ? 1 : 0,
                        'removal_reply_text' => $result->factCheckDraft,
                    ]);
                }

                DB::table('comments')->where('id', $commentId)->update([
                    'status'       => 'approved',
                    'processed_at' => date('Y-m-d H:i:s'),
                ]);

                $this->logger?->info(
                    "Comment #{$commentId} approved + fact-check auto-published " .
                    "(confidence: {$result->factCheckConfidence}, dev: " . ($this->isDevMode() ? 'yes' : 'no') . ")"
                );

                return [
                    'action'                  => 'auto_fact_checked',
                    'comment_id'              => $commentId,
                    'fact_check_published'    => $autoPublished,
                    'fact_check_confidence'   => $result->factCheckConfidence,
                ];
            }

            // Confidenza insufficiente → coda umana con draft pronto
            DB::table('comments')->where('id', $commentId)->update([
                'status'       => 'escalated_human',
                'processed_at' => date('Y-m-d H:i:s'),
            ]);
            if ($logId) {
                DB::table('moderation_log')->where('id', $logId)->update(['final_action' => 'pending_human']);
            }

            $this->logger?->info(
                "Comment #{$commentId} queued for human review — fact-check draft ready " .
                "(confidence: {$result->factCheckConfidence} < threshold: {$threshold})"
            );

            return [
                'action'                => 'fact_check_queued_human',
                'comment_id'            => $commentId,
                'fact_check_confidence' => $result->factCheckConfidence,
            ];
        }

        // ── Approvazione normale (nessun fact-check) ─────────────────────
        DB::table('comments')->where('id', $commentId)->update([
            'status'       => 'approved',
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
        return ['action' => 'approved', 'comment_id' => $commentId, 'stage' => $result->stage];
    }

    // ──────────────────────────────────────────────────────────────────
    // Hide flow (GDPR-friendly default moderation action)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Hide a comment on Facebook (visible only to author + admins),
     * post an appeal reply as a sub-comment (also hidden from public),
     * and persist the moderation state.
     *
     * The appeal reply is posted BEFORE hiding so the thread is still
     * accessible when we write to it.
     */
    private function executeHide(
        int              $commentId,
        array            $socialUser,
        array            $page,
        ModerationResult $result,
        int              $logId,
        string           $decidedBy,
        ?int             $adminUserId = null,
        bool             $reportable  = false,
        bool             $silent      = false,
    ): array {
        $devMode = $this->isDevMode();

        if ($devMode) {
            $this->logger?->info("[DEV MODE] Would hide comment #{$commentId}");
            DB::table('comments')->where('id', $commentId)->update([
                'status'       => 'dev_flagged',
                'processed_at' => date('Y-m-d H:i:s'),
            ]);
            return ['action' => 'dev_flagged_hide', 'comment_id' => $commentId, 'dev_mode' => true];
        }

        $comment     = DB::table('comments')->find($commentId);
        $platformId  = $comment->platform_comment_id ?? '';
        $displayName = $socialUser['display_name'] ?? 'utente';

        // 1. Generate appeal token
        $appealToken = $this->generateAppealToken($commentId, $socialUser['id']);

        // 2. Post appeal reply (sub-comment, hidden from public) — skip if silent
        if (!$silent) {
            $this->postHideReply(
                platformCommentId: $platformId,
                displayName:       $displayName,
                publicReason:      $result->publicReason ?? $result->reason ?? '',
                appealToken:       $appealToken,
                pageToken:         $page['page_access_token'],
                reportable:        $reportable,
                logId:             $logId,
            );
        }

        // 3. Hide the comment on Facebook
        if ($platformId) {
            $this->meta->hideComment($platformId, $page['page_access_token']);
        }

        // 4. Persist state
        $status = $reportable ? 'hidden_reportable' : 'hidden';
        DB::table('comments')->where('id', $commentId)->update([
            'status'        => $status,
            'appeal_token'  => $appealToken,
            'processed_at'  => date('Y-m-d H:i:s'),
        ]);

        DB::table('moderation_log')->where('id', $logId)->update([
            'final_action' => 'hidden',
        ]);

        // 5. Increment violation count and check ban threshold
        $newCount = ($socialUser['violation_count'] ?? 0) + 1;
        DB::table('social_users')->where('id', $socialUser['id'])->update([
            'violation_count'   => $newCount,
            'last_violation_at' => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        // Read recidivism threshold from settings
        $recidivismLimit = (int) ($_ENV['RECIDIVISM_COMMENT_BAN_LIMIT'] ?? 3);
        try {
            $dbVal = DB::table('app_settings')
                ->where('key', 'recidivism_comment_ban_limit')->value('value');
            if ($dbVal !== null) $recidivismLimit = max(1, (int) $dbVal);
        } catch (\Throwable) {}

        // Ban triggers AFTER the threshold is crossed, not on reaching it.
        // e.g. threshold=3: violations 1,2,3 → hide only; violation 4+ → hide + ban
        $banAction = null;
        if ($newCount > $recidivismLimit) {
            $banAction = $this->ban->applyUserBan(
                socialUserId: $socialUser['id'],
                pageId:       isset($page['id']) ? (int) $page['id'] : null,
                logId:        $logId,
                commentId:    $commentId,
                decidedBy:    $decidedBy,
                adminUserId:  $adminUserId,
                reason:       "Recidivista: {$newCount} violazioni",
                categories:   $result->categories,
            );
        }

        $this->logger?->info("Comment #{$commentId} hidden" . ($reportable ? ' [REPORTABLE]' : '') . ($banAction ? " + ban: {$banAction}" : ''));

        return [
            'action'      => $reportable ? 'hidden_reportable' : 'hidden',
            'comment_id'  => $commentId,
            'user_id'     => $socialUser['id'],
            'violations'  => $newCount,
            'decided_by'  => $decidedBy,
            'appeal_token'=> $appealToken,
            'ban_action'  => $banAction,
        ];
    }

    /**
     * Post the appeal reply as a sub-comment on the hidden comment.
     * Since the parent comment is still visible at call time, the reply
     * will be threaded under it and remain visible only to the author.
     */
    private function postHideReply(
        string $platformCommentId,
        string $displayName,
        string $publicReason,
        string $appealToken,
        string $pageToken,
        bool   $reportable,
        int    $logId,
    ): void {
        // Respect admin setting — default ON (GDPR recommended)
        try {
            $enabled = DB::table('app_settings')->where('key', 'removal_reply_enabled')->value('value');
            if ($enabled !== null && !(bool)(int)$enabled) {
                DB::table('moderation_log')->where('id', $logId)->update([
                    'removal_reply_sent' => 0,
                    'removal_reply_text' => null,
                ]);
                return;
            }
        } catch (\Throwable) {}

        $baseUrl   = rtrim(
            DB::table('app_settings')->where('key', 'app_url')->value('value')
            ?? $_ENV['APP_URL']
            ?? '',
            '/'
        );
        $appealUrl = "{$baseUrl}/appeal?token={$appealToken}";
        $reason    = rtrim($publicReason ?: 'non rispetta le linee guida della nostra community', '.');
        $message   = $this->buildHideReply($displayName, $reason, $appealUrl, $reportable);

        $sent = $this->meta->replyToComment($platformCommentId, $message, $pageToken);

        DB::table('moderation_log')->where('id', $logId)->update([
            'removal_reply_sent' => $sent ? 1 : 0,
            'removal_reply_text' => $message,
        ]);
    }

    /**
     * Builds the hide-reply message from DB templates (PRO: customisable).
     * Falls back to hardcoded defaults when keys are absent.
     * Placeholders: {nome}, {reason}, {appeal_url}
     */
    private function buildHideReply(
        string $displayName,
        string $reason,
        string $appealUrl,
        bool   $reportable,
    ): string {
        $settingKey = $reportable
            ? 'hide_reportable_reply_template'
            : 'hide_reply_template';

        $defaultNormal =
            "Ciao {nome}, il tuo commento \u00e8 stato temporaneamente nascosto perch\u00e9 {reason}.\n\n" .
            "Se ritieni che ci sia un errore, puoi richiedere una revisione: {appeal_url}";

        $defaultReportable =
            "Ciao {nome}, il tuo commento \u00e8 stato temporaneamente nascosto perch\u00e9 {reason}.\n\n" .
            "\u26a0\ufe0f Il contenuto \u00e8 stato segnalato per valutazione legale da parte della redazione.\n\n" .
            "Se ritieni che ci sia un errore, puoi richiedere una revisione: {appeal_url}";

        $template = $reportable ? $defaultReportable : $defaultNormal;

        try {
            $tpl = DB::table('app_settings')->where('key', $settingKey)->value('value');
            if (!empty($tpl)) {
                $template = $tpl;
            }
        } catch (\Throwable) {}

        return str_replace(
            ['{nome}', '{reason}', '{appeal_url}'],
            [$displayName, $reason, $appealUrl],
            $template,
        );
    }

    /**
     * Generate a signed appeal token for a hidden comment.
     * Format: base64(commentId:userId:expires:hmac)
     * No external library required.
     */
    private function generateAppealToken(int $commentId, int $socialUserId): string
    {
        $expires = time() + (86400 * 30); // 30 days
        $secret  = $_ENV['APP_SECRET'] ?? 'fallback-secret';
        $payload = "{$commentId}:{$socialUserId}:{$expires}";
        $sig     = hash_hmac('sha256', $payload, $secret);
        return rtrim(base64_encode("{$payload}:{$sig}"), '=');
    }

    /**
     * Verify and decode an appeal token.
     * Returns ['comment_id' => int, 'social_user_id' => int] or null if invalid/expired.
     */
    public function verifyAppealToken(string $token): ?array
    {
        try {
            $decoded = base64_decode(str_pad($token, strlen($token) + (4 - strlen($token) % 4) % 4, '='));
            [$commentId, $socialUserId, $expires, $sig] = explode(':', $decoded, 4);

            $secret   = $_ENV['APP_SECRET'] ?? 'fallback-secret';
            $payload  = "{$commentId}:{$socialUserId}:{$expires}";
            $expected = hash_hmac('sha256', $payload, $secret);

            if (!hash_equals($expected, $sig)) return null;
            if ((int) $expires < time()) return null;

            return ['comment_id' => (int) $commentId, 'social_user_id' => (int) $socialUserId];
        } catch (\Throwable) {
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Edited comment re-analysis
    // ──────────────────────────────────────────────────────────────────

    /**
     * Handle a webhook 'edited' event.
     *
     * If the comment was previously hidden, re-run the full AI pipeline on
     * the new text. If it now passes, auto-unhide and log the restoration.
     * If it still fails, keep it hidden and update the log.
     * If it was not hidden (approved/pending), process normally as a new comment.
     */
    private function processEditedComment(array $webhookComment, array $page): array
    {
        $platformId = $webhookComment['id'] ?? '';

        // Fetch current text from Graph API (not in the webhook payload)
        $fresh = $this->meta->getComment($platformId, $page['page_access_token']);
        if (empty($fresh['message'])) {
            $this->logger?->warning("Edited comment {$platformId}: could not fetch updated text");
            return ['action' => 'edit_skipped_no_text', 'platform_comment_id' => $platformId];
        }

        $newText = $fresh['message'];

        // Find existing comment record by platform_comment_id
        $existing = DB::table('comments')
            ->where('platform_comment_id', $platformId)
            ->first();

        if (!$existing) {
            // Never seen before — treat as new comment
            $webhookComment['message'] = $newText;
            $webhookComment['verb']    = 'add';
            return $this->processWebhookComment($webhookComment, $page);
        }

        $wasHidden = in_array($existing->status, ['hidden', 'hidden_reportable'], true);

        // Log the edit event
        $policy = DB::table('policies')->where('is_active', 1)->first();
        DB::table('moderation_log')->insertGetId([
            'comment_id'   => $existing->id,
            'stage'        => 'system',
            'policy_id'    => $policy?->id ?? 1,
            'human_note'   => "Comment edited by user. New text: \"{$newText}\"",
            'final_action' => 'comment_edited',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        // Update stored text
        DB::table('comments')->where('id', $existing->id)->update([
            'content'      => $newText,
            'content_hash' => hash('sha256', $newText),
        ]);

        if (!$wasHidden) {
            // Was already approved or pending — no re-analysis needed
            return ['action' => 'edit_logged', 'comment_id' => $existing->id];
        }

        // Re-analyse with AI
        $socialUser = DB::table('social_users')->find($existing->social_user_id);
        $accountMeta = [];
        if (!empty($socialUser->platform_user_id) && !str_starts_with($socialUser->platform_user_id, 'unknown_')) {
            $accountMeta = $this->fetchAccountMeta($socialUser->platform_user_id, $page['page_access_token']);
        }

        $commentContext = $this->buildCommentContext($newText, (array) $socialUser, $accountMeta);

        $reasonMaxWords = 40;
        try {
            $rmw = DB::table('app_settings')->where('key', 'reason_max_words')->value('value');
            if ($rmw !== null) $reasonMaxWords = max(10, (int) $rmw);
        } catch (\Throwable) {}

        $reModPrompt = $policy?->moderation_prompt ?? '';

        [$pgHaiku, $pgSonnet, $pgFactCheck] = $this->pageThresholdOverrides((int) ($page['id'] ?? 0));

        $result = $this->claude->moderate(
            commentText:      $commentContext,
            moderationPrompt: $reModPrompt,
            reasonMaxWords:   $reasonMaxWords,
            haikuThreshold:   $pgHaiku,
            sonnetThreshold:  $pgSonnet,
            factCheckEnabled: $pgFactCheck,
        );

        $reAnalysisLogId = DB::table('moderation_log')->insertGetId([
            'comment_id'          => $existing->id,
            'stage'               => $result->stage,
            'policy_id'           => $policy?->id ?? 1,
            'ai_decision'         => $result->decision,
            'ai_confidence'       => $result->confidence,
            'ai_reason'           => $result->reason,
            'ai_public_reason'    => $result->publicReason,
            'ai_categories'       => json_encode($result->categories),
            'ai_severity'         => $result->severity,
            'ai_model'            => $result->model,
            'ai_latency_ms'       => $result->latencyMs,
            'human_note'          => 'Re-analysis after user edit',
            'final_action'        => 'pending',
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        // If AI now approves → unhide
        if (in_array($result->decision, ['allow'], true) && $result->stage !== 'human') {
            if (!$this->isDevMode()) {
                $this->meta->unhideComment($platformId, $page['page_access_token']);
            }
            DB::table('comments')->where('id', $existing->id)->update([
                'status'       => 'approved',
                'processed_at' => date('Y-m-d H:i:s'),
            ]);
            DB::table('moderation_log')->where('id', $reAnalysisLogId)->update(['final_action' => 'approved']);
            $this->logger?->info("Comment #{$existing->id} unhidden after user edit — re-analysis passed");
            return ['action' => 'edit_unhidden', 'comment_id' => $existing->id];
        }

        // Still problematic — keep hidden, update log
        DB::table('moderation_log')->where('id', $reAnalysisLogId)->update(['final_action' => 'hidden']);
        $this->logger?->info("Comment #{$existing->id} remains hidden after edit re-analysis");
        return ['action' => 'edit_remains_hidden', 'comment_id' => $existing->id];
    }
}