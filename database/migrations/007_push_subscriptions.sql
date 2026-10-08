-- Notifiche push (Web Push): una riga per dispositivo/browser iscritto.
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `endpoint_hash`   CHAR(64) NOT NULL,
    `endpoint`        VARCHAR(1024) NOT NULL,
    `p256dh`          VARCHAR(255) NOT NULL,
    `auth`            VARCHAR(64)  NOT NULL,
    `user_agent`      VARCHAR(255) NULL,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_success_at` TIMESTAMP NULL,
    UNIQUE KEY `uq_endpoint` (`endpoint_hash`),
    INDEX `idx_user` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `admin_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
