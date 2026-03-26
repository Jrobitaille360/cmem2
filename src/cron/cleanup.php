<?php

/**
 * Cron — Nettoyage des données expirées
 *
 * À planifier : 1x/jour (ex. 02:00)
 *   crontab : 0 2 * * * php /path/to/src/cron/cleanup.php >> /path/to/logs/cron.log 2>&1
 *
 * Tâches :
 *   - OTP expirés ou déjà utilisés      (otp_codes)
 *   - Tokens JWT blacklistés expirés    (jwt_blacklist)
 *   - Tentatives de login périmées      (login_attempts)
 */

// Sécurité : refuser l'exécution depuis le web
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accès refusé — script CLI uniquement.');
}

$rootDir = dirname(__DIR__, 2);
require_once $rootDir . '/vendor/autoload.php';
require_once $rootDir . '/src/auth_groups/loader.php';

use AuthGroups\Models\JwtBlacklist;
use AuthGroups\Services\OtpService;
use AuthGroups\Services\RateLimitService;

$startedAt = date('Y-m-d H:i:s');
$results   = [];

// 1. OTP expirés / utilisés
try {
    OtpService::cleanup();
    $results['otp_codes'] = 'OK';
} catch (\Throwable $e) {
    $results['otp_codes'] = 'ERREUR : ' . $e->getMessage();
}

// 2. JWT blacklist expirée
try {
    $deleted = (new JwtBlacklist())->deleteExpired();
    $results['jwt_blacklist'] = "OK — {$deleted} supprimé(s)";
} catch (\Throwable $e) {
    $results['jwt_blacklist'] = 'ERREUR : ' . $e->getMessage();
}

// 3. Tentatives de login périmées
try {
    $deleted = RateLimitService::deleteExpired();
    $results['login_attempts'] = "OK — {$deleted} supprimé(s)";
} catch (\Throwable $e) {
    $results['login_attempts'] = 'ERREUR : ' . $e->getMessage();
}

// Rapport
echo "[{$startedAt}] cleanup.php\n";
foreach ($results as $table => $status) {
    echo "  {$table} : {$status}\n";
}
echo "\n";
