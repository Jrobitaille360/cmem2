<?php

/**
 * Cron — Expiration des abonnements Stripe (fallback si webhook manqué)
 *
 * À planifier : 1x/jour (ex. 03:20)
 *   crontab : 20 3 * * * php /path/to/src/cron/expire_stripe.php >> /path/to/logs/cron.log 2>&1
 *
 * Tâches :
 *   - stripe_subscriptions actifs dont expires_at est dépassé → statut 'expired'
 *
 * Note : webhook Stripe est la source de vérité ; ce script est un fallback
 * pour les abonnements non mis à jour par événement Stripe.
 */

if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REMOTE_ADDR'])) {
    http_response_code(403);
    exit('Accès refusé — script CLI uniquement.');
}

$rootDir = dirname(__DIR__, 2);
require_once $rootDir . '/vendor/autoload.php';
require_once $rootDir . '/src/auth_groups/loader.php';

use AuthGroups\Services\LogService;

$startedAt = date('Y-m-d H:i:s');

try {
    require_once $rootDir . '/src/auth_groups/database.php';
    $pdo = \Database::getInstance()->getConnection();

    $stmt = $pdo->prepare("
        UPDATE stripe_subscriptions
        SET status = 'expired'
        WHERE status IN ('active', 'trialing', 'past_due')
          AND expires_at IS NOT NULL
          AND expires_at < NOW()
          AND cancel_at_period_end = 0
    ");
    $stmt->execute();
    $count = $stmt->rowCount();

    LogService::info('expire_stripe: abonnements expirés', ['count' => $count]);
    echo "[{$startedAt}] expire_stripe.php : {$count} abonnement(s) Stripe expiré(s)\n";
} catch (\Throwable $e) {
    LogService::error('expire_stripe: erreur', ['error' => $e->getMessage()]);
    echo "[{$startedAt}] expire_stripe.php : ERREUR — {$e->getMessage()}\n";
    exit(1);
}
