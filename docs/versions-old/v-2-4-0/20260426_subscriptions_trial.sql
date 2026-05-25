-- Migration : abonnements — champs essai, identifiant hybride, client Stripe
-- Date      : 2026-04-26
-- Référence : docs/core/DIRECTIVE_API_abonnement_essai.md — Section 5

-- 1. Rendre user_id nullable (sessions anonymes Play Store sans compte cmem2)
ALTER TABLE subscriptions
    MODIFY COLUMN user_id INT(11) NULL;

-- 2. Ajouter les colonnes manquantes
ALTER TABLE subscriptions
    ADD COLUMN device_token    VARCHAR(64)  NULL               AFTER user_id,
    ADD COLUMN stripe_customer VARCHAR(64)  NULL               AFTER stripe_sub_id,
    ADD COLUMN is_premium      TINYINT(1)   NOT NULL DEFAULT 0 AFTER plan,
    ADD COLUMN show_ads        TINYINT(1)   NOT NULL DEFAULT 1 AFTER is_premium,
    ADD COLUMN is_trial        TINYINT(1)   NOT NULL DEFAULT 0 AFTER show_ads,
    ADD COLUMN trial_end       DATETIME     NULL               AFTER is_trial;

-- 3. Remplacer la contrainte unique provider-dépendante par la logique hybride
ALTER TABLE subscriptions
    DROP INDEX uq_user_app_provider,
    ADD  UNIQUE KEY uq_user_app   (user_id,      app_id),
    ADD  UNIQUE KEY uq_device_app (device_token, app_id);

-- 4. Recalculer is_premium / show_ads pour les lignes existantes
UPDATE subscriptions
SET is_premium = CASE WHEN status = 'active' AND expires_at > NOW() THEN 1 ELSE 0 END,
    show_ads   = CASE WHEN status = 'active' AND expires_at > NOW() THEN 0 ELSE 1 END;
