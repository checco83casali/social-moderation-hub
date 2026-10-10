-- Training AI (licenza Pro, feature `ai_training`): per N decisioni umane i moderatori scrivono una
-- nota obbligatoria che spiega perché rinforzano o correggono il verdetto dell'AI. Raggiunto N la
-- raccolta si ferma e Sonnet propone una nuova versione (inattiva) del prompt di moderazione.

CREATE TABLE IF NOT EXISTS `ai_training_sessions` (
    `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `target`             SMALLINT UNSIGNED NOT NULL COMMENT 'Numero di note dopo il quale la raccolta si ferma',
    `status`             VARCHAR(12) NOT NULL DEFAULT 'active' COMMENT 'active | analyzing | done | stopped | failed',
    `started_by`         INT UNSIGNED NULL,
    `started_at`         DATETIME NOT NULL,
    `finished_at`        DATETIME NULL,
    `summary`            TEXT NULL COMMENT 'Sintesi degli schemi trovati dall''analisi',
    `proposed_policy_id` INT UNSIGNED NULL COMMENT 'Versione del prompt proposta (policies.id, inattiva)',
    `error`              VARCHAR(255) NULL,
    INDEX `idx_ai_training_status` (`status`),
    FOREIGN KEY (`started_by`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_training_notes` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `session_id`    INT UNSIGNED NOT NULL,
    `comment_id`    INT UNSIGNED NOT NULL,
    `user_id`       INT UNSIGNED NULL COMMENT 'admin_users.id di chi ha scritto la nota',
    `kind`          VARCHAR(12) NOT NULL COMMENT 'rinforzo | correzione | neutro (rispetto al verdetto dell''AI)',
    `posthumous`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = nota aggiunta dopo la decisione dal menù del commento',
    `note`          TEXT NOT NULL,
    `comment_text`  TEXT NULL COMMENT 'Copia del testo del commento al momento della nota',
    `ai_stage`      VARCHAR(10) NULL,
    `ai_decision`   VARCHAR(12) NULL,
    `ai_confidence` DECIMAL(4,3) NULL,
    `ai_categories` TEXT NULL COMMENT 'JSON',
    `ai_reason`     TEXT NULL,
    `final_outcome` VARCHAR(8) NOT NULL COMMENT 'hidden | visible: esito finale deciso dall''umano',
    `created_at`    DATETIME NOT NULL,
    UNIQUE KEY `uniq_training_session_comment` (`session_id`, `comment_id`),
    INDEX `idx_training_created` (`created_at`),
    FOREIGN KEY (`session_id`) REFERENCES `ai_training_sessions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`comment_id`) REFERENCES `comments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`)    REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_settings` (`key`, `value`) VALUES ('ai_training_enabled', '0'), ('ai_training_limit', '30');
