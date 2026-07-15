<?php

namespace ICS\Controllers;

use ICS\Models\Calendar;
use ICS\Models\CalendarTag;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Services\LogService;

/**
 * Contrôleur des étiquettes (tags) scopées par calendrier.
 * Routes : /calendars/{id}/tags[/{tagId}]
 * Directive : 20260715_090000_cmem_web_vers_cmem2_API__tags-par-calendrier.md
 */
class TagController
{
    private Calendar $calModel;
    private CalendarTag $tagModel;

    public function __construct()
    {
        $this->calModel = new Calendar();
        $this->tagModel = new CalendarTag();
    }

    // ----------------------------------------------------------------
    // GET /calendars/{calendarId}/tags
    // ----------------------------------------------------------------
    public function getTags(int $calendarId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        $permission = $this->calModel->getUserPermissionForCalendar($calendarId, $userId);
        if (!$permission) {
            LoggingMiddleware::logExit(404);
            Response::error('Calendrier non trouvé ou accès non autorisé', null, 404);
            return;
        }

        try {
            $tags = $this->tagModel->getByCalendarId($calendarId);
            LoggingMiddleware::logExit(200);
            Response::success('Étiquettes récupérées', ['tags' => $tags, 'count' => count($tags)]);
        } catch (\Exception $e) {
            LogService::error('Erreur récupération tags', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération des étiquettes', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // POST /calendars/{calendarId}/tags
    // ----------------------------------------------------------------
    public function createTag(int $calendarId, int $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'name'  => 'required|string|max:191',
            'color' => 'optional|string|max:20',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        if (!$this->calModel->canUserWrite($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour gérer les étiquettes de ce calendrier', null, 403);
            return;
        }

        if ($this->tagModel->existsByName($calendarId, $input['name'])) {
            LoggingMiddleware::logExit(409);
            Response::error('TAG_ALREADY_EXISTS', null, 409);
            return;
        }

        try {
            $tag = new CalendarTag();
            $tag->calendarId = $calendarId;
            $tag->name       = $input['name'];
            $tag->color      = $input['color'] ?? null;

            $result = $tag->create();
            LoggingMiddleware::logExit(201);
            Response::success('Étiquette créée avec succès', ['tag' => $result], 201);
        } catch (\Exception $e) {
            LogService::error('Erreur création tag', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la création de l\'étiquette', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // PUT /calendars/{calendarId}/tags/{tagId}
    // ----------------------------------------------------------------
    public function updateTag(int $calendarId, int $tagId, int $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'name'  => 'optional|string|max:191',
            'color' => 'optional|string|max:20',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        if (!$this->calModel->canUserWrite($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour gérer les étiquettes de ce calendrier', null, 403);
            return;
        }

        $tag = $this->tagModel->getById($tagId);
        if (!$tag || (int) $tag['calendar_id'] !== $calendarId) {
            LoggingMiddleware::logExit(404);
            Response::error('Étiquette non trouvée', null, 404);
            return;
        }

        $newName = $input['name'] ?? null;
        if ($newName !== null && $newName !== $tag['name'] && $this->tagModel->existsByName($calendarId, $newName)) {
            LoggingMiddleware::logExit(409);
            Response::error('TAG_ALREADY_EXISTS', null, 409);
            return;
        }

        try {
            $result = $this->tagModel->renameWithCascade($tagId, $calendarId, $newName, $input['color'] ?? null);
            LoggingMiddleware::logExit(200);
            Response::success('Étiquette mise à jour avec succès', ['tag' => $result]);
        } catch (\Exception $e) {
            LogService::error('Erreur mise à jour tag', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la mise à jour de l\'étiquette', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // DELETE /calendars/{calendarId}/tags/{tagId}
    // ----------------------------------------------------------------
    public function deleteTag(int $calendarId, int $tagId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        if (!$this->calModel->canUserWrite($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour gérer les étiquettes de ce calendrier', null, 403);
            return;
        }

        $tag = $this->tagModel->getById($tagId);
        if (!$tag || (int) $tag['calendar_id'] !== $calendarId) {
            LoggingMiddleware::logExit(404);
            Response::error('Étiquette non trouvée', null, 404);
            return;
        }

        try {
            $this->tagModel->deleteWithCascade($tagId, $calendarId);
            LoggingMiddleware::logExit(204);
            Response::success('Étiquette supprimée avec succès', null, 204);
        } catch (\Exception $e) {
            LogService::error('Erreur suppression tag', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la suppression de l\'étiquette', null, 500);
        }
    }
}
