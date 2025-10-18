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
        return ['calendars', 'calendar'];
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
                
            // GET /calendar/{token}.ics - Télécharger fichier ICS (public)
            ($request['controller'] === 'calendar' && $method === 'GET' && str_ends_with($action, '.ics')) => 
                $this->handlePublicIcsDownload($action),
                
            // POST /calendars/{id}/events - Créer un événement
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && $method === 'POST') => 
                $this->controller->createEvent((int)$action, $user['user_id']),
                
            // POST /calendars/{id}/share - Partager un calendrier
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'share' && $method === 'POST') => 
                $this->controller->shareCalendar((int)$action, $user['user_id']),
                
            // GET /calendars/{id}/events - Lister les événements d'un calendrier
            ($action && ctype_digit($action) && isset($segments[2]) && $segments[2] === 'events' && $method === 'GET') => 
                $this->controller->getCalendarEvents((int)$action, $user['user_id']),
                
            default => Response::error('Endpoint non trouvé', 404)
        };
    }
    
    private function handlePublicIcsDownload(string $filename): void {
        // Extraire le token du nom de fichier
        $shareToken = str_replace('.ics', '', $filename);
        $this->controller->getCalendarIcs($shareToken);
    }
}