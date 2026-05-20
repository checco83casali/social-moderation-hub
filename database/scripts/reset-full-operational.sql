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
-- ALWAYS take a backup first:
--   mysqldump -u <user> -p moderation_hub > backup_$(date +%F).sql
--
-- Usage:
--   mysql -u <user> -p moderation_hub < database/scripts/reset-full-operational.sql
-- or paste into phpMyAdmin / TablePlus on the moderation_hub database.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE `appeal_records`;
TRUNCATE TABLE `moderation_log`;
TRUNCATE TABLE `ban_records`;
TRUNCATE TABLE `comments`;
TRUNCATE TABLE `social_users`;
TRUNCATE TABLE `webhook_events`;
TRUNCATE TABLE `page_settings`;
TRUNCATE TABLE `connected_pages`;

SET FOREIGN_KEY_CHECKS = 1;

-- Tables intentionally NOT touched (configuration kept):
--   admin_users, policies, app_settings, license_cache
--
-- After running this you must re-connect Facebook pages from the
-- dashboard (Pagine Facebook → + Aggiungi pagine).
