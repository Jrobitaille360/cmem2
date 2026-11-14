<?php

/**
 * Cron unifié — rappels courriel + push (directive cmem_web #487, contrat #236 §6).
 *
 * Remplace la file email_notification_queue et le DueScanner push : une seule sélection
 * (AuthGroups\Services\ReminderScanner), deux canaux, idempotence par reminder_log
 * (élément, occurrence, canal, délai, appareil — 0 pour le courriel).
 *
 * Usage :
 *   /usr/local/bin/php src/notifications/send_reminders.php [--channel=EMAIL|DISPLAY|all]
 *       [--dry-run] [--verbose] [--user=ID] [--now=ISO8601] [--batch=N]
 *
 * Les deux crontabs existantes restent valables sans modification :
 *   src/notifications/send_email_notifications.php → --channel=EMAIL
 *   src/push/send_push_notifications.php           → --channel=DISPLAY
 *
 * Sortie : une ligne « DUE » par rappel (élément, canal, délai) à envoyer :
 *   DUE user=… channel=… kind=… entity=… entity_type=… occ=… lead=… devices=… title="…" body="…"
 *
 * Défauts par type et appareils : app REMINDER_APP_ID (.env, défaut cmemweb).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script doit être exécuté en ligne de commande.' . PHP_EOL);
}

if (!defined('RUNNING_AS_CRON')) {
    define('RUNNING_AS_CRON', true);
}

$rootDir = dirname(__DIR__, 2);
require_once $rootDir . '/vendor/autoload.php';
require_once $rootDir . '/src/auth_groups/loader.php';
require_once $rootDir . '/src/push/autoloader.php';
require_once $rootDir . '/src/assistant/autoloader.php';

use Assistant\Services\UserSmtpService;
use AuthGroups\Controllers\NotificationSettingsController;
use AuthGroups\Services\EmailService;
use AuthGroups\Services\LogService;
use AuthGroups\Services\ReminderScanner;
use Push\Services\WebPushService;

// ------------------------------------------------------------------
// Arguments
// ------------------------------------------------------------------
$dryRun   = in_array('--dry-run', $argv, true);
$verbose  = in_array('--verbose', $argv, true);
$channel  = 'all';
$batch    = 500;
$onlyUser = null;
$nowArg   = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--channel=')) {
        $channel = strtoupper(substr($arg, 10)) === 'ALL' ? 'all' : strtoupper(substr($arg, 10));
    } elseif (str_starts_with($arg, '--batch=')) {
        $batch = max(1, (int) substr($arg, 8));
    } elseif (str_starts_with($arg, '--user=')) {
        $onlyUser = (int) substr($arg, 7);
    } elseif (str_starts_with($arg, '--now=')) {
        $nowArg = substr($arg, 6);
    }
}
if (!in_array($channel, ['all', 'EMAIL', 'DISPLAY'], true)) {
    echo "Canal invalide : {$channel} (EMAIL, DISPLAY ou all)" . PHP_EOL;
    exit(1);
}
$doEmail   = $channel !== 'DISPLAY';
$doDisplay = $channel !== 'EMAIL';
$appId     = trim((string) ($_ENV['REMINDER_APP_ID'] ?? '')) ?: 'cmemweb';

try {
    $nowUtc = $nowArg !== null
        ? (new DateTimeImmutable($nowArg))->setTimezone(new DateTimeZone('UTC'))
        : new DateTimeImmutable('now', new DateTimeZone('UTC'));
} catch (Throwable $e) {
    echo "Argument --now invalide : {$nowArg}" . PHP_EOL;
    exit(1);
}

// ------------------------------------------------------------------
// Verrou par canal : une exécution à la fois (chevauchement de cron)
// ------------------------------------------------------------------
if (!$dryRun) {
    $lockFile = sys_get_temp_dir() . '/cmem2_reminders_' . $channel . '_' . md5($rootDir) . '.lock';
    $lockFp   = fopen($lockFile, 'c');
    if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
        echo date('[Y-m-d H:i:s]') . " Exécution précédente encore active ({$channel}) — sortie." . PHP_EOL;
        exit(0);
    }
}

$label = $dryRun ? '[DRY-RUN] ' : '';
$start = microtime(true);
echo date('[Y-m-d H:i:s]') . " {$label}Rappels — canal={$channel} app={$appId} now=" . $nowUtc->format('Y-m-d H:i:s')
    . ' UTC' . ($onlyUser ? " user={$onlyUser}" : '') . PHP_EOL;

// ------------------------------------------------------------------
// Journal (reminder_log)
// ------------------------------------------------------------------
function reminderLogged(PDO $db, int $owner, array $r, string $channel, int $deviceId): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM reminder_log
          WHERE owner_id = ? AND entity_type = ? AND entity_id = ? AND occurrence_key = ?
            AND channel = ? AND minutes_before = ? AND device_id = ?'
    );
    $stmt->execute([$owner, $r['entity_type'], $r['entity_id'], $r['occurrence_key'], $channel, $r['minutes_before'], $deviceId]);
    return (bool) $stmt->fetchColumn();
}

/** Réserve l'envoi AVANT de l'effectuer ; null = déjà journalisé (autre exécution). */
function reminderClaim(PDO $db, string $appId, int $owner, array $r, string $channel, int $deviceId): ?int
{
    $stmt = $db->prepare(
        'INSERT IGNORE INTO reminder_log
             (app_id, owner_id, kind, entity_type, entity_id, occurrence_key, channel, minutes_before,
              device_id, due_at, fire_at, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'claimed\')'
    );
    $stmt->execute([
        $appId, $owner, $r['kind'], $r['entity_type'], $r['entity_id'], $r['occurrence_key'], $channel,
        $r['minutes_before'], $deviceId, $r['due_at']->format('Y-m-d H:i:s'), $r['fire_at']->format('Y-m-d H:i:s'),
    ]);
    return $stmt->rowCount() === 1 ? (int) $db->lastInsertId() : null;
}

function reminderComplete(PDO $db, int $logId, string $status, ?string $error = null): void
{
    $db->prepare('UPDATE reminder_log SET status = ?, error = ?, sent_at = UTC_TIMESTAMP() WHERE id = ?')
       ->execute([$status, $error !== null ? mb_substr($error, 0, 255) : null, $logId]);
}

/** Usagers ayant au moins un rappel possible (défaut non vide ou liste par élément non vide). */
function reminderOwners(PDO $db, string $appId, ?int $onlyUser): array
{
    if ($onlyUser !== null) {
        return [$onlyUser];
    }
    $nonEmpty = "notifications IS NOT NULL AND notifications NOT IN ('', '[]', 'null')";
    $sql = "SELECT owner_id FROM notification_defaults WHERE app_id = ? AND notifications NOT IN ('', '[]')
            UNION SELECT user_id FROM calendar_events WHERE deleted_at IS NULL AND {$nonEmpty}
            UNION SELECT user_id FROM calendar_todos  WHERE deleted_at IS NULL AND due IS NOT NULL AND {$nonEmpty}";
    $s = $db->prepare($sql);
    $s->execute([$appId]);
    $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    foreach (['contacts', 'opportunite'] as $t) {
        try {
            $ids = array_merge($ids, array_map('intval',
                $db->query("SELECT user_id FROM {$t} WHERE supprime_le IS NULL AND {$nonEmpty}")->fetchAll(PDO::FETCH_COLUMN)));
        } catch (PDOException $e) {
            // pilier Contacts absent
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
}

// ------------------------------------------------------------------
// Balayage
// ------------------------------------------------------------------
try {
    $db       = Database::getInstance()->getConnection();
    $scanner  = new ReminderScanner($db);
    $mailer   = $doEmail && !$dryRun ? new EmailService() : null;
    $userSmtp = $doEmail && !$dryRun ? new UserSmtpService() : null;
    $owners   = array_slice(reminderOwners($db, $appId, $onlyUser), 0, $batch);
    $stats    = ['due' => 0, 'email_sent' => 0, 'email_failed' => 0, 'push_sent' => 0, 'push_failed' => 0, 'purged' => 0, 'skipped' => 0];

    foreach ($owners as $ownerId) {
        $reminders = $scanner->scan($ownerId, $appId, $nowUtc);
        if ($verbose) {
            echo 'SCAN ' . json_encode($scanner->debug, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
        if (empty($reminders)) {
            continue;
        }

        $user    = null;
        $userCfg = false; // false = pas encore lu ; null = SMTP système
        if ($doEmail) {
            $s = $db->prepare('SELECT email, email_notifications_enabled FROM users WHERE id = ?');
            $s->execute([$ownerId]);
            $user = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $devices = [];
        if ($doDisplay) {
            $s = $db->prepare('SELECT * FROM push_subscriptions WHERE owner_id = ? AND app_id = ?');
            $s->execute([$ownerId, $appId]);
            $devices = $s->fetchAll(PDO::FETCH_ASSOC);
        }
        $userTz = $scanner->userTimezone($ownerId);

        foreach ($reminders as $r) {
            if ($r['channel'] === 'EMAIL' && $doEmail) {
                if (!$user || !(int) $user['email_notifications_enabled'] || empty($user['email'])) {
                    continue;
                }
                $logId = null;
                $taken = $dryRun
                    ? reminderLogged($db, $ownerId, $r, 'EMAIL', 0)
                    : ($logId = reminderClaim($db, $appId, $ownerId, $r, 'EMAIL', 0)) === null;
                if ($taken) {
                    $stats['skipped']++;
                    continue;
                }
                $stats['due']++;
                printf("DUE user=%d channel=EMAIL kind=%s entity=%d entity_type=%s occ=%s lead=%d devices=0 due=%s title=\"%s\" body=\"%s\"%s",
                    $ownerId, $r['kind'], $r['entity_id'], $r['entity_type'], $r['occurrence_key'], $r['minutes_before'],
                    $r['due_at']->format('c'), $r['title'], $r['body'], PHP_EOL);
                if ($dryRun) {
                    continue;
                }

                $subject = $r['title'] === ReminderScanner::genericTitle() ? $r['title'] : 'Rappel : ' . $r['title'];
                $body    = $r['generic_body'] . "\n" . ($r['body'] !== $r['generic_body'] ? $r['body'] . "\n" : '')
                         . "\n— Journaux de Bord";
                $ok  = false;
                $via = 'system';
                // SMTP personnel de l'usager (module Assistant, #254) ; repli SMTP système en cas d'échec
                if ($userCfg === false) {
                    $userCfg = $userSmtp->configFor($ownerId);
                }
                if ($userCfg !== null) {
                    $ok  = $userSmtp->send($userCfg, $ownerId, $user['email'], $subject,
                        UserSmtpService::htmlFromText($subject, $body), $body);
                    $via = $ok ? 'user' : 'system-fallback';
                }
                if (!$ok) {
                    try {
                        $ok = (bool) $mailer->sendEmail($user['email'], $subject, $body, false);
                    } catch (Throwable $e) {
                        LogService::warning('Rappels : échec courriel', ['owner' => $ownerId, 'error' => $e->getMessage()]);
                    }
                }
                printf("MAIL user=%d via=%s ok=%d%s", $ownerId, $via, $ok ? 1 : 0, PHP_EOL);
                reminderComplete($db, $logId, $ok ? 'sent' : 'failed', $ok ? null : 'échec envoi courriel');
                $stats[$ok ? 'email_sent' : 'email_failed']++;
                continue;
            }

            if ($r['channel'] === 'DISPLAY' && $doDisplay) {
                $targets = [];
                foreach ($devices as $d) {
                    if (!in_array($r['kind'], NotificationSettingsController::deviceKinds($d['kinds']), true)) {
                        continue;
                    }
                    if (ReminderScanner::inQuietHours($d['quiet_from'], $d['quiet_to'], $nowUtc, $userTz)) {
                        continue;
                    }
                    if ($dryRun) {
                        if (reminderLogged($db, $ownerId, $r, 'DISPLAY', (int) $d['id'])) {
                            continue;
                        }
                        $targets[(int) $d['id']] = $d;
                    } elseif (($logId = reminderClaim($db, $appId, $ownerId, $r, 'DISPLAY', (int) $d['id'])) !== null) {
                        $d['_log'] = $logId;
                        $targets[(int) $d['id']] = $d;
                    }
                }
                if (empty($targets)) {
                    continue;
                }
                $stats['due']++;
                printf("DUE user=%d channel=DISPLAY kind=%s entity=%d entity_type=%s occ=%s lead=%d devices=%d due=%s title=\"%s\" body=\"%s\"%s",
                    $ownerId, $r['kind'], $r['entity_id'], $r['entity_type'], $r['occurrence_key'], $r['minutes_before'],
                    count($targets), $r['due_at']->format('c'), $r['title'], $r['body'], PHP_EOL);
                if ($dryRun) {
                    continue;
                }

                $results = WebPushService::sendToSubscriptions(array_values($targets), [
                    'title' => $r['title'],
                    'body'  => $r['body'],
                    'data'  => $r['data'],
                ]);
                foreach ($targets as $id => $d) {
                    $res = $results[$id] ?? ['ok' => false, 'purged' => false, 'error' => 'aucun rapport'];
                    reminderComplete($db, $d['_log'], $res['ok'] ? 'sent' : 'failed', $res['error']);
                    $stats[$res['ok'] ? 'push_sent' : 'push_failed']++;
                    if ($res['purged']) {
                        $stats['purged']++;
                        $devices = array_values(array_filter($devices, fn($x) => (int) $x['id'] !== $id));
                    }
                }
            }
        }
    }

    // Purge : abonnement sans envoi réussi depuis 90 jours (en plus du 410 Gone)
    if ($doDisplay && !$dryRun) {
        $sql    = 'DELETE FROM push_subscriptions WHERE COALESCE(last_seen_at, created_at) < ?';
        $params = [$nowUtc->modify('-90 days')->format('Y-m-d H:i:s')];
        if ($onlyUser !== null) {
            $sql     .= ' AND owner_id = ?';
            $params[] = $onlyUser;
        }
        $s = $db->prepare($sql);
        $s->execute($params);
        $stats['purged'] += $s->rowCount();
    }

    $elapsed = round(microtime(true) - $start, 3);
    echo date('[Y-m-d H:i:s]') . " {$label}Terminé en {$elapsed}s — usagers: " . count($owners)
        . ", rappels: {$stats['due']}, courriels: {$stats['email_sent']} (échecs {$stats['email_failed']}), "
        . "push: {$stats['push_sent']} (échecs {$stats['push_failed']}), déjà envoyés: {$stats['skipped']}, "
        . "abonnements purgés: {$stats['purged']}" . PHP_EOL;
    exit(0);

} catch (Throwable $e) {
    echo date('[Y-m-d H:i:s]') . ' ERREUR FATALE : ' . $e->getMessage() . PHP_EOL;
    LogService::error('Cron rappels — erreur fatale', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    exit(1);
}
