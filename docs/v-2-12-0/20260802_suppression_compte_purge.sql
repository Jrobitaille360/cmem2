-- ============================================================
-- Migration : Suppression de compte — purge physique après 30 jours (Loi 25)
-- Date       : 2026-08-02
-- Directive  : 20260729_220000_cmem_web_vers_cmem2_API__suppression-compte-purge-30-jours
-- Plan       : docs/PLAN_suppression-compte-purge-30-jours.md — Phase 1
--
-- Contenu :
--   1. Table billing_archive — registres de facturation conservés ANONYMISÉS
--      avant la purge physique du compte (obligation fiscale, §3 de la directive).
--
-- Idempotente : réexécutable sans erreur.
--
-- Historique : une première version de cette migration créait un index
-- `idx_files_uploaded_by` sur `files(uploaded_by)` pour la lecture des fichiers
-- par usager. Cet index faisait double emploi avec `idx_file_uploaded_by`
-- (singulier), déjà présent sur la même colonne. Le doublon a été retiré sur dev
-- et en production le 2026-08-02 (`DROP INDEX idx_files_uploaded_by ON files`) et
-- la création est retirée d'ici : aucun index n'est à ajouter.
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
-- Note — index sur files.uploaded_by
--
-- files.uploaded_by n'a volontairement AUCUNE contrainte FK : des lignes
-- orphelines antérieures existent, une FK échouerait à la création. La purge
-- traite ces lignes par code (fichier disque puis ligne en base), et lit donc
-- les fichiers par usager.
--
-- Cette lecture est déjà couverte par `idx_file_uploaded_by`, présent de longue
-- date sur `files(uploaded_by)`. Aucun index n'est à créer.
-- ------------------------------------------------------------
