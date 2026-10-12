-- Contesto della discussione: id Facebook del commento (o del post) a cui un commento risponde.
-- Per un commento di primo livello coincide con l'id del post. Serve a mostrare il commento padre
-- e le risposte nel dettaglio (feature Pro `post_context`).
ALTER TABLE `comments`
    ADD COLUMN `parent_platform_id` VARCHAR(128) NULL AFTER `platform_post_id`,
    ADD INDEX `idx_comment_parent` (`parent_platform_id`),
    ADD INDEX `idx_comment_post` (`platform_post_id`, `id`);
