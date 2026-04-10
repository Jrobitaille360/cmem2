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

if (php_sapi_name() !== 'cli') {
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

try {
    if ($doFull) {
        // ====== BACKUP COMPLET ======
        $stamp   = date('Ym');
        $tarBase = "{$destDir}/uploads_full_{$stamp}.tar";
        $tarGz   = "{$tarBase}.gz";

        $phar = new PharData($tarBase);
        $phar->buildFromDirectory($uploadDir);
        $phar->compress(Phar::GZ);
        unlink($tarBase);

        // Supprimer les anciens incrémentiels
        foreach (glob("{$destDir}/uploads_incr_*.tar.gz") ?: [] as $old) {
            unlink($old);
        }

        // Mettre à jour le marqueur
        file_put_contents($lastFullFile, (string) time());

        $sizeOctets = filesize($tarGz);
        $sizeMo     = round($sizeOctets / 1048576, 1);
        $elapsed    = round(microtime(true) - $startTime, 1);

        echo "[{$date}] backup_uploads complet OK | {$sizeMo} Mo | {$elapsed}s\n";

    } else {
        // ====== BACKUP INCRÉMENTAL ======
        $lastIncrFile = "{$destDir}/uploads_last_incr.txt";
        $lastIncrTime = file_exists($lastIncrFile) ? (int) file_get_contents($lastIncrFile) : $lastFullTime;

        $stamp   = date('Ymd');
        $tarBase = "{$destDir}/uploads_incr_{$stamp}.tar";
        $tarGz   = "{$tarBase}.gz";

        // Collecter les fichiers modifiés depuis la dernière sauvegarde
        $newFiles = 0;
        $phar     = new PharData($tarBase);

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->isFile() && $file->getMTime() > $lastIncrTime) {
                $relative = ltrim(str_replace($uploadDir, '', $file->getPathname()), '/\\');
                $phar->addFile($file->getPathname(), $relative);
                $newFiles++;
            }
        }

        if ($newFiles === 0) {
            // Rien de nouveau — pas de fichier créé
            unset($phar);
            if (file_exists($tarBase)) {
                unlink($tarBase);
            }
            $elapsed = round(microtime(true) - $startTime, 1);
            echo "[{$date}] backup_uploads incr OK | 0 nouveau fichier | {$elapsed}s\n";
        } else {
            $phar->compress(Phar::GZ);
            unlink($tarBase);

            file_put_contents($lastIncrFile, (string) time());

            $sizeOctets = filesize($tarGz);
            $sizeMo     = round($sizeOctets / 1048576, 1);
            $elapsed    = round(microtime(true) - $startTime, 1);

            echo "[{$date}] backup_uploads incr OK | {$newFiles} fichiers | {$sizeMo} Mo | {$elapsed}s\n";
        }
    }

} catch (\Throwable $e) {
    // Nettoyer le .tar partiel si présent
    if (isset($tarBase) && file_exists($tarBase)) {
        unlink($tarBase);
    }
    echo "[{$date}] backup_uploads ERREUR | {$e->getMessage()}\n";
    exit(1);
}
