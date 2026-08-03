<?php

/**
 * Cron / CLI — Purge physique des comptes après le délai de grâce (Loi 25)
 *
 * La purge tourne déjà dans src/cron/maintenance.php (tâche auth_groups).
 * Ce script sert à la déclencher seule : vérification avant mise en production,
 * exécution manuelle après incident, et tests d'acceptation.
 *
 * Usage (CLI uniquement) :
 *   php src/cron/purge_accounts.php [--dry-run] [--user=ID] [--json]
 *
 * Options :
 *   --dry-run   Compte sans rien supprimer
 *   --user=ID   Purge ce seul compte (le délai de grâce doit être écoulé)
 *   --json      Sortie JSON (rapport par usager), pour consommation automatisée
 *
 * Directive : 20260729_220000_cmem_web_vers_cmem2_API__suppression-compte-purge-30-jours
 */

// Sécurité : refuser l'exécution depuis le web
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REMOTE_ADDR'])) {
    http_response_code(403);
    exit('Accès refusé — script CLI uniquement.' . PHP_EOL);
}

define('RUNNING_AS_CRON', true);

$rootDir = dirname(__DIR__, 2);
require_once $rootDir . '/vendor/autoload.php';
require_once $rootDir . '/src/auth_groups/loader.php';

use AuthGroups\Services\AccountPurgeService;

$argvSafe = $argv ?? [];
$dryRun   = in_array('--dry-run', $argvSafe, true);
$asJson   = in_array('--json', $argvSafe, true);

$onlyUser = null;
foreach ($argvSafe as $arg) {
    if (strpos($arg, '--user=') === 0) {
        $onlyUser = (int) substr($arg, 7);
    }
}

$db = Database::getInstance()->getConnection();

$userIds = AccountPurgeService::findPurgeable($db);

if ($onlyUser !== null) {
    // Le délai de grâce reste la seule porte d'entrée : --user ne le contourne pas.
    $userIds = in_array($onlyUser, $userIds, true) ? [$onlyUser] : [];
}

$reports = [];
foreach ($userIds as $userId) {
    $reports[] = AccountPurgeService::purgeUser($db, $userId, $dryRun);
}

if ($asJson) {
    echo json_encode([
        'dry_run' => $dryRun,
        'count'   => count($reports),
        'reports' => $reports,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

$mode = $dryRun ? ' (dry-run)' : '';
echo '[' . date('Y-m-d H:i:s') . "] purge_accounts.php{$mode}\n";
echo '  Comptes éligibles : ' . count($userIds) . "\n";

foreach ($reports as $report) {
    echo "  — usager {$report['user_id']} : "
        . "fichiers base={$report['files_rows_deleted']}, "
        . "disque={$report['files_disk_deleted']}, "
        . "groupes transférés={$report['groups_transferred']}, "
        . "groupes supprimés={$report['groups_deleted']}, "
        . "casse-têtes={$report['puzzles_reassigned']}, "
        . "facturation archivée={$report['billing_archived']}\n";

    foreach ($report['warnings'] as $warning) {
        echo "      ! {$warning}\n";
    }
}

echo "\n";
exit(0);
