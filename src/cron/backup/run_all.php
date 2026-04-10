<?php

/**
 * Orchestrateur — Sauvegarde complète cmem2
 *
 * Appelle dans l'ordre :
 *   1. maintenance_occurrences.php  (régénère les occurrences ICS)
 *   2. backup_core.php
 *   3. backup_ics.php
 *   4. backup_pomo.php
 *   5. backup_quiz.php
 *   6. backup_puzzle.php
 *   7. backup_uploads.php
 *   8. cleanup_logs.php
 *   9. cleanup_backups.php
 *
 * Usage :
 *   php run_all.php [/chemin/destination/]
 *   Si absent, utilise BACKUP_DIR du .env
 *
 * Sortie : une ligne par script + résumé final sur une ligne :
 *   [YYYY-MM-DD HH:MM:SS] run_all DONE | N modules OK | N erreur(s) | Ns
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

// Répertoire de destination transmis aux scripts enfants
$destArg = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : ($_ENV['BACKUP_DIR'] ?? '');
$phpBin  = PHP_BINARY;
$backDir = __DIR__;
$icsDir  = $rootDir . '/src/ics';

// Séquence de scripts : [chemin, description, passe destArg ?, timeout secondes]
$scripts = [
    [$icsDir  . '/maintenance_occurrences.php', 'maintenance_occurrences', false, 300],
    [$backDir . '/backup_core.php',             'backup_core',             true,  120],
    [$backDir . '/backup_ics.php',              'backup_ics',              true,  120],
    [$backDir . '/backup_pomo.php',             'backup_pomo',             true,   60],
    [$backDir . '/backup_quiz.php',             'backup_quiz',             true,   60],
    [$backDir . '/backup_puzzle.php',           'backup_puzzle',           true,   60],
    [$backDir . '/backup_uploads.php',          'backup_uploads',          true,  600],
    [$backDir . '/cleanup_logs.php',            'cleanup_logs',            false,  30],
    [$backDir . '/cleanup_backups.php',         'cleanup_backups',         true,   30],
];

$errors  = 0;
$modules = 0;

foreach ($scripts as [$script, $label, $passDir, $timeout]) {
    if (!file_exists($script)) {
        echo "[{$date}] {$label} IGNORÉ | fichier introuvable : {$script}\n";
        continue;
    }

    $cmd = $passDir && $destArg !== ''
        ? escapeshellcmd($phpBin) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($destArg)
        : escapeshellcmd($phpBin) . ' ' . escapeshellarg($script);

    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $desc, $pipes);

    if (!is_resource($proc)) {
        echo "[{$date}] {$label} ERREUR | impossible de démarrer le processus\n";
        $errors++;
        continue;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $deadline = time() + $timeout;
    $code     = null;

    while (true) {
        // Lire stdout et stderr ligne par ligne et afficher immédiatement
        foreach ([$pipes[1], $pipes[2]] as $pipe) {
            while (($line = fgets($pipe)) !== false) {
                echo rtrim($line) . "\n";
                flush();
            }
        }

        $status = proc_get_status($proc);
        if (!$status['running']) {
            $code = $status['exitcode'];
            break;
        }

        if (time() > $deadline) {
            proc_terminate($proc);
            echo "[{$date}] {$label} ERREUR | timeout ({$timeout}s) dépassé\n";
            $code = 1;
            break;
        }

        usleep(100000); // 100ms
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    if ($code !== 0) {
        $errors++;
    } else {
        $modules++;
    }
}

$elapsed = round(microtime(true) - $startTime, 1);
echo "[{$date}] run_all DONE | {$modules} scripts OK | {$errors} erreur(s) | {$elapsed}s\n";

if ($errors > 0) {
    exit(1);
}
