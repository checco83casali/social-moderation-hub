-- ============================================================
-- Migration 003 — Password temporanea al primo accesso
-- ============================================================
-- Esegui questa migrazione UNA SOLA VOLTA su installazioni esistenti.
-- Le nuove installazioni applicano già lo schema completo da 001 e NON
-- devono eseguire questo file.
--
-- Uso:
--   mysql -u <user> -p <database> < database/migrations/003_temp_password.sql
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `admin_users`
  ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Se 1, l''utente deve cambiare la password al prossimo accesso'
        AFTER `password_hash`;
