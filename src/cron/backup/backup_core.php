<?php

/**
 * Sauvegarde — Module core (auth_groups)
 *
 * Exporte les 23 tables du module core dans l'ordre des clés étrangères.
 * Effectue un ménage des données expirées avant l'export.
 *
 * Usage :
 *   php backup_core.php [/chemin/destination/]
 *   Si absent, utilise BACKUP_DIR du .env
 *
 * Sortie (une seule ligne) :
 *   [YYYY-MM-DD HH:MM:SS] backup_core OK | 23 tables | N lignes | N Ko | Ns
 *   [YYYY-MM-DD HH:MM:SS] backup_core ERREUR | message
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_export.php';

[$pdo, $destDir, $rootDir] = backupBootstrap($argv);

$module    = 'core';
$startTime = microtime(true);
$date      = date('Y-m-d H:i:s');
$stamp     = date('Ymd_His');
$db        = $_ENV['DB_NAME'] ?? 'cmem2';
$outFile   = "{$destDir}/cmem2_{$module}_{$stamp}.sql";

// Tables dans l'ordre FK (parents avant enfants)
$tables = [
    'plans',
    'users',
    'tags',
    'groups',
    'files',
    'user_sessions',
    'user_app_setup',
    'password_resets',
    'email_verifications',
    'notifications',
    'otp_codes',
    'device_tokens',
    'jwt_blacklist',
    'login_attempts',
    'group_members',
    'group_invitations',
    'group_tag_relations',
    'file_tag_relations',
    'plan_invitations',
    'subscriptions',
    'group_stats_snapshot',
    'user_stats_snapshot',
    'platform_stats',
];

try {
    // --- 1. Ménage pré-backup ---
    $cleaned = 0;
    $cleaned += cleanupTable($pdo, "DELETE FROM `jwt_blacklist` WHERE expires_at < NOW()");
    $cleaned += cleanupTable($pdo, "DELETE FROM `login_attempts` WHERE created_at < NOW() - INTERVAL 30 DAY");
    $cleaned += cleanupTable($pdo, "DELETE FROM `otp_codes` WHERE expires_at < NOW()");
    $cleaned += cleanupTable($pdo, "DELETE FROM `user_sessions` WHERE expires_at < NOW()");
    $cleaned += cleanupTable($pdo, "DELETE FROM `password_resets` WHERE expires_at < NOW()");
    $cleaned += cleanupTable($pdo, "DELETE FROM `email_verifications` WHERE expires_at < NOW()");
    $cleaned += cleanupTable($pdo, "DELETE FROM `notifications` WHERE created_at < NOW() - INTERVAL 90 DAY AND is_read = 1");

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
