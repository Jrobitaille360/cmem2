<?php

namespace ICS\Controllers;

use ICS\Models\Calendar;
use ICS\Models\CalendarEvent;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Services\LogService;
use PHPUnit\TextUI\XmlConfiguration\Logging\Logging;

class CalendarController
{
    /**
     * Crée un nouveau calendrier pour un utilisateur
     */
    public function createCalendar($userId): void
    {
        LoggingMiddleware::logEntry();         
        $input = Response::getRequestParams();
    
        // Validation
        $validator = new Validator();
        $validation = $validator->validate($input, [
                'titre' => 'required|string|max:100',
                'description' => 'optionnal|string|max:1000',
                'visibility' => 'optionnal|string|in:public,private',
                'max_members' => 'optionnal|integer|min:1|max:1000',
                'color' => 'optionnal|string|max:7',
                'timezone' => 'optionnal|string|max:100',
            ]);

            if (!$validation['valid']) {
                LogService::warning("Données de création invalides", [
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Données invalides', $validation['errors'], 400);
                return;
            }
       
        try {
            $cal = new Calendar();
            $cal->userId = $userId;
            $cal->title = $input['title'];
            $cal->description = $input['description'] ?? '';
            $cal->visibility = $input['visibility'] ?? 'private';
            $cal->maxMembers = $input['max_members'] ?? 1000;
            $cal->color = $input['color'] ?? '#3174ad';
            $cal->timezone = $input['timezone'] ?? 'America/Montreal';

            $result = $cal->create();
            LoggingMiddleware::logExit(201);
            Response::success([
                'calendar' => $result,
                'message' => 'Calendrier créé avec succès'
            ], 201);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la création du calendrier", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la création du calendrier', 500);
        }
    }
    
    /**
     * Récupère tous les calendriers d'un utilisateur
     */
    public function getUserCalendars($userId): void
    {
        LoggingMiddleware::logEntry();         
        try {
            $cal = new Calendar();
            $calendars = $cal->getUserCalendars($userId);
            LoggingMiddleware::logExit(200);
            Response::success('calendriers de l\'utilisateur récupérés avec succès', [
                'calendars' => $calendars,
                'count' => count($calendars)
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la récupération des calendriers", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération des calendriers', 500);
        }
    }
    
    /**
     * Récupère un calendrier public partagé par token
     */
    public function getPublicCalendarIcs($shareToken): void
    {
        LoggingMiddleware::logEntry();         
        try {
            $cal = new Calendar();
            $calendar = $cal->getByShareToken($shareToken);

            if (!$calendar) {
                LogService::warning("Calendrier non trouvé", [
                    'share_token' => $shareToken
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Calendrier non trouvé', 404);
                return;
            }
            
            $events = Calendar::getEventsForCalendar($calendar['id']);
            $icsContent = Calendar::generateIcsContent($calendar, $events);
            logService::info("ICS généré pour le calendrier public", [
                'calendar_id' => $calendar['id'],
                'share_token' => $shareToken
            ]);
            LoggingMiddleware::logExit(200);
            Response::sendIcs($icsContent, $calendar['title'] . '.ics');
            
            exit;
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la génération du fichier ICS", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la génération du fichier ICS', 500);
        }
    }

    /**
     * Récupère un calendrier partagé par token pour un utilisateur authentifié
     */
    public function getCalendarIcsUserId($shareToken, $userId): void
    {
        LoggingMiddleware::logEntry();         
        try {
            $cal = new Calendar();
            $calendar = $cal->getByShareTokenUserId($shareToken, $userId);

            if (!$calendar) {
                LogService::warning("Calendrier non trouvé", [
                    'share_token' => $shareToken,
                    'user_id' => $userId
                ]);
                Response::error('Calendrier non trouvé', 404);
                return;
            }
            
            $events = Calendar::getEventsForCalendar($calendar['id']);
            $icsContent = Calendar::generateIcsContent($calendar, $events);

            Response::sendIcs($icsContent, $calendar['title'] . '.ics');
            
            exit;
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la génération du fichier ICS", [
                'exception' => $e->getMessage()
            ]);
            Response::error('Erreur lors de la génération du fichier ICS', 500);
        }
    }

    /**
     * Partage un calendrier avec un autre utilisateur
     */
    public function shareCalendar($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();  
        $input = Response::getRequestParams();
        $validator = new Validator();
        $validation = $validator->validate($input, [
            'user_id' => 'required|integer'
        ]);
        if(!$validation['valid']) {
            LogService::warning("Données de partage invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }
        
        $cal = new Calendar();
        $calendar = $cal->getById($calendarId);
        // Vérifier que l'utilisateur possède le calendrier
        if (!$calendar || ($calendar['user_id'] != $userId && $calendar['visibility'] != 'public')) {
            logService::warning("Tentative de partage d'un calendrier non possédé", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(404);
            Response::error('Calendrier non trouvé', 404);
            return;
        }
        
        try {
            $shareResult = $cal->shareWith($calendarId, $input['user_id']);
            LogService::info("Calendrier partagé", [
                'calendar_id' => $calendarId,
                'shared_with_user_id' => $input['user_id'],
                'shared_by_user_id' => $userId
            ]);
            LoggingMiddleware::logExit(200);
            Response::success('Calendrier partagé avec succès', [
                'share' => $shareResult
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors du partage du calendrier", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors du partage du calendrier', 500);
        }
    }
    
    public function createEvent($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        $validator = new Validator();
        $validation = $validator->validate($input, [
            'title' => 'required|string',
            'start_datetime' => 'required|date',
            'end_datetime' => 'required|date'
        ]);
        if(!$validation['valid']) {
            LogService::warning("Données d'événement invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        $cal = new Calendar();
        // Vérifier l'accès au calendrier
        $calendar = $cal->getById($calendarId);
        if (!$calendar || $calendar['user_id'] != $userId) {
            Response::error('Calendrier non trouvé', 404);
            return;
        }
            
        try {
            $event = new CalendarEvent();
            $event->id = $calendarId;
            $event->title = $input['title'];
            $event->startDatetime = $input['start_datetime'];
            $event->endDatetime = $input['end_datetime'];
            $event->allDay = $input['all_day'] ?? false;
            $event->location = $input['location'] ?? null;
            $event->organizerEmail = $input['organizer_email'] ?? null;
            $event->attendees = $input['attendees'] ?? [];
            $event->recurrenceRule = $input['recurrence_rule'] ?? null;
            $event->status = $input['status'] ?? 'confirmed';

            $event = $event->create();

            LogService::info("Événement créé", [
                'event_id' => $event['id'],
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(200);
            Response::success([
                'event' => $event,
                'message' => 'Événement créé avec succès'
            ], 201);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la création de l'événement", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la création de l\'événement', 500);
        }
    }

    public function getCalendarEvents($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();         
        $cal = new Calendar();
        // Vérifier l'accès au calendrier
        $calendar = $cal->getById($calendarId);
        if (!$calendar || $calendar['user_id'] != $userId) {
            logService::warning("Tentative d'accès aux événements d'un calendrier non possédé", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(404);
            Response::error('Calendrier non trouvé', 404);
            return;
        }
        
        try {
            $events = Calendar::getEventsForCalendar($calendarId);
            logService::info("Événements du calendrier récupérés", [
                'calendar_id' => $calendarId,
                'user_id' => $userId,
                'event_count' => count($events)
            ]);
            LoggingMiddleware::logExit(200);
            Response::success('Événements du calendrier récupérés avec succès', [
                'events' => $events,
                'count' => count($events)
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la récupération des événements", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération des événements', 500);
        }
        
    }

    public function getCalendarIcs($shareToken): void
    {
        LoggingMiddleware::logEntry();         
        try {
            $cal = new Calendar();
            $calendar = $cal->getByShareToken($shareToken);

            if (!$calendar) {
                LogService::warning("Calendrier non trouvé", [
                    'share_token' => $shareToken
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Calendrier non trouvé', 404);
                return;
            }
            
            $events = Calendar::getEventsForCalendar($calendar['id']);
            $icsContent = Calendar::generateIcsContent($calendar, $events);
            logService::info("ICS généré pour le calendrier public", [
                'calendar_id' => $calendar['id'],
                'share_token' => $shareToken
            ]);
            LoggingMiddleware::logExit(200);
            Response::sendIcs($icsContent, $calendar['title'] . '.ics');
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la génération du fichier ICS", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la génération du fichier ICS', 500);
        }
    }
}