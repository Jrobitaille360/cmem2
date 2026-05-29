-- Migration 2026-05-29 : ajout backup_json + backup_saved_at
-- sur android_devices et web_devices (remplacement puzzle_devices.backup_json)

ALTER TABLE `android_devices`
    ADD COLUMN `backup_json`     MEDIUMTEXT NULL AFTER `last_replaced_at`,
    ADD COLUMN `backup_saved_at` DATETIME   NULL AFTER `backup_json`;

ALTER TABLE `web_devices`
    ADD COLUMN `backup_json`     MEDIUMTEXT NULL AFTER `last_replaced_at`,
    ADD COLUMN `backup_saved_at` DATETIME   NULL AFTER `backup_json`;
