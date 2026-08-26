<?php

/**
 * Script cron — Envoi des notifications push web (VAPID)
 *
 * Usage :
 *   /usr/local/bin/php src/push/send_push_notifications.php [--dry-run] [--batch=200] [--user=ID] [--now="Y-m-d H:i:s"]
 *
 * IMPORTANT — Binaire PHP CLI (serveur verdun / cPanel) :
 *   /usr/local/bin/php  (PHP 8.3 CLI)
 *   Ne pas utiliser `php` seul : pointe vers php-cgi en mode cron → 403 Forbidden.
 *
 * Crontab suggérée : toutes les 5 minutes.
 * Ligne exacte (le motif « slash 5 » ne peut pas figurer dans ce commentaire) :
 * voir docs/docs-api/push/GUIDE.md § Cron.
 *
 * Options :
 *   --dry-run   liste les échéances sans envoyer et sans écrire au journal d'idempotence
 *   --batch=N   nombre maximal d'usagers traités par exécution (défaut 200)
 *   --user=ID   restreint le balayage à un usager (tests, diagnostic)
 *   --now=…     instant de référence (fuseau serveur) — tests déterministes
 *
 * Sortie (une ligne par échéance) :
 *   DUE user=<id> kind=<kind> entity=<id> entity_type=<contact|opportunite|-> occ=<clé|->
 *       devices=<n> title="…" body="…"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script doit être exécuté en ligne de commande.' . PHP_EOL);
}

define('RUNNING_AS_CRON', true);

$rootDir = dirname(__DIR__, 2);
require_once $rootDir . '/vendor/autoload.php';
require_once $rootDir . '/src/auth_groups/loader.php';
require_once __DIR__ . '/autoloader.php';

use AuthGroups\Services\LogService;
use Push\Models\PushNotificationLog;
use Push\Models\PushSubscription;
use Push\Services\DueScanner;
use Push\Services\WebPushService;

// ------------------------------------------------------------------
// Arguments CLI
// ------------------------------------------------------------------
$dryRun    = in_array('--dry-run', $argv, true);
$verbose   = in_array('--verbose', $argv, true);
$batchSize = 200;
$onlyUser  = null;
$nowArg    = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--batch=')) {
        $batchSize = max(1, (int) substr($arg, 8));
    } elseif (str_starts_with($arg, '--user=')) {
        $onlyUser = (int) substr($arg, 7);
    } elseif (str_starts_with($arg, '--now=')) {
        $nowArg = substr($arg, 6);
    }
}

$label = $dryRun ? '[DRY-RUN] ' : '';
$start = microtime(true);

try {
    $nowUtc = $nowArg !== null
        ? (new DateTimeImmutable($nowArg))->setTimezone(new DateTimeZone('UTC'))
        : new DateTimeImmutable('now', new DateTimeZone('UTC'));
} catch (Throwable $e) {
    echo "Argument --now invalide : {$nowArg}" . PHP_EOL;
    exit(1);
}

echo date('[Y-m-d H:i:s]') . " {$label}Démarrage du balayage push (batch={$batchSize}"
    . ($onlyUser ? ", user={$onlyUser}" : '') . ', now=' . $nowUtc->format('Y-m-d H:i:s') . ' UTC)' . PHP_EOL;

if (!$dryRun && !WebPushService::isConfigured()) {
    echo date('[Y-m-d H:i:s]') . ' ERREUR : VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY absentes du .env' . PHP_EOL;
    exit(1);
}

try {
    $db            = Database::getInstance()->getConnection();
    $subModel      = new PushSubscription();
    $logModel      = new PushNotificationLog();
    $scanner       = new DueScanner($db);

    $owners        = array_slice($subModel->ownersWithSubscriptions($onlyUser), 0, $batchSize);
    $totalDue      = 0;
    $totalSent     = 0;
    $totalPurged   = 0;
    $totalSkipped  = 0;

    foreach ($owners as $ownerId) {
        $items = $scanner->scan($ownerId, $nowUtc);

        if ($verbose) {
            echo 'SCAN ' . json_encode($scanner->debug, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }

        foreach ($items as $item) {
            $devices = count($subModel->listByOwner($ownerId));
            $logId   = null;

            if (!$dryRun) {
                // Réservation AVANT envoi : une échéance = un envoi logique, même si le cron
                // se chevauche avec une autre exécution.
                $logId = $logModel->claim(
                    $item['app_id'],
                    $ownerId,
                    $item['kind'],
                    $item['entity_id'],
                    $item['occurrence_key'],
                    $item['fire_at']
                );

                // Déjà notifiée lors d'une exécution précédente → aucune ligne DUE.
                if ($logId === null) {
                    $totalSkipped++;
                    continue;
                }
            } elseif ($logModel->existsFor($ownerId, $item['kind'], $item['entity_id'], $item['occurrence_key'])) {
                // En dry-run, refléter l'idempotence sans écrire au journal.
                $totalSkipped++;
                continue;
            }

            $totalDue++;

            printf(
                'DUE user=%d kind=%s entity=%d entity_type=%s occ=%s devices=%d title="%s" body="%s"%s',
                $ownerId,
                $item['kind'],
                $item['entity_id'],
                $item['data']['entity'] ?? '-',
                $item['occurrence_key'],
                $devices,
                $item['title'],
                $item['body'],
                PHP_EOL
            );

            if ($dryRun) {
                continue;
            }

            $result = WebPushService::sendToOwner($ownerId, [
                'title' => $item['title'],
                'body'  => $item['body'],
                'data'  => $item['data'],
            ]);

            $logModel->complete($logId, $result['devices'], $result['delivered'], $result['error']);

            $totalSent   += $result['delivered'];
            $totalPurged += $result['purged'];
        }
    }

    $elapsed = round(microtime(true) - $start, 3);
    echo date('[Y-m-d H:i:s]') . " {$label}Terminé en {$elapsed}s — "
       . "usagers: " . count($owners) . ", "
       . "échéances: {$totalDue}, "
       . "envois: {$totalSent}, "
       . "déjà notifiées: {$totalSkipped}, "
       . "subscriptions purgées: {$totalPurged}" . PHP_EOL;

    exit(0);

} catch (Throwable $e) {
    echo date('[Y-m-d H:i:s]') . ' ERREUR FATALE : ' . $e->getMessage() . PHP_EOL;
    LogService::error('Cron push — erreur fatale', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    exit(1);
}
