<?php

namespace Assistant\Services;

use Assistant\Models\AssistantStore;
use AuthGroups\Services\ReminderList;
use Access\Services\EntitlementService;
use DateTimeImmutable;
use DateTimeZone;
use Database;
use ICS\Models\CalendarTodo;
use PDO;
use Throwable;

/**
 * Outils exposés à Claude (tâche #258) — tâches ICS (calendar_todos) et leurs rappels (#487).
 *
 * Règles de sécurité :
 *  - l'identité vient TOUJOURS du JWT ($userId du constructeur), jamais de l'entrée du modèle ;
 *  - chaque élément manipulé doit appartenir à l'usager (sinon « introuvable ») ;
 *  - aucune suppression directe : propose_delete crée une action PENDING que l'usager
 *    confirme par POST /assistant/actions/{id}/confirm ;
 *  - les tâches chiffrées de bout en bout (enc_alg) ne sont jamais lues ni réécrites en clair.
 *
 * Dates : heure locale « YYYY-MM-DDTHH:MM » dans le fuseau de l'usager, comme la colonne
 * calendar_todos.due (lue avec calendar_todos.timezone par le cron des rappels).
 */
class AssistantTools
{
    private const LIST_LIMIT = 50;

    private PDO $pdo;
    private AssistantStore $store;
    private string $tz;

    /** @var array<int, array> actions effectuées ou proposées pendant ce message */
    private array $actions = [];

    public function __construct(
        private readonly int $userId,
        private readonly ?int $conversationId,
        ?AssistantStore $store = null,
        ?PDO $pdo = null
    ) {
        $this->pdo   = $pdo ?? Database::getInstance()->getConnection();
        $this->store = $store ?? new AssistantStore($this->pdo);
        $this->tz    = self::userTimezone($this->pdo, $userId);
    }

    public function timezone(): string
    {
        return $this->tz;
    }

    /** @return array<int, array> actions journalisées pendant ce message */
    public function actions(): array
    {
        return $this->actions;
    }

    /** Fuseau de l'usager : users.timezone, sinon premier calendrier, sinon America/Montreal (comme ReminderScanner). */
    public static function userTimezone(PDO $pdo, int $userId): string
    {
        $valid = fn($tz) => is_string($tz) && $tz !== '' && in_array($tz, timezone_identifiers_list(), true);
        $st = $pdo->prepare('SELECT timezone FROM users WHERE id = ?');
        $st->execute([$userId]);
        $tz = $st->fetchColumn();
        if ($valid($tz)) {
            return $tz;
        }
        $st = $pdo->prepare('SELECT timezone FROM calendars WHERE user_id = ? AND deleted_at IS NULL AND timezone IS NOT NULL ORDER BY id LIMIT 1');
        $st->execute([$userId]);
        $tz = $st->fetchColumn();
        return $valid($tz) ? $tz : 'America/Montreal';
    }

    // ------------------------------------------------------------------
    // Définitions (strict : additionalProperties false, tout en required, optionnels nullables)
    // ------------------------------------------------------------------

    public static function definitions(): array
    {
        $reminders = [
            'type'        => ['array', 'null'],
            'description' => "Rappels de la tâche. null = rappel par défaut ; [] = aucun rappel. "
                . "Canal EMAIL (courriel) ou DISPLAY (notification push). minutes_before = délai avant l'échéance.",
            'items'       => [
                'type'                 => 'object',
                'properties'           => [
                    'type'           => ['type' => 'string', 'enum' => ['EMAIL', 'DISPLAY']],
                    'minutes_before' => ['type' => 'integer'],
                ],
                'required'             => ['type', 'minutes_before'],
                'additionalProperties' => false,
            ],
        ];
        $localDate = "Heure locale de l'usager, format YYYY-MM-DDTHH:MM (ou YYYY-MM-DD pour une journée entière).";
        // Schéma strict : un enum ne se combine pas avec type ['string','null'] → anyOf.
        $statusOrNull = fn(string $desc) => [
            'anyOf'       => [
                ['type' => 'string', 'enum' => ['NEEDS-ACTION', 'IN-PROCESS', 'COMPLETED', 'CANCELLED']],
                ['type' => 'null'],
            ],
            'description' => $desc,
        ];

        return [
            [
                'name'        => 'list_tasks',
                'description' => "Liste les tâches de l'usager (50 au plus), triées par échéance. "
                    . "Utiliser avant toute modification pour retrouver l'identifiant d'une tâche.",
                'strict'      => true,
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'status'     => $statusOrNull('Filtrer par statut ; null = toutes sauf COMPLETED et CANCELLED.'),
                        'due_after'  => ['type' => ['string', 'null'], 'description' => "Échéance à partir de cette date. {$localDate}"],
                        'due_before' => ['type' => ['string', 'null'], 'description' => "Échéance jusqu'à cette date. {$localDate}"],
                        'search'     => ['type' => ['string', 'null'], 'description' => 'Texte cherché dans le titre.'],
                    ],
                    'required'             => ['status', 'due_after', 'due_before', 'search'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'create_task',
                'description' => "Crée une tâche pour l'usager, avec ses rappels éventuels.",
                'strict'      => true,
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'title'         => ['type' => 'string', 'description' => 'Titre court de la tâche.'],
                        'due'           => ['type' => ['string', 'null'], 'description' => "Échéance. {$localDate} null = sans échéance."],
                        'description'   => ['type' => ['string', 'null']],
                        'priority'      => ['type' => ['integer', 'null'], 'description' => '1 = haute, 5 = moyenne, 9 = basse, null = aucune.'],
                        'notifications' => $reminders,
                    ],
                    'required'             => ['title', 'due', 'description', 'priority', 'notifications'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'update_task',
                'description' => "Modifie une tâche existante de l'usager. Les champs null restent inchangés.",
                'strict'      => true,
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'task_id'  => ['type' => 'integer'],
                        'title'    => ['type' => ['string', 'null']],
                        'due'      => ['type' => ['string', 'null'], 'description' => "Nouvelle échéance. {$localDate}"],
                        'status'   => $statusOrNull('Nouveau statut ; null = inchangé.'),
                        'priority' => ['type' => ['integer', 'null']],
                    ],
                    'required'             => ['task_id', 'title', 'due', 'status', 'priority'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'set_reminder',
                'description' => "Remplace la liste des rappels d'une tâche existante.",
                'strict'      => true,
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'task_id'       => ['type' => 'integer'],
                        'notifications' => $reminders,
                    ],
                    'required'             => ['task_id', 'notifications'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'list_events',
                'description' => "Liste les événements d'agenda de l'usager sur une période (50 au plus).",
                'strict'      => true,
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'start' => ['type' => 'string', 'description' => "Début de la période. {$localDate}"],
                        'end'   => ['type' => 'string', 'description' => "Fin de la période. {$localDate}"],
                    ],
                    'required'             => ['start', 'end'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'propose_delete',
                'description' => "Propose la suppression d'une tâche. Ne supprime rien : l'usager doit confirmer "
                    . "dans l'application. Dire à l'usager que la suppression attend sa confirmation.",
                'strict'      => true,
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'task_id' => ['type' => 'integer'],
                        'reason'  => ['type' => ['string', 'null']],
                    ],
                    'required'             => ['task_id', 'reason'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Exécution
    // ------------------------------------------------------------------

    /**
     * Exécute un appel d'outil. Ne lève jamais : une erreur devient ['error' => …]
     * (renvoyée au modèle en tool_result is_error).
     *
     * @return array{ok: bool, result: array}
     */
    public function execute(string $name, array $input): array
    {
        try {
            $result = match ($name) {
                'list_tasks'     => $this->listTasks($input),
                'create_task'    => $this->createTask($input),
                'update_task'    => $this->updateTask($input),
                'set_reminder'   => $this->setReminder($input),
                'list_events'    => $this->listEvents($input),
                'propose_delete' => $this->proposeDelete($input),
                default          => ['error' => "Outil inconnu : {$name}"],
            };
        } catch (Throwable $e) {
            \AuthGroups\Services\LogService::error('Assistant : échec outil', ['tool' => $name, 'error' => $e->getMessage()]);
            $result = ['error' => "Erreur interne pendant l'exécution de l'outil."];
        }

        $ok = !isset($result['error']);
        // Écritures journalisées (lectures non journalisées) ; propose_delete journalise elle-même (PENDING).
        if (in_array($name, ['create_task', 'update_task', 'set_reminder'], true)) {
            $this->record($name, $input, $result, $ok ? 'DONE' : 'FAILED');
        }
        return ['ok' => $ok, 'result' => $result];
    }

    private function record(string $tool, array $input, array $result, string $status): int
    {
        $id = $this->store->logAction($this->userId, $this->conversationId, $tool, $input, $result, $status);
        $this->actions[] = [
            'id'     => $id,
            'tool'   => $tool,
            'input'  => $input,
            'result' => $result,
            'status' => $status,
        ];
        return $id;
    }

    /** « YYYY-MM-DDTHH:MM » ou « YYYY-MM-DD » → [Y-m-d H:i:s, allDay] ; null si invalide. */
    private function parseLocal(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone($this->tz));
            return $d && $d->format('Y-m-d') === $value ? [$d->format('Y-m-d 00:00:00'), true] : null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?$/', $value, $m)) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "{$m[1]} {$m[2]}", new DateTimeZone($this->tz));
            return $d && $d->format('Y-m-d H:i') === "{$m[1]} {$m[2]}" ? [$d->format('Y-m-d H:i:00'), false] : null;
        }
        return null;
    }

    /** Tâche de l'usager (non supprimée) ou null. */
    private function ownedTodo(int $taskId): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT t.* FROM calendar_todos t
               JOIN calendars c ON c.id = t.calendar_id AND c.user_id = ? AND c.deleted_at IS NULL
              WHERE t.id = ? AND t.user_id = ? AND t.deleted_at IS NULL AND t.project_id IS NULL"
        );
        $st->execute([$this->userId, $taskId, $this->userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function todoSummary(array $row): array
    {
        $encrypted = !empty($row['enc_alg']);
        return [
            'id'            => (int) $row['id'],
            'title'         => $encrypted ? '(tâche chiffrée — contenu illisible pour l\'assistant)' : $row['title'],
            'encrypted'     => $encrypted,
            'due'           => $row['due'] !== null
                ? (((int) ($row['is_all_day'] ?? 0)) ? substr($row['due'], 0, 10) : substr(str_replace(' ', 'T', $row['due']), 0, 16))
                : null,
            'status'        => $row['status'],
            'priority'      => (int) $row['priority'],
            // brut (SELECT) = chaîne JSON ; CalendarTodo::getById() = déjà décodé
            'notifications' => is_string($row['notifications'] ?? null) ? json_decode($row['notifications'], true) : ($row['notifications'] ?? null),
        ];
    }

    private function validateReminders(mixed $value): array
    {
        $check = ReminderList::validate($value);
        return $check['ok'] ? ['value' => $check['value']] : ['error' => $check['error']['message']];
    }

    private function listTasks(array $in): array
    {
        $sql    = "SELECT t.* FROM calendar_todos t
                     JOIN calendars c ON c.id = t.calendar_id AND c.user_id = ? AND c.deleted_at IS NULL
                    WHERE t.user_id = ? AND t.deleted_at IS NULL AND t.project_id IS NULL";
        $params = [$this->userId, $this->userId];

        if (!empty($in['status'])) {
            $sql     .= ' AND t.status = ?';
            $params[] = $in['status'];
        } else {
            $sql .= " AND t.status NOT IN ('COMPLETED', 'CANCELLED')";
        }
        foreach (['due_after' => '>=', 'due_before' => '<='] as $key => $op) {
            if (!empty($in[$key])) {
                $d = $this->parseLocal($in[$key]);
                if ($d === null) {
                    return ['error' => "Date invalide pour {$key} : format YYYY-MM-DDTHH:MM attendu."];
                }
                $bound    = $key === 'due_before' && $d[1] ? substr($d[0], 0, 10) . ' 23:59:59' : $d[0];
                $sql     .= " AND t.due {$op} ?";
                $params[] = $bound;
            }
        }
        if (!empty($in['search'])) {
            $sql     .= ' AND t.enc_alg IS NULL AND t.title LIKE ?';
            $params[] = '%' . addcslashes((string) $in['search'], '%_\\') . '%';
        }
        $sql .= ' ORDER BY t.due IS NULL, t.due, t.id LIMIT ' . self::LIST_LIMIT;

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $tasks = array_map([self::class, 'todoSummary'], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['tasks' => $tasks, 'count' => count($tasks)];
    }

    private function createTask(array $in): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 500) {
            return ['error' => 'Titre requis (500 caractères au plus).'];
        }
        $due = null;
        if (!empty($in['due'])) {
            $due = $this->parseLocal($in['due']);
            if ($due === null) {
                return ['error' => 'Échéance invalide : format YYYY-MM-DDTHH:MM ou YYYY-MM-DD attendu.'];
            }
        }
        $priority = $in['priority'] ?? null;
        if ($priority !== null && (!is_int($priority) || $priority < 0 || $priority > 9)) {
            return ['error' => 'Priorité : entier de 0 à 9.'];
        }
        $reminders = array_key_exists('notifications', $in) ? $this->validateReminders($in['notifications']) : ['value' => null];
        if (isset($reminders['error'])) {
            return ['error' => $reminders['error']];
        }

        $calendarId = $this->defaultCalendarId();
        if ($calendarId === null) {
            return ['error' => "L'usager n'a aucun calendrier : il doit en créer un dans l'application."];
        }

        $todoModel  = new CalendarTodo();
        $quotaError = EntitlementService::checkQuota($this->userId, 'max_tasks', $todoModel->countByUserId($this->userId));
        if ($quotaError) {
            return ['error' => 'Quota de tâches atteint.'];
        }

        $todo                  = new CalendarTodo();
        $todo->calendarId      = $calendarId;
        $todo->userId          = $this->userId;
        $todo->title           = $title;
        $todo->description     = $in['description'] ?? null;
        $todo->due             = $due[0] ?? null;
        $todo->isAllDay        = $due[1] ?? false;
        $todo->status          = 'NEEDS-ACTION';
        $todo->priority        = $priority ?? 0;
        $todo->percentComplete = 0;
        $todo->timezone        = $this->tz;
        $todo->notifications   = $reminders['value'];
        $row                   = $todo->create();

        return ['task' => self::todoSummary($row)];
    }

    private function updateTask(array $in): array
    {
        $row = $this->ownedTodo((int) ($in['task_id'] ?? 0));
        if ($row === null) {
            return ['error' => 'Tâche introuvable.'];
        }
        $encrypted = !empty($row['enc_alg']);

        $todo     = new CalendarTodo();
        $todo->id = (int) $row['id'];
        $changed  = false;

        if (isset($in['title'])) {
            if ($encrypted) {
                return ['error' => "Tâche chiffrée : le titre ne peut être modifié que dans l'application."];
            }
            $title = trim((string) $in['title']);
            if ($title === '' || mb_strlen($title) > 500) {
                return ['error' => 'Titre invalide.'];
            }
            $todo->title = $title;
            $changed     = true;
        }
        if (isset($in['due'])) {
            $due = $this->parseLocal($in['due']);
            if ($due === null) {
                return ['error' => 'Échéance invalide : format YYYY-MM-DDTHH:MM ou YYYY-MM-DD attendu.'];
            }
            $todo->due      = $due[0];
            $todo->isAllDay = $due[1] ? 1 : 0;
            $todo->timezone = $this->tz;
            $changed        = true;
        }
        if (isset($in['status'])) {
            if (!in_array($in['status'], ['NEEDS-ACTION', 'IN-PROCESS', 'COMPLETED', 'CANCELLED'], true)) {
                return ['error' => 'Statut invalide.'];
            }
            $todo->status = $in['status'];
            if ($in['status'] === 'COMPLETED') {
                $todo->completed       = date('Y-m-d H:i:s');
                $todo->percentComplete = 100;
            }
            $changed = true;
        }
        if (isset($in['priority'])) {
            if (!is_int($in['priority']) || $in['priority'] < 0 || $in['priority'] > 9) {
                return ['error' => 'Priorité : entier de 0 à 9.'];
            }
            $todo->priority = $in['priority'];
            $changed        = true;
        }
        if (!$changed) {
            return ['error' => 'Aucun champ à modifier.'];
        }

        $todo->update();
        return ['task' => self::todoSummary($this->ownedTodo((int) $row['id']))];
    }

    private function setReminder(array $in): array
    {
        $row = $this->ownedTodo((int) ($in['task_id'] ?? 0));
        if ($row === null) {
            return ['error' => 'Tâche introuvable.'];
        }
        $reminders = $this->validateReminders($in['notifications'] ?? null);
        if (isset($reminders['error'])) {
            return ['error' => $reminders['error']];
        }
        $todo                   = new CalendarTodo();
        $todo->id               = (int) $row['id'];
        $todo->notifications    = $reminders['value'];
        $todo->notificationsSet = true;
        $todo->update();
        return ['task' => self::todoSummary($this->ownedTodo((int) $row['id']))];
    }

    private function listEvents(array $in): array
    {
        $start = $this->parseLocal($in['start'] ?? null);
        $end   = $this->parseLocal($in['end'] ?? null);
        if ($start === null || $end === null) {
            return ['error' => 'Période invalide : format YYYY-MM-DDTHH:MM ou YYYY-MM-DD attendu.'];
        }
        $endBound = $end[1] ? substr($end[0], 0, 10) . ' 23:59:59' : $end[0];

        $st = $this->pdo->prepare(
            "SELECT e.id, e.title, e.start_datetime, e.end_datetime, e.all_day, e.location
               FROM calendar_events e
               JOIN calendars c ON c.id = e.calendar_id AND c.user_id = ? AND c.deleted_at IS NULL
              WHERE e.deleted_at IS NULL AND e.start_datetime <= ? AND e.end_datetime >= ?
              ORDER BY e.start_datetime LIMIT " . self::LIST_LIMIT
        );
        $st->execute([$this->userId, $endBound, $start[0]]);
        $events = array_map(fn($r) => [
            'id'       => (int) $r['id'],
            'title'    => $r['title'],
            'start'    => $r['start_datetime'],
            'end'      => $r['end_datetime'],
            'all_day'  => (bool) $r['all_day'],
            'location' => $r['location'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['events' => $events, 'count' => count($events), 'note' => 'Événements récurrents : seule la série est listée.'];
    }

    private function proposeDelete(array $in): array
    {
        $row = $this->ownedTodo((int) ($in['task_id'] ?? 0));
        if ($row === null) {
            return ['error' => 'Tâche introuvable.'];
        }
        $summary = self::todoSummary($row);
        $input   = ['todo_id' => (int) $row['id'], 'title' => $summary['title'], 'reason' => $in['reason'] ?? null];
        $result  = ['pending' => true, 'message' => "Suppression en attente de confirmation par l'usager."];
        $id      = $this->record('propose_delete', $input, $result, 'PENDING');
        return $result + ['action_id' => $id];
    }

    /** Premier calendrier possédé (non supprimé). */
    private function defaultCalendarId(): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM calendars WHERE user_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $st->execute([$this->userId]);
        $id = $st->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    // ------------------------------------------------------------------
    // Confirmation d'une suppression proposée (hors modèle : route /assistant/actions)
    // ------------------------------------------------------------------

    /** Exécute la suppression d'une action propose_delete ; retourne le résultat à journaliser. */
    public function performDelete(array $action): array
    {
        $todoId = (int) ($action['input']['todo_id'] ?? 0);
        $row    = $this->ownedTodo($todoId);
        if ($row === null) {
            return ['deleted' => false, 'error' => 'Tâche introuvable ou déjà supprimée.'];
        }
        (new CalendarTodo())->softDeleteById($todoId);
        return ['deleted' => true, 'todo_id' => $todoId];
    }
}
