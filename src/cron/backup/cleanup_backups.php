<?php

/**
 * Ménage des fichiers de backup
 *
 * Supprime tout fichier *.sql et *.tar.gz dont la date (extraite du nom
 * de fichier) dépasse 28 jours, à l'exception du dernier uploads_full_*.tar.gz
 * s'il est le seul backup complet restant.
 * Format attendu dans le nom : YYYYMMDD ou YYYY-MM-DD.
 * Si aucune date trouvée dans le nom, utilise le filemtime en repli.
 *
 * Usage :
 *   php cleanup_backups.php [/chemin/backups/]
 *   Si absent, utilise BACKUP_DIR du .env
 *
 * Sortie (une seule ligne) :
 *   [YYYY-MM-DD HH:MM:SS] cleanup_backups OK | N fichiers supprimés | N Mo libérés
 *   [YYYY-MM-DD HH:MM:SS] cleanup_backups ERREUR | message
 */

if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REMOTE_ADDR'])) {
    http_response_code(403);
    exit('Accès refusé — script CLI uniquement.' . PHP_EOL);
}

require_once __DIR__ . '/_bootstrap.php';

$rootDir = dirname(__DIR__, 3);
backupLoadEnv($rootDir);

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Montreal');

$date      = date('Y-m-d H:i:s');
$startTime = microtime(true);

$backupDir = isset($argv[1]) && $argv[1] !== ''
    ? rtrim($argv[1], '/')
    : rtrim($_ENV['BACKUP_DIR'] ?? '', '/');

if ($backupDir === '' || !is_dir($backupDir)) {
    echo "[{$date}] cleanup_backups ERREUR | Répertoire introuvable : {$backupDir}\n";
    exit(1);
}

/**
 * Retourne le timestamp de référence d'un fichier backup.
 * Extrait la date du nom (YYYY-MM-DD ou YYYYMMDD), repli sur filemtime.
 */
function fileAge(string $file): int
{
    $base = basename($file);
    // Format YYYY-MM-DD
    if (preg_match('/(\d{4}-\d{2}-\d{2})/', $base, $m)) {
        return (int) strtotime($m[1]);
    }
    // Format YYYYMMDD (ex. cmem2_core_20260210_120000.sql)
    if (preg_match('/(\d{4})(\d{2})(\d{2})/', $base, $m)) {
        return (int) strtotime("{$m[1]}-{$m[2]}-{$m[3]}");
    }
    return (int) filemtime($file);
}

$retention  = 28 * 86400;
$cutoff     = time() - $retention;
$deleted    = 0;
$freedBytes = 0;

try {
    // Identifier tous les backups complets uploads — en garder au moins un
    $fullBackups = glob("{$backupDir}/uploads_full_*.tar.gz") ?: [];
    usort($fullBackups, fn($a, $b) => fileAge($b) - fileAge($a)); // tri décroissant (plus ancien en dernier)
    $keepFull = count($fullBackups) > 0 ? $fullBackups[0] : null; // le plus récent

    $candidates = array_merge(
        glob("{$backupDir}/*.sql")    ?: [],
        glob("{$backupDir}/*.tar.gz") ?: []
    );

    foreach ($candidates as $file) {
        if (!is_file($file)) {
            continue;
        }
        // Protéger le dernier backup complet uploads s'il est le seul
        if ($file === $keepFull && count($fullBackups) === 1) {
            continue;
        }
        if (fileAge($file) < $cutoff) {
            $freedBytes += filesize($file);
            unlink($file);
            $deleted++;
        }
    }

    $freedMo = round($freedBytes / 1048576, 1);
    $elapsed = round(microtime(true) - $startTime, 1);
    echo "[{$date}] cleanup_backups OK | {$deleted} fichiers supprimés | {$freedMo} Mo libérés | {$elapsed}s\n";

} catch (\Throwable $e) {
    echo "[{$date}] cleanup_backups ERREUR | {$e->getMessage()}\n";
    exit(1);
}
