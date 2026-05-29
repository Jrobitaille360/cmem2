<?php

/**
 * Cron — Expiration des abonnements Play Store
 *
 * À planifier : 1x/jour (ex. 03:10)
 *   crontab : 10 3 * * * php /path/to/src/cron/expire_playstore.php >> /path/to/logs/cron.log 2>&1
 *
 * Tâches :
 *   - playstore_subscriptions actifs dont expires_at est dépassé → statut 'expired'
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
        UPDATE playstore_subscriptions
        SET status = 'expired'
        WHERE status = 'active'
          AND expires_at IS NOT NULL
          AND expires_at < NOW()
    ");
    $stmt->execute();
    $count = $stmt->rowCount();

    LogService::info('expire_playstore: abonnements expirés', ['count' => $count]);
    echo "[{$startedAt}] expire_playstore.php : {$count} abonnement(s) Play Store expiré(s)\n";
} catch (\Throwable $e) {
    LogService::error('expire_playstore: erreur', ['error' => $e->getMessage()]);
    echo "[{$startedAt}] expire_playstore.php : ERREUR — {$e->getMessage()}\n";
    exit(1);
}
