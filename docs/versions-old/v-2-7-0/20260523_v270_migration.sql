-- ============================================================
-- Migration v2.6.5 → v2.7.0
-- Date : 2026-05-23
--
-- Integre :
--   20260514_device_subscription_refonte.sql
--   20260520_android_anonymous_web_devices.sql
--   puzzle/migrations/002_puzzle_pieces_state.sql (adapte)
--   20260523_puzzle_shared_fk_users.sql
--
-- Dependance : build_DB-v-2.6.5.sql applique.
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ============================================================
-- 1. Purge donnees caduques (puzzle_devices supprimee)
-- ============================================================
DELETE FROM `puzzle_shared_events`;
DELETE FROM `puzzle_shared_pieces`;
DELETE FROM `puzzle_shared`;

-- ============================================================
-- 2. Supprimer les contraintes FK caduques
-- ============================================================

-- puzzle_shared → puzzle_devices
ALTER TABLE `puzzle_shared`
    DROP FOREIGN KEY IF EXISTS `fk_shared_creator`,
    DROP FOREIGN KEY IF EXISTS `fk_shared_partner`;

-- puzzle_shared_pieces (si 002 avait ete applique)
ALTER TABLE `puzzle_shared_pieces`
    DROP FOREIGN KEY IF EXISTS `fk_pieces_held_by`,
    DROP FOREIGN KEY IF EXISTS `fk_pieces_by`;
ALTER TABLE `puzzle_shared_pieces`
    DROP INDEX IF EXISTS `fk_pieces_held_by`,
    DROP INDEX IF EXISTS `fk_pieces_by`;

-- puzzle_shared_events (si 002 avait ete applique)
ALTER TABLE `puzzle_shared_events`
    DROP FOREIGN KEY IF EXISTS `fk_events_held_by`,
    DROP FOREIGN KEY IF EXISTS `fk_events_by`;
ALTER TABLE `puzzle_shared_events`
    DROP INDEX IF EXISTS `fk_events_held_by`,
    DROP INDEX IF EXISTS `fk_events_by`;

-- ============================================================
-- 3. DROP tables supprimees en v2.7.0
-- ============================================================
DROP TABLE IF EXISTS `puzzle_devices`;
DROP TABLE IF EXISTS `subscriptions`;

-- ============================================================
-- 4. puzzle_shared — statut + types + FK vers users
-- ============================================================
ALTER TABLE `puzzle_shared`
    MODIFY COLUMN `status` ENUM('active','archived','complete') NOT NULL DEFAULT 'active';

ALTER TABLE `puzzle_shared`
    MODIFY COLUMN `creator_id` INT(11) NOT NULL COMMENT 'FK users.id',
    MODIFY COLUMN `partner_id` INT(11) NOT NULL COMMENT 'FK users.id';

ALTER TABLE `puzzle_shared`
    ADD CONSTRAINT `fk_shared_creator`
        FOREIGN KEY (`creator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_shared_partner`
        FOREIGN KEY (`partner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- ============================================================
-- 5. puzzle_shared_pieces — nouveau modele d'etat (002 adapte)
-- ============================================================

-- Supprimer ancienne colonne + ajuster types
ALTER TABLE `puzzle_shared_pieces`
    DROP COLUMN IF EXISTS `locked`,
    MODIFY COLUMN `x`        FLOAT NULL DEFAULT NULL,
    MODIFY COLUMN `y`        FLOAT NULL DEFAULT NULL,
    MODIFY COLUMN `rotation` SMALLINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT '0-3 quarts de tour (0=0deg, 1=90deg, 2=180deg, 3=270deg)';

-- Ajouter colonnes etat
ALTER TABLE `puzzle_shared_pieces`
    ADD COLUMN IF NOT EXISTS `state`      ENUM('tray','floating','locked','held') NOT NULL DEFAULT 'tray'
        AFTER `piece_id`,
    ADD COLUMN IF NOT EXISTS `held_by_id` INT(11) NULL DEFAULT NULL COMMENT 'FK users.id'
        AFTER `rotation`,
    ADD COLUMN IF NOT EXISTS `prev_state` ENUM('tray','floating') NOT NULL DEFAULT 'tray'
        AFTER `held_by_id`,
    ADD COLUMN IF NOT EXISTS `held_at`    DATETIME NULL DEFAULT NULL
        AFTER `prev_state`,
    ADD COLUMN IF NOT EXISTS `by_id`      INT(11) NULL DEFAULT NULL COMMENT 'FK users.id'
        AFTER `held_at`;

-- Normalisation type : colonnes issues de 002 pouvaient etre INT UNSIGNED
-- users.id est INT(11) signe — doit correspondre exactement
ALTER TABLE `puzzle_shared_pieces`
    MODIFY COLUMN `held_by_id` INT(11) NULL DEFAULT NULL COMMENT 'FK users.id',
    MODIFY COLUMN `by_id`      INT(11) NULL DEFAULT NULL COMMENT 'FK users.id';

-- FK → users
ALTER TABLE `puzzle_shared_pieces`
    ADD CONSTRAINT `fk_pieces_held_by`
        FOREIGN KEY (`held_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_pieces_by`
        FOREIGN KEY (`by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- ============================================================
-- 6. puzzle_shared_events — nouveau modele d'etat (002 adapte)
-- ============================================================

-- Supprimer ancienne colonne + ajuster types
ALTER TABLE `puzzle_shared_events`
    DROP COLUMN IF EXISTS `locked`,
    MODIFY COLUMN `x`         FLOAT NULL DEFAULT NULL,
    MODIFY COLUMN `y`         FLOAT NULL DEFAULT NULL,
    MODIFY COLUMN `rotation`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    MODIFY COLUMN `device_id` INT(11) NULL DEFAULT NULL
        COMMENT 'user_id (users.id) — nomme device_id pour compatibilite historique';

-- Ajouter colonnes etat
ALTER TABLE `puzzle_shared_events`
    ADD COLUMN IF NOT EXISTS `state`      ENUM('tray','floating','locked','held') NOT NULL DEFAULT 'floating'
        AFTER `piece_id`,
    ADD COLUMN IF NOT EXISTS `held_by_id` INT(11) NULL DEFAULT NULL
        AFTER `rotation`,
    ADD COLUMN IF NOT EXISTS `by_id`      INT(11) NULL DEFAULT NULL
        AFTER `held_by_id`;

-- Normalisation type : colonnes issues de 002 pouvaient etre INT UNSIGNED
ALTER TABLE `puzzle_shared_events`
    MODIFY COLUMN `held_by_id` INT(11) NULL DEFAULT NULL,
    MODIFY COLUMN `by_id`      INT(11) NULL DEFAULT NULL;

-- FK → users
ALTER TABLE `puzzle_shared_events`
    ADD CONSTRAINT `fk_events_device`
        FOREIGN KEY (`device_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_events_held_by`
        FOREIGN KEY (`held_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_events_by`
        FOREIGN KEY (`by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- ============================================================
-- 7. android_devices (user_id nullable, last_replaced_at inclus)
-- ============================================================
CREATE TABLE IF NOT EXISTS `android_devices` (
    `id`               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`          INT(11)          NULL,
    `app_id`           VARCHAR(64)      NOT NULL,
    `device_uuid`      VARCHAR(64)      NOT NULL,
    `device_token`     VARCHAR(256)     NOT NULL,
    `token_expires_at` DATETIME         NOT NULL,
    `last_seen_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_replaced_at` DATE             NULL DEFAULT NULL,
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device` (`app_id`, `device_uuid`),
    KEY `idx_user_app` (`user_id`, `app_id`),
    CONSTRAINT `fk_ad_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. app_user_settings
-- ============================================================
CREATE TABLE IF NOT EXISTS `app_user_settings` (
    `user_id`   INT(11)      NOT NULL,
    `app_id`    VARCHAR(64)  NOT NULL,
    `pseudonym` VARCHAR(64)  NULL,
    PRIMARY KEY (`user_id`, `app_id`),
    UNIQUE KEY `uq_pseudo_app` (`app_id`, `pseudonym`),
    CONSTRAINT `fk_aus_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. playstore_subscriptions
-- ============================================================
CREATE TABLE IF NOT EXISTS `playstore_subscriptions` (
    `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`        INT(11)          NOT NULL,
    `app_id`         VARCHAR(64)      NOT NULL,
    `purchase_token` VARCHAR(512)     NOT NULL,
    `product_id`     VARCHAR(128)     NOT NULL,
    `status`         ENUM('active','expired','cancelled','pending') NOT NULL DEFAULT 'pending',
    `expires_at`     DATETIME         NULL,
    `verified_at`    DATETIME         NULL,
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token_app` (`purchase_token`(255), `app_id`),
    KEY `idx_user_app` (`user_id`, `app_id`),
    CONSTRAINT `fk_ps_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. stripe_subscriptions
-- ============================================================
CREATE TABLE IF NOT EXISTS `stripe_subscriptions` (
    `id`                     BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`                INT(11)          NOT NULL,
    `app_id`                 VARCHAR(64)      NOT NULL,
    `stripe_customer_id`     VARCHAR(64)      NOT NULL,
    `stripe_subscription_id` VARCHAR(64)      NULL,
    `plan`                   VARCHAR(64)      NOT NULL,
    `status`                 ENUM('active','trialing','past_due','cancelled','expired') NOT NULL DEFAULT 'active',
    `is_trial`               TINYINT(1)       NOT NULL DEFAULT 0,
    `trial_end`              DATETIME         NULL,
    `expires_at`             DATETIME         NULL,
    `cancel_at_period_end`   TINYINT(1)       NOT NULL DEFAULT 0,
    `created_at`             DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_app` (`user_id`, `app_id`),
    KEY `idx_stripe_sub` (`stripe_subscription_id`),
    KEY `idx_stripe_cust` (`stripe_customer_id`),
    CONSTRAINT `fk_ss_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. web_devices (last_replaced_at inclus)
-- ============================================================
CREATE TABLE IF NOT EXISTS `web_devices` (
    `id`               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`          INT(11)          NULL,
    `app_id`           VARCHAR(64)      NOT NULL,
    `device_uuid`      VARCHAR(64)      NOT NULL,
    `device_token`     VARCHAR(256)     NOT NULL,
    `token_expires_at` DATETIME         NOT NULL,
    `last_seen_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_replaced_at` DATE             NULL DEFAULT NULL,
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wd_device` (`app_id`, `device_uuid`),
    KEY `idx_wd_user_app` (`user_id`, `app_id`),
    CONSTRAINT `fk_wd_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
