-- Migration : ajout de la colonne accessibility sur la table files
-- Date : 2026-04-30
-- Valeur par défaut public → aucun impact sur les fichiers existants

ALTER TABLE `files`
  ADD COLUMN `accessibility` ENUM('public','private') NOT NULL DEFAULT 'private'
  AFTER `uploaded_by`;
