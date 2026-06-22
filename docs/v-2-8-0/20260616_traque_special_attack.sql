-- Migration : attaque spéciale + jets de sauvegarde Phase 2
-- Date : 2026-06-16
-- Directive : traque → cmem2_API  (special-attack-save-dc-save-stat-monster-templates)

-- 1. Ajouter les colonnes sur monsters
ALTER TABLE `monsters`
  ADD COLUMN `special_attack` ENUM('none','poison','spell') NOT NULL DEFAULT 'none',
  ADD COLUMN `save_dc`        TINYINT UNSIGNED              NOT NULL DEFAULT 0,
  ADD COLUMN `save_stat`      ENUM('con','sag')             NOT NULL DEFAULT 'con';

-- 2. Seeds : monstres venimeux / sorts (zone Montréal)
INSERT IGNORE INTO `monsters`
  ( `name`, `asset_key`, `lore`,
   `level_base`, `lat`, `lng`,
   `hp_max`, `hp_current`, `ac`, `damage_dice`, `xp_reward`,
   `is_alive`, `behavior_type`, `move_radius_m`, `biome`,
   `special_attack`, `save_dc`, `save_stat`)
VALUES
  (  'Naga',   'naga',   'Serpent immortel dont la morsure empoisonne le sang.',
   4,  45.5031200, -73.6201000,
   28, 28, 13, '1d8',   120, 1, 'patrol', 200, 'water',
   'poison', 12, 'con'),
  (  'Ratman', 'ratman', 'Hybride vermine-humain porteur de fièvres mortelles.',
   2,  45.5055000, -73.6245000,
   14, 14, 10, '1d6',    40, 1, 'roam',   100, 'urban',
   'poison', 10, 'con'),
  (  'Liche',  'liche',  'Nécromancien ascendé, maître des sorts de détresse mentale.',
   7,  45.4985000, -73.6188000,
   56, 56, 15, '1d10',  250, 1, 'static',   0, 'cemetery',
   'spell', 14, 'sag');
