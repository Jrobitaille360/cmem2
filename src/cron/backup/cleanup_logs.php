<?php

/**
 * Ménage des fichiers de log
 *
 * Supprime tout fichier *.log dont la date (extraite du nom de fichier) dépasse 28 jours.
 * Format attendu dans le nom : YYYY-MM-DD (ex. app-2026-02-06.log)
 * Si aucune date trouvée dans le nom, utilise le filemtime en repli.
 *
 * Usage :
 *   php cleanup_logs.php [/chemin/logs/]
 *   Si absent, utilise LOG_DIR du .env
 *
 * Sortie (une seule ligne) :
 *   [YYYY-MM-DD HH:MM:SS] cleanup_logs OK | N fichiers supprimés | N Ko libérés
 *   [YYYY-MM-DD HH:MM:SS] cleanup_logs ERREUR | message
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accès refusé — script CLI uniquement.' . PHP_EOL);
}

require_once __DIR__ . '/_bootstrap.php';

$rootDir = dirname(__DIR__, 3);
backupLoadEnv($rootDir);

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Montreal');

$date      = date('Y-m-d H:i:s');
$startTime = microtime(true);

$logDir = isset($argv[1]) && $argv[1] !== ''
    ? rtrim($argv[1], '/')
    : rtrim($rootDir . '/' . ltrim($_ENV['LOG_DIR'] ?? 'logs/', '/'), '/');

if (!is_dir($logDir)) {
    echo "[{$date}] cleanup_logs ERREUR | Répertoire introuvable : {$logDir}\n";
    exit(1);
}

$retention  = 28 * 86400; // 28 jours en secondes
$cutoff     = time() - $retention;
$deleted    = 0;
$freedBytes = 0;

try {
    foreach (glob("{$logDir}/*.log") ?: [] as $file) {
        if (!is_file($file)) {
            continue;
        }
        // Extraire la date du nom de fichier (YYYY-MM-DD)
        $fileTime = false;
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', basename($file), $m)) {
            $fileTime = strtotime($m[1]);
        }
        // Repli sur filemtime si aucune date trouvée dans le nom
        if ($fileTime === false) {
            $fileTime = filemtime($file);
        }

        if ($fileTime < $cutoff) {
            $freedBytes += filesize($file);
            unlink($file);
            $deleted++;
        }
    }

    $freedKo = round($freedBytes / 1024);
    $elapsed = round(microtime(true) - $startTime, 1);
    echo "[{$date}] cleanup_logs OK | {$deleted} fichiers supprimés | {$freedKo} Ko libérés | {$elapsed}s\n";

} catch (\Throwable $e) {
    echo "[{$date}] cleanup_logs ERREUR | {$e->getMessage()}\n";
    exit(1);
}
