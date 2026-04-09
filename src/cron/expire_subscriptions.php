<?php

/**
 * Cron — Expiration des abonnements Premium
 *
 * À planifier : 1x/jour (ex. 03:00)
 *   crontab : 0 3 * * * php /path/to/src/cron/expire_subscriptions.php >> /path/to/logs/cron.log 2>&1
 *
 * Tâches :
 *   - Abonnements actifs dont expires_at est dépassé → statut 'expired'
 *   - Email de notification d'expiration à l'utilisateur concerné
 */

// Sécurité : refuser l'exécution depuis le web
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accès refusé — script CLI uniquement.');
}

$rootDir = dirname(__DIR__, 2);
require_once $rootDir . '/vendor/autoload.php';
require_once $rootDir . '/src/auth_groups/loader.php';

use AuthGroups\Services\SubscriptionService;

$startedAt = date('Y-m-d H:i:s');

try {
    $count = SubscriptionService::checkAndExpireSubscriptions();
    echo "[{$startedAt}] expire_subscriptions.php : {$count} abonnement(s) expiré(s)\n";
} catch (\Throwable $e) {
    echo "[{$startedAt}] expire_subscriptions.php : ERREUR — {$e->getMessage()}\n";
    exit(1);
}
