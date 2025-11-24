<?php

namespace ICS\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use ICS\Controllers\CalendarController;
use AuthGroups\Utils\Response;

class CalendarRouteHandler extends BaseRouteHandler 
{
    private CalendarController $controller;
    
    public function __construct($authService) {
        parent::__construct($authService);
        $this->controller = new CalendarController();
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
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'ics' && $method === 'GET') => 
                $this->controller->getCalendarIcsByIdAndUserId((int)$action, $user['user_id']),
                
            // POST /calendars/{id}/events - Créer un événement
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && $method === 'POST') => 
                $this->controller->createEvent((int)$action, $user['user_id']),
            
            // GET /calendars/{id}/events/{eventId}/occurrences - Obtenir les occurrences d'un événement récurrent
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && ctype_digit($segments[3]) && isset($segments[4]) && $segments[4] === 'occurrences' && $method === 'GET') => 
                $this->controller->getEventOccurrences((int)$segments[3], (int)$action, $user['user_id']),

            // GET /calendars/{id}/events - Lister les événements d'un calendrier
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && $method === 'GET') => 
                $this->controller->getCalendarEvents((int)$action, $user['user_id']),
                
            // PUT /calendars/{id}/events/{eventId} - Mettre à jour un événement
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && ctype_digit($segments[3]) && $method === 'PUT') => 
                $this->controller->updateEvent((int)$segments[3], (int)$action, $user['user_id']),
                
            // DELETE /calendars/{id}/events/{eventId} - Supprimer un événement (soft delete)
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && ctype_digit($segments[3]) && !isset($segments[4]) && $method === 'DELETE') => 
                $this->controller->deleteEvent((int)$segments[3], (int)$action, $user['user_id']),
                
            // DELETE /calendars/{id}/events/{eventId}/hard - Supprimer définitivement un événement
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && isset($segments[3]) && ctype_digit($segments[3]) && isset($segments[4]) && $segments[4] === 'hard' && $method === 'DELETE') => 
                $this->controller->hardDeleteEvent((int)$segments[3], (int)$action, $user['user_id']),
                

                
            // POST /calendars/{id}/share - Partager un calendrier
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'share' && $method === 'POST') => 
                $this->controller->shareCalendar((int)$action, $user['user_id']),
                
            // DELETE /calendars/{id}/share - Supprimer un partage de calendrier
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'share' && $method === 'DELETE') => 
                $this->controller->removeCalendarShare((int)$action, $user['user_id']),

            // POST /calendars/import - Importer un fichier ICS
            ($action === 'import' && $method === 'POST') =>
                $this->controller->importIcsFile($user['user_id']),
                
            default => Response::error('Endpoint non trouvé', 404)
        };
    }
    
    private function handlePublicIcsDownload(string $filename): void {
        // Extraire le token du nom de fichier
        $shareToken = str_replace('.ics', '', $filename);
        $this->controller->getCalendarIcs($shareToken);
    }
   
}