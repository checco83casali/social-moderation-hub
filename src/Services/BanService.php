<?php
// src/Services/BanService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Manages user ban lifecycle:
 *   comment_removed → temp_ban → perm_ban
 *
 * Human ban decisions feed back into the system as training signals.
 */
class BanService
{
    // Temp ban duration in days per escalation level
    private const TEMP_BAN_DAYS = [1 => 1, 2 => 7, 3 => 30];

    // ──────────────────────────────────────────────────────────────────
    // Query
    // ──────────────────────────────────────────────────────────────────

    public function isUserBanned(int $socialUserId): bool
    {
        return DB::table('ban_records')
            ->where('social_user_id', $socialUserId)
            ->where('ban_scope', 'user')
            ->where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', date('Y-m-d H:i:s'));
            })
            ->exists();
    }

    public function getUserBanStatus(int $socialUserId): array
    {
        $active = DB::table('ban_records')
            ->where('social_user_id', $socialUserId)
            ->where('ban_scope', 'user')
            ->where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', date('Y-m-d H:i:s'));
            })
            ->orderByDesc('created_at')
            ->first();

        if (!$active) {
            return ['banned' => false];
        }

        return [
            'banned'     => true,
            'type'       => $active->ban_type,
            'expires_at' => $active->expires_at,
            'reason'     => $active->reason,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Apply
    // ──────────────────────────────────────────────────────────────────

    /**
     * Called when violation count crosses recidivism threshold.
     * Determines whether to apply temp or permanent ban.
     * Returns the action string for the moderation response.
     */
    public function applyUserBan(
        int     $socialUserId,
        int     $pageId,
        int     $logId,
        int     $commentId,
        string  $decidedBy,
        ?int    $adminUserId,
        string  $reason,
        array   $categories = [],
    ): string {
        $user         = DB::table('social_users')->find($socialUserId);
        $banCount     = $this->countUserBans($socialUserId);
        $isPermanent  = $banCount >= 2; // 3rd ban → permanent

        $expiresAt    = null;
        $banType      = 'perm_ban';

        if (!$isPermanent) {
            $days      = self::TEMP_BAN_DAYS[$banCount + 1] ?? 30;
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));
            $banType   = 'temp_ban';
        }

        // Deactivate any prior user-scope bans (superseded)
        DB::table('ban_records')
            ->where('social_user_id', $socialUserId)
            ->where('ban_scope', 'user')
            ->where('is_active', 1)
            ->update(['is_active' => 0]);

        DB::table('ban_records')->insert([
            'social_user_id'     => $socialUserId,
            'page_id'            => $pageId,
            'ban_type'           => $banType,
            'ban_scope'          => 'user',
            'trigger_comment_id' => $commentId,
            'trigger_log_id'     => $logId,
            'decided_by'         => $decidedBy,
            'admin_user_id'      => $adminUserId,
            'reason'             => $reason,
            'categories'         => json_encode($categories),
            'expires_at'         => $expiresAt,
            'is_active'          => 1,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        DB::table('social_users')->where('id', $socialUserId)->update([
            'ban_status' => $isPermanent ? 'perm_banned' : 'temp_banned',
            'ban_expires_at' => $expiresAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $isPermanent ? 'user_perm_banned' : 'user_temp_banned';
    }

    /**
     * Manually lift a ban (admin action).
     */
    public function liftBan(int $socialUserId, int $adminUserId, string $reason = ''): bool
    {
        $affected = DB::table('ban_records')
            ->where('social_user_id', $socialUserId)
            ->where('ban_scope', 'user')
            ->where('is_active', 1)
            ->update(['is_active' => 0]);

        DB::table('social_users')->where('id', $socialUserId)->update([
            'ban_status'     => 'clean',
            'ban_expires_at' => null,
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        // Log the lift action as a special ban record (for audit trail & learning)
        DB::table('ban_records')->insert([
            'social_user_id' => $socialUserId,
            'page_id'        => null,
            'ban_type'       => 'comment_removed', // repurposed as "no ban"
            'ban_scope'      => 'user',
            'decided_by'     => 'human',
            'admin_user_id'  => $adminUserId,
            'reason'         => 'Ban lifted: ' . $reason,
            'categories'     => json_encode([]),
            'is_active'      => 0, // already inactive — this is audit only
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        return $affected > 0;
    }

    // ──────────────────────────────────────────────────────────────────
    // Learning data export
    // ──────────────────────────────────────────────────────────────────

    /**
     * Returns human ban decisions with comment content for policy refinement.
     * This data can be used to tune the AI system prompt or thresholds.
     */
    public function getHumanBanLearningData(int $limit = 500): array
    {
        return DB::table('ban_records as b')
            ->join('moderation_log as ml', 'ml.id', '=', 'b.trigger_log_id')
            ->join('comments as c', 'c.id', '=', 'b.trigger_comment_id')
            ->where('b.decided_by', 'human')
            ->whereNotNull('b.trigger_log_id')
            ->select([
                'b.id',
                'b.ban_type',
                'b.categories',
                'b.reason',
                'b.created_at',
                'c.content as comment_text',
                'ml.ai_decision',
                'ml.ai_confidence',
                'ml.ai_reason',
                'ml.stage as ai_stage',
            ])
            ->orderByDesc('b.created_at')
            ->limit($limit)
            ->get()
            ->map(fn($row) => (array) $row)
            ->toArray();
    }

    // ──────────────────────────────────────────────────────────────────

    private function countUserBans(int $socialUserId): int
    {
        return DB::table('ban_records')
            ->where('social_user_id', $socialUserId)
            ->where('ban_scope', 'user')
            ->where('ban_type', '!=', 'comment_removed')
            ->count();
    }
}
