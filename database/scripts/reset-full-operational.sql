-- ============================================================
-- Reset FULL operational data (incl. connected pages)
-- ============================================================
-- Empties every table holding operational data — comments,
-- moderation logs, appeals, bans, tracked social users, webhook
-- events, per-page settings AND the connected Facebook pages —
-- so the installation is back to "no pages connected yet" while
-- keeping all configuration intact.
--
-- Difference vs reset-operational-data.sql:
--   this script ALSO wipes `connected_pages` and `page_settings`,
--   so you have to re-connect the Facebook pages afterwards.
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
--   mysql -u <user> -p moderation_hub < database/scripts/reset-full-operational.sql
-- ============================================================

-- Delete in child -> parent order so foreign keys are satisfied.
DELETE FROM `appeal_records`;
DELETE FROM `ban_records`;
DELETE FROM `moderation_log`;
DELETE FROM `page_settings`;
DELETE FROM `comments`;
DELETE FROM `connected_pages`;
DELETE FROM `social_users`;
DELETE FROM `webhook_events`;

-- Reset auto-increment counters for a clean "new DB" feel.
ALTER TABLE `appeal_records`  AUTO_INCREMENT = 1;
ALTER TABLE `ban_records`     AUTO_INCREMENT = 1;
ALTER TABLE `moderation_log`  AUTO_INCREMENT = 1;
ALTER TABLE `page_settings`   AUTO_INCREMENT = 1;
ALTER TABLE `comments`        AUTO_INCREMENT = 1;
ALTER TABLE `connected_pages` AUTO_INCREMENT = 1;
ALTER TABLE `social_users`    AUTO_INCREMENT = 1;
ALTER TABLE `webhook_events`  AUTO_INCREMENT = 1;

-- Tables intentionally NOT touched (configuration kept):
--   admin_users, policies, app_settings, license_cache
--
-- After running this you must re-connect Facebook pages from the
-- dashboard (Pagine Facebook → + Aggiungi pagine).
