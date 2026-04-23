<?php

/**
 * Cron — Replanification des VTODO récurrents échus
 *
 * À planifier : 1x/jour (ex. 01:00)
 *   crontab : 0 1 * * * php /path/to/src/cron/todo_reschedule.php >> /path/to/logs/cron.log 2>&1
 *
 * Tâches :
 *   - Détecte les VTODO récurrents dont la date d'échéance est passée (due < NOW())
 *   - Calcule la prochaine occurrence via la RRULE (simshaun/recurr)
 *   - Met à jour due (et dtstart si présent) à la prochaine date
 */

// Sécurité : refuser l'exécution depuis le web
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REMOTE_ADDR'])) {
    http_response_code(403);
    exit('Accès refusé — script CLI uniquement.');
}

$rootDir = dirname(__DIR__, 2);
require_once $rootDir . '/vendor/autoload.php';
require_once $rootDir . '/src/auth_groups/loader.php';
require_once $rootDir . '/src/ics/autoloader.php';

use AuthGroups\Services\LogService;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;

$startedAt = date('Y-m-d H:i:s');
$rescheduled = 0;
$skipped     = 0;
$errors      = 0;

try {
    $db = \Database::getInstance()->getConnection();

    // Tous les VTODO récurrents non terminés dont la date d'échéance est passée
    $stmt = $db->prepare("
        SELECT id, dtstart, due, recurrence_rule, timezone, sequence
        FROM calendar_todos
        WHERE recurrence_rule IS NOT NULL
          AND recurrence_rule != ''
          AND due < NOW()
          AND status NOT IN ('COMPLETED', 'CANCELLED')
          AND deleted_at IS NULL
    ");
    $stmt->execute();
    $todos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $config = new ArrayTransformerConfig();
    $config->setVirtualLimit(500);
    $transformer = new ArrayTransformer();
    $transformer->setConfig($config);

    $now     = new \DateTime('now', new \DateTimeZone('UTC'));
    $horizon = new \DateTime('+2 years', new \DateTimeZone('UTC'));

    $update = $db->prepare("
        UPDATE calendar_todos
        SET due      = ?,
            dtstart  = ?,
            sequence = sequence + 1,
            updated_at = NOW()
        WHERE id = ? AND deleted_at IS NULL
    ");

    foreach ($todos as $todo) {
        try {
            $tz = new \DateTimeZone(!empty($todo['timezone']) ? $todo['timezone'] : 'America/Montreal');

            // Ancre RRULE : DTSTART si présent, sinon DUE
            $anchor = !empty($todo['dtstart']) ? $todo['dtstart'] : $todo['due'];
            $anchorDt = new \DateTime($anchor, $tz);

            // Intervalle entre l'ancre et l'échéance initiale (pour décaler dtstart identiquement)
            $dueInitial = new \DateTime($todo['due'], $tz);
            $hasDtstart = !empty($todo['dtstart']);
            $offsetSeconds = $hasDtstart
                ? $anchorDt->getTimestamp() - $dueInitial->getTimestamp()
                : 0;

            $rule        = new Rule('RRULE:' . $todo['recurrence_rule'], $anchorDt);
            $occurrences = $transformer->transform($rule);

            // Première occurrence strictement après maintenant, dans la limite de 2 ans
            $nextStart = null;
            foreach ($occurrences as $occ) {
                $start = $occ->getStart();
                if ($start > $horizon) {
                    break;
                }
                if ($start > $now) {
                    $nextStart = $start;
                    break;
                }
            }

            if ($nextStart === null) {
                // RRULE épuisée (COUNT/UNTIL atteint) — aucune prochaine occurrence
                LogService::info('todo_reschedule: RRULE épuisée, tâche ignorée', ['todo_id' => $todo['id']]);
                $skipped++;
                continue;
            }

            $newDue  = (new \DateTime('@' . $nextStart->getTimestamp()))->setTimezone($tz)->format('Y-m-d H:i:s');
            $newDtstart = $hasDtstart
                ? (new \DateTime('@' . ($nextStart->getTimestamp() + $offsetSeconds)))->setTimezone($tz)->format('Y-m-d H:i:s')
                : null;

            $update->execute([$newDue, $newDtstart, $todo['id']]);

            LogService::info('todo_reschedule: tâche replanifiée', [
                'todo_id'     => $todo['id'],
                'old_due'     => $todo['due'],
                'new_due'     => $newDue,
                'new_dtstart' => $newDtstart,
            ]);

            $rescheduled++;
        } catch (\Throwable $e) {
            LogService::error('todo_reschedule: erreur sur tâche', [
                'todo_id' => $todo['id'],
                'error'   => $e->getMessage(),
            ]);
            $errors++;
        }
    }
} catch (\Throwable $e) {
    LogService::error('todo_reschedule: erreur fatale', ['error' => $e->getMessage()]);
    echo "[{$startedAt}] todo_reschedule.php — ERREUR FATALE : {$e->getMessage()}\n";
    exit(1);
}

// Rapport
echo "[{$startedAt}] todo_reschedule.php\n";
echo "  replanifiées : {$rescheduled}\n";
echo "  ignorées     : {$skipped}\n";
echo "  erreurs      : {$errors}\n";
echo "\n";
