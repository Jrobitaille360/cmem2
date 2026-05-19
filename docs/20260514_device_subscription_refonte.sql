-- ============================================================
-- Migration v2.7.0 — Refonte Device + Subscription
-- Date : 2026-05-14
-- Auteur : Phase 1 du plan PLAN_refonte-device-subscription-v2.7.0.md
--
-- Opérations :
--   1. Supprimer les FK de puzzle_shared qui référencent puzzle_devices
--   2. Tronquer puzzle_shared et puzzle_shared_events (données caduques)
--   3. DROP TABLE puzzle_devices
--   4. DROP TABLE subscriptions
--   5. CREATE TABLE android_devices
--   6. CREATE TABLE app_user_settings
--   7. CREATE TABLE playstore_subscriptions
--   8. CREATE TABLE stripe_subscriptions
--
-- Prérequis : appliquer sur une instance fraîche ou après backup.
-- Les FK de puzzle_shared vers android_devices seront rétablies en Phase 2.
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ------------------------------------------------------------
-- 1. Vider les tables dépendantes de puzzle_devices
--    (données caduques — pas de migration)
-- ------------------------------------------------------------
DELETE FROM `puzzle_shared_events`;
DELETE FROM `puzzle_shared_pieces`;
DELETE FROM `puzzle_shared`;

-- ------------------------------------------------------------
-- 2. Supprimer les contraintes FK sur puzzle_shared
--    qui bloquent le DROP de puzzle_devices
-- ------------------------------------------------------------
ALTER TABLE `puzzle_shared`
    DROP FOREIGN KEY IF EXISTS `fk_shared_creator`,
    DROP FOREIGN KEY IF EXISTS `fk_shared_partner`;

-- ------------------------------------------------------------
-- 3. Supprimer les anciennes tables
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `puzzle_devices`;
DROP TABLE IF EXISTS `subscriptions`;

-- ------------------------------------------------------------
-- 4. android_devices
--    Un enregistrement par (app_id, device_uuid).
--    Lié à un user_id — authentification JWT obligatoire.
-- ------------------------------------------------------------
CREATE TABLE `android_devices` (
    `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`         INT(11)          NOT NULL,
    `app_id`          VARCHAR(64)      NOT NULL,
    `device_uuid`     VARCHAR(64)      NOT NULL,
    `device_token`    VARCHAR(256)     NOT NULL,
    `token_expires_at` DATETIME        NOT NULL,
    `last_seen_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device` (`app_id`, `device_uuid`),
    KEY `idx_user_app` (`user_id`, `app_id`),
    CONSTRAINT `fk_ad_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. app_user_settings
--    Pseudonyme par (user_id, app_id).
--    UNIQUE(app_id, pseudonym) évite les doublons par app.
-- ------------------------------------------------------------
CREATE TABLE `app_user_settings` (
    `user_id`   INT(11)      NOT NULL,
    `app_id`    VARCHAR(64)  NOT NULL,
    `pseudonym` VARCHAR(64)  NULL,
    PRIMARY KEY (`user_id`, `app_id`),
    UNIQUE KEY `uq_pseudo_app` (`app_id`, `pseudonym`),
    CONSTRAINT `fk_aus_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. playstore_subscriptions
--    Un enregistrement par (purchase_token, app_id).
--    Lié au user, pas au device.
-- ------------------------------------------------------------
CREATE TABLE `playstore_subscriptions` (
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

-- ------------------------------------------------------------
-- 7. stripe_subscriptions
--    Un enregistrement par (user_id, app_id).
--    Identifié côté Stripe par stripe_customer_id.
-- ------------------------------------------------------------
CREATE TABLE `stripe_subscriptions` (
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

SET foreign_key_checks = 1;
