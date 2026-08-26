-- Migration : remplace biome 'mountain' par 'peak' (alignement enum Flutter BiomeType)
-- Date : 2026-06-15
-- ORDRE : UPDATE avant ALTER (sinon MySQL convertit 'mountain' en '' lors du MODIFY)

-- 1. Renommer mountain→peak pendant que les deux valeurs coexistent dans l'ENUM courant
UPDATE `monsters` SET `biome` = 'peak' WHERE `biome` IN ('mountain', '');

-- 2. Retirer 'mountain' de l'ENUM et ajouter 'peak'
ALTER TABLE `monsters`
  MODIFY `biome` ENUM('forest','peak','water','cemetery','worship','industrial','urban')
    NOT NULL DEFAULT 'urban';
