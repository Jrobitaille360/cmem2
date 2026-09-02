-- Ajoute is_premium (colonne générée) à stripe_subscriptions.
-- Dérivée de status : premium tant que trialing / active / past_due.
-- Voir AC6/AC7 de private/tests/test_stripe_webhooks.php.
ALTER TABLE `stripe_subscriptions`
    ADD COLUMN `is_premium` TINYINT(1) UNSIGNED
        GENERATED ALWAYS AS (`status` IN ('trialing', 'active', 'past_due')) STORED
        AFTER `status`;
