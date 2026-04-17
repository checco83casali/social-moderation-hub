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
    // Entry point: process a raw comment from a webhook
    // ──────────────────────────────────────────────────────────────────

    public function processWebhookComment(array $webhookComment, array $page): array
    {
        // 1. Upsert the social user
        $socialUser = $this->upsertSocialUser(
            platformUserId: $webhookComment['from']['id'],
            displayName:    $webhookComment['from']['name'] ?? null,
            platform:       'facebook',
        );

        // 2. Check if user is already banned → auto-remove without calling AI
        if ($this->ban->isUserBanned($socialUser['id'])) {
            $this->removeComment($webhookComment['id'], $page['page_access_token']);
            return ['action' => 'auto_removed_banned_user', 'user_id' => $socialUser['id']];
        }

        // 3. Deduplicate
        $commentHash = hash('sha256', $webhookComment['message'] ?? '');
        $existingId  = DB::table('comments')
            ->where('content_hash', $commentHash)
            ->where('social_user_id', $socialUser['id'])
            ->value('id');

        if ($existingId) {
            return ['action' => 'duplicate_skipped', 'comment_id' => $existingId];
        }

        // 4. Persist comment
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

        // 5. Load active policy
        $policy = DB::table('policies')->where('is_active', 1)->first();
        if (!$policy) {
            $this->logger?->error('No active moderation policy found.');
            return ['action' => 'error_no_policy', 'comment_id' => $commentId];
        }

        // 6. Enrich with Meta account metadata (best-effort, non-blocking)
        $accountMeta = $this->fetchAccountMeta(
            $webhookComment['from']['id'],
            $page['page_access_token']
        );

        // 7. Build enriched context for Claude
        $commentContext = $this->buildCommentContext(
            $webhookComment['message'] ?? '',
            $socialUser,
            $accountMeta,
        );

        // 8. Run AI moderation pipeline
        $result = $this->claude->moderate(
            commentText:  $commentContext,
            systemPrompt: $policy->system_prompt,
        );

        // 9. Persist moderation log
        $logId = DB::table('moderation_log')->insertGetId([
            'comment_id'    => $commentId,
            'stage'         => $result->stage,
            'policy_id'     => $policy->id,
            'ai_decision'   => $result->decision,
            'ai_confidence' => $result->confidence,
            'ai_reason'     => $result->reason,
            'ai_categories' => json_encode($result->categories),
            'ai_severity'   => $result->severity,
            'ai_model'      => $result->model,
            'ai_latency_ms' => $result->latencyMs,
            'final_action'  => $result->stage === 'human' ? 'pending_human' :
                               ($result->decision === 'remove' ? 'removed' : 'approved'),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        // 10. Act on AI decision
        return match (true) {
            $result->stage === 'human'      => $this->escalateToHuman($commentId, $result),
            $result->decision === 'remove'  => $this->executeRemoval(
                $commentId, $socialUser, $page, $result, $logId, decidedBy: 'ai'
            ),
            default                         => $this->approveComment($commentId, $result),
        };
    }

    // ──────────────────────────────────────────────────────────────────
    // Human decision (called from the admin panel)
    // ──────────────────────────────────────────────────────────────────

    public function applyHumanDecision(
        int    $commentId,
        string $decision,
        int    $adminUserId,
        string $note = '',
    ): array {
        $comment    = DB::table('comments')->find($commentId);
        if (!$comment) return ['error' => 'Comment not found'];

        $page       = DB::table('connected_pages')->find($comment->page_id);
        $socialUser = DB::table('social_users')->find($comment->social_user_id);
        $log        = DB::table('moderation_log')
            ->where('comment_id', $commentId)
            ->orderByDesc('id')
            ->first();

        DB::table('moderation_log')->where('id', $log->id)->update([
            'human_user_id'    => $adminUserId,
            'human_decision'   => $decision,
            'human_note'       => $note,
            'human_decided_at' => date('Y-m-d H:i:s'),
            'final_action'     => $decision === 'remove' ? 'removed' : 'approved',
        ]);

        if ($decision === 'remove') {
            $result = new ModerationResult(
                stage: 'human', decision: 'remove', confidence: 1.0,
                reason: $note, categories: [], model: 'human',
            );
            return $this->executeRemoval(
                $commentId, (array) $socialUser, (array) $page,
                $result, $log->id, decidedBy: 'human', adminUserId: $adminUserId,
            );
        }

        DB::table('comments')->where('id', $commentId)->update(['status' => 'approved']);
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
     * Build an enriched text context for Claude that includes
     * account metadata alongside the comment text.
     */
    private function buildCommentContext(string $message, array $socialUser, array $meta): string
    {
        $lines = [];
        $lines[] = "COMMENT TO EVALUATE:";
        $lines[] = "\"{$message}\"";
        $lines[] = "";
        $lines[] = "USER CONTEXT (do not share with public):";
        $lines[] = "- Internal violations recorded: " . ($socialUser['violation_count'] ?? 0);
        $lines[] = "- Internal ban status: " . ($socialUser['ban_status'] ?? 'clean');

        if (!empty($meta)) {
            if (isset($meta['account_age_days'])) {
                $age = $meta['account_age_days'];
                $ageLabel = $age < 30 ? "very new ({$age} days) — higher scam risk"
                          : ($age < 180 ? "recent ({$age} days)"
                          : "established ({$age} days)");
                $lines[] = "- Facebook account age: {$ageLabel}";
            }
            if (isset($meta['fan_count'])) {
                $lines[] = "- Account followers/fans: " . number_format($meta['fan_count']);
            }
            if (isset($meta['verified'])) {
                $lines[] = "- Account verified: " . ($meta['verified'] ? 'yes' : 'no');
            }
        }

        $lines[] = "";
        $lines[] = "Evaluate only the comment text above. The user context is supplementary signal.";

        return implode("\n", $lines);
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

        if ($user) return (array) $user;

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

    private function escalateToHuman(int $commentId, ModerationResult $result): array
    {
        DB::table('comments')->where('id', $commentId)->update(['status' => 'escalated_human']);
        $this->logger?->info("Comment #{$commentId} escalated to human review");
        return ['action' => 'escalated_human', 'comment_id' => $commentId, 'stage' => $result->stage];
    }

    private function approveComment(int $commentId, ModerationResult $result): array
    {
        DB::table('comments')->where('id', $commentId)->update([
            'status'       => 'approved',
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
        return ['action' => 'approved', 'comment_id' => $commentId, 'stage' => $result->stage];
    }

    private function executeRemoval(
        int              $commentId,
        array            $socialUser,
        array            $page,
        ModerationResult $result,
        int              $logId,
        string           $decidedBy,
        ?int             $adminUserId = null,
    ): array {
        try {
            $platformId = DB::table('comments')->where('id', $commentId)->value('platform_comment_id') ?? '';
            $this->meta->deleteComment($platformId, $page['page_access_token']);
        } catch (\Throwable $e) {
            $this->logger?->warning("Could not remove comment from Meta: " . $e->getMessage());
        }

        DB::table('comments')->where('id', $commentId)->update([
            'status'       => 'removed',
            'processed_at' => date('Y-m-d H:i:s'),
        ]);

        $newCount = ($socialUser['violation_count'] ?? 0) + 1;
        DB::table('social_users')->where('id', $socialUser['id'])->update([
            'violation_count'   => $newCount,
            'last_violation_at' => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        DB::table('ban_records')->insert([
            'social_user_id'     => $socialUser['id'],
            'page_id'            => $page['id'],
            'ban_type'           => 'comment_removed',
            'ban_scope'          => 'comment',
            'trigger_comment_id' => $commentId,
            'trigger_log_id'     => $logId,
            'decided_by'         => $decidedBy,
            'admin_user_id'      => $adminUserId,
            'reason'             => $result->reason,
            'categories'         => json_encode($result->categories),
            'is_active'          => 1,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $recidivismLimit = (int) ($_ENV['RECIDIVISM_COMMENT_BAN_LIMIT'] ?? 3);
        $banAction       = 'comment_removed';

        if ($newCount >= $recidivismLimit) {
            $banAction = $this->ban->applyUserBan(
                socialUserId: $socialUser['id'],
                pageId:       (int) $page['id'],
                logId:        $logId,
                commentId:    $commentId,
                decidedBy:    $decidedBy,
                adminUserId:  $adminUserId,
                reason:       "Recidivist: {$newCount} violations",
                categories:   $result->categories,
            );
        }

        return [
            'action'     => $banAction,
            'comment_id' => $commentId,
            'user_id'    => $socialUser['id'],
            'violations' => $newCount,
            'decided_by' => $decidedBy,
        ];
    }

    private function removeComment(string $platformCommentId, string $pageToken): void
    {
        $this->meta->deleteComment($platformCommentId, $pageToken);
    }
}
