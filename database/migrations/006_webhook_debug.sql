-- Debug del webhook Meta: dettagli diagnostici (header, IP, esito del parsing)
-- salvati accanto al payload grezzo solo mentre il debug è attivo.
ALTER TABLE `webhook_events`
    ADD COLUMN `debug` MEDIUMTEXT NULL AFTER `error`;

INSERT IGNORE INTO `app_settings` (`key`, `value`) VALUES ('webhook_debug_until', '0');
