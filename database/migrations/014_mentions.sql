-- Menzioni (licenza Enterprise, feature `mentions`): commenti in cui una pagina collegata è
-- taggata ma che stanno sotto post di ALTRE pagine. Non entrano nella coda di moderazione
-- (il token della pagina non permette di nasconderli o eliminarli): lista di sola lettura.
-- Si conserva solo un estratto del testo; le righe seguono la finestra di conservazione
-- generale (RetentionService) e spariscono con l'utente (FK in cascata, diritto all'oblio).
CREATE TABLE IF NOT EXISTS `mentions` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `page_id`             INT UNSIGNED NOT NULL COMMENT 'Pagina collegata menzionata',
    `social_user_id`      INT UNSIGNED NULL COMMENT 'Autore della menzione (pseudonimizzato nelle liste)',
    `platform_comment_id` VARCHAR(128) NOT NULL,
    `platform_post_id`    VARCHAR(128) NOT NULL COMMENT 'Post di un''altra pagina',
    `excerpt`             VARCHAR(500) NULL,
    `mentioned_at`        DATETIME NOT NULL,
    `received_at`         DATETIME NOT NULL,
    UNIQUE KEY `uniq_mention_comment` (`page_id`, `platform_comment_id`),
    INDEX `idx_mention_received` (`received_at`),
    FOREIGN KEY (`page_id`) REFERENCES `connected_pages`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`social_user_id`) REFERENCES `social_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
