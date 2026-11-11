<?php

/**
 * Script cron — Envoi des notifications email planifiées
 *
 * Usage :
 *   /usr/local/bin/php src/notifications/send_email_notifications.php [--dry-run] [--batch=50]
 *
 * IMPORTANT — Binaire PHP CLI (serveur verdun / cPanel) :
 *   /usr/local/bin/php  (PHP 8.3.30 CLI, confirmé 2026-03-23)
 *   Ne pas utiliser `php` seul : pointe vers php-cgi en mode cron → 403 Forbidden.
 *
 * Crontab active (production — toutes les minutes) :
 *   * * * * * /usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/notifications/send_email_notifications.php >> /home/lmdkhdg5/logs/notifications-$(date +\%Y-\%m-\%d).log 2>&1
 *
 * Rotation des logs (un fichier par jour, garder 2 jours) — à ajouter en crontab :
 *   5 0 * * * find /home/lmdkhdg5/logs/ -name "notifications-*.log" -mtime +2 -delete
 *
 * Test manuel (SSH) :
 *   /usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/notifications/send_email_notifications.php
 *
 * Options :
 *   --plan-only     planifie les rappels des séries sans rien envoyer
 *   --horizon=H     fenêtre de planification des séries en heures (défaut 48)
 *
 * Dry-run (liste ce qui serait envoyé sans envoyer ni planifier) :
 *   /usr/local/bin/php /home/lmdkhdg5/cmem2.journauxdebord.com/src/notifications/send_email_notifications.php --dry-run
 */

// Ce script doit être exécuté en CLI uniquement
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script doit être exécuté en ligne de commande.' . PHP_EOL);
}

// ------------------------------------------------------------------
// Bootstrap
// ------------------------------------------------------------------
define('RUNNING_AS_CRON', true);

$rootDir = dirname(__DIR__, 2); // remonte à la racine du projet
require_once $rootDir . '/vendor/autoload.php';
require_once $rootDir . '/src/auth_groups/loader.php';

use ICS\Services\EmailNotificationService;
use AuthGroups\Services\LogService;

// ------------------------------------------------------------------
// Arguments CLI
// ------------------------------------------------------------------
$dryRun       = in_array('--dry-run', $argv, true);
$planOnly     = in_array('--plan-only', $argv, true);
$batchSize    = 50;
$horizonHours = 48;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--batch=')) {
        $batchSize = max(1, (int)substr($arg, 8));
    }
    if (str_starts_with($arg, '--horizon=')) {
        $horizonHours = max(1, min(24 * 31, (int)substr($arg, 10)));
    }
}

// ------------------------------------------------------------------
// Verrou : une seule exécution à la fois (cron relancé / chevauchement)
// → aucun doublon de planification ni d'envoi (directive #438).
// Fichier propre à l'installation (prod et dev partagent le même /tmp).
// ------------------------------------------------------------------
$lockFile = sys_get_temp_dir() . '/cmem2_email_notifications_' . md5($rootDir) . '.lock';
$lockFp   = fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo date('[Y-m-d H:i:s]') . " Exécution précédente encore active — sortie." . PHP_EOL;
    exit(0);
}

// ------------------------------------------------------------------
// Exécution
// ------------------------------------------------------------------
$start = microtime(true);
$label = $dryRun ? '[DRY-RUN] ' : '';

echo date('[Y-m-d H:i:s]') . " {$label}Démarrage du traitement des notifications email (batch={$batchSize})" . PHP_EOL;

if ($dryRun) {
    // En mode dry-run, on liste seulement ce qui serait envoyé
    $rows = \ICS\Models\EmailNotificationQueue::getDueNotifications($batchSize);
    echo date('[Y-m-d H:i:s]') . " [DRY-RUN] {$label}" . count($rows) . " notification(s) seraient traitées." . PHP_EOL;
    foreach ($rows as $row) {
        echo "  - ID#{$row['id']} event#{$row['event_id']} → {$row['recipient_email']} (fire_at: {$row['fire_at']})" . PHP_EOL;
    }
    exit(0);
}

try {
    // Rappels des occurrences de séries sur la fenêtre glissante (directive #438)
    $plan = EmailNotificationService::planRecurringReminders($horizonHours);
    echo date('[Y-m-d H:i:s]') . " Séries : {$plan['events']} événement(s), "
       . "planifiés: {$plan['planned']}, recalés: {$plan['updated']}, retirés: {$plan['removed']} "
       . "(horizon {$horizonHours} h)" . PHP_EOL;

    if ($planOnly) {
        exit(0);
    }

    $stats = EmailNotificationService::processDueNotifications($batchSize);

    $elapsed = round(microtime(true) - $start, 3);

    $msg = date('[Y-m-d H:i:s]') . " Terminé en {$elapsed}s — "
         . "envoyés: {$stats['sent']}, "
         . "échecs: {$stats['failed']}, "
         . "ignorés (désactivés): {$stats['skipped']}";

    echo $msg . PHP_EOL;

    exit(0);

} catch (\Exception $e) {
    $errMsg = date('[Y-m-d H:i:s]') . ' ERREUR FATALE : ' . $e->getMessage();
    echo $errMsg . PHP_EOL;
    LogService::error('Cron notifications email — erreur fatale', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    exit(1);
}
