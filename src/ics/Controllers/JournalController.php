<?php

namespace ICS\Controllers;

use ICS\Models\Calendar;
use ICS\Models\CalendarJournal;
use ICS\Utils\IcsGenerator;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Services\LogService;

/**
 * Contrôleur VJOURNAL — Phase 5.2
 * Routes : /calendars/{id}/journals[/{journalId}]
 */
class JournalController
{
    private Calendar $calModel;
    private CalendarJournal $journalModel;

    public function __construct()
    {
        $this->calModel     = new Calendar();
        $this->journalModel = new CalendarJournal();
    }

    // ----------------------------------------------------------------
    // POST /calendars/{calendarId}/journals
    // ----------------------------------------------------------------
    public function createJournal(int $calendarId, int $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'summary'     => 'required|string|max:255',
            'description' => 'optional|string',
            'dtstart'     => 'optional|date_or_datetime',
            'status'      => 'optional|string|in:DRAFT,FINAL,CANCELLED',
            'categories'  => 'optional|array',
            'url'         => 'optional|string|max:2083',
            'timezone'    => 'optional|string|max:100',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        if (!$this->calModel->isOwner($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Accès non autorisé', null, 403);
            return;
        }

        try {
            $journal = new CalendarJournal();
            $journal->calendarId  = $calendarId;
            $journal->userId      = $userId;
            $journal->summary     = $input['summary'];
            $journal->description = $input['description'] ?? null;
            $journal->dtstart     = isset($input['dtstart'])
                ? date('Y-m-d H:i:s', strtotime($input['dtstart'])) : null;
            $journal->status      = $input['status'] ?? 'DRAFT';
            $journal->categories  = $input['categories'] ?? null;
            $journal->url         = $input['url'] ?? null;
            $journal->timezone    = $input['timezone'] ?? 'America/Montreal';

            $result = $journal->create();
            LoggingMiddleware::logExit(201);
            Response::success('Journal créé avec succès', $result, 201);
        } catch (\Exception $e) {
            LogService::error('Erreur création journal', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la création du journal', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // GET /calendars/{calendarId}/journals
    // ----------------------------------------------------------------
    public function getJournals(int $calendarId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        $permission = $this->calModel->getUserPermissionForCalendar($calendarId, $userId);
        if (!$permission) {
            LoggingMiddleware::logExit(404);
            Response::error('Calendrier non trouvé ou accès non autorisé', null, 404);
            return;
        }

        try {
            $journals = $this->journalModel->getByCalendarId($calendarId);
            LoggingMiddleware::logExit(200);
            Response::success('Journaux récupérés', ['journals' => $journals, 'count' => count($journals)]);
        } catch (\Exception $e) {
            LogService::error('Erreur récupération journaux', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération des journaux', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // GET /calendars/{calendarId}/journals/{journalId}
    // ----------------------------------------------------------------
    public function getJournal(int $calendarId, int $journalId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        $permission = $this->calModel->getUserPermissionForCalendar($calendarId, $userId);
        if (!$permission) {
            LoggingMiddleware::logExit(404);
            Response::error('Calendrier non trouvé ou accès non autorisé', null, 404);
            return;
        }

        $journal = $this->journalModel->getById($journalId);
        if (!$journal || (int)$journal['calendar_id'] !== $calendarId) {
            LoggingMiddleware::logExit(404);
            Response::error('Journal non trouvé', null, 404);
            return;
        }

        LoggingMiddleware::logExit(200);
        Response::success('Journal récupéré', $journal);
    }

    // ----------------------------------------------------------------
    // PUT /calendars/{calendarId}/journals/{journalId}
    // ----------------------------------------------------------------
    public function updateJournal(int $calendarId, int $journalId, int $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'summary'     => 'optional|string|max:255',
            'description' => 'optional|string',
            'dtstart'     => 'optional|date_or_datetime',
            'status'      => 'optional|string|in:DRAFT,FINAL,CANCELLED',
            'categories'  => 'optional|array',
            'url'         => 'optional|string|max:2083',
            'timezone'    => 'optional|string|max:100',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        if (!$this->journalModel->isOwner($journalId, $calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Accès non autorisé', null, 403);
            return;
        }

        try {
            $journal     = new CalendarJournal();
            $journal->id = $journalId;

            foreach (['summary','description','status','url','timezone'] as $f) {
                if (isset($input[$f])) {
                    $journal->$f = $input[$f];
                }
            }
            if (isset($input['dtstart'])) {
                $journal->dtstart = date('Y-m-d H:i:s', strtotime($input['dtstart']));
            }
            if (isset($input['categories'])) {
                $journal->categories = $input['categories'];
            }

            $journal->update();
            $result = $this->journalModel->getById($journalId);

            LoggingMiddleware::logExit(200);
            Response::success('Journal mis à jour', $result);
        } catch (\Exception $e) {
            LogService::error('Erreur mise à jour journal', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la mise à jour du journal', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // DELETE /calendars/{calendarId}/journals/{journalId}
    // ----------------------------------------------------------------
    public function deleteJournal(int $calendarId, int $journalId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        if (!$this->journalModel->isOwner($journalId, $calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Accès non autorisé', null, 403);
            return;
        }

        try {
            $this->journalModel->softDeleteById($journalId);
            LoggingMiddleware::logExit(200);
            Response::success('Journal supprimé');
        } catch (\Exception $e) {
            LogService::error('Erreur suppression journal', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la suppression du journal', null, 500);
        }
    }
}
