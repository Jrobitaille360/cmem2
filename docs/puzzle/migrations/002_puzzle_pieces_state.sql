-- Migration 002 : modèle d'état des pièces (pick / drop / TTL)
-- Dépend de 001_puzzle_base.sql

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- -------------------------------------------------------
-- puzzle_shared : ajouter le statut 'complete'
-- -------------------------------------------------------
ALTER TABLE `puzzle_shared`
    MODIFY COLUMN `status` ENUM('active','archived','complete') NOT NULL DEFAULT 'active';

-- -------------------------------------------------------
-- puzzle_shared_pieces : nouveau modèle d'état
-- -------------------------------------------------------

-- 1. Passer x, y en NULL-able (état tray / held : pas de position)
-- Données existantes en degrés (0/90/180/270) → convertir en quarts de tour (0‑3)
UPDATE `puzzle_shared_pieces` SET `rotation` = `rotation` / 90 WHERE `rotation` IN (90, 180, 270);
ALTER TABLE `puzzle_shared_pieces`
    MODIFY COLUMN `x`        FLOAT NULL DEFAULT NULL,
    MODIFY COLUMN `y`        FLOAT NULL DEFAULT NULL,
    MODIFY COLUMN `rotation` SMALLINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT '0‑3 quarts de tour (0=0°, 1=90°, 2=180°, 3=270°)';

-- 2. Supprimer l'ancienne colonne locked (remplacée par state)
ALTER TABLE `puzzle_shared_pieces`
    DROP COLUMN IF EXISTS `locked`;

-- 3. Ajouter les nouvelles colonnes état
ALTER TABLE `puzzle_shared_pieces`
    ADD COLUMN `state`      ENUM('tray','floating','locked','held')
                            NOT NULL DEFAULT 'tray'
                            COMMENT 'État courant de la pièce'
                            AFTER `piece_id`,
    ADD COLUMN `held_by_id` INT UNSIGNED NULL DEFAULT NULL
                            COMMENT 'FK puzzle_devices.id — joueur qui tient la pièce'
                            AFTER `rotation`,
    ADD COLUMN `prev_state` ENUM('tray','floating')
                            NOT NULL DEFAULT 'tray'
                            COMMENT 'État avant la prise (pour retour TTL)'
                            AFTER `held_by_id`,
    ADD COLUMN `held_at`    DATETIME NULL DEFAULT NULL
                            COMMENT 'Timestamp de la prise (base du TTL 30 s)'
                            AFTER `prev_state`,
    ADD COLUMN `by_id`      INT UNSIGNED NULL DEFAULT NULL
                            COMMENT 'FK puzzle_devices.id — dernier joueur à avoir posé la pièce'
                            AFTER `held_at`;

ALTER TABLE `puzzle_shared_pieces`
    ADD CONSTRAINT `fk_pieces_held_by`
        FOREIGN KEY (`held_by_id`) REFERENCES `puzzle_devices` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_pieces_by`
        FOREIGN KEY (`by_id`)      REFERENCES `puzzle_devices` (`id`) ON DELETE SET NULL;

-- -------------------------------------------------------
-- puzzle_shared_events : nouveau format événement
-- -------------------------------------------------------

-- 1. x, y deviendront NULL-ables (held, tray)
ALTER TABLE `puzzle_shared_events`
    MODIFY COLUMN `x`        FLOAT NULL DEFAULT NULL,
    MODIFY COLUMN `y`        FLOAT NULL DEFAULT NULL,
    MODIFY COLUMN `rotation` SMALLINT UNSIGNED NOT NULL DEFAULT 0;

-- 2. Supprimer l'ancienne colonne locked
ALTER TABLE `puzzle_shared_events`
    DROP COLUMN IF EXISTS `locked`;

-- 3. Ajouter les nouvelles colonnes
ALTER TABLE `puzzle_shared_events`
    ADD COLUMN `state`      ENUM('tray','floating','locked','held')
                            NOT NULL DEFAULT 'floating'
                            AFTER `piece_id`,
    ADD COLUMN `held_by_id` INT UNSIGNED NULL DEFAULT NULL
                            AFTER `rotation`,
    ADD COLUMN `by_id`      INT UNSIGNED NULL DEFAULT NULL
                            AFTER `held_by_id`;

ALTER TABLE `puzzle_shared_events`
    ADD CONSTRAINT `fk_events_held_by`
        FOREIGN KEY (`held_by_id`) REFERENCES `puzzle_devices` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_events_by`
        FOREIGN KEY (`by_id`)      REFERENCES `puzzle_devices` (`id`) ON DELETE SET NULL;

SET foreign_key_checks = 1;
