-- ============================================================
-- Migration 002 — Whataboutism detection (PRO feature)
-- ============================================================
-- Esegui questa migrazione UNA SOLA VOLTA su installazioni esistenti
-- per allinearle allo schema dichiarativo in 001_initial_schema.sql.
--
-- Le nuove installazioni applicano già lo schema completo da 001 e NON
-- devono eseguire questo file (gli ALTER fallirebbero perché le colonne
-- esistono già).
--
-- Uso:
--   mysql -u <user> -p <database> < database/migrations/002_whataboutism.sql
--
-- Idempotenza: il file è studiato per essere ri-eseguibile a vuoto.
-- Gli ADD COLUMN su colonne esistenti generano un warning che puoi ignorare;
-- l'INSERT IGNORE non duplica la seed di app_settings.
-- ============================================================

SET NAMES utf8mb4;

-- ── moderation_log: 4 colonne nuove + nuovo enum auto_whataboutism_replied ─
ALTER TABLE `moderation_log`
  ADD COLUMN `ai_whataboutism_suggested`  TINYINT(1) NOT NULL DEFAULT 0
        AFTER `ai_fact_check_suggested`,
  ADD COLUMN `ai_whataboutism_draft`      TEXT NULL
        COMMENT 'Bozza di risposta editoriale generata da Sonnet (niente URL)'
        AFTER `ai_whataboutism_suggested`,
  ADD COLUMN `ai_whataboutism_confidence` DECIMAL(4,3) NULL
        COMMENT 'Confidence del secondo passaggio Sonnet whataboutism'
        AFTER `ai_whataboutism_draft`,
  ADD COLUMN `ai_whataboutism_latency_ms` SMALLINT UNSIGNED NULL
        COMMENT 'Latency ms della call Sonnet whataboutism'
        AFTER `ai_whataboutism_confidence`,
  MODIFY `final_action`
        ENUM('approved','removed','hidden','pending_human','comment_edited','auto_fact_checked','auto_whataboutism_replied')
        NOT NULL DEFAULT 'pending_human';

-- ── page_settings: toggle per-pagina ─────────────────────────────
ALTER TABLE `page_settings`
  ADD COLUMN `whataboutism_enabled` TINYINT(1) NOT NULL DEFAULT 1
        AFTER `fact_check_enabled`;

-- ── app_settings: soglia globale di auto-pubblicazione ───────────
-- 0.95 = soglia conservativa (più alta del fact-check 0.90) perché qui
-- non esistono fonti esterne come secondo gate — la verifica avviene
-- via 2° call Sonnet fredda lato applicazione (verifyWhataboutism).
INSERT IGNORE INTO `app_settings` (`key`, `value`) VALUES
  ('whataboutism_auto_publish_threshold', '0.95');
