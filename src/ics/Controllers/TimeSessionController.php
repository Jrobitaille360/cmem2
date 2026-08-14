<?php

namespace ICS\Controllers;

use ICS\Models\Calendar;
use ICS\Models\CalendarTodo;
use ICS\Models\TimeSession;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Services\LogService;

/**
 * Contrôleur Sessions de temps — Directive D3 (2026-08-14)
 * Routes :
 *   POST   /calendars/{id}/todos/{todoId}/time-sessions/start
 *   GET    /calendars/{id}/todos/{todoId}/time-sessions
 *   GET    /time-sessions/active
 *   PATCH  /time-sessions/{id}/stop
 *   PUT|PATCH /time-sessions/{id}
 *   DELETE /time-sessions/{id}
 */
class TimeSessionController
{
    private const NOTE_MAX = 2000;

    private Calendar $calModel;
    private CalendarTodo $todoModel;
    private TimeSession $sessionModel;

    public function __construct()
    {
        $this->calModel     = new Calendar();
        $this->todoModel    = new CalendarTodo();
        $this->sessionModel = new TimeSession();
    }

    /**
     * Vérifie l'accès en lecture au calendrier propriétaire de la tâche.
     * Retourne le todo si l'accès est permis, false si une réponse d'erreur a déjà été envoyée.
     */
    private function resolveTodoWithAccess(int $calendarId, int $todoId, int $userId): array|false
    {
        $permission = $this->calModel->getUserPermissionForCalendar($calendarId, $userId);
        if (!$permission) {
            Response::error('Calendrier non trouvé ou accès non autorisé', null, 404);
            return false;
        }

        $todo = $this->todoModel->getById($todoId);
        if (!$todo || (int)$todo['calendar_id'] !== $calendarId) {
            Response::error('Tâche non trouvée', null, 404);
            return false;
        }

        return $todo;
    }

    // ----------------------------------------------------------------
    // POST /calendars/{calendarId}/todos/{todoId}/time-sessions/start
    // ----------------------------------------------------------------
    public function startSession(int $calendarId, int $todoId, int $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'note'    => 'optional|string|max:' . self::NOTE_MAX,
            'enc_alg' => 'optional|string|max:32',
            'enc_iv'  => 'optional|string|max:32',
        ]);
        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        if (!$this->resolveTodoWithAccess($calendarId, $todoId, $userId)) {
            LoggingMiddleware::logExit(404);
            return;
        }

        $active = $this->sessionModel->getActiveForUser($userId);
        if ($active) {
            LoggingMiddleware::logExit(409);
            Response::error(
                'Une session de temps est déjà en cours',
                ['code' => 'ACTIVE_SESSION_EXISTS', 'active_session_id' => (int)$active['id']],
                409
            );
            return;
        }

        try {
            $session           = new TimeSession();
            $session->todoId   = $todoId;
            $session->userId   = $userId;
            $session->note     = $input['note'] ?? null;
            $session->encAlg   = $input['enc_alg'] ?? null;
            $session->encIv    = $input['enc_iv'] ?? null;

            $result = $session->create();
            LoggingMiddleware::logExit(201);
            Response::success('Session de temps démarrée', ['session' => $result], 201);
        } catch (\PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                LoggingMiddleware::logExit(409);
                $active = $this->sessionModel->getActiveForUser($userId);
                Response::error(
                    'Une session de temps est déjà en cours',
                    ['code' => 'ACTIVE_SESSION_EXISTS', 'active_session_id' => $active ? (int)$active['id'] : null],
                    409
                );
                return;
            }
            LogService::error('Erreur démarrage session de temps', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors du démarrage de la session', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // GET /calendars/{calendarId}/todos/{todoId}/time-sessions
    // ----------------------------------------------------------------
    public function getSessionsForTodo(int $calendarId, int $todoId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        if (!$this->resolveTodoWithAccess($calendarId, $todoId, $userId)) {
            LoggingMiddleware::logExit(404);
            return;
        }

        $sessions = $this->sessionModel->getByTodoId($todoId);
        LoggingMiddleware::logExit(200);
        Response::success('Sessions de temps récupérées', ['sessions' => $sessions, 'count' => count($sessions)]);
    }

    // ----------------------------------------------------------------
    // GET /time-sessions/active
    // ----------------------------------------------------------------
    public function getActiveSession(int $userId): void
    {
        LoggingMiddleware::logEntry();

        $active = $this->sessionModel->getActiveForUser($userId);
        LoggingMiddleware::logExit(200);
        Response::success('Session active récupérée', ['session' => $active]);
    }

    // ----------------------------------------------------------------
    // PATCH /time-sessions/{id}/stop
    // ----------------------------------------------------------------
    public function stopSession(int $sessionId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        $session = $this->sessionModel->getById($sessionId);
        if (!$session) {
            LoggingMiddleware::logExit(404);
            Response::error('Session non trouvée', null, 404);
            return;
        }
        if ((int)$session['user_id'] !== $userId) {
            LoggingMiddleware::logExit(403);
            Response::error('Accès non autorisé', null, 403);
            return;
        }
        if ($session['ended_at'] !== null) {
            LoggingMiddleware::logExit(409);
            Response::error('Cette session est déjà arrêtée', ['code' => 'SESSION_ALREADY_STOPPED'], 409);
            return;
        }

        $result = $this->sessionModel->stop($sessionId);
        LoggingMiddleware::logExit(200);
        Response::success('Session de temps arrêtée', ['session' => $result]);
    }

    // ----------------------------------------------------------------
    // PUT|PATCH /time-sessions/{id}
    // ----------------------------------------------------------------
    public function updateSession(int $sessionId, int $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'started_at' => 'optional|date_or_datetime',
            'ended_at'   => 'optional|date_or_datetime',
            'note'       => 'optional|string|max:' . self::NOTE_MAX,
            'enc_alg'    => 'optional|string|max:32',
            'enc_iv'     => 'optional|string|max:32',
        ]);
        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        $existing = $this->sessionModel->getById($sessionId);
        if (!$existing) {
            LoggingMiddleware::logExit(404);
            Response::error('Session non trouvée', null, 404);
            return;
        }
        if ((int)$existing['user_id'] !== $userId) {
            LoggingMiddleware::logExit(403);
            Response::error('Accès non autorisé', null, 403);
            return;
        }

        $newStartedAt = isset($input['started_at']) ? date('Y-m-d H:i:s', strtotime($input['started_at'])) : $existing['started_at'];
        $endedAtProvided = array_key_exists('ended_at', $input);
        $newEndedAt = $endedAtProvided
            ? ($input['ended_at'] === null ? null : date('Y-m-d H:i:s', strtotime($input['ended_at'])))
            : $existing['ended_at'];

        if ($newEndedAt !== null && strtotime($newEndedAt) < strtotime($newStartedAt)) {
            LoggingMiddleware::logExit(400);
            Response::error('ended_at ne peut pas être antérieur à started_at', null, 400);
            return;
        }

        try {
            $session = new TimeSession();
            $session->id = $sessionId;
            if (isset($input['started_at'])) {
                $session->startedAt = $newStartedAt;
            }
            if ($endedAtProvided) {
                $session->endedAt = $newEndedAt;
            }
            if (array_key_exists('note', $input)) {
                $session->note = $input['note'];
            }
            if (array_key_exists('enc_alg', $input)) {
                if ($input['enc_alg'] === null) {
                    $session->clearEncAlg = true;
                } else {
                    $session->encAlg = $input['enc_alg'];
                }
            }
            if (array_key_exists('enc_iv', $input)) {
                if ($input['enc_iv'] === null) {
                    $session->clearEncIv = true;
                } else {
                    $session->encIv = $input['enc_iv'];
                }
            }

            $session->update();
            $result = $this->sessionModel->getById($sessionId);
            LoggingMiddleware::logExit(200);
            Response::success('Session de temps mise à jour', ['session' => $result]);
        } catch (\PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                LoggingMiddleware::logExit(409);
                Response::error(
                    'Une autre session de temps est déjà en cours',
                    ['code' => 'ACTIVE_SESSION_EXISTS'],
                    409
                );
                return;
            }
            LogService::error('Erreur mise à jour session de temps', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la mise à jour de la session', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // DELETE /time-sessions/{id}
    // ----------------------------------------------------------------
    public function deleteSession(int $sessionId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        $existing = $this->sessionModel->getById($sessionId);
        if (!$existing) {
            LoggingMiddleware::logExit(404);
            Response::error('Session non trouvée', null, 404);
            return;
        }
        if ((int)$existing['user_id'] !== $userId) {
            LoggingMiddleware::logExit(403);
            Response::error('Accès non autorisé', null, 403);
            return;
        }

        $this->sessionModel->deleteById($sessionId);
        LoggingMiddleware::logExit(200);
        Response::success('Session de temps supprimée');
    }

    private function isUniqueViolation(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
