-- Registro di audit append-only: chi ha fatto cosa (commenti, ban, ricorsi, policy, impostazioni...).
-- Le righe non si modificano mai; vengono eliminate solo dalla pulizia dei dati (retention)
-- dopo `violation_retention_days` (o `data_retention_days`). Visibile solo agli admin.
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `created_at`     DATETIME NOT NULL,
    `actor_id`       INT UNSIGNED NULL COMMENT 'admin_users.id di chi ha agito (NULL se l''account non esiste più)',
    `actor_label`    VARCHAR(190) NULL COMMENT 'Nome/email al momento dell''azione (resta anche se l''account cambia)',
    `actor_role`     VARCHAR(20) NULL,
    `action`         VARCHAR(50) NOT NULL COMMENT 'es. comment.hide, comment.restore, user.ban, appeal.accept',
    `comment_id`     INT UNSIGNED NULL,
    `social_user_id` INT UNSIGNED NULL,
    `page_id`        INT UNSIGNED NULL,
    `note`           TEXT NULL,
    `details`        TEXT NULL COMMENT 'JSON: prima/dopo, testo dell''avviso, esito...',
    INDEX `idx_audit_created` (`created_at`),
    INDEX `idx_audit_comment` (`comment_id`),
    INDEX `idx_audit_user`    (`social_user_id`),
    INDEX `idx_audit_actor`   (`actor_id`),
    INDEX `idx_audit_action`  (`action`),
    FOREIGN KEY (`actor_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
