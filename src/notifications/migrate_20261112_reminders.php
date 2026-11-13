<?php

/**
 * Reprise des données — rappels unifiés (directive cmem_web #487, contrat #236 §7), tâche #493.
 * À lancer APRÈS la migration de schéma docs/v-2-22-0/20261112_notifications_unifiees.sql.
 *
 * Usage :
 *   php src/notifications/migrate_20261112_reminders.php                 → comptes seulement (aucune écriture)
 *   php src/notifications/migrate_20261112_reminders.php --apply --cutoff="2026-11-13 02:00:00"
 *
 * --cutoff (UTC, requis avec --apply) = moment du déploiement du code #487. Un événement
 * modifié après ce moment a pu recevoir `notifications: []` volontairement (aucun rappel) :
 * il n'est pas converti en NULL.
 *
 * Étapes (§7) :
 *   1. notification_prefs → notification_defaults : kind enabled → [DISPLAY lead], plage et
 *      show_entity_detail recopiés ; aucun défaut EMAIL créé. Seulement pour les (compte, app)
 *      sans aucun défaut — un compte déjà réglé par la nouvelle interface n'est pas écrasé.
 *   2. Appareils de ces comptes : kinds = types enabled du compte (§7.2).
 *   3. Événements à liste non vide : forme normalisée ; pour les comptes de l'étape 1 dont le
 *      push était activé sur le kind de l'événement, ajout de {DISPLAY, lead}.
 *   4. Événements à liste vide `[]` (modifiés avant --cutoff) → NULL = défaut du type.
 *   5. Tâches de projet : remind_minutes_before = n et notifications NULL → [EMAIL n, DISPLAY n].
 *   6. Tâches, contacts, opportunités : rien (NULL par défaut).
 *   7. email_notification_queue : lignes pending → cancelled (file retirée, cron unifié).
 *
 * Idempotent : relancé, il ne refait rien. updated_at des lignes n'est pas modifié
 * (pas de resynchronisation forcée des clients).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI uniquement.' . PHP_EOL);
}
if (!defined('RUNNING_AS_CRON')) {
    define('RUNNING_AS_CRON', true);
}

$rootDir = dirname(__DIR__, 2);
require_once $rootDir . '/vendor/autoload.php';
require_once $rootDir . '/src/auth_groups/loader.php';

use AuthGroups\Services\ReminderList;

$apply  = in_array('--apply', $argv, true);
$cutoff = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--cutoff=')) {
        $cutoff = trim(substr($a, 9), "\"' ");
    }
}
if ($apply && ($cutoff === null || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cutoff))) {
    echo "--apply exige --cutoff=\"AAAA-MM-JJ HH:MM:SS\" (UTC, moment du déploiement du code #487)" . PHP_EOL;
    exit(1);
}
$cutoff ??= gmdate('Y-m-d H:i:s');

$db = Database::getInstance()->getConnection();
echo ($apply ? '[APPLY]' : '[COMPTES SEULEMENT]') . ' base=' . $db->query('SELECT DATABASE()')->fetchColumn()
    . " cutoff={$cutoff} UTC" . PHP_EOL;

// ------------------------------------------------------------------
// Comptes
// ------------------------------------------------------------------
function counts(PDO $db): array
{
    $c = [];
    $c['prefs_enabled_par_kind'] = $db->query(
        "SELECT kind, COUNT(*) FROM notification_prefs WHERE enabled = 1 GROUP BY kind"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $c['defaults_lignes']          = (int) $db->query('SELECT COUNT(*) FROM notification_defaults')->fetchColumn();
    $c['defaults_avec_display']    = (int) $db->query("SELECT COUNT(*) FROM notification_defaults WHERE notifications LIKE '%DISPLAY%'")->fetchColumn();
    $c['defaults_avec_email']      = (int) $db->query("SELECT COUNT(*) FROM notification_defaults WHERE notifications LIKE '%EMAIL%'")->fetchColumn();
    $c['appareils']                = (int) $db->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();
    $c['appareils_kinds_null']     = (int) $db->query('SELECT COUNT(*) FROM push_subscriptions WHERE kinds IS NULL')->fetchColumn();

    $ev = ['NULL' => 0, '[]' => 0, 'avec EMAIL' => 0, 'avec DISPLAY' => 0, 'formes héritées' => 0];
    foreach ($db->query('SELECT notifications FROM calendar_events WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        if ($raw === null) { $ev['NULL']++; continue; }
        $list = json_decode($raw, true);
        if (!is_array($list) || $list === []) { $ev['[]']++; continue; }
        $norm = ReminderList::normalize($list);
        if (json_encode($norm) !== json_encode($list)) { $ev['formes héritées']++; }
        if (in_array('EMAIL', array_column($norm, 'type'), true)) { $ev['avec EMAIL']++; }
        if (in_array('DISPLAY', array_column($norm, 'type'), true)) { $ev['avec DISPLAY']++; }
    }
    $c['evenements'] = $ev;

    $c['taches_projet_remind']        = (int) $db->query('SELECT COUNT(*) FROM calendar_todos WHERE project_id IS NOT NULL AND deleted_at IS NULL AND remind_minutes_before IS NOT NULL')->fetchColumn();
    $c['taches_projet_notifications'] = (int) $db->query('SELECT COUNT(*) FROM calendar_todos WHERE project_id IS NOT NULL AND deleted_at IS NULL AND notifications IS NOT NULL')->fetchColumn();
    $c['file_courriel_pending']       = (int) $db->query("SELECT COUNT(*) FROM email_notification_queue WHERE status = 'pending'")->fetchColumn();
    return $c;
}

$before = counts($db);
echo 'AVANT ' . json_encode($before, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

// ------------------------------------------------------------------
// Plan
// ------------------------------------------------------------------
$plan = ['1_defauts_crees' => 0, '2_appareils' => 0, '3_evenements_normalises' => 0, '3_display_ajoutes' => 0,
         '4_vides_vers_null' => 0, '5_taches_projet' => 0, '7_file_annulee' => 0];

if ($apply) {
    $db->beginTransaction();
}
try {
    // --- 1. Préférences push → défauts, pour les (compte, app) sans défaut
    $prefs = $db->query(
        "SELECT p.* FROM notification_prefs p
          WHERE NOT EXISTS (SELECT 1 FROM notification_defaults d WHERE d.owner_id = p.owner_id AND d.app_id = p.app_id)
          ORDER BY p.owner_id, p.app_id, p.kind"
    )->fetchAll(PDO::FETCH_ASSOC);

    $migrated = []; // [owner][app][kind] = lead si enabled
    $insert   = $db->prepare(
        'INSERT IGNORE INTO notification_defaults (app_id, owner_id, kind, notifications, quiet_from, quiet_to, show_entity_detail)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($prefs as $p) {
        $migrated[(int) $p['owner_id']][$p['app_id']] ??= [];
        $list = [];
        if ((int) $p['enabled']) {
            $list = [['type' => 'DISPLAY', 'minutes_before' => (int) $p['lead_minutes']]];
            $migrated[(int) $p['owner_id']][$p['app_id']][$p['kind']] = (int) $p['lead_minutes'];
        }
        $plan['1_defauts_crees']++;
        if ($apply) {
            $insert->execute([$p['app_id'], $p['owner_id'], $p['kind'], json_encode($list),
                $p['quiet_from'], $p['quiet_to'], (int) $p['show_entity_detail']]);
        }
    }

    // --- 2. Appareils de ces comptes : kinds = types enabled (§7.2)
    $upd = $db->prepare('UPDATE push_subscriptions SET kinds = ? WHERE id = ?');
    foreach ($migrated as $owner => $apps) {
        foreach ($apps as $app => $enabled) {
            $s = $db->prepare('SELECT id FROM push_subscriptions WHERE owner_id = ? AND app_id = ? AND kinds IS NULL');
            $s->execute([$owner, $app]);
            $kinds = array_values(array_intersect(['event', 'recurring', 'task_due', 'contact_followup'], array_keys($enabled)));
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $plan['2_appareils']++;
                if ($apply) {
                    $upd->execute([json_encode($kinds), $id]);
                }
            }
        }
    }

    // Lead push par compte et kind (toutes apps confondues : les événements n'ont pas d'app)
    $pushLead = [];
    foreach ($migrated as $owner => $apps) {
        foreach ($apps as $enabled) {
            foreach ($enabled as $kind => $lead) {
                $pushLead[$owner][$kind] ??= $lead;
            }
        }
    }

    // --- 3 et 4. Événements
    $updEv = $db->prepare('UPDATE calendar_events SET notifications = ?, updated_at = updated_at WHERE id = ?');
    $rows  = $db->query(
        "SELECT id, user_id, notifications, recurrence_rule, rdate, updated_at
           FROM calendar_events WHERE deleted_at IS NULL AND notifications IS NOT NULL"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $e) {
        $list = json_decode((string) $e['notifications'], true);
        if (!is_array($list) || $list === []) {
            if ($e['updated_at'] < $cutoff) {
                $plan['4_vides_vers_null']++;
                if ($apply) {
                    $updEv->execute([null, $e['id']]);
                }
            }
            continue;
        }
        $norm = ReminderList::normalize($list);
        // Doublons (même canal + délai) possibles après normalisation : dédoublonner
        $seen = [];
        $norm = array_values(array_filter($norm, function ($r) use (&$seen) {
            $k = $r['type'] . '|' . $r['minutes_before'];
            if (isset($seen[$k])) { return false; }
            return $seen[$k] = true;
        }));

        $kind = (!empty($e['recurrence_rule']) || !empty($e['rdate'])) ? 'recurring' : 'event';
        $lead = $pushLead[(int) $e['user_id']][$kind] ?? null;
        $added = false;
        if ($lead !== null && !isset($seen["DISPLAY|{$lead}"]) && count($norm) < ReminderList::MAX_ITEMS) {
            $norm[] = ['type' => 'DISPLAY', 'minutes_before' => $lead];
            $added  = true;
        }

        if (json_encode($norm) !== json_encode($list)) {
            $plan['3_evenements_normalises']++;
            if ($added) {
                $plan['3_display_ajoutes']++;
            }
            if ($apply) {
                $updEv->execute([json_encode($norm), $e['id']]);
            }
        }
    }

    // --- 5. Tâches de projet (#439)
    $tasks = $db->query(
        'SELECT id, remind_minutes_before FROM calendar_todos
          WHERE project_id IS NOT NULL AND remind_minutes_before IS NOT NULL AND notifications IS NULL'
    )->fetchAll(PDO::FETCH_ASSOC);
    $updT = $db->prepare('UPDATE calendar_todos SET notifications = ?, updated_at = updated_at WHERE id = ?');
    foreach ($tasks as $t) {
        $n = (int) $t['remind_minutes_before'];
        $plan['5_taches_projet']++;
        if ($apply) {
            $updT->execute([json_encode([['type' => 'EMAIL', 'minutes_before' => $n], ['type' => 'DISPLAY', 'minutes_before' => $n]]), $t['id']]);
        }
    }

    // --- 7. File courriel retirée
    $plan['7_file_annulee'] = $before['file_courriel_pending'];
    if ($apply) {
        $db->exec("UPDATE email_notification_queue SET status = 'cancelled', error = 'remplacée par le cron unifié #487' WHERE status = 'pending'");
    }

    if ($apply) {
        $db->commit();
    }
} catch (Throwable $e) {
    if ($apply && $db->inTransaction()) {
        $db->rollBack();
    }
    echo 'ERREUR — rien n\'a été écrit : ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo 'PLAN ' . json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
if ($apply) {
    echo 'APRÈS ' . json_encode(counts($db), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}
