-- ============================================================
-- Migration 2026-05-20 — Device anonyme Android + web_devices
--
-- Contexte : gap identifié post-migration v2.7.0.
--   android_devices.user_id était NOT NULL — impossible d'enregistrer
--   un device sans JWT (fresh install Android).
--   Ajout table web_devices pour devices web/Windows anonymes.
--
-- Référence : PLAN_refonte-device-subscription-v2.7.0.md §Addendum 2026-05-20
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ------------------------------------------------------------
-- 1. android_devices — user_id nullable
--    Deux ALTER séparés : MySQL ne libère pas le nom de contrainte
--    avant la fin de l'instruction, donc DROP + ADD même nom = Errcode 121.
-- ------------------------------------------------------------
ALTER TABLE `android_devices`
    DROP FOREIGN KEY `fk_ad_user`,
    MODIFY COLUMN `user_id` INT(11) NULL;

ALTER TABLE `android_devices`
    ADD CONSTRAINT `fk_ad_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- 2. web_devices
--    Device web/Windows par (app_id, device_uuid).
--    user_id nullable — anonyme par défaut, lié après achat Stripe.
-- ------------------------------------------------------------
CREATE TABLE `web_devices` (
    `id`               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`          INT(11)          NULL,
    `app_id`           VARCHAR(64)      NOT NULL,
    `device_uuid`      VARCHAR(64)      NOT NULL,
    `device_token`     VARCHAR(256)     NOT NULL,
    `token_expires_at` DATETIME         NOT NULL,
    `last_seen_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wd_device` (`app_id`, `device_uuid`),
    KEY `idx_wd_user_app` (`user_id`, `app_id`),
    CONSTRAINT `fk_wd_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
