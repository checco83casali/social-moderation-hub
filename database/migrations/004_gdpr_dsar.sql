-- ============================================================
-- Migration 004 — Registro delle richieste GDPR (artt. 15/17/20)
-- ============================================================
-- Esegui questa migrazione UNA SOLA VOLTA su installazioni esistenti.
-- Le nuove installazioni applicano già lo schema completo da 001 e NON
-- devono eseguire questo file.
--
-- Uso:
--   mysql -u <user> -p <database> < database/migrations/004_gdpr_dsar.sql
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Audit trail delle richieste dell'interessato evase manualmente
-- dal Titolare (accesso, export, anonimizzazione). Non contiene mai
-- il contenuto dei dati trattati: solo chi ha agito, quando, su quale
-- utente interno e perché — a fini di accountability art. 5.2 GDPR.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gdpr_audit_log` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `action`          ENUM('search','export','anonymise') NOT NULL,
    `social_user_id`  INT UNSIGNED NULL COMMENT 'Riferimento interno; NULL se l''utente è stato già anonimizzato in precedenza',
    `admin_user_id`   INT UNSIGNED NOT NULL,
    `reason`          TEXT NULL,
    `details`         JSON NULL COMMENT 'Conteggi righe coinvolte per tabella, esito',
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users`(`id`) ON DELETE RESTRICT,
    INDEX `idx_social_user` (`social_user_id`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
