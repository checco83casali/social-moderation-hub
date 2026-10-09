-- Contesto del post (licenza Pro, feature `post_context`): al primo commento su un post
-- Haiku ne riassume il contenuto (testo, link condiviso, immagine) e il riassunto viene
-- passato all'AI insieme a ogni commento dello stesso post. Una riga per post.
CREATE TABLE IF NOT EXISTS `post_contexts` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `page_id`          INT UNSIGNED NOT NULL,
    `platform_post_id` VARCHAR(128) NOT NULL,
    `status`           VARCHAR(10) NOT NULL DEFAULT 'pending' COMMENT 'pending | ready | failed',
    `summary`          TEXT NULL COMMENT 'Riassunto generato da Haiku, iniettato nel contesto dei commenti',
    `source_hash`      CHAR(64) NULL COMMENT 'Hash di testo + link del post: se cambia (post modificato) si rigenera',
    `model`            VARCHAR(64) NULL,
    `error`            VARCHAR(255) NULL,
    `checked_at`       DATETIME NULL COMMENT 'Ultimo controllo su Facebook per modifiche al post',
    `created_at`       DATETIME NOT NULL,
    `updated_at`       DATETIME NOT NULL,
    UNIQUE KEY `uniq_post_context_post` (`platform_post_id`),
    INDEX `idx_post_context_updated` (`updated_at`),
    FOREIGN KEY (`page_id`) REFERENCES `connected_pages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Interruttore nelle Impostazioni (attivo di default quando la licenza lo include)
INSERT IGNORE INTO `app_settings` (`key`, `value`) VALUES ('post_context_enabled', '1');
