<?php

/**
 * Script cron — rappels courriel (canal EMAIL) — enveloppe du cron unifié.
 *
 * Depuis la directive cmem_web #487 (contrat #236), la file email_notification_queue n'est
 * plus traitée : la sélection et l'envoi passent par src/notifications/send_reminders.php.
 * Ce fichier est conservé pour que la crontab existante (toutes les minutes) reste valable
 * sans modification : il lance le cron unifié sur le seul canal EMAIL.
 *
 * Usage :
 *   /usr/local/bin/php src/notifications/send_email_notifications.php [--dry-run] [--verbose] [--user=ID] [--now=…]
 *
 * Options retirées (sans effet) : --plan-only, --horizon=H (planification de la file).
 *
 * IMPORTANT — Binaire PHP CLI (serveur verdun / cPanel) : /usr/local/bin/php
 *   Ne pas utiliser `php` seul : pointe vers php-cgi en mode cron → 403 Forbidden.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script doit être exécuté en ligne de commande.' . PHP_EOL);
}

if (in_array('--plan-only', $argv, true)) {
    echo date('[Y-m-d H:i:s]') . ' --plan-only : sans objet depuis le cron unifié (#487) — sortie.' . PHP_EOL;
    exit(0);
}

$argv = array_values(array_filter($argv, fn($a) => !str_starts_with($a, '--channel=') && !str_starts_with($a, '--horizon=')));
$argv[] = '--channel=EMAIL';

require __DIR__ . '/send_reminders.php';
