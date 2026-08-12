<?php

namespace ICS\Controllers;

use ICS\Models\Calendar;
use ICS\Models\CalendarTodo;
use ICS\Models\CalendarEvent;
use ICS\Utils\IcsGenerator;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Services\LogService;
use Stripe\Services\EntitlementService;

/**
 * Contrôleur VTODO — Phase 5.1
 * Routes : /calendars/{id}/todos[/{todoId}]
 */
class TodoController
{
    /**
     * Longueurs maximales — chiffrement E2E (directive 20260804_090000).
     * Le base64 gonfle le contenu d'environ 4/3 : les bornes du clair seraient
     * trop courtes pour un corps chiffré. Elles bornent l'entrée pour renvoyer
     * un 400 explicite plutôt qu'une erreur SQL sur VARCHAR(2000) / MEDIUMTEXT.
     */
    private const TITLE_MAX       = 2000;
    private const DESCRIPTION_MAX = 16000000;

    private Calendar $calModel;
    private CalendarTodo $todoModel;

    public function __construct()
    {
        $this->calModel  = new Calendar();
        $this->todoModel = new CalendarTodo();
    }

    // ----------------------------------------------------------------
    // POST /calendars/{calendarId}/todos
    // ----------------------------------------------------------------
    public function createTodo(int $calendarId, int $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'title'            => 'required|string|max:' . self::TITLE_MAX,
            'description'      => 'optional|string|max:' . self::DESCRIPTION_MAX,
            'enc_alg'          => 'optional|string|max:32',
            'enc_iv'           => 'optional|string|max:32',
            'due'              => 'optional|date_or_datetime',
            'dtstart'          => 'optional|date_or_datetime',
            'status'           => 'optional|string|in:NEEDS-ACTION,IN-PROCESS,COMPLETED,CANCELLED',
            'priority'         => 'optional|integer|min:0|max:9',
            'percent_complete' => 'optional|integer|min:0|max:100',
            'location'         => 'optional|string|max:255',
            'categories'       => 'optional|array',
            'url'              => 'optional|string|max:2083',
            'timezone'         => 'optional|string|max:100',
            'recurrence_rule'  => 'optional|string|max:255',
            'is_all_day'       => 'optional|boolean',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        if (isset($input['recurrence_rule']) && !CalendarEvent::isValidRecurrenceRule($input['recurrence_rule'])) {
            LoggingMiddleware::logExit(400);
            Response::error('Règle de récurrence invalide', null, 400);
            return;
        }

        if (!$this->calModel->isOwner($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Accès non autorisé', null, 403);
            return;
        }

        $quotaError = EntitlementService::checkQuota(
            $userId,
            'max_tasks',
            $this->todoModel->countByUserId($userId)
        );
        if ($quotaError) {
            LoggingMiddleware::logExit(403);
            Response::error('Quota de tâches atteint', $quotaError, 403);
            return;
        }

        try {
            $isAllDay = !empty($input['is_all_day']);

            $todo = new CalendarTodo();
            $todo->calendarId      = $calendarId;
            $todo->userId          = $userId;
            $todo->title           = $input['title'];
            $todo->description     = $input['description'] ?? null;
            // Chiffrement E2E : stockés tels quels, jamais interprétés côté serveur.
            $todo->encAlg          = $input['enc_alg'] ?? null;
            $todo->encIv           = $input['enc_iv'] ?? null;
            $todo->due             = isset($input['due']) ? date('Y-m-d H:i:s', strtotime($input['due'])) : null;
            $todo->dtstart         = isset($input['dtstart']) ? date('Y-m-d H:i:s', strtotime($input['dtstart'])) : null;
            $todo->isAllDay        = $isAllDay;
            $todo->status          = $input['status'] ?? 'NEEDS-ACTION';
            $todo->priority        = $input['priority'] ?? 0;
            $todo->percentComplete = $input['percent_complete'] ?? 0;
            $todo->location        = $input['location'] ?? null;
            $todo->categories      = $input['categories'] ?? null;
            $todo->url             = $input['url'] ?? null;
            $todo->timezone        = $input['timezone'] ?? 'America/Montreal';
            $todo->recurrenceRule  = $input['recurrence_rule'] ?? null;

            $result = $todo->create();
            LoggingMiddleware::logExit(201);
            Response::success('Tâche créée avec succès', ['todo' => $result], 201);
        } catch (\Exception $e) {
            LogService::error('Erreur création todo', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la création de la tâche', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // GET /calendars/{calendarId}/todos
    // ----------------------------------------------------------------
    public function getTodos(int $calendarId, int $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $permission = $this->calModel->getUserPermissionForCalendar($calendarId, $userId);
        if (!$permission) {
            LoggingMiddleware::logExit(404);
            Response::error('Calendrier non trouvé ou accès non autorisé', null, 404);
            return;
        }

        try {
            $status = $input['status'] ?? null;
            $todos  = $this->todoModel->getByCalendarId($calendarId, $status);
            LoggingMiddleware::logExit(200);
            Response::success('Tâches récupérées', ['todos' => $todos, 'count' => count($todos)]);
        } catch (\Exception $e) {
            LogService::error('Erreur récupération todos', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération des tâches', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // GET /calendars/{calendarId}/todos/{todoId}
    // ----------------------------------------------------------------
    public function getTodo(int $calendarId, int $todoId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        $permission = $this->calModel->getUserPermissionForCalendar($calendarId, $userId);
        if (!$permission) {
            LoggingMiddleware::logExit(404);
            Response::error('Calendrier non trouvé ou accès non autorisé', null, 404);
            return;
        }

        $todo = $this->todoModel->getById($todoId);
        if (!$todo || (int)$todo['calendar_id'] !== $calendarId) {
            LoggingMiddleware::logExit(404);
            Response::error('Tâche non trouvée', null, 404);
            return;
        }

        LoggingMiddleware::logExit(200);
        Response::success('Tâche récupérée', $todo);
    }

    // ----------------------------------------------------------------
    // PUT /calendars/{calendarId}/todos/{todoId}
    // ----------------------------------------------------------------
    public function updateTodo(int $calendarId, int $todoId, int $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'title'            => 'optional|string|max:' . self::TITLE_MAX,
            'description'      => 'optional|string|max:' . self::DESCRIPTION_MAX,
            'enc_alg'          => 'optional|string|max:32',
            'enc_iv'           => 'optional|string|max:32',
            'due'              => 'optional|date_or_datetime',
            'dtstart'          => 'optional|date_or_datetime',
            'completed'        => 'optional|date_or_datetime',
            'status'           => 'optional|string|in:NEEDS-ACTION,IN-PROCESS,COMPLETED,CANCELLED',
            'priority'         => 'optional|integer|min:0|max:9',
            'percent_complete' => 'optional|integer|min:0|max:100',
            'location'         => 'optional|string|max:255',
            'categories'       => 'optional|array',
            'url'              => 'optional|string|max:2083',
            'timezone'         => 'optional|string|max:100',
            'recurrence_rule'  => 'optional|string|max:255',
            'is_all_day'       => 'optional|boolean',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        if (isset($input['recurrence_rule']) && !CalendarEvent::isValidRecurrenceRule($input['recurrence_rule'])) {
            LoggingMiddleware::logExit(400);
            Response::error('Règle de récurrence invalide', null, 400);
            return;
        }

        if (!$this->todoModel->isOwner($todoId, $calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Accès non autorisé', null, 403);
            return;
        }

        $existing = $this->todoModel->getById($todoId);
        \AuthGroups\Utils\ConditionalRequest::enforce($existing['updated_at'] ?? null, fn() => $existing);

        try {
            $todo     = new CalendarTodo();
            $todo->id = $todoId;

            foreach (['title','description','status','location','url','timezone'] as $f) {
                if (isset($input[$f])) {
                    $todo->$f = $input[$f];
                }
            }
            if (isset($input['due'])) {
                $todo->due = date('Y-m-d H:i:s', strtotime($input['due']));
            }
            if (isset($input['dtstart'])) {
                $todo->dtstart = date('Y-m-d H:i:s', strtotime($input['dtstart']));
            }
            if (isset($input['completed'])) {
                $todo->completed = date('Y-m-d H:i:s', strtotime($input['completed']));
            }
            if (isset($input['priority'])) {
                $todo->priority = (int)$input['priority'];
            }
            if (isset($input['percent_complete'])) {
                $todo->percentComplete = (int)$input['percent_complete'];
            }
            if (isset($input['categories'])) {
                $todo->categories = $input['categories'];
            }
            if (isset($input['recurrence_rule'])) {
                $todo->recurrenceRule = $input['recurrence_rule'];
            }
            if (array_key_exists('is_all_day', $input)) {
                $todo->isAllDay = !empty($input['is_all_day']);
            }
            // Chiffrement E2E : null explicite = retour au clair ; champ omis = inchangé.
            if (array_key_exists('enc_alg', $input)) {
                if ($input['enc_alg'] === null) {
                    $todo->clearEncAlg = true;
                } else {
                    $todo->encAlg = $input['enc_alg'];
                }
            }
            if (array_key_exists('enc_iv', $input)) {
                if ($input['enc_iv'] === null) {
                    $todo->clearEncIv = true;
                } else {
                    $todo->encIv = $input['enc_iv'];
                }
            }

            $todo->update();
            $result = $this->todoModel->getById($todoId);

            LoggingMiddleware::logExit(200);
            Response::success('Tâche mise à jour', $result);
        } catch (\Exception $e) {
            LogService::error('Erreur mise à jour todo', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la mise à jour de la tâche', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // DELETE /calendars/{calendarId}/todos/{todoId}
    // ----------------------------------------------------------------
    public function deleteTodo(int $calendarId, int $todoId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        if (!$this->todoModel->isOwner($todoId, $calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Accès non autorisé', null, 403);
            return;
        }

        try {
            $this->todoModel->softDeleteById($todoId);
            LoggingMiddleware::logExit(200);
            Response::success('Tâche supprimée');
        } catch (\Exception $e) {
            LogService::error('Erreur suppression todo', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la suppression de la tâche', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // GET /calendars/{calendarId}/todos/deleted — corbeille
    // ----------------------------------------------------------------
    public function getDeletedTodos(int $calendarId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        $permission = $this->calModel->getUserPermissionForCalendar($calendarId, $userId);
        if (!$permission) {
            LoggingMiddleware::logExit(404);
            Response::error('Calendrier non trouvé ou accès non autorisé', null, 404);
            return;
        }

        $pagination = Response::getPaginationParams();

        try {
            $todos = $this->todoModel->getDeletedByCalendarId($calendarId, $pagination['page'], $pagination['limit']);
            LoggingMiddleware::logExit(200);
            Response::success('Tâches supprimées récupérées', [
                'todos' => $todos,
                'count' => count($todos),
                'page'  => $pagination['page'],
                'limit' => $pagination['limit'],
            ]);
        } catch (\Exception $e) {
            LogService::error('Erreur récupération todos supprimés', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération des tâches supprimées', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // POST /calendars/{calendarId}/todos/{todoId}/restore
    // ----------------------------------------------------------------
    public function restoreTodo(int $calendarId, int $todoId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        $todo = new CalendarTodo();
        $existing = $todo->findById($todoId, true);

        if (!$existing || (int)$existing['calendar_id'] !== $calendarId) {
            LoggingMiddleware::logExit(404);
            Response::error('Tâche non trouvée', null, 404);
            return;
        }

        if ((int)$existing['user_id'] !== $userId) {
            LoggingMiddleware::logExit(403);
            Response::error('Accès non autorisé', null, 403);
            return;
        }

        if (empty($existing['deleted_at'])) {
            LoggingMiddleware::logExit(404);
            Response::error('Cette tâche n\'est pas supprimée', null, 404);
            return;
        }

        if (strtotime($existing['deleted_at']) < strtotime('-' . CalendarTodo::RESTORE_RETENTION_DAYS . ' days')) {
            LoggingMiddleware::logExit(404);
            Response::error('Fenêtre de restauration expirée', null, 404);
            return;
        }

        try {
            if ($todo->restore()) {
                LoggingMiddleware::logExit(200);
                Response::success('Tâche restaurée avec succès', ['todo_id' => $todoId]);
            } else {
                throw new \Exception('Échec de la restauration');
            }
        } catch (\Exception $e) {
            LogService::error('Erreur restauration todo', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la restauration de la tâche', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // GET /calendars/{calendarId}/todos.ics  — export VTODO en ICS
    // ----------------------------------------------------------------
    public function exportTodosIcs(int $calendarId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        $permission = $this->calModel->getUserPermissionForCalendar($calendarId, $userId);
        if (!$permission) {
            LoggingMiddleware::logExit(404);
            Response::error('Calendrier non trouvé ou accès non autorisé', null, 404);
            return;
        }

        $calendar = $this->calModel->getById($calendarId);
        $todos    = $this->todoModel->getByCalendarId($calendarId);

        $ics = IcsGenerator::generateTodosCalendar($calendar, $todos);

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="todos.ics"');
        LoggingMiddleware::logExit(200);
        echo $ics;
    }
}
