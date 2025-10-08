-- Migration: Ajouter la colonne deleted_at à la table api_keys
-- Pour support du soft delete pattern utilisé par BaseModel

ALTER TABLE `api_keys` 
ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `updated_at`;

-- Index pour les requêtes de soft delete
CREATE INDEX `idx_deleted_at` ON `api_keys` (`deleted_at`);
