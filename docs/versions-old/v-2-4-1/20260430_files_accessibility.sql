-- Migration : ajout de la colonne accessibility sur la table files
-- Date : 2026-04-30
-- Valeur par défaut private → les fichiers existants conservent leur comportement actuel

ALTER TABLE `files`
  ADD COLUMN `accessibility` ENUM('public','private','grand-public') NOT NULL DEFAULT 'private'
  AFTER `uploaded_by`;
