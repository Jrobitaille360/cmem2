<?php

namespace ICS\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use ICS\Controllers\CalendarController;
use ICS\Controllers\TodoController;
use ICS\Controllers\JournalController;
use ICS\Controllers\TagController;
use AuthGroups\Utils\Response;

class CalendarRouteHandler extends BaseRouteHandler
{
    private CalendarController $controller;
    private TodoController $todoController;
    private JournalController $journalController;
    private TagController $tagController;

    public function __construct($authService) {
        parent::__construct($authService);
        $this->controller        = new CalendarController();
        $this->todoController    = new TodoController();
        $this->journalController = new JournalController();
        $this->tagController      = new TagController();
    }
    
    protected function getSupportedControllers(): array {
        return ['calendars'];
    }
    
    protected function handleRoute(array $request): void {
        $action = $request['action'];
        $method = $request['method'];
        $id = $request['id'];
        $user = $request['user'];
        $segments = $request['segments'];
        
        match(true) {
            // POST /calendars - Créer un calendrier
            ($action === '' && $method === 'POST') => 
                $this->controller->createCalendar($user['user_id']),
                
            // GET /calendars - Lister les calendriers de l'utilisateur
            ($action === '' && $method === 'GET') => 
                $this->controller->getUserCalendars($user['user_id']),
                               
            // PUT /calendars/{id} - Mettre à jour un calendrier
            ($action && ctype_digit($action) && !isset($segments[2]) && $method === 'PUT') => 
                $this->controller->updateCalendar((int)$action, $user['user_id']),
                
            // DELETE /calendars/{id} - Supprimer un calendrier (soft delete)
            ($action && ctype_digit($action) && !isset($segments[2]) && $method === 'DELETE') => 
                $this->controller->deleteCalendar((int)$action, $user['user_id']),
                
            // DELETE /calendars/{id}/hard - Supprimer définitivement un calendrier
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'hard' && $method === 'DELETE') => 
                $this->controller->hardDeleteCalendar((int)$action, $user['user_id']),
                
            // GET /calendars/{id}/ics - Télécharger fichier ICS (authentifié)
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'ics' && !isset($segments[3]) && $method === 'GET') => 
                $this->controller->getCalendarIcsByIdAndUserId((int)$action, $user['user_id']),

            // POST /calendars/{id}/ics/import - Mettre à jour un calendrier depuis un fichier ICS (upsert par UID)
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'ics' && isset($segments[3]) && $segments[3] === 'import' && $method === 'POST') =>
                $this->controller->importIcsFileToCalendar((int)$action, $user['user_id']),
                
            // POST /calendars/{id}/events - Créer un événement
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && !isset($segments[3]) && $method === 'POST') => 
                $this->controller->createEvent((int)$action, $user['user_id']),

            // GET /calendars/{id}/events/{eventId}/occurrences/expand - Expansion RRULE à la demande (variante par événement)
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && ctype_digit($segments[3]) && isset($segments[4]) && $segments[4] === 'occurrences' && isset($segments[5]) && $segments[5] === 'expand' && $method === 'GET') =>
                $this->controller->getEventOccurrenceExpand((int)$segments[3], (int)$action, $user['user_id']),

            // GET /calendars/{id}/events/{eventId}/occurrences - DÉPRÉCIÉ (410) → utiliser .../occurrences/expand
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && ctype_digit($segments[3]) && isset($segments[4]) && $segments[4] === 'occurrences' && !isset($segments[5]) && $method === 'GET') =>
                $this->controller->getEventOccurrences((int)$segments[3], (int)$action, $user['user_id']),

            // DELETE /calendars/{id}/events/{eventId}/occurrences - Supprimer/annuler une occurrence
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && ctype_digit($segments[3]) && isset($segments[4]) && $segments[4] === 'occurrences' && $method === 'DELETE') => 
                $this->controller->deleteEventOccurrence((int)$action, (int)$segments[3], $user['user_id']),

            // PUT /calendars/{id}/events/{eventId}/occurrences - Modifier une occurrence
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && ctype_digit($segments[3]) && isset($segments[4]) && $segments[4] === 'occurrences' && $method === 'PUT') => 
                $this->controller->updateEventOccurrence((int)$action, (int)$segments[3], $user['user_id']),

            // GET /calendars/{id}/events/occurrences/expand - Expansion RRULE à la demande (calendrier entier)
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && $segments[3] === 'occurrences' && isset($segments[4]) && $segments[4] === 'expand' && $method === 'GET') =>
                $this->controller->getEventsOccurrencesExpand((int)$action, $user['user_id']),

            // GET /calendars/{id}/events/occurrences - DÉPRÉCIÉ (410) → utiliser .../occurrences/expand
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && $segments[3] === 'occurrences' && !isset($segments[4]) && $method === 'GET') =>
                $this->controller->getEventsOccurrences((int)$action, $user['user_id']),

            // GET /calendars/{id}/events/{eventId} - get a unique event
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && ctype_digit($segments[3]) && !isset($segments[4]) && $method === 'GET') => 
                $this->controller->getEvent((int)$segments[3], (int)$action, $user['user_id']),


            // GET /calendars/{id}/events - Lister les événements d'un calendrier
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && !isset($segments[3]) && $method === 'GET') => 
                $this->controller->getCalendarEvents((int)$action, $user['user_id']),
                
            // PUT /calendars/{id}/events/{eventId} - Mettre à jour un événement
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && ctype_digit($segments[3]) && !isset($segments[4]) && $method === 'PUT') => 
                $this->controller->updateEvent((int)$segments[3], (int)$action, $user['user_id']),
                
            // DELETE /calendars/{id}/events/{eventId} - Supprimer un événement (soft delete)
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && ctype_digit($segments[3]) && !isset($segments[4]) && $method === 'DELETE') => 
                $this->controller->deleteEvent((int)$segments[3], (int)$action, $user['user_id']),
                
            // DELETE /calendars/{id}/events/{eventId}/hard - Supprimer définitivement un événement
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && ctype_digit($segments[3]) && isset($segments[4]) && $segments[4] === 'hard' && $method === 'DELETE') =>
                $this->controller->hardDeleteEvent((int)$segments[3], (int)$action, $user['user_id']),

            // GET /calendars/{id}/events/deleted - Lister les événements soft-deleted (corbeille)
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && $segments[3] === 'deleted' && $method === 'GET') =>
                $this->controller->getDeletedEvents((int)$action, $user['user_id']),

            // POST /calendars/{id}/events/{eventId}/restore - Restaurer un événement soft-deleted
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && ctype_digit($segments[3]) && isset($segments[4]) && $segments[4] === 'restore' && $method === 'POST') =>
                $this->controller->restoreEvent((int)$segments[3], (int)$action, $user['user_id']),

            // GET /calendars/{id}/share - Récupérer les partages d'un calendrier
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'share' && $method === 'GET') => 
                $this->controller->getCalendarShares((int)$action, $user['user_id']),
 
                
            // POST /calendars/{id}/share - Partager un calendrier
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'share' && $method === 'POST') => 
                $this->controller->shareCalendar((int)$action, $user['user_id']),
                
            // DELETE /calendars/{id}/share - Supprimer un partage de calendrier
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'share' && $method === 'DELETE') => 
                $this->controller->removeCalendarShare((int)$action, $user['user_id']),

            // POST /calendars/import - Importer un fichier ICS
            ($action === 'import' && $method === 'POST') =>
                $this->controller->importIcsFile($user['user_id']),

            // GET /calendars/{id}/freebusy — Phase 5.3
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'freebusy' && !isset($segments[3]) && $method === 'GET') =>
                $this->controller->getFreeBusy((int)$action, $user['user_id']),

            // ── Todos — Phase 5.1 ─────────────────────────────────────────
            // POST /calendars/{id}/todos
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'todos' && !isset($segments[3]) && $method === 'POST') =>
                $this->todoController->createTodo((int)$action, $user['user_id']),

            // GET /calendars/{id}/todos/deleted - Lister les tâches soft-deleted (corbeille)
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'todos' && isset($segments[3]) && $segments[3] === 'deleted' && $method === 'GET') =>
                $this->todoController->getDeletedTodos((int)$action, $user['user_id']),

            // POST /calendars/{id}/todos/{todoId}/restore - Restaurer une tâche soft-deleted
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'todos' && isset($segments[3]) && ctype_digit($segments[3]) && isset($segments[4]) && $segments[4] === 'restore' && $method === 'POST') =>
                $this->todoController->restoreTodo((int)$action, (int)$segments[3], $user['user_id']),

            // GET /calendars/{id}/todos
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'todos' && !isset($segments[3]) && $method === 'GET') =>
                $this->todoController->getTodos((int)$action, $user['user_id']),

            // GET /calendars/{id}/todos/{todoId}
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'todos' && isset($segments[3]) && ctype_digit($segments[3]) && !isset($segments[4]) && $method === 'GET') =>
                $this->todoController->getTodo((int)$action, (int)$segments[3], $user['user_id']),

            // PUT|PATCH /calendars/{id}/todos/{todoId} — mise à jour partielle dans les deux cas
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'todos' && isset($segments[3]) && ctype_digit($segments[3]) && !isset($segments[4]) && in_array($method, ['PUT', 'PATCH'], true)) =>
                $this->todoController->updateTodo((int)$action, (int)$segments[3], $user['user_id']),

            // DELETE /calendars/{id}/todos/{todoId}
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'todos' && isset($segments[3]) && ctype_digit($segments[3]) && !isset($segments[4]) && $method === 'DELETE') =>
                $this->todoController->deleteTodo((int)$action, (int)$segments[3], $user['user_id']),

            // ── Journals — Phase 5.2 ──────────────────────────────────────
            // POST /calendars/{id}/journals
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'journals' && !isset($segments[3]) && $method === 'POST') =>
                $this->journalController->createJournal((int)$action, $user['user_id']),

            // GET /calendars/{id}/journals/deleted - Lister les journaux soft-deleted (corbeille)
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'journals' && isset($segments[3]) && $segments[3] === 'deleted' && $method === 'GET') =>
                $this->journalController->getDeletedJournals((int)$action, $user['user_id']),

            // POST /calendars/{id}/journals/{journalId}/restore - Restaurer un journal soft-deleted
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'journals' && isset($segments[3]) && ctype_digit($segments[3]) && isset($segments[4]) && $segments[4] === 'restore' && $method === 'POST') =>
                $this->journalController->restoreJournal((int)$action, (int)$segments[3], $user['user_id']),

            // GET /calendars/{id}/journals
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'journals' && !isset($segments[3]) && $method === 'GET') =>
                $this->journalController->getJournals((int)$action, $user['user_id']),

            // GET /calendars/{id}/journals/{journalId}
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'journals' && isset($segments[3]) && ctype_digit($segments[3]) && !isset($segments[4]) && $method === 'GET') =>
                $this->journalController->getJournal((int)$action, (int)$segments[3], $user['user_id']),

            // PUT|PATCH /calendars/{id}/journals/{journalId} — mise à jour partielle dans les deux cas
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'journals' && isset($segments[3]) && ctype_digit($segments[3]) && !isset($segments[4]) && in_array($method, ['PUT', 'PATCH'], true)) =>
                $this->journalController->updateJournal((int)$action, (int)$segments[3], $user['user_id']),

            // DELETE /calendars/{id}/journals/{journalId}
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'journals' && isset($segments[3]) && ctype_digit($segments[3]) && !isset($segments[4]) && $method === 'DELETE') =>
                $this->journalController->deleteJournal((int)$action, (int)$segments[3], $user['user_id']),

            // ── Tags — étiquettes scopées par calendrier ──────────────────
            // GET /calendars/{id}/tags
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'tags' && !isset($segments[3]) && $method === 'GET') =>
                $this->tagController->getTags((int)$action, $user['user_id']),

            // POST /calendars/{id}/tags
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'tags' && !isset($segments[3]) && $method === 'POST') =>
                $this->tagController->createTag((int)$action, $user['user_id']),

            // PUT /calendars/{id}/tags/{tagId}
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'tags' && isset($segments[3]) && ctype_digit($segments[3]) && $method === 'PUT') =>
                $this->tagController->updateTag((int)$action, (int)$segments[3], $user['user_id']),

            // DELETE /calendars/{id}/tags/{tagId}
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'tags' && isset($segments[3]) && ctype_digit($segments[3]) && $method === 'DELETE') =>
                $this->tagController->deleteTag((int)$action, (int)$segments[3], $user['user_id']),

            default => Response::error('Endpoint non trouvé', 404)
        };
    }
    
    private function handlePublicIcsDownload(string $filename): void {
        // Extraire le token du nom de fichier
        $shareToken = str_replace('.ics', '', $filename);
        $this->controller->getCalendarIcs($shareToken);
    }
   
}