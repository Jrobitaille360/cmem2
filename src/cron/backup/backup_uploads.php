<?php

/**
 * Sauvegarde — Répertoire uploads/
 *
 * Deux modes (déterminés automatiquement) :
 *
 *   Complet  : si aucun backup complet de moins de 90 jours dans BACKUP_DIR
 *              → archive complète de uploads/ : uploads_full_YYYYMM.tar.gz
 *              → supprime tous les uploads_incr_*.tar.gz existants
 *              → met à jour uploads_last_full.txt
 *
 *   Incrémental : sinon
 *              → archive les fichiers dont filemtime > dernière sauvegarde
 *              → uploads_incr_YYYYMMDD.tar.gz
 *              → met à jour uploads_last_incr.txt
 *
 * Usage :
 *   php backup_uploads.php [/chemin/destination/]
 *   Si absent, utilise BACKUP_DIR du .env
 *
 * Sortie (une seule ligne) :
 *   [YYYY-MM-DD HH:MM:SS] backup_uploads complet OK | N fichiers | N Mo | Ns
 *   [YYYY-MM-DD HH:MM:SS] backup_uploads incr OK | N fichiers | N Mo | Ns
 *   [YYYY-MM-DD HH:MM:SS] backup_uploads ERREUR | message
 */

if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REMOTE_ADDR'])) {
    http_response_code(403);
    exit('Accès refusé — script CLI uniquement.' . PHP_EOL);
}

require_once __DIR__ . '/_bootstrap.php';

// Pour backup_uploads, on n'a pas besoin de PDO — on charge juste l'env
$rootDir = dirname(__DIR__, 3);
backupLoadEnv($rootDir);

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Montreal');

$destDir = isset($argv[1]) && $argv[1] !== ''
    ? rtrim($argv[1], '/')
    : rtrim($_ENV['BACKUP_DIR'] ?? '', '/');

if ($destDir === '') {
    echo "[" . date('Y-m-d H:i:s') . "] backup_uploads ERREUR | BACKUP_DIR manquant\n";
    exit(1);
}

if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
    echo "[" . date('Y-m-d H:i:s') . "] backup_uploads ERREUR | Impossible de créer {$destDir}\n";
    exit(1);
}

$uploadDir  = rtrim($rootDir . '/' . ltrim($_ENV['UPLOAD_DIR'] ?? 'uploads/', '/'), '/');
$date       = date('Y-m-d H:i:s');
$startTime  = microtime(true);

if (!is_dir($uploadDir)) {
    echo "[{$date}] backup_uploads ERREUR | Répertoire uploads introuvable : {$uploadDir}\n";
    exit(1);
}

// --- Détecter si backup complet nécessaire ---
$lastFullFile = "{$destDir}/uploads_last_full.txt";
$lastFullTime = file_exists($lastFullFile) ? (int) file_get_contents($lastFullFile) : 0;
$doFull       = (time() - $lastFullTime) >= (90 * 86400);

if (!function_exists('exec')) {
    echo "[{$date}] backup_uploads ERREUR | exec() désactivé sur ce serveur\n";
    exit(1);
}

try {
    if ($doFull) {
        // ====== BACKUP COMPLET ======
        $stamp = date('Ym');
        $tarGz = "{$destDir}/uploads_full_{$stamp}.tar.gz";

        if (file_exists($tarGz)) {
            unlink($tarGz);
        }

        $cmd = sprintf('tar -czf %s -C %s . 2>&1', escapeshellarg($tarGz), escapeshellarg($uploadDir));
        exec($cmd, $output, $returnCode);
        if ($returnCode !== 0) {
            throw new \RuntimeException('tar failed: ' . implode("\n", $output));
        }

        // Supprimer les anciens incrémentiels
        foreach (glob("{$destDir}/uploads_incr_*.tar.gz") ?: [] as $old) {
            unlink($old);
        }

        // Mettre à jour le marqueur
        file_put_contents($lastFullFile, (string) time());

        $sizeMo  = round(filesize($tarGz) / 1048576, 1);
        $elapsed = round(microtime(true) - $startTime, 1);

        echo "[{$date}] backup_uploads complet OK | {$sizeMo} Mo | {$elapsed}s\n";

    } else {
        // ====== BACKUP INCRÉMENTAL ======
        $lastIncrFile = "{$destDir}/uploads_last_incr.txt";
        $lastIncrTime = file_exists($lastIncrFile) ? (int) file_get_contents($lastIncrFile) : $lastFullTime;

        $stamp = date('Ymd');
        $tarGz = "{$destDir}/uploads_incr_{$stamp}.tar.gz";

        if (file_exists($tarGz)) {
            unlink($tarGz);
        }

        // --newer-mtime filtre les fichiers non modifiés depuis la dernière sauvegarde
        $sinceDate = date('Y-m-d H:i:s', $lastIncrTime);
        $cmd = sprintf(
            'tar -czf %s --newer-mtime=%s -C %s . 2>&1',
            escapeshellarg($tarGz),
            escapeshellarg($sinceDate),
            escapeshellarg($uploadDir)
        );
        exec($cmd, $output, $returnCode);
        if ($returnCode !== 0) {
            throw new \RuntimeException('tar failed: ' . implode("\n", $output));
        }

        // Compter les fichiers archivés (exclure les entrées de répertoires)
        exec(sprintf('tar -tzf %s 2>&1', escapeshellarg($tarGz)), $contents, $listCode);
        $newFiles = $listCode === 0
            ? count(array_filter($contents, static fn($f) => !str_ends_with($f, '/')))
            : 0;

        if ($newFiles === 0) {
            unlink($tarGz);
            $elapsed = round(microtime(true) - $startTime, 1);
            echo "[{$date}] backup_uploads incr OK | 0 nouveau fichier | {$elapsed}s\n";
        } else {
            file_put_contents($lastIncrFile, (string) time());

            $sizeMo  = round(filesize($tarGz) / 1048576, 1);
            $elapsed = round(microtime(true) - $startTime, 1);

            echo "[{$date}] backup_uploads incr OK | {$newFiles} fichiers | {$sizeMo} Mo | {$elapsed}s\n";
        }
    }

} catch (\Throwable $e) {
    if (isset($tarGz) && file_exists($tarGz)) {
        unlink($tarGz);
    }
    echo "[{$date}] backup_uploads ERREUR | {$e->getMessage()}\n";
    exit(1);
}
