-- Migration : contrainte unique pour les abonnements Google Play (purchase_token + app_id)
-- MySQL autorise plusieurs NULL dans un index unique :
-- les lignes Stripe (purchase_token NULL) ne sont pas affectées.
ALTER TABLE `subscriptions`
    ADD UNIQUE KEY `uq_purchase_token_app` (`purchase_token`, `app_id`);

-- Migrer tous les devices Play Store vers subscriptions.
-- INSERT IGNORE : idempotent si relancé.
INSERT IGNORE INTO `subscriptions`
    (`purchase_token`, `app_id`, `provider`, `product_id`, `plan`,
     `status`, `is_premium`, `show_ads`, `started_at`, `expires_at`)
SELECT
    pd.`purchase_token`,
    'puzzle',
    'google_play',
    pd.`product_id`,
    CASE WHEN pd.`product_id` LIKE '%yearly%' THEN 'yearly' ELSE 'monthly' END,
    CASE WHEN pd.`is_premium` = 1 AND pd.`premium_expires_at` > NOW()
         THEN 'active' ELSE 'expired' END,
    CASE WHEN pd.`is_premium` = 1 AND pd.`premium_expires_at` > NOW()
         THEN 1 ELSE 0 END,
    CASE WHEN pd.`is_premium` = 1 AND pd.`premium_expires_at` > NOW()
         THEN 0 ELSE 1 END,
    pd.`created_at`,
    pd.`premium_expires_at`
FROM `puzzle_devices` pd
WHERE pd.`purchase_token` IS NOT NULL;
