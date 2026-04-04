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

/**
 * Contrôleur VTODO — Phase 5.1
 * Routes : /calendars/{id}/todos[/{todoId}]
 */
class TodoController
{
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
            'title'            => 'required|string|max:255',
            'description'      => 'optional|string',
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

        try {
            $todo = new CalendarTodo();
            $todo->calendarId      = $calendarId;
            $todo->userId          = $userId;
            $todo->title           = $input['title'];
            $todo->description     = $input['description'] ?? null;
            $todo->due             = isset($input['due']) ? date('Y-m-d H:i:s', strtotime($input['due'])) : null;
            $todo->dtstart         = isset($input['dtstart']) ? date('Y-m-d H:i:s', strtotime($input['dtstart'])) : null;
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
            'title'            => 'optional|string|max:255',
            'description'      => 'optional|string',
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
