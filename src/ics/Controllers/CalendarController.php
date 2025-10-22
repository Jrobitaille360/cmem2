<?php

namespace ICS\Controllers;

use ICS\Models\Calendar;
use ICS\Models\CalendarEvent;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Services\LogService;
use AuthGroups\Services\EmailService;
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
                'title' => 'required|string|max:100',
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
            Response::success('Calendrier créé avec succès', $result, 201);
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
     * Récupère un calendrier public partagé par token (accessible à tous avec le token)
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
            
            $events = $cal->getEventsForCalendar($calendar['id']);
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
            
            $events = $cal->getEventsForCalendar($calendar['id']);
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
     * Récupère le fichier ICS d'un calendrier par son ID pour un utilisateur authentifié
     * Vérifie les permissions : propriétaire, public, ou partagé
     */
    public function getCalendarIcsByIdAndUserId($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();         
        try {
            $cal = new Calendar();
            $permission = $cal->getUserPermissionForCalendar($calendarId, $userId);

            if (!$permission) {
                LogService::warning("Calendrier non trouvé ou accès non autorisé", [
                    'calendar_id' => $calendarId,
                    'user_id' => $userId
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Calendrier non trouvé', 404);
                return;
            }
            
            $events = $cal->getEventsForCalendar($calendarId);
            $icsContent = $cal->generateIcsContent($permission, $events);

            LogService::info("ICS généré pour le calendrier", [
                'calendar_id' => $calendarId,
                'user_id' => $userId,
                'access_level' => $permission['access_level']
            ]);
            LoggingMiddleware::logExit(200);
            Response::sendIcs($icsContent, $permission['title'] . '.ics');
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la génération du fichier ICS", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la génération du fichier ICS', 500);
        }
    }

    /**
     * Partage un calendrier avec un autre utilisateur ou par email
     */
    public function shareCalendar($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();  
        $input = Response::getRequestParams();
        $validator = new Validator();
        $validation = $validator->validate($input, [
            'user_id' => 'optional|integer',
            'email' => 'optional|email',
            'permission' => 'optional|string|in:read,write'
        ]);
        
        if(!$validation['valid']) {
            LogService::warning("Données de partage invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        // Vérifier qu'au moins user_id ou email est fourni
        if (!isset($input['user_id']) && !isset($input['email'])) {
            LoggingMiddleware::logExit(400);
            Response::error('Vous devez fournir soit user_id soit email', 400);
            return;
        }
        
        $cal = new Calendar();
        $calendar = $cal->getById($calendarId);
        
        // Vérifier que l'utilisateur possède le calendrier ou a les droits d'écriture
        if (!$calendar || !$cal->canUserWrite($calendarId, $userId)) {
            logService::warning("Tentative de partage d'un calendrier sans permission", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour partager ce calendrier', 403);
            return;
        }
        
        $permission = $input['permission'] ?? 'read';
        
        try {
            if (isset($input['user_id'])) {
                // Partage avec un utilisateur existant
                $shareResult = $cal->shareWith($calendarId, $input['user_id'], $permission);
                LogService::info("Calendrier partagé avec utilisateur", [
                    'calendar_id' => $calendarId,
                    'shared_with_user_id' => $input['user_id'],
                    'shared_by_user_id' => $userId,
                    'permission' => $permission
                ]);
            } else {
                // Partage par email
                $shareResult = $cal->shareWithEmail($calendarId, $input['email'], $permission);
                LogService::info("Calendrier partagé par email", [
                    'calendar_id' => $calendarId,
                    'shared_with_email' => $input['email'],
                    'shared_by_user_id' => $userId,
                    'permission' => $permission
                ]);
            }
            
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
    
    /**
     * Envoie le share_token d'un calendrier par email
     */
    public function sendCalendarTokenByEmail($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        $validator = new Validator();
        $validation = $validator->validate($input, [
            'email' => 'required|email',
            'permission' => 'optional|string|in:read,write',
            'message' => 'optional|string|max:500'
        ]);
        
        if(!$validation['valid']) {
            LogService::warning("Données d'envoi d'email invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }
        
        $cal = new Calendar();
        $calendar = $cal->getById($calendarId);
        
        // Vérifier que l'utilisateur a accès au calendrier
        $permission = $cal->getUserPermissionForCalendar($calendarId, $userId);
        if (!$permission) {
            LogService::warning("Tentative d'envoi de token pour un calendrier sans accès", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(403);
            Response::error('Accès refusé à ce calendrier', 403);
            return;
        }
        
        try {
            $emailService = new EmailService();
            $recipientEmail = $input['email'];
            $requestedPermission = $input['permission'] ?? 'read';
            $personalMessage = $input['message'] ?? '';
            
            // Récupérer le nom de l'utilisateur expéditeur (optionnel)
            $senderName = "Utilisateur"; // Vous pouvez enrichir cela avec les données utilisateur
            
            // Construire le contenu de l'email
            $subject = "Calendrier partagé : " . $calendar['title'];
            
            $calendarUrl = BASE_URL . '/calendar/' . $calendar['share_token'] . '.ics';
            $webUrl = BASE_URL . '/calendar/view/' . $calendar['share_token'];
            
            $permissionText = $requestedPermission === 'write' ? 'modification' : 'lecture seule';
            
            $emailBody = "
<!DOCTYPE html>
<html>
<body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
    <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
        <h2 style='color: #2c3e50;'>Calendrier partagé avec vous</h2>
        
        <p>Bonjour,</p>
        
        <p><strong>{$senderName}</strong> a partagé un calendrier avec vous.</p>
        
        <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
            <h3 style='margin-top: 0; color: #495057;'>Détails du calendrier :</h3>
            <ul style='list-style: none; padding: 0;'>
                <li><strong>Nom :</strong> {$calendar['title']}</li>
                <li><strong>Description :</strong> " . ($calendar['description'] ?: 'Aucune description') . "</li>
                <li><strong>Permission :</strong> {$permissionText}</li>
            </ul>
        </div>";
        
        if ($personalMessage) {
            $emailBody .= "
        <div style='background-color: #e3f2fd; padding: 15px; border-radius: 5px; margin: 20px 0;'>
            <h4 style='margin-top: 0; color: #1976d2;'>Message personnel :</h4>
            <p style='font-style: italic; margin-bottom: 0;'>" . htmlspecialchars($personalMessage) . "</p>
        </div>";
        }
        
        $emailBody .= "
        <div style='margin: 30px 0;'>
            <h3 style='color: #495057;'>Comment accéder au calendrier :</h3>
            
            <div style='margin: 15px 0;'>
                <strong>1. Ajouter à votre application de calendrier :</strong><br>
                <span style='background-color: #f1f3f4; padding: 5px; border-radius: 3px; font-family: monospace; word-break: break-all;'>{$calendarUrl}</span>
            </div>
            
            <div style='margin: 15px 0;'>
                <strong>2. Voir en ligne :</strong><br>
                <a href='{$webUrl}' style='color: #1976d2; text-decoration: none;'>{$webUrl}</a>
            </div>
        </div>
        
        <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; font-size: 12px; color: #6c757d;'>
            <p>Ce lien vous donne accès au calendrier selon les permissions qui vous ont été accordées.</p>
            <p>---<br>CMEM Calendar System</p>
        </div>
    </div>
</body>
</html>";

            $success = $emailService->sendEmail($recipientEmail, $subject, $emailBody, true);
            
            if ($success) {
                LogService::info("Token de calendrier envoyé par email", [
                    'calendar_id' => $calendarId,
                    'recipient' => $recipientEmail,
                    'sender_user_id' => $userId,
                    'permission' => $requestedPermission
                ]);
                LoggingMiddleware::logExit(200);
                Response::success('Token de calendrier envoyé par email avec succès', [
                    'sent_to' => $recipientEmail,
                    'calendar_title' => $calendar['title'],
                    'permission' => $requestedPermission
                ]);
            } else {
                LogService::error("Échec de l'envoi de l'email", [
                    'calendar_id' => $calendarId,
                    'recipient' => $recipientEmail
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de l\'envoi de l\'email', 500);
            }
        } catch (\Exception $e) {
            LogService::error("Erreur lors de l'envoi du token par email", [
                'exception' => $e->getMessage(),
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de l\'envoi de l\'email', 500);
        }
    }
    
    /**
     * Supprime un partage de calendrier par user_id ou email
     */
    public function removeCalendarShare($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        $validator = new Validator();
        $validation = $validator->validate($input, [
            'user_id' => 'optional|integer',
            'email' => 'optional|email'
        ]);
        
        if(!$validation['valid']) {
            LogService::warning("Données de suppression de partage invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        // Vérifier qu'au moins user_id ou email est fourni
        if (!isset($input['user_id']) && !isset($input['email'])) {
            LoggingMiddleware::logExit(400);
            Response::error('Vous devez fournir soit user_id soit email', 400);
            return;
        }

        $targetUserId = $input['user_id'] ?? null;
        $targetEmail = $input['email'] ?? null;
        
        $cal = new Calendar();
        
        // Vérifier les permissions de suppression
        if (!$cal->canUserRemoveShare($calendarId, $userId, $targetUserId, $targetEmail)) {
            LogService::warning("Tentative de suppression de partage sans permission", [
                'calendar_id' => $calendarId,
                'current_user_id' => $userId,
                'target_user_id' => $targetUserId,
                'target_email' => $targetEmail
            ]);
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour supprimer ce partage', 403);
            return;
        }

        // Vérifier que le partage existe
        $existingShare = $cal->findCalendarShare($calendarId, $targetUserId, $targetEmail);
        if (!$existingShare) {
            LogService::warning("Tentative de suppression d'un partage inexistant", [
                'calendar_id' => $calendarId,
                'target_user_id' => $targetUserId,
                'target_email' => $targetEmail
            ]);
            LoggingMiddleware::logExit(404);
            Response::error('Partage non trouvé', 404);
            return;
        }

        try {
            // Effectuer la suppression soft delete
            $result = $cal->removeShare($calendarId, $targetUserId, $targetEmail);
            
            if (!$result) {
                throw new \Exception("Échec de la suppression du partage");
            }

            LogService::info("Partage de calendrier supprimé", [
                'share_id' => $existingShare['id'],
                'calendar_id' => $calendarId,
                'removed_by_user_id' => $userId,
                'target_user_id' => $targetUserId,
                'target_email' => $targetEmail
            ]);

            LoggingMiddleware::logExit(200);
            Response::success('Partage de calendrier supprimé avec succès', [
                'share_id' => $existingShare['id'],
                'calendar_id' => $calendarId,
                'shared_with_user_id' => $existingShare['shared_with_user_id'],
                'shared_with_email' => $existingShare['shared_with_email'],
                'deleted_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la suppression du partage", [
                'exception' => $e->getMessage(),
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la suppression du partage', 500);
        }
    }
    
    public function createEvent($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        $validator = new Validator();
        $validation = $validator->validate($input, [
            'title' => 'required|string',
            'start_datetime' => 'required|date_or_datetime',
            'end_datetime' => 'required|date_or_datetime'
        ]);
        if(!$validation['valid']) {
            LogService::warning("Données d'événement invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }
        // Vérifier que les dates sont valides
        if (strtotime($input['end_datetime']) < strtotime($input['start_datetime'])) {
            LogService::warning("Dates d'événement invalides", [
                'start_datetime' => $input['start_datetime'],
                'end_datetime' => $input['end_datetime']
            ]);
            LoggingMiddleware::logExit(401);
            Response::error('La date de fin doit être après la date de début', 401);
            return;
        }
        // Vérifier validité de la récurence s'il y en a une
        if (isset($input['recurrence_rule']) && !CalendarEvent::isValidRecurrenceRule($input['recurrence_rule'])) {
            LogService::warning("Règle de récurrence invalide", [
                'recurrence_rule' => $input['recurrence_rule']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Règle de récurrence invalide', 400);
            return;
        }
        $cal = new Calendar();
        // Vérifier l'accès en écriture au calendrier
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LogService::warning("Tentative de création d'événement sans permission d'écriture", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour créer un événement dans ce calendrier', 403);
            return;
        }
            
        try {
            $event = new CalendarEvent();
            $event->calendarId = $calendarId;
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
            Response::success('Événement créé avec succès', $event, 201);
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
        // Vérifier l'accès au calendrier (lecture ou plus)
        $permission = $cal->getUserPermissionForCalendar($calendarId, $userId);
        if (!$permission) {
            logService::warning("Tentative d'accès aux événements d'un calendrier sans permission", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(403);
            Response::error('Accès refusé à ce calendrier', 403);
            return;
        }
        
        try {
            $input = Response::getRequestParams();
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'start_datetime' => 'optionnal|date_or_datetime',
                'end_datetime' => 'optionnal|date_or_datetime',
                'page' => 'optionnal|integer|min:1',
                'limit' => 'optionnal|integer|min:1|max:100'
            ]);
            $events = $cal->getEventsForCalendar($calendarId);
            if (isset($input['start_datetime'])) {
                $events = array_filter($events, function($event) use ($input) {
                    return strtotime($event['start_datetime']) >= strtotime($input['start_datetime']);
                });
            }
            if (isset($input['end_datetime'])) {
                $events = array_filter($events, function($event) use ($input) {
                    return strtotime($event['end_datetime']) <= strtotime($input['end_datetime']);
                });
            }
            if (isset($input['page']) && isset($input['limit'])) {
                $offset = ($input['page'] - 1) * $input['limit'];
                $events = array_slice($events, $offset, $input['limit']);
            }
            logService::info("Événements du calendrier récupérés", [
                'calendar_id' => $calendarId,
                'user_id' => $userId,
                'event_count' => count($events),
                'access_level' => $permission['access_level']
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
            
            $events = $cal->getEventsForCalendar($calendar['id']);
            $icsContent = $cal->generateIcsContent($calendar, $events);
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


        /**
     * Met à jour un calendrier existant
     */
    public function updateCalendar($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        
        // Validation
        $validator = new Validator();
        $validation = $validator->validate($input, [
            'title' => 'optionnal|string|max:100',
            'description' => 'optionnal|string|max:1000',
            'visibility' => 'optionnal|string|in:public,private',
            'max_members' => 'optionnal|integer|min:1|max:1000',
            'color' => 'optionnal|string|max:7',
            'timezone' => 'optionnal|string|max:100',
        ]);
        
        if (!$validation['valid']) {
            LogService::warning("Données de mise à jour invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }
        
        $cal = new Calendar();
        
        // Vérifier que l'utilisateur a les droits d'écriture sur le calendrier
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LogService::warning("Tentative de modification d'un calendrier sans permission d'écriture", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour modifier ce calendrier', 403);
            return;
        }
        
        $calendar = $cal->findById($calendarId);
        
        try {
            // Mettre à jour les propriétés de l'instance
            $updatedFields = [];
            if (isset($input['title'])) {
                $cal->title = $input['title'];
                $updatedFields[] = 'title';
            }
            if (isset($input['description'])) {
                $cal->description = $input['description'];
                $updatedFields[] = 'description';
            }
            if (isset($input['visibility'])) {
                $cal->visibility = $input['visibility'];
                $updatedFields[] = 'visibility';
            }
            if (isset($input['max_members'])) {
                $cal->maxMembers = $input['max_members'];
                $updatedFields[] = 'max_members';
            }
            if (isset($input['color'])) {
                $cal->color = $input['color'];
                $updatedFields[] = 'color';
            }
            if (isset($input['timezone'])) {
                $cal->timezone = $input['timezone'];
                $updatedFields[] = 'timezone';
            }
            
            // Appeler la méthode update() qui n'accepte pas de paramètres
            $result = $cal->update();
            
            if (!$result) {
                throw new \Exception("Échec de la mise à jour");
            }
            
            // Récupérer les données mises à jour
            $updatedCalendar = $cal->findById($calendarId);
            
            LogService::info("Calendrier mis à jour", [
                'calendar_id' => $calendarId,
                'user_id' => $userId,
                'updated_fields' => $updatedFields
            ]);
            LoggingMiddleware::logExit(200);
            Response::success('Calendrier mis à jour avec succès', $updatedCalendar);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la mise à jour du calendrier", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la mise à jour du calendrier', 500);
        }
    }

    /**
     * Supprime un calendrier (soft delete)
     */
    public function deleteCalendar($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        
        $cal = new Calendar();
        
        // Vérifier que l'utilisateur a les droits d'écriture sur le calendrier
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LogService::warning("Tentative de suppression d'un calendrier sans permission d'écriture", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour supprimer ce calendrier', 403);
            return;
        }
        
        $calendar = $cal->findById($calendarId);
        
        try {
            // Le trait SoftDeleteTrait nécessite que l'ID soit défini sur l'instance
            $cal->id = $calendarId;
            $result = $cal->softDelete();
            
            if (!$result) {
                throw new \Exception("Échec du soft delete");
            }
            
            LogService::info("Calendrier supprimé (soft delete)", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(200);
            Response::success('Calendrier supprimé avec succès', [
                'calendar_id' => $calendarId,
                'deleted_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la suppression du calendrier", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la suppression du calendrier', 500);
        }
    }

    /**
     * Supprime définitivement un calendrier (hard delete)
     */
    public function hardDeleteCalendar($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        
        $cal = new Calendar();
        
        // Vérifier que l'utilisateur a les droits d'écriture sur le calendrier
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LogService::warning("Tentative de suppression définitive d'un calendrier sans permission d'écriture", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour supprimer définitivement ce calendrier', 403);
            return;
        }
        
        $calendar = $cal->findById($calendarId);
        
        try {
            // Le trait SoftDeleteTrait nécessite que l'ID soit défini sur l'instance
            $cal->id = $calendarId;
            $result = $cal->forceDelete();
            
            if (!$result) {
                throw new \Exception("Échec du hard delete");
            }
            
            LogService::info("Calendrier supprimé définitivement (hard delete)", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(200);
            Response::success('Calendrier supprimé définitivement avec succès', [
                'calendar_id' => $calendarId
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la suppression définitive du calendrier", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la suppression définitive du calendrier', 500);
        }
    }

    /**
     * Met à jour un événement existant
     */
    public function updateEvent($eventId, $calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        
        // Validation
        $validator = new Validator();
        $validation = $validator->validate($input, [
            'title' => 'optionnal|string',
            'start_datetime' => 'optionnal|date_or_datetime',
            'end_datetime' => 'optionnal|date_or_datetime',
            'description' => 'optionnal|string',
            'all_day' => 'optionnal|boolean',
            'location' => 'optionnal|string',
            'organizer_email' => 'optionnal|email',
            'recurrence_rule' => 'optionnal|string',
            'status' => 'optionnal|string|in:confirmed,tentative,cancelled'
        ]);
        
        if (!$validation['valid']) {
            LogService::warning("Données de mise à jour d'événement invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }
        
        // Vérifier que les dates sont valides si fournies
        if (isset($input['start_datetime']) && isset($input['end_datetime'])) {
            if (strtotime($input['end_datetime']) < strtotime($input['start_datetime'])) {
                LogService::warning("Dates d'événement invalides", [
                    'start_datetime' => $input['start_datetime'],
                    'end_datetime' => $input['end_datetime']
                ]);
                LoggingMiddleware::logExit(401);
                Response::error('La date de fin doit être après la date de début', 401);
                return;
            }
        }
        
        // Vérifier validité de la récurrence s'il y en a une
        if (isset($input['recurrence_rule']) && !CalendarEvent::isValidRecurrenceRule($input['recurrence_rule'])) {
            LogService::warning("Règle de récurrence invalide", [
                'recurrence_rule' => $input['recurrence_rule']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Règle de récurrence invalide', 400);
            return;
        }
        
        $cal = new Calendar();
        
        // Vérifier l'accès en écriture au calendrier  
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour modifier les événements de ce calendrier', 403);
            return;
        }
        
        $calendar = $cal->findById($calendarId);
        
        $event = new CalendarEvent();
        $existingEvent = $event->findById($eventId);
        
        // Vérifier que l'événement existe et appartient au calendrier
        if (!$existingEvent || $existingEvent['calendar_id'] != $calendarId) {
            LogService::warning("Événement non trouvé ou non associé au calendrier", [
                'event_id' => $eventId,
                'calendar_id' => $calendarId
            ]);
            LoggingMiddleware::logExit(404);
            Response::error('Événement non trouvé', 404);
            return;
        }
        
        try {
            // Mettre à jour les propriétés de l'instance
            $updatedFields = [];
            if (isset($input['title'])) {
                $event->title = $input['title'];
                $updatedFields[] = 'title';
            }
            if (isset($input['start_datetime'])) {
                $event->startDatetime = $input['start_datetime'];
                $updatedFields[] = 'start_datetime';
            }
            if (isset($input['end_datetime'])) {
                $event->endDatetime = $input['end_datetime'];
                $updatedFields[] = 'end_datetime';
            }
            if (isset($input['description'])) {
                $event->description = $input['description'];
                $updatedFields[] = 'description';
            }
            if (isset($input['all_day'])) {
                $event->allDay = $input['all_day'];
                $updatedFields[] = 'all_day';
            }
            if (isset($input['location'])) {
                $event->location = $input['location'];
                $updatedFields[] = 'location';
            }
            if (isset($input['organizer_email'])) {
                $event->organizerEmail = $input['organizer_email'];
                $updatedFields[] = 'organizer_email';
            }
            if (isset($input['attendees'])) {
                $event->attendees = $input['attendees'];
                $updatedFields[] = 'attendees';
            }
            if (isset($input['recurrence_rule'])) {
                $event->recurrenceRule = $input['recurrence_rule'];
                $updatedFields[] = 'recurrence_rule';
            }
            if (isset($input['status'])) {
                $event->status = $input['status'];
                $updatedFields[] = 'status';
            }
            
            // Appeler la méthode update() qui n'accepte pas de paramètres
            $result = $event->update();
            
            if (!$result) {
                throw new \Exception("Échec de la mise à jour");
            }
            
            // Récupérer les données mises à jour
            $updatedEvent = $event->findById($eventId);
            
            LogService::info("Événement mis à jour", [
                'event_id' => $eventId,
                'calendar_id' => $calendarId,
                'user_id' => $userId,
                'updated_fields' => $updatedFields
            ]);
            LoggingMiddleware::logExit(200);
            Response::success('Événement mis à jour avec succès', $updatedEvent);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la mise à jour de l'événement", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la mise à jour de l\'événement', 500);
        }
    }

    /**
     * Supprime un événement (soft delete)
     */
    public function deleteEvent($eventId, $calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        
        $cal = new Calendar();
        
        // Vérifier l'accès en écriture au calendrier
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour supprimer les événements de ce calendrier', 403);
            return;
        }
        
        $event = new CalendarEvent();
        $existingEvent = $event->findById($eventId);
        
        // Vérifier que l'événement existe et appartient au calendrier
        if (!$existingEvent || $existingEvent['calendar_id'] != $calendarId) {
            LogService::warning("Événement non trouvé ou non associé au calendrier", [
                'event_id' => $eventId,
                'calendar_id' => $calendarId
            ]);
            LoggingMiddleware::logExit(404);
            Response::error('Événement non trouvé', 404);
            return;
        }
        
        try {
            // Le trait SoftDeleteTrait nécessite que l'ID soit défini sur l'instance
            $event->id = $eventId;
            $result = $event->softDelete();
            
            if (!$result) {
                throw new \Exception("Échec du soft delete");
            }
            
            LogService::info("Événement supprimé (soft delete)", [
                'event_id' => $eventId,
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(200);
            Response::success('Événement supprimé avec succès', [
                'event_id' => $eventId,
                'deleted_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la suppression de l'événement", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la suppression de l\'événement', 500);
        }
    }

    /**
     * Supprime définitivement un événement (hard delete)
     */
    public function hardDeleteEvent($eventId, $calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        
        $cal = new Calendar();
        
        // Vérifier l'accès en écriture au calendrier
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour supprimer définitivement les événements de ce calendrier', 403);
            return;
        }
        
        $event = new CalendarEvent();
        $existingEvent = $event->findById($eventId);
        
        // Vérifier que l'événement existe et appartient au calendrier
        if (!$existingEvent || $existingEvent['calendar_id'] != $calendarId) {
            LogService::warning("Événement non trouvé ou non associé au calendrier", [
                'event_id' => $eventId,
                'calendar_id' => $calendarId
            ]);
            LoggingMiddleware::logExit(404);
            Response::error('Événement non trouvé', 404);
            return;
        }
        
        try {
            // Le trait SoftDeleteTrait nécessite que l'ID soit défini sur l'instance
            $event->id = $eventId;
            $result = $event->forceDelete();
            
            if (!$result) {
                throw new \Exception("Échec du hard delete");
            }
            
            LogService::info("Événement supprimé définitivement (hard delete)", [
                'event_id' => $eventId,
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(200);
            Response::success('Événement supprimé définitivement avec succès', [
                'event_id' => $eventId
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la suppression définitive de l'événement", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la suppression définitive de l\'événement', 500);
        }
    }

}
