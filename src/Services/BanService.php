<?php
// src/Services/BanService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Manages user ban lifecycle:
 *   comment_removed → temp_ban (level 1) → temp_ban (level 2) → temp_ban (level 3+)
 *
 * Bans are always temporary. From the 3rd ban onwards the level-3 duration repeats
 * indefinitely — no permanent ban is ever applied automatically (Art. 22 GDPR compliance).
 *
 * Human ban decisions feed back into the system as training signals.
 */
class BanService
{
    // Fallback defaults (used when DB settings are not yet available)
    private const DEFAULT_BAN_1_HOURS = 1;
    private const DEFAULT_BAN_2_DAYS  = 7;
    private const DEFAULT_BAN_3_DAYS  = 30;

    /**
     * Load ban escalation config from app_settings, falling back to defaults.
     */
    private function loadBanConfig(): array
    {
        try {
            $rows = DB::table('app_settings')
                ->whereIn('key', [
                    'ban_level_1_hours',
                    'ban_level_2_days',
                    'ban_level_3_days',
                ])
                ->pluck('value', 'key')
                ->toArray();

            return [
                'hours_1' => max(1, (int) ($rows['ban_level_1_hours'] ?? self::DEFAULT_BAN_1_HOURS)),
                'days_2'  => max(1, (int) ($rows['ban_level_2_days']  ?? self::DEFAULT_BAN_2_DAYS)),
                'days_3'  => max(1, (int) ($rows['ban_level_3_days']  ?? self::DEFAULT_BAN_3_DAYS)),
            ];
        } catch (\Throwable) {
            return [
                'hours_1' => self::DEFAULT_BAN_1_HOURS,
                'days_2'  => self::DEFAULT_BAN_2_DAYS,
                'days_3'  => self::DEFAULT_BAN_3_DAYS,
            ];
        }
    }

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
     * All bans are temporary. Sequence:
     *   1st ban → level-1 duration (hours)
     *   2nd ban → level-2 duration (days)
     *   3rd ban and above → level-3 duration repeats indefinitely
     *
     * No permanent ban is ever applied automatically (Art. 22 GDPR compliance).
     * Returns the action string for the moderation response.
     */
    public function applyUserBan(
        int     $socialUserId,
        ?int    $pageId,
        int     $logId,
        int     $commentId,
        string  $decidedBy,
        ?int    $adminUserId,
        string  $reason,
        array   $categories = [],
    ): string {
        // Safety guard: never ban the owner/admin of a connected page.
        // A page admin commenting on their own page would create an unrecoverable loop.
        $socialUser = DB::table('social_users')->find($socialUserId);
        if ($socialUser && !empty($socialUser->platform_user_id)) {
            $isPageOwner = DB::table('connected_pages')
                ->where('page_owner_fb_id', $socialUser->platform_user_id)
                ->exists();
            if ($isPageOwner) {
                return 'skipped_page_admin';
            }
        }

        $cfg      = $this->loadBanConfig();
        $banCount = $this->countUserBans($socialUserId);
        $level    = $banCount + 1; // next ban level (1-based)

        // Compute duration: level 1 → hours, level 2 → days_2, level 3+ → days_3 (repeats)
        if ($level === 1) {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$cfg['hours_1']} hours"));
        } elseif ($level === 2) {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$cfg['days_2']} days"));
        } else {
            // Level 3 and every subsequent ban: repeat the level-3 duration
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$cfg['days_3']} days"));
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
            'ban_type'           => 'temp_ban',
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
            'ban_status'     => 'temp_banned',
            'ban_expires_at' => $expiresAt,
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        return 'user_temp_banned';
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
    // Maintenance
    // ──────────────────────────────────────────────────────────────────

    /**
     * Mark expired temp bans as inactive and reset the user's ban_status.
     * Call this before any query that reads active bans to keep counts consistent.
     * Lightweight — only touches rows where expires_at has passed.
     */
    public function cleanupExpiredBans(): void
    {
        $now = date('Y-m-d H:i:s');

        $expiredUserIds = DB::table('ban_records')
            ->where('is_active', 1)
            ->where('ban_scope', 'user')
            ->where('ban_type', 'temp_ban')
            ->where('expires_at', '<=', $now)
            ->pluck('social_user_id')
            ->toArray();

        if (empty($expiredUserIds)) return;

        DB::table('ban_records')
            ->where('is_active', 1)
            ->where('ban_scope', 'user')
            ->where('ban_type', 'temp_ban')
            ->where('expires_at', '<=', $now)
            ->update(['is_active' => 0]);

        // Reset ban_status only for users with no other active ban
        foreach ($expiredUserIds as $userId) {
            $stillBanned = DB::table('ban_records')
                ->where('social_user_id', $userId)
                ->where('ban_scope', 'user')
                ->where('is_active', 1)
                ->where(function ($q) use ($now) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                })
                ->exists();

            if (!$stillBanned) {
                DB::table('social_users')->where('id', $userId)->update([
                    'ban_status'     => 'clean',
                    'ban_expires_at' => null,
                    'updated_at'     => $now,
                ]);
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────

    public function getConfig(): array
    {
        return $this->loadBanConfig();
    }

    public function countUserBans(int $socialUserId): int
    {
        return DB::table('ban_records')
            ->where('social_user_id', $socialUserId)
            ->where('ban_scope', 'user')
            ->where('ban_type', '!=', 'comment_removed')
            ->count();
    }
}