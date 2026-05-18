-- ============================================================
-- Reset operational data
-- ============================================================
-- Empties every table holding moderation/comment/ban/appeal data,
-- so the installation can be restarted "from zero" without losing
-- configuration (admin users, connected pages, policies, settings,
-- license cache).
--
-- ALWAYS take a backup first:
--   mysqldump -u <user> -p moderation_hub > backup_$(date +%F).sql
--
-- Usage:
--   mysql -u <user> -p moderation_hub < database/scripts/reset-operational-data.sql
-- or paste into phpMyAdmin / TablePlus on the moderation_hub database.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE `appeal_records`;
TRUNCATE TABLE `moderation_log`;
TRUNCATE TABLE `ban_records`;
TRUNCATE TABLE `comments`;
TRUNCATE TABLE `social_users`;
TRUNCATE TABLE `webhook_events`;

SET FOREIGN_KEY_CHECKS = 1;

-- Tables intentionally NOT touched:
--   admin_users, connected_pages, policies, app_settings,
--   page_settings, license_cache
