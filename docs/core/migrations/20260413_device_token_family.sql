-- ============================================================
-- MIGRATION : Refresh token rotatif — chaînage par famille
-- Ajoute family_id à device_tokens pour regrouper les tokens
-- d'une même chaîne de rotation et détecter les replay attacks.
-- À exécuter UNE seule fois sur la base de données.
-- ============================================================

ALTER TABLE `device_tokens`
    ADD COLUMN `family_id` VARCHAR(36) NULL
        COMMENT 'UUID de la famille de rotation — tous les tokens issus d\'un même appareil partagent le même family_id'
        AFTER `device_name`;

-- Index pour la révocation rapide d'une famille entière
CREATE INDEX `idx_device_family` ON `device_tokens` (`family_id`);

-- Rollback :
-- ALTER TABLE `device_tokens` DROP INDEX `idx_device_family`;
-- ALTER TABLE `device_tokens` DROP COLUMN `family_id`;
