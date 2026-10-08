-- Marca i commenti ricevuti mentre dev_mode era attivo, così restano
-- visibili nelle liste normali (Nascosti, Approvati, Coda, Segnalazioni)
-- con un badge "DEV". Esegui PRIMA di caricare il nuovo codice.
ALTER TABLE `comments`
    ADD COLUMN `is_dev` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`;

-- I vecchi 'dev_flagged' diventano nascosti veri, marcati DEV.
UPDATE `comments` SET `status` = 'hidden', `is_dev` = 1 WHERE `status` = 'dev_flagged';
