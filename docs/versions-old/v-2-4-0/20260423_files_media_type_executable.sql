-- Migration : ajout des valeurs 'executable' et 'default' à l'ENUM media_type de la table files
-- Date      : 2026-04-23
-- Contexte  : Support des fichiers exe/msi/zip/7z dans POST /files

ALTER TABLE files
  MODIFY COLUMN media_type
    ENUM('text','audio','video','image','gpx','summary','event','todo','document','executable','default')
    DEFAULT NULL;
