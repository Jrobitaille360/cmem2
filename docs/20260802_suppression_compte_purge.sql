-- ============================================================
-- Migration : Suppression de compte — purge physique après 30 jours (Loi 25)
-- Date       : 2026-08-02
-- Directive  : 20260729_220000_cmem_web_vers_cmem2_API__suppression-compte-purge-30-jours
-- Plan       : docs/PLAN_suppression-compte-purge-30-jours.md — Phase 1
--
-- Contenu :
--   1. Table billing_archive — registres de facturation conservés ANONYMISÉS
--      avant la purge physique du compte (obligation fiscale, §3 de la directive).
--   2. Index sur files.uploaded_by — la purge lit les fichiers par usager.
--
-- Idempotente : réexécutable sans erreur.
-- ============================================================

-- ------------------------------------------------------------
-- 1. billing_archive
--
-- Alimentée par AccountPurgeService juste avant le DELETE FROM users,
-- à partir de stripe_subscriptions (que le CASCADE emporte ensuite).
--
-- AUCUNE donnée identifiante : pas de user_id, pas de courriel, pas de nom.
-- Les identifiants Stripe sont conservés car ce sont les clés de
-- rapprochement comptable côté Stripe, seule source des montants —
-- stripe_subscriptions n'en stocke aucun.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `billing_archive` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `app_id`                 VARCHAR(64)     NOT NULL,
    `stripe_customer_id`     VARCHAR(64)     NOT NULL,
    `stripe_subscription_id` VARCHAR(64)     NULL,
    `plan`                   VARCHAR(64)     NOT NULL,
    `status`                 ENUM('active','trialing','past_due','cancelled','expired') NOT NULL,
    `is_trial`               TINYINT(1)      NOT NULL DEFAULT 0,
    `trial_end`              DATETIME        NULL,
    `expires_at`             DATETIME        NULL,
    `cancel_at_period_end`   TINYINT(1)      NOT NULL DEFAULT 0,
    `subscribed_at`          DATETIME        NULL COMMENT 'stripe_subscriptions.created_at d''origine',
    `archived_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             COMMENT 'Moment de la purge du compte',
    PRIMARY KEY (`id`),
    KEY `idx_ba_stripe_sub`  (`stripe_subscription_id`),
    KEY `idx_ba_stripe_cust` (`stripe_customer_id`),
    KEY `idx_ba_archived`    (`archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registres de facturation anonymisés, conservés après purge de compte (obligation fiscale)';

-- ------------------------------------------------------------
-- 2. Index files.uploaded_by
--
-- files.uploaded_by n'a volontairement AUCUNE contrainte FK : des lignes
-- orphelines antérieures existent, une FK échouerait à la création.
-- La purge traite ces lignes par code (fichier disque puis ligne en base),
-- d'où le besoin d'un index de lecture par usager.
--
-- CREATE INDEX n'accepte pas IF NOT EXISTS sur MySQL/MariaDB : on passe par
-- information_schema pour garder la migration réexécutable.
-- ------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'files'
      AND INDEX_NAME   = 'idx_files_uploaded_by'
);

SET @sql := IF(
    @idx_exists = 0,
    'CREATE INDEX `idx_files_uploaded_by` ON `files` (`uploaded_by`)',
    'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
