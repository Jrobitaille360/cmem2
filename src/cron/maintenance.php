<?php

/**
 * Cron — Maintenance centralisée
 *
 * Usage (CLI uniquement) :
 *   /usr/local/bin/php src/cron/maintenance.php [--dry-run]
 *
 * Options :
 *   --dry-run   Journalise et simule sans modifier la base de données
 *
 * Crontab recommandée (production) :
 *   0 3 * * * /usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/cron/maintenance.php >> /home/lmdkhdg5/logs/maintenance-$(date +\%Y-\%m-\%d).log 2>&1
 *   5 3 * * * find /home/lmdkhdg5/logs/ -name "maintenance-*.log" -mtime +7 -delete
 */

// Sécurité : refuser l'exécution depuis le web
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REMOTE_ADDR'])) {
    http_response_code(403);
    exit('Accès refusé — script CLI uniquement.' . PHP_EOL);
}

define('RUNNING_AS_CRON', true);

// ============================================================================
// Lock file — empêche l'exécution simultanée
// ============================================================================
$lockFile = sys_get_temp_dir() . '/cmem2_maintenance.lock';

$lockFp = fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, '[maintenance] Une instance est déjà en cours. Abandon.' . PHP_EOL);
    exit(1);
}

// ============================================================================
// Arguments CLI
// ============================================================================
$dryRun = in_array('--dry-run', $argv ?? [], true);

if ($dryRun) {
    echo '[maintenance] Mode dry-run : aucune modification ne sera appliquée.' . PHP_EOL;
}

// ============================================================================
// Bootstrap
// ============================================================================
$rootDir = dirname(__DIR__, 2); // src/cron → src → racine du projet

require_once $rootDir . '/vendor/autoload.php';
require_once $rootDir . '/src/auth_groups/loader.php';

// Autoloaders des modules
require_once $rootDir . '/src/ics/autoloader.php';
require_once $rootDir . '/src/quiz/autoloader.php';
require_once $rootDir . '/src/pomo/autoloader.php';
require_once $rootDir . '/src/puzzle/autoloader.php';
require_once $rootDir . '/src/items/autoloader.php';

use Core\Maintenance\MaintenanceOrchestrator;
use Core\Maintenance\MaintenanceReport;
use AuthGroups\Services\LogService;

// ============================================================================
// Connexion base de données
// ============================================================================
$db = Database::getInstance()->getConnection();

// ============================================================================
// Enregistrement des tâches (ordre FK-safe)
// ============================================================================
$orchestrator = new MaintenanceOrchestrator();

// 1. Quiz — enfants avant parents
$orchestrator->register(new \Quiz\Services\MaintenanceService($dryRun));

// 2. Puzzle — événements de polling (forte croissance) avant parties
$orchestrator->register(new \Puzzle\Services\MaintenanceService($dryRun));

// 3. ICS — queue email, occurrences, locks, sync log, soft-deletes, régénération
$orchestrator->register(new \ICS\Services\MaintenanceService($dryRun));

// 4. Items — soft-deletes anciens
$orchestrator->register(new \Items\Services\MaintenanceService($dryRun));

// 5. AuthGroups — tokens, sessions, abonnements, notifications, stats
$orchestrator->register(new \AuthGroups\Services\MaintenanceService($dryRun));

// 6. Pomo — comptage uniquement (aucune suppression)
$orchestrator->register(new \Pomo\Services\MaintenanceService($dryRun));

// ============================================================================
// Exécution
// ============================================================================
$startTime = microtime(true);
LogService::info('Maintenance: démarrage', ['dry_run' => $dryRun, 'date' => date('Y-m-d H:i:s')]);

$results = $orchestrator->run($db);

$totalDuration = round(microtime(true) - $startTime, 2);
LogService::info('Maintenance: terminée', ['duration_s' => $totalDuration]);

// ============================================================================
// Rapport courriel
// ============================================================================
$report = new MaintenanceReport($startTime);
foreach ($results as $r) {
    $report->addResult($r);
}
$report->send($db);

// ============================================================================
// Nettoyage du lock
// ============================================================================
flock($lockFp, LOCK_UN);
fclose($lockFp);
@unlink($lockFile);

exit(0);
