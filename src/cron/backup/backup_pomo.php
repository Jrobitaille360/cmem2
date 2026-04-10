<?php

/**
 * Sauvegarde — Module Pomodoro
 *
 * Exporte les tables du module Pomodoro.
 * Aucun ménage pré-backup : toutes les données sont utiles à long terme.
 *
 * Usage :
 *   php backup_pomo.php [/chemin/destination/]
 *   Si absent, utilise BACKUP_DIR du .env
 *
 * Sortie (une seule ligne) :
 *   [YYYY-MM-DD HH:MM:SS] backup_pomo OK | N tables | N lignes | N Ko | Ns
 *   [YYYY-MM-DD HH:MM:SS] backup_pomo ERREUR | message
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_export.php';

[$pdo, $destDir, $rootDir] = backupBootstrap($argv);

$module    = 'pomo';
$startTime = microtime(true);
$date      = date('Y-m-d H:i:s');
$stamp     = date('Ymd_His');
$db        = $_ENV['DB_NAME'] ?? 'cmem2';
$outFile   = "{$destDir}/cmem2_{$module}_{$stamp}.sql";

// Tables existantes — ajouter ici au fur et à mesure
$tables = [
    'pomo_engagements',
];

// Détection des tables futures optionnelles
$optionalTables = ['pomo_support', 'pomo_sync', 'pomo_subscriptions'];
foreach ($optionalTables as $t) {
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = '{$t}'"
    )->fetchColumn();
    if ($exists) {
        $tables[] = $t;
    }
}

try {
    // --- 1. Export SQL (aucun ménage pour ce module) ---
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

    // --- 2. Résumé ---
    $elapsed = round(microtime(true) - $startTime, 1);
    $sizeKo  = round(filesize($outFile) / 1024);
    echo "[{$date}] backup_{$module} OK | " . count($tables) . " tables | {$totalRows} lignes | {$sizeKo} Ko | {$elapsed}s\n";

} catch (\Throwable $e) {
    if (isset($fh) && is_resource($fh)) {
        fclose($fh);
    }
    echo "[{$date}] backup_{$module} ERREUR | {$e->getMessage()}\n";
    exit(1);
}
