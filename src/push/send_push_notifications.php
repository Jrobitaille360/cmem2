<?php

/**
 * Script cron — rappels push (canal DISPLAY) — enveloppe du cron unifié.
 *
 * Depuis la directive cmem_web #487 (contrat #236), la sélection et l'envoi passent par
 * src/notifications/send_reminders.php. Ce fichier est conservé pour que la crontab
 * existante reste valable sans modification : il lance le cron unifié sur le seul canal
 * DISPLAY (le canal EMAIL est lancé par src/notifications/send_email_notifications.php).
 *
 * Usage :
 *   /usr/local/bin/php src/push/send_push_notifications.php [--dry-run] [--verbose] [--batch=N] [--user=ID] [--now=…]
 *
 * IMPORTANT — Binaire PHP CLI (serveur verdun / cPanel) : /usr/local/bin/php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script doit être exécuté en ligne de commande.' . PHP_EOL);
}

$argv = array_values(array_filter($argv, fn($a) => !str_starts_with($a, '--channel=')));
$argv[] = '--channel=DISPLAY';

require dirname(__DIR__) . '/notifications/send_reminders.php';
