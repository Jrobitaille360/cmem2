-- ============================================================
-- Migration : playstore_subscriptions — user_id → device_uuid
-- Date      : 2026-05-24
--
-- Raison : Android n'expose jamais l'email utilisateur.
--          Identité stable = device_uuid (UUID v4 généré à l'install,
--          transmis à Google via setObfuscatedAccountId()).
--          Google retourne ce uuid dans obfuscatedExternalAccountId
--          ce qui permet de retrouver l'abonnement sur tout appareil.
--
-- Clé unique : (device_uuid, app_id) — un seul abonnement par appareil par app.
-- purchase_token stocké pour ré-appels Google (renouvellement).
--
-- Dépendance : 20260523_v270_migration.sql appliqué.
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- 1. Supprimer FK, index et contrainte unique liés à user_id
ALTER TABLE `playstore_subscriptions`
    DROP FOREIGN KEY `fk_ps_user`,
    DROP KEY         `idx_user_app`,
    DROP KEY         `uq_token_app`;

-- 2. Ajouter device_uuid (DEFAULT '' temporaire requis pour colonne NOT NULL sur table existante)
ALTER TABLE `playstore_subscriptions`
    ADD COLUMN `device_uuid` VARCHAR(64) NOT NULL DEFAULT '' AFTER `id`;

-- 3. Supprimer user_id et retirer le DEFAULT temporaire
ALTER TABLE `playstore_subscriptions`
    DROP COLUMN `user_id`,
    ALTER COLUMN `device_uuid` DROP DEFAULT;

-- 4. Ajouter contraintes finales
ALTER TABLE `playstore_subscriptions`
    ADD UNIQUE KEY `uq_device_app`      (`device_uuid`, `app_id`),
    ADD KEY        `idx_purchase_token` (`purchase_token`(255));

SET foreign_key_checks = 1;
