<?php

/**
 * Sauvegarde — Module ICS (CalDAV / calendriers)
 *
 * Exporte les 9 tables du module ICS dans l'ordre des clés étrangères.
 * Effectue un ménage des données expirées avant l'export.
 *
 * Usage :
 *   php backup_ics.php [/chemin/destination/]
 *   Si absent, utilise BACKUP_DIR du .env
 *
 * Sortie (une seule ligne) :
 *   [YYYY-MM-DD HH:MM:SS] backup_ics OK | 9 tables | N lignes | N Ko | Ns
 *   [YYYY-MM-DD HH:MM:SS] backup_ics ERREUR | message
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_export.php';

[$pdo, $destDir, $rootDir] = backupBootstrap($argv);

$module    = 'ics';
$startTime = microtime(true);
$date      = date('Y-m-d H:i:s');
$stamp     = date('Ymd_His');
$db        = $_ENV['DB_NAME'] ?? 'cmem2';
$outFile   = "{$destDir}/cmem2_{$module}_{$stamp}.sql";

// Tables dans l'ordre FK (parents avant enfants)
$tables = [
    'calendars',
    'calendar_events',
    'calendar_shares',
    'calendar_todos',
    'calendar_journals',
    'event_occurrences',
    'caldav_sync_log',
    'caldav_locks',
    'email_notification_queue',
];

try {
    // --- 1. Ménage pré-backup ---
    $cleaned = 0;
    $cleaned += cleanupTable($pdo, "DELETE FROM `event_occurrences` WHERE start_datetime < NOW() - INTERVAL 90 DAY");
    $cleaned += cleanupTable($pdo, "DELETE FROM `caldav_sync_log` WHERE changed_at < NOW() - INTERVAL 30 DAY");
    $cleaned += cleanupTable($pdo, "DELETE FROM `caldav_locks` WHERE expires_at < NOW()");
    $cleaned += cleanupTable($pdo, "DELETE FROM `email_notification_queue` WHERE status IN ('sent','failed') AND created_at < NOW() - INTERVAL 7 DAY");

    // --- 2. Export SQL ---
    $fh = fopen($outFile, 'w');
    if ($fh === false) {
        throw new RuntimeException("Impossible d'ouvrir le fichier : {$outFile}");
    }

    writeSqlHeader($fh, $module, $db, $tables, $date);

    $totalRows = 0;
    foreach ($tables as $table) {
        $totalRows += exportTable($pdo, $fh, $table);
    }

    writeSqlFooter($fh);
    fclose($fh);

    // --- 3. Résumé ---
    $elapsed = round(microtime(true) - $startTime, 1);
    $sizeKo  = round(filesize($outFile) / 1024);
    echo "[{$date}] backup_{$module} OK | " . count($tables) . " tables | {$totalRows} lignes | {$cleaned} supprimés | {$sizeKo} Ko | {$elapsed}s\n";

} catch (\Throwable $e) {
    if (isset($fh) && is_resource($fh)) {
        fclose($fh);
    }
    echo "[{$date}] backup_{$module} ERREUR | {$e->getMessage()}\n";
    exit(1);
}
