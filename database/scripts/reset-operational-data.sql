-- ============================================================
-- Reset operational data
-- ============================================================
-- Empties every table holding moderation/comment/ban/appeal data,
-- so the installation can be restarted "from zero" without losing
-- configuration (admin users, connected pages, policies, settings,
-- per-page settings, license cache).
--
-- NOTE: uses DELETE (not TRUNCATE) in foreign-key dependency order.
-- TRUNCATE fails with error #1701 on tables referenced by a FK, and
-- SET FOREIGN_KEY_CHECKS=0 is lost between statements in phpMyAdmin.
-- Ordered DELETE works everywhere (CLI and phpMyAdmin).
--
-- ALWAYS take a backup first:
--   mysqldump -u <user> -p moderation_hub > backup_$(date +%F).sql
--
-- Usage:
--   mysql -u <user> -p moderation_hub < database/scripts/reset-operational-data.sql
-- ============================================================

-- Delete in child -> parent order so foreign keys are satisfied.
DELETE FROM `appeal_records`;
DELETE FROM `ban_records`;
DELETE FROM `moderation_log`;
DELETE FROM `comments`;
DELETE FROM `social_users`;
DELETE FROM `webhook_events`;

ALTER TABLE `appeal_records` AUTO_INCREMENT = 1;
ALTER TABLE `ban_records`    AUTO_INCREMENT = 1;
ALTER TABLE `moderation_log` AUTO_INCREMENT = 1;
ALTER TABLE `comments`       AUTO_INCREMENT = 1;
ALTER TABLE `social_users`   AUTO_INCREMENT = 1;
ALTER TABLE `webhook_events` AUTO_INCREMENT = 1;

-- Tables intentionally NOT touched:
--   admin_users, connected_pages, policies, app_settings,
--   page_settings, license_cache
