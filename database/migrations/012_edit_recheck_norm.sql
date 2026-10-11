-- Filtro "stesse parole": hash del testo normalizzato (senza maiuscole, punteggiatura, accenti)
-- già valutato. Se dopo la modifica le parole sono le stesse, la rianalisi AI viene saltata.
ALTER TABLE `comments`
    ADD COLUMN `edit_baseline_norm` CHAR(64) NULL COMMENT 'Hash del testo normalizzato già valutato' AFTER `edit_baseline_hash`;
