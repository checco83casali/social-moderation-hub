-- Ricontrollo delle modifiche: quando un commento approvato o in coda viene modificato,
-- la rianalisi AI parte dopo 5 minuti di quiete (bin/recheck-edits.php) e solo se il testo
-- è davvero cambiato rispetto a quello già valutato.
ALTER TABLE `comments`
    ADD COLUMN `edit_recheck_at`    DATETIME NULL COMMENT 'Quando rianalizzare il commento modificato',
    ADD COLUMN `edit_baseline_hash` CHAR(64) NULL COMMENT 'Hash del testo già valutato dall''AI prima delle modifiche',
    ADD INDEX `idx_edit_recheck` (`edit_recheck_at`);
