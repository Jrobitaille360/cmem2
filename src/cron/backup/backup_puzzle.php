<?php

/**
 * Sauvegarde — Module Puzzle
 *
 * Exporte les 9 tables du module Puzzle dans l'ordre des clés étrangères.
 * Effectue un ménage des sessions partagées inactives depuis plus de 90 jours.
 *
 * Usage :
 *   php backup_puzzle.php [/chemin/destination/]
 *   Si absent, utilise BACKUP_DIR du .env
 *
 * Sortie (une seule ligne) :
 *   [YYYY-MM-DD HH:MM:SS] backup_puzzle OK | 9 tables | N lignes | N Ko | Ns
 *   [YYYY-MM-DD HH:MM:SS] backup_puzzle ERREUR | message
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_export.php';

[$pdo, $destDir, $rootDir] = backupBootstrap($argv);

$module    = 'puzzle';
$startTime = microtime(true);
$date      = date('Y-m-d H:i:s');
$stamp     = date('Ymd_His');
$db        = $_ENV['DB_NAME'] ?? 'cmem2';
$outFile   = "{$destDir}/cmem2_{$module}_{$stamp}.sql";

// Tables dans l'ordre FK (parents avant enfants)
$tables = [
    'puzzle_images',
    'puzzle_image_translations',
    'puzzle_themes',
    'puzzle_theme_translations',
    'puzzle_image_themes',
    'puzzle_devices',
    'puzzle_shared',
    'puzzle_shared_pieces',
    'puzzle_shared_events',
];

try {
    // --- 1. Ménage pré-backup ---
    // puzzle_shared_pieces et puzzle_shared_events supprimés en cascade
    $cleaned  = cleanupTable($pdo, "DELETE FROM `puzzle_shared_events` WHERE created_at < NOW() - INTERVAL 30 DAY");
    $cleaned += cleanupTable($pdo, "DELETE FROM `puzzle_shared` WHERE last_activity_at < NOW() - INTERVAL 90 DAY");

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
