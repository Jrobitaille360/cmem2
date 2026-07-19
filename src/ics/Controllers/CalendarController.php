<?php

namespace ICS\Controllers;

use ICS\Models\Calendar;
use ICS\Models\CalendarEvent;
use ICS\Utils\TimezoneHelper;
use ICS\Services\EmailNotificationService;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Utils\ColorName;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Services\LogService;
use AuthGroups\Services\EmailService;
use AuthGroups\Models\User;
use AuthGroups\Models\Group;
use PharIo\Manifest\Email;
use Stripe\Services\EntitlementService;

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
        $validation = Validator::validate($input, [
                'title' => 'required|string|max:100',
                'description' => 'optionnal|string|max:1000',
                'visibility' => 'optionnal|string|in:public,private',
                'max_members' => 'optionnal|integer|min:1|max:1000',
                'color' => 'optionnal|color',
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
            
            // Valider le timezone si fourni
            if (isset($input['timezone']) && !TimezoneHelper::isValidTimezone($input['timezone'])) {
                LogService::warning("Timezone invalide", [
                    'timezone' => $input['timezone']
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Le timezone fourni est invalide', ['timezone' => 'Le timezone spécifié n\'est pas valide'], 400);
                return;
            }

            $quotaError = EntitlementService::checkQuota(
                $userId,
                'max_calendars',
                (new Calendar())->countOwnedByUserId($userId)
            );
            if ($quotaError) {
                LoggingMiddleware::logExit(403);
                Response::error('Quota de calendriers atteint', $quotaError, 403);
                return;
            }

        try {
            $cal = new Calendar();
            $cal->userId = $userId;
            $cal->title = $input['title'];
            $cal->description = $input['description'] ?? '';
            $cal->visibility = $input['visibility'] ?? 'private';
            $cal->maxMembers = $input['max_members'] ?? 1000;
            
            // Normaliser la couleur au format #RRGGBB
            if (isset($input['color'])) {
                $colorRgb = ColorName::stringToColor($input['color']);
                $cal->color = ColorName::colorToHexString($colorRgb);
            } else {
                $cal->color = '#3174ad';
            }
            
            $cal->timezone = $input['timezone'] ?? 'America/Montreal';

            $result = $cal->create();
            LoggingMiddleware::logExit(201);
            Response::success('Calendrier créé avec succès', $result, 201);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la création du calendrier", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la création du calendrier', null, 500);
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
            Response::error('Erreur lors de la récupération des calendriers', null, 500);
        }
    }

    /**
     * Récupère les événements d'un calendrier spécifique.
     * SI expand_multi_jour est true, les événements multi-jours sont développés en occurrences journalières.
     * si expand_recurrence est true, les événements récurrents sont développés en occurrences.
     */
    public function getCalendarEvents($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
    
        // Validation
        $validation = Validator::validate($input, [
                'start' => 'optional|date_or_datetime',
                'end' => 'optional|date_or_datetime',
                'last_update_after' => 'optional|datetime',
                'limit' => 'optional|integer|min:1',
                'expand_multi_jour' => 'optional|boolean',
                'expand_recurrence' => 'optional|boolean',
            ]);

        if (!$validation['valid']) {
            LogService::warning("Données de création invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        $start_datetime = $input['start'] ?? null;
        $end_datetime = $input['end'] ?? null;
        $last_update_after = $input['last_update_after'] ?? null;
        $expand_multi_jour = $input['expand_multi_jour'] ?? true;
        $expand_recurrence = $input['expand_recurrence'] ?? true;
        $limit = $input['limit'] ?? 10000;
        try {
            $cal = new Calendar();
            // Vérifier si l'utilisateur a accès au calendrier
            $permission = $cal->getUserPermissionForCalendar($calendarId, $userId);

            if (!$permission) {
                LogService::warning("Accès non autorisé ou calendrier non trouvé", [
                    'calendar_id' => $calendarId,
                    'user_id' => $userId
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Calendrier non trouvé ou accès non autorisé', null, 404);
                return;
            }

            $eventModel = new CalendarEvent();
            $events = $eventModel->getByCalendarId($calendarId, $start_datetime, $end_datetime, expandRecurrence: $expand_recurrence, lastUpdateAfter: $last_update_after, expandMultiJour:$expand_multi_jour, limit: $limit);

            LoggingMiddleware::logExit(200);
            Response::success('Événements du calendrier récupérés avec succès', [
                'events' => $events,
                'count' => count($events)
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la récupération des événements du calendrier", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération des événements', null, 500);
        }
    }
    
    public function getEvent($eventId, $calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();

        try {
            $cal = new Calendar();
            // Vérifier si l'utilisateur a accès au calendrier
            $permission = $cal->getUserPermissionForCalendar($calendarId, $userId);

            if (!$permission) {
                LogService::warning("Accès non autorisé ou calendrier non trouvé", [
                    'calendar_id' => $calendarId,
                    'user_id' => $userId
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Calendrier non trouvé ou accès non autorisé', null, 404);
                return;
            }

            $eventModel = new CalendarEvent();

            $event = $eventModel->getEventById($eventId);
            
            if(!$event || $event['calendar_id'] != $calendarId) {
                LogService::warning("Événement non trouvé dans le calendrier", [
                    'event_id' => $eventId,
                    'calendar_id' => $calendarId
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Événement non trouvé dans ce calendrier', null, 404);
                return;
            }

            LoggingMiddleware::logExit(200);
            Response::success('Événement du calendrier récupéré avec succès', [
                'event' => $event,
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la récupération de l'événement du calendrier", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération de l\'événement', null, 500);
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
                Response::error('Calendrier non trouvé', null, 404);
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
            Response::error('Erreur lors de la génération du fichier ICS', null, 500);
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
                Response::error('Calendrier non trouvé', null, 404);
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
            Response::error('Erreur lors de la génération du fichier ICS', null, 500);
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
                Response::error('Calendrier non trouvé', null, 404);
                return;
            }
            
            $events   = $cal->getEventsForCalendar($calendarId);
            $todos    = (new \ICS\Models\CalendarTodo())->getByCalendarId($calendarId);
            $journals = (new \ICS\Models\CalendarJournal())->getByCalendarId($calendarId);
            $icsContent = \ICS\Utils\IcsGenerator::generateCalendar($permission, $events, null, $todos, $journals);

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
            Response::error('Erreur lors de la génération du fichier ICS', null, 500);
        }
    }

    /**
     * Partage un calendrier avec un autre utilisateur ou par email
     */
    public function shareCalendar($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        $validation = Validator::validate($input, [
            'user_id' => 'optional|integer',
            'email' => 'optional|email',
            'group_id' => 'optional|integer',
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

        // Vérifier qu'au moins un et un seul de user_id / email / group_id est fourni
        $targetsProvided = (int)isset($input['user_id']) + (int)isset($input['email']) + (int)isset($input['group_id']);
        if ($targetsProvided === 0) {
            LoggingMiddleware::logExit(400);
            Response::error('Vous devez fournir soit user_id, soit email, soit group_id', null, 400);
            return;
        }
        if ($targetsProvided > 1) {
            LoggingMiddleware::logExit(400);
            Response::error('Vous ne pouvez fournir qu\'un seul de user_id, email ou group_id', null, 400);
            return;
        }

        $cal = new Calendar();
        $calendar = $cal->getById($calendarId);

        // Partage avec un groupe
        if (isset($input['group_id'])) {
            if (!$calendar || !$cal->canUserWrite($calendarId, $userId)) {
                LogService::warning("Tentative de partage d'un calendrier sans permission", [
                    'calendar_id' => $calendarId,
                    'user_id' => $userId
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Permission insuffisante pour partager ce calendrier', null, 403);
                return;
            }

            $groupModel = new Group();
            $group = $groupModel->findById($input['group_id']);
            if (!$group) {
                LoggingMiddleware::logExit(400);
                Response::error('Groupe spécifié introuvable', null, 400);
                return;
            }

            $permission = $input['permission'] ?? 'read';

            try {
                $shareResult = $cal->shareWithGroup($calendarId, $input['group_id'], $permission);

                LogService::info("Calendrier partagé avec groupe", [
                    'calendar_id' => $calendarId,
                    'shared_with_group_id' => $input['group_id'],
                    'shared_by_user_id' => $userId,
                    'permission' => $permission
                ]);

                LoggingMiddleware::logExit(200);
                Response::success('Calendrier partagé avec succès', [
                    'share' => $shareResult
                ]);
            } catch (\Exception $e) {
                LogService::error("Erreur lors du partage du calendrier avec un groupe", [
                    'exception' => $e->getMessage()
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors du partage du calendrier', null, 500);
            }
            return;
        }

        // find user by userid or email
        $targetUser = null;
        if (isset($input['user_id'])) {
            $userModel = new User();
            $targetUser = $userModel->findById($input['user_id']);
            if (!$targetUser) {
                LoggingMiddleware::logExit(400);
                Response::error('Utilisateur spécifié introuvable', null, 400);
                return;
            }
        } elseif (isset($input['email'])) {
            $userModel = new User();
            $targetUser = $userModel->findByEmail($input['email']);
            if ($targetUser) {
                // Si l'utilisateur existe, utiliser son ID pour le partage
                $input['user_id'] = $targetUser['id'];
                unset($input['email']);
            }
        }

        // Vérifier que l'utilisateur ne partage pas avec lui-même
        if ($targetUser && $targetUser['id'] == $userId) {
            LoggingMiddleware::logExit(400);
            Response::error('Vous ne pouvez pas partager un calendrier avec vous-même', null, 400);
            return;
        }


        // Vérifier que l'utilisateur possède le calendrier ou a les droits d'écriture
        if (!$calendar || !$cal->canUserWrite($calendarId, $userId)) {
            logService::warning("Tentative de partage d'un calendrier sans permission", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour partager ce calendrier', null, 403);
            return;
        }
        
        $permission = $input['permission'] ?? 'read';
        
        try {
            $shareResult = $cal->shareWith($calendarId, $targetUser['id'], $targetUser['email'], $permission);

            LogService::info("Calendrier partagé avec utilisateur", [
                    'calendar_id' => $calendarId,
                    'shared_with_user_id' => $input['user_id'],
                    'shared_with_email' => $targetUser['email'],
                    'shared_by_user_id' => $userId,
                    'permission' => $permission
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
            Response::error('Erreur lors du partage du calendrier', null, 500);
        }
    }
    
    /**
     * Envoie le share_token d'un calendrier par email
     */
    public function sendCalendarTokenByEmail($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        $validation = Validator::validate($input, [
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
            Response::error('Accès refusé à ce calendrier', null, 403);
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
                Response::error('Erreur lors de l\'envoi de l\'email', null, 500);
            }
        } catch (\Exception $e) {
            LogService::error("Erreur lors de l'envoi du token par email", [
                'exception' => $e->getMessage(),
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de l\'envoi de l\'email', null, 500);
        }
    }
    
    /**
     * Supprime un partage de calendrier par user_id ou email
     */
    public function removeCalendarShare($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        $validation = Validator::validate($input, [
            'user_id' => 'optional|integer',
            'email' => 'optional|email',
            'group_id' => 'optional|integer'
        ]);

        if(!$validation['valid']) {
            LogService::warning("Données de suppression de partage invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        // Vérifier qu'au moins user_id, email ou group_id est fourni
        if (!isset($input['user_id']) && !isset($input['email']) && !isset($input['group_id'])) {
            LoggingMiddleware::logExit(400);
            Response::error('Vous devez fournir soit user_id, soit email, soit group_id', null, 400);
            return;
        }

        $targetUserId = $input['user_id'] ?? null;
        $targetEmail = $input['email'] ?? null;
        $targetGroupId = $input['group_id'] ?? null;

        $cal = new Calendar();

        // Vérifier les permissions de suppression
        if (!$cal->canUserRemoveShare($calendarId, $userId, $targetUserId, $targetEmail, $targetGroupId)) {
            LogService::warning("Tentative de suppression de partage sans permission", [
                'calendar_id' => $calendarId,
                'current_user_id' => $userId,
                'target_user_id' => $targetUserId,
                'target_email' => $targetEmail,
                'target_group_id' => $targetGroupId
            ]);
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour supprimer ce partage', null, 403);
            return;
        }

        // Vérifier que le partage existe
        $existingShare = $cal->findCalendarShare($calendarId, $targetUserId, $targetEmail, $targetGroupId);
        if (!$existingShare) {
            LogService::warning("Tentative de suppression d'un partage inexistant", [
                'calendar_id' => $calendarId,
                'target_user_id' => $targetUserId,
                'target_email' => $targetEmail,
                'target_group_id' => $targetGroupId
            ]);
            LoggingMiddleware::logExit(404);
            Response::error('Partage non trouvé', null, 404);
            return;
        }

        try {
            // Effectuer la suppression soft delete
            $result = $cal->removeShare($calendarId, $targetUserId, $targetEmail, $targetGroupId);

            if (!$result) {
                throw new \Exception("Échec de la suppression du partage");
            }

            LogService::info("Partage de calendrier supprimé", [
                'share_id' => $existingShare['id'],
                'calendar_id' => $calendarId,
                'removed_by_user_id' => $userId,
                'target_user_id' => $targetUserId,
                'target_email' => $targetEmail,
                'target_group_id' => $targetGroupId
            ]);

            LoggingMiddleware::logExit(200);
            Response::success('Partage de calendrier supprimé avec succès', [
                'share_id' => $existingShare['id'],
                'calendar_id' => $calendarId,
                'shared_with_user_id' => $existingShare['shared_with_user_id'],
                'shared_with_email' => $existingShare['shared_with_email'],
                'shared_with_group_id' => $existingShare['shared_with_group_id'] ?? null,
                'deleted_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la suppression du partage du calendrier", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la suppression du partage du calendrier', null, 500);
        }
    }

    /**
     * récupère les partages d'un calendrier
     */
    public function getCalendarShares($calendarId, $userId): void
    {   
        LoggingMiddleware::logEntry();         
        try {
            $cal = new Calendar();
            // Vérifier que l'utilisateur possède le calendrier ou a les droits d'écriture
            if (!$cal->canUserWrite($calendarId, $userId)) {
                logService::warning("Tentative de récupération des partages d'un calendrier sans permission", [
                    'calendar_id' => $calendarId,
                    'user_id' => $userId
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Permission insuffisante pour voir les partages de ce calendrier', null, 403);
                return;
            }

            $shares = $cal->getSharesForCalendar($calendarId);
            LoggingMiddleware::logExit(200);
            Response::success('Partages du calendrier récupérés avec succès', [
                'shares' => $shares,
                'count' => count($shares)
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la récupération des partages du calendrier", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération des partages du calendrier', null, 500);
        }
    }


    /**
     * Importe un calendrier complet depuis un fichier ICS.
     */
    public function importIcsFile($userId): void
    {
        LoggingMiddleware::logEntry();

        if (!isset($_FILES['icsfile']) || $_FILES['icsfile']['error'] !== UPLOAD_ERR_OK) {
            LogService::warning("Aucun fichier ICS n'a été envoyé ou une erreur s'est produite.", []);
            LoggingMiddleware::logExit(400);
            Response::error('Aucun fichier ICS n\'a été envoyé ou une erreur s\'est produite.', null, 400);
            return;
        }

        $icsFilePath = $_FILES['icsfile']['tmp_name'];
        $icsContent = file_get_contents($icsFilePath);

        try {
            $calendarModel = new Calendar();
            $newCalendar = $calendarModel->createFromIcs($userId, $icsContent);

            LoggingMiddleware::logExit(201);
            Response::success("Calendrier importé avec succès.", $newCalendar, 201);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de l'importation du fichier ICS", ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de l\'importation du fichier ICS: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Met à jour un calendrier existant depuis un fichier ICS uploadé.
     * Les événements sont upsertés par UID : mise à jour si connu, création sinon.
     * Nécessite un accès en écriture sur le calendrier.
     */
    public function importIcsFileToCalendar(int $calendarId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        if (!isset($_FILES['icsfile']) || $_FILES['icsfile']['error'] !== UPLOAD_ERR_OK) {
            LogService::warning("Aucun fichier ICS envoyé ou erreur d'upload.", []);
            LoggingMiddleware::logExit(400);
            Response::error('Aucun fichier ICS n\'a été envoyé ou une erreur s\'est produite.', null, 400);
            return;
        }

        $icsContent = file_get_contents($_FILES['icsfile']['tmp_name']);

        try {
            $calendarModel = new Calendar();
            $result = $calendarModel->updateFromIcs($calendarId, $userId, $icsContent);

            LoggingMiddleware::logExit(200);
            Response::success("Calendrier mis à jour depuis le fichier ICS.", $result, 200);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la mise à jour du calendrier depuis ICS", [
                'calendar_id' => $calendarId,
                'exception'   => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la mise à jour depuis ICS: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Récupère le contenu d'un calendrier au format ICS via un token de partage.
     * @param string $shareToken Le token de partage unique.
     */
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
                Response::error('Calendrier non trouvé', null, 404);
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
            Response::error('Erreur lors de la génération du fichier ICS', null, 500);
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
        $validation = Validator::validate($input, [
            'title' => 'optionnal|string|max:100',
            'description' => 'optionnal|string|max:1000',
            'visibility' => 'optionnal|string|in:public,private',
            'max_members' => 'optionnal|integer|min:1|max:1000',
            'color' => 'optionnal|color',
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
        
        // Valider le timezone si fourni
        if (isset($input['timezone']) && !TimezoneHelper::isValidTimezone($input['timezone'])) {
            LogService::warning("Timezone invalide lors de la mise à jour", [
                'timezone' => $input['timezone']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Le timezone fourni est invalide', ['timezone' => 'Le timezone spécifié n\'est pas valide'], 400);
            return;
        }
        
        $cal = new Calendar();

        $calendar = $cal->findById($calendarId);

        if (!$calendar) {
            LoggingMiddleware::logExit(404);
            Response::error('Calendrier non trouvé', null, 404);
            return;
        }

        // Vérifier que l'utilisateur a les droits d'écriture sur le calendrier
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LogService::warning("Tentative de modification d'un calendrier sans permission d'écriture", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour modifier ce calendrier', null, 403);
            return;
        }
        
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
                // Normaliser la couleur au format #RRGGBB
                $colorRgb = ColorName::stringToColor($input['color']);
                $cal->color = ColorName::colorToHexString($colorRgb);
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
            Response::error('Erreur lors de la mise à jour du calendrier', null, 500);
        }
    }

    /**
     * Supprime un calendrier (soft delete)
     */
    public function deleteCalendar($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        
        $cal = new Calendar();

        $calendar = $cal->findById($calendarId);

        if (!$calendar) {
            LoggingMiddleware::logExit(404);
            Response::error('Calendrier non trouvé', null, 404);
            return;
        }

        // Vérifier que l'utilisateur a les droits d'écriture sur le calendrier
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LogService::warning("Tentative de suppression d'un calendrier sans permission d'écriture", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour supprimer ce calendrier', null, 403);
            return;
        }
        
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
            Response::error('Erreur lors de la suppression du calendrier', null, 500);
        }
    }

    /**
     * Supprime définitivement un calendrier (hard delete)
     */
    public function hardDeleteCalendar($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        
        $cal = new Calendar();

        if (!$cal->isOwnerIncludingDeleted($calendarId, $userId)) {
            // Distingue 404 (n'existe pas) de 403 (existe mais pas propriétaire)
            $exists = $cal->findById($calendarId);
            if (!$exists) {
                LoggingMiddleware::logExit(404);
                Response::error('Calendrier non trouvé', null, 404);
                return;
            }
            LogService::warning("Tentative de suppression définitive d'un calendrier sans permission d'écriture", [
                'calendar_id' => $calendarId,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour supprimer définitivement ce calendrier', null, 403);
            return;
        }
        
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
            Response::error('Erreur lors de la suppression définitive du calendrier', null, 500);
        }
    }

    /**
     * Crée un nouvel événement dans un calendrier
     */
    public function createEvent($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        // Validation des champs de base
        $validation = Validator::validate($input, [
            'title' => 'required|string',
            'user_id' => 'optional|integer',
            'start_datetime' => 'required|date_or_datetime',
            'end_datetime' => 'optional|date_or_datetime',  // optionnel si duration fourni (Ph4)
            'description' => 'optional|string',
            'all_day' => 'optional|boolean',
            'location' => 'optional|string',
            'attendees' => 'optional|array',
            'recurrence_rule' => 'optional|string',
            'status' => 'optional|string|in:confirmed,tentative,cancelled',
            // Phase 2
            'priority' => 'optional|integer',
            'class' => 'optional|string|in:PUBLIC,PRIVATE,CONFIDENTIAL',
            'transp' => 'optional|string|in:OPAQUE,TRANSPARENT',
            'categories' => 'optional|array',
            'geo_lat' => 'optional|numeric',
            'geo_lng' => 'optional|numeric',
            'attachments' => 'optional|array',
        ]);

        if (!$validation['valid']) {
            LogService::warning("Données de création d'événement invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        // Validation des nouveaux champs
        $eventValidation = \ICS\Utils\EventValidator::validateEventFields($input);
        if (!$eventValidation['valid']) {
            LogService::warning("Validation des nouveaux champs échouée", [
                'errors' => $eventValidation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $eventValidation['errors'], 400);
            return;
        }

        // Vérifier que la date de fin est après la date de début (sauf si duration fourni)
        $hasDuration = !empty($input['duration']);
        if (!$hasDuration && empty($input['end_datetime'])) {
            LoggingMiddleware::logExit(400);
            Response::error('end_datetime est requis quand duration n\'est pas fourni', null, 400);
            return;
        }
        if (!$hasDuration && isset($input['end_datetime']) && strtotime($input['end_datetime']) < strtotime($input['start_datetime'])) {
            LogService::warning("Dates d'événement invalides", [
                'start_datetime' => $input['start_datetime'],
                'end_datetime' => $input['end_datetime']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('La date de fin doit être après la date de début', null, 400);
            return;
        }
        if (!$hasDuration && $input['end_datetime'] === $input['start_datetime'] && empty($input['all_day'])) {
            // autoriser quand même — certains clients envoient dtstart=dtend pour un instant
        }

        // Vérifier validité de la récurrence s'il y en a une
        if (isset($input['recurrence_rule']) && !CalendarEvent::isValidRecurrenceRule($input['recurrence_rule'])) {
            LogService::warning("Règle de récurrence invalide", [
                'recurrence_rule' => $input['recurrence_rule']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Règle de récurrence invalide', null, 400);
            return;
        }

        $cal = new Calendar();

        // Vérifier l'accès en écriture au calendrier

        $droit = $cal->canUserWrite($calendarId, $userId); 

        if (!$droit) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour ajouter un événement à ce calendrier', null, 403);
            return;
        }

        try {
            $event = new CalendarEvent();
            $event->calendarId = $calendarId;
            $event->userId = $userId;
            $event->title = $input['title'];
            $event->startDatetime = $input['start_datetime'];

            // Phase 4.5 — si duration fourni sans end_datetime, calculer end_datetime
            if (!empty($input['duration']) && empty($input['end_datetime'])) {
                try {
                    $dtStart = new \DateTime($input['start_datetime']);
                    $dtStart->add(new \DateInterval($input['duration']));
                    $event->endDatetime = $dtStart->format('Y-m-d H:i:s');
                } catch (\Exception $dtEx) {
                    $event->endDatetime = $input['start_datetime']; // fallback
                }
            } else {
                $event->endDatetime = $input['end_datetime'];
            }

            $event->description = $input['description'] ?? null;
            $event->allDay = $input['all_day'] ?? false;
            $event->location = $input['location'] ?? null;
            $event->attendees = $input['attendees'] ?? null;
            $event->organizerEmail = $input['organizer_email'] ?? null;
            $event->organizerName  = $input['organizer_name']  ?? null;
            $event->recurrenceRule = $input['recurrence_rule'] ?? null;
            $event->status = $input['status'] ?? 'confirmed';
            
            // Nouveaux champs
            $event->timezone = $eventValidation['data']['timezone'] ?? 'America/Montreal';
            $event->meetingLink = $eventValidation['data']['meeting_link'] ?? null;
            $event->notifications = $eventValidation['data']['notifications'] ?? null;
            $event->color = $eventValidation['data']['color'] ?? null;
            // Phase 2
            if (isset($eventValidation['data']['priority']))    $event->priority    = $eventValidation['data']['priority'];
            if (isset($eventValidation['data']['class']))       $event->class       = $eventValidation['data']['class'];
            if (isset($eventValidation['data']['transp']))      $event->transp      = $eventValidation['data']['transp'];
            if (isset($eventValidation['data']['categories']))  $event->categories  = $eventValidation['data']['categories'];
            if (isset($eventValidation['data']['geo_lat']))     $event->geoLat      = $eventValidation['data']['geo_lat'];
            if (isset($eventValidation['data']['geo_lng']))     $event->geoLng      = $eventValidation['data']['geo_lng'];
            if (isset($eventValidation['data']['attachments'])) $event->attachments = $eventValidation['data']['attachments'];
            // Phase 4
            if (isset($eventValidation['data']['duration']))   $event->duration  = $eventValidation['data']['duration'];
            if (isset($eventValidation['data']['related_to'])) $event->relatedTo = $eventValidation['data']['related_to'];
            if (isset($eventValidation['data']['rdate']))      $event->rdate     = $eventValidation['data']['rdate'];

            $result = $event->create();

            // Planifier les notifications email (§1.1 spec)
            try {
                EmailNotificationService::scheduleEmailsForEvent($result, $userId);
            } catch (\Exception $notifEx) {
                LogService::warning("Échec planification notifications email (création)", [
                    'event_id' => $result['id'] ?? null,
                    'error'    => $notifEx->getMessage(),
                ]);
            }

            // Phase 3.4 — Invitations email aux attendees (iTIP METHOD:REQUEST)
            if (!empty($result['attendees'])) {
                try {
                    $calRow = (new Calendar())->getById($calendarId);
                    $calTz  = $calRow['timezone'] ?? 'America/Montreal';

                    // Enrichir avec les infos organisateur depuis l'utilisateur courant si non surchargées
                    if (empty($result['organizer_email'])) {
                        $userModel = new User();
                        $user = $userModel->findById($userId);
                        if ($user) {
                            $result['organizer_email'] = $user['email'] ?? null;
                            $result['organizer_name']  = $user['name']  ?? null;
                        }
                    }

                    EmailNotificationService::sendInvitationEmails($result, $calTz);
                } catch (\Exception $invitEx) {
                    LogService::warning("Échec envoi invitations email (création)", [
                        'event_id' => $result['id'] ?? null,
                        'error'    => $invitEx->getMessage(),
                    ]);
                }
            }


            LoggingMiddleware::logExit(201);
            Response::success('Événement créé avec succès', $result, 201);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la création de l'événement", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la création de l\'événement', null, 500);
        }
    }

    /**
     * Met à jour un événement existant
     */
    public function updateEvent($eventId, $calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        
        // Validation des champs de base
        $validation = Validator::validate($input, [
            'title' => 'optional|string',
            'user_id' => 'optional|integer',
            'start_datetime' => 'optional|date_or_datetime',
            'end_datetime' => 'optional|date_or_datetime',
            'description' => 'optional|string',
            'all_day' => 'optional|boolean',
            'location' => 'optional|string',
            'color' => 'optional|color',
            'recurrence_rule' => 'optional|string',
            'status' => 'optional|string|in:confirmed,tentative,cancelled',
            // Phase 2
            'priority' => 'optional|integer',
            'class' => 'optional|string|in:PUBLIC,PRIVATE,CONFIDENTIAL',
            'transp' => 'optional|string|in:OPAQUE,TRANSPARENT',
            'categories' => 'optional|array',
            'geo_lat' => 'optional|numeric',
            'geo_lng' => 'optional|numeric',
            'attachments' => 'optional|array',
        ]);
        
        if (!$validation['valid']) {
            LogService::warning("Données de mise à jour d'événement invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }
        
        // Validation des nouveaux champs
        $eventValidation = \ICS\Utils\EventValidator::validateEventFields($input);
        if (!$eventValidation['valid']) {
            LogService::warning("Validation des nouveaux champs échouée", [
                'errors' => $eventValidation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $eventValidation['errors'], 400);
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
                Response::error('La date de fin doit être après la date de début', null, 401);
                return;
            }
        }
        
        // Vérifier validité de la récurrence s'il y en a une
        if (isset($input['recurrence_rule']) && !CalendarEvent::isValidRecurrenceRule($input['recurrence_rule'])) {
            LogService::warning("Règle de récurrence invalide", [
                'recurrence_rule' => $input['recurrence_rule']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Règle de récurrence invalide', null, 400);
            return;
        }
        
        $cal = new Calendar();
        
        // Vérifier l'accès en écriture au calendrier  
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour modifier les événements de ce calendrier', null, 403);
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
            Response::error('Événement non trouvé', null, 404);
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
            if (isset($input['user_id'])) {
                $event->userId = $input['user_id'];
                $updatedFields[] = 'user_id';
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
            
            // Nouveaux champs
            if (isset($eventValidation['data']['timezone'])) {
                $event->timezone = $eventValidation['data']['timezone'];
                $updatedFields[] = 'timezone';
            }
            if (isset($eventValidation['data']['meeting_link'])) {
                $event->meetingLink = $eventValidation['data']['meeting_link'];
                $updatedFields[] = 'meeting_link';
            }
            if (isset($eventValidation['data']['notifications'])) {
                $event->notifications = $eventValidation['data']['notifications'];
                $updatedFields[] = 'notifications';
            }
            if (isset($eventValidation['data']['color'])) {
                $event->color = $eventValidation['data']['color'];
                $updatedFields[] = 'color';
            }
            // Phase 2
            if (isset($eventValidation['data']['priority'])) {
                $event->priority = $eventValidation['data']['priority'];
                $updatedFields[] = 'priority';
            }
            if (isset($eventValidation['data']['class'])) {
                $event->class = $eventValidation['data']['class'];
                $updatedFields[] = 'class';
            }
            if (isset($eventValidation['data']['transp'])) {
                $event->transp = $eventValidation['data']['transp'];
                $updatedFields[] = 'transp';
            }
            if (isset($eventValidation['data']['categories'])) {
                $event->categories = $eventValidation['data']['categories'];
                $updatedFields[] = 'categories';
            }
            if (isset($eventValidation['data']['geo_lat'])) {
                $event->geoLat = $eventValidation['data']['geo_lat'];
                $updatedFields[] = 'geo_lat';
            }
            if (isset($eventValidation['data']['geo_lng'])) {
                $event->geoLng = $eventValidation['data']['geo_lng'];
                $updatedFields[] = 'geo_lng';
            }
            if (isset($eventValidation['data']['attachments'])) {
                $event->attachments = $eventValidation['data']['attachments'];
                $updatedFields[] = 'attachments';
            }
            // Phase 4
            if (isset($eventValidation['data']['duration'])) {
                $event->duration = $eventValidation['data']['duration'];
                $updatedFields[] = 'duration';
            }
            if (isset($eventValidation['data']['related_to'])) {
                $event->relatedTo = $eventValidation['data']['related_to'];
                $updatedFields[] = 'related_to';
            }
            if (isset($eventValidation['data']['rdate'])) {
                $event->rdate = $eventValidation['data']['rdate'];
                $updatedFields[] = 'rdate';
            }
            
            LogService::info("Champs à mettre à jour", [
                'updated_fields' => $updatedFields,
                'event_id' => $event->id
            ]);
            
            // Appeler la méthode update() qui n'accepte pas de paramètres
            $result = $event->update();
            
            if (!$result) {
                throw new \Exception("Échec de la mise à jour");
            }
            
            // Récupérer les données mises à jour
            $updatedEvent = $event->findById($eventId);

            // Replanifier les notifications email si le champ `notifications` était fourni (§1.2 spec)
            if (in_array('notifications', $updatedFields, true) && $updatedEvent) {
                try {
                    EmailNotificationService::rescheduleEmailsForEvent($eventId, $updatedEvent, $userId);
                } catch (\Exception $notifEx) {
                    LogService::warning("Échec replanification notifications email (mise à jour)", [
                        'event_id' => $eventId,
                        'error'    => $notifEx->getMessage(),
                    ]);
                }
            }


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
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la mise à jour de l\'événement', null, 500);
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
            Response::error('Permission insuffisante pour supprimer les événements de ce calendrier', null, 403);
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
            Response::error('Événement non trouvé', null, 404);
            return;
        }
        
        try {
            // Le trait SoftDeleteTrait nécessite que l'ID soit défini sur l'instance
            $event->id = $eventId;
            $result = $event->softDelete();
            
            if (!$result) {
                throw new \Exception("Échec du soft delete");
            }
            
            // Annuler toutes les occurrences de l'événement
            $cancelledCount = \ICS\Models\EventOccurrence::cancelAllForEvent($eventId);

            // Annuler les notifications email en attente (§1.3 spec)
            try {
                EmailNotificationService::cancelEmailsForEvent($eventId);
            } catch (\Exception $notifEx) {
                LogService::warning("Échec annulation notifications email (suppression)", [
                    'event_id' => $eventId,
                    'error'    => $notifEx->getMessage(),
                ]);
            }


            LogService::info("Événement supprimé (soft delete)", [
                'event_id' => $eventId,
                'calendar_id' => $calendarId,
                'user_id' => $userId,
                'cancelled_occurrences' => $cancelledCount
            ]);
            LoggingMiddleware::logExit(200);
            Response::success('Événement supprimé avec succès', [
                'event_id' => $eventId,
                'deleted_at' => date('Y-m-d H:i:s'),
                'cancelled_occurrences' => $cancelledCount
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la suppression de l'événement", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la suppression de l\'événement', null, 500);
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
            Response::error('Permission insuffisante pour supprimer définitivement les événements de ce calendrier', null, 403);
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
            Response::error('Événement non trouvé', null, 404);
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
            Response::error('Erreur lors de la suppression définitive de l\'événement', null, 500);
        }
    }

    /**
     * Liste les événements soft-deleted d'un calendrier (corbeille)
     */
    public function getDeletedEvents($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $cal = new Calendar();
        $permission = $cal->getUserPermissionForCalendar($calendarId, $userId);

        if (!$permission) {
            LoggingMiddleware::logExit(404);
            Response::error('Calendrier non trouvé ou accès non autorisé', null, 404);
            return;
        }

        $pagination = Response::getPaginationParams();

        try {
            $event = new CalendarEvent();
            $events = $event->getDeletedByCalendarId($calendarId, $pagination['page'], $pagination['limit']);

            LoggingMiddleware::logExit(200);
            Response::success('Événements supprimés récupérés', [
                'events' => $events,
                'count'  => count($events),
                'page'   => $pagination['page'],
                'limit'  => $pagination['limit'],
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la récupération des événements supprimés", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération des événements supprimés', null, 500);
        }
    }

    /**
     * Restaure un événement soft-deleted
     */
    public function restoreEvent($eventId, $calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();

        $cal = new Calendar();

        if (!$cal->canUserWrite($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour restaurer les événements de ce calendrier', null, 403);
            return;
        }

        $event = new CalendarEvent();
        $existingEvent = $event->findById($eventId, true);

        if (!$existingEvent || $existingEvent['calendar_id'] != $calendarId) {
            LoggingMiddleware::logExit(404);
            Response::error('Événement non trouvé', null, 404);
            return;
        }

        if (empty($existingEvent['deleted_at'])) {
            LoggingMiddleware::logExit(404);
            Response::error('Cet événement n\'est pas supprimé', null, 404);
            return;
        }

        if (strtotime($existingEvent['deleted_at']) < strtotime('-' . CalendarEvent::RESTORE_RETENTION_DAYS . ' days')) {
            LoggingMiddleware::logExit(404);
            Response::error('Fenêtre de restauration expirée', null, 404);
            return;
        }

        try {
            $result = $event->restore();

            if (!$result) {
                throw new \Exception("Échec de la restauration");
            }

            LogService::info("Événement restauré", [
                'event_id' => $eventId,
                'calendar_id' => $calendarId,
                'restored_by' => $userId
            ]);
            LoggingMiddleware::logExit(200);
            Response::success('Événement restauré avec succès', ['event_id' => (int)$eventId]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la restauration de l'événement", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la restauration de l\'événement', null, 500);
        }
    }

    /**
     * Obtient toutes les occurrences (un jour) d'un événement depuis la table pré-calculée
     */
    public function getEventOccurrences($eventId, $calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        
        $cal = new Calendar();
        
        // Vérifier l'accès en lecture au calendrier (utiliser canUserWrite car il n'y a pas de canUserRead)
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour accéder à ce calendrier', null, 403);
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
            Response::error('Événement non trouvé', null, 404);
            return;
        } 
        
        // Récupérer les paramètres de période
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        
        try {
            // Utiliser les occurrences pré-calculées de la table event_occurrences
            $occurrences = \ICS\Models\EventOccurrence::getByEventId($eventId, $startDate, $endDate);
            
            LogService::info("Occurrences d'événement récupérées depuis table pré-calculée", [
                'event_id' => $eventId,
                'calendar_id' => $calendarId,
                'count' => count($occurrences)
            ]);
            
            LoggingMiddleware::logExit(200);
            Response::success('Occurrences récupérées avec succès', [
                'occurrences' => $occurrences,
                'count' => count($occurrences)
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la récupération des occurrences", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération des occurrences', null, 500);
        }
    }

    /**
     * Extrait et valide la clé d'occurrence : exactement une des deux clés
     * occurrence_id XOR occurrence_date. La clé date accepte 'YYYY-MM-DD' et
     * 'YYYY-MM-DD HH:MM:SS' (désambiguïsation, interprétée dans le TZ de l'événement).
     *
     * @return array [occurrenceId|null, occurrenceDate|null] ; $error rempli si invalide
     */
    private static function extractOccurrenceKey(array $input, ?string &$error): array
    {
        $error = null;
        $occurrenceId = $input['occurrence_id'] ?? null;
        $occurrenceDate = isset($input['occurrence_date']) ? trim((string)$input['occurrence_date']) : null;
        if ($occurrenceDate === '') {
            $occurrenceDate = null;
        }

        if (($occurrenceId === null) === ($occurrenceDate === null)) {
            $error = 'Fournir exactement une des deux clés : occurrence_id ou occurrence_date';
            return [null, null];
        }

        if ($occurrenceDate !== null) {
            $format = strlen($occurrenceDate) > 10 ? 'Y-m-d H:i:s' : 'Y-m-d';
            $dt = \DateTime::createFromFormat($format, $occurrenceDate);
            if (!$dt || $dt->format($format) !== $occurrenceDate) {
                $error = 'occurrence_date invalide — formats acceptés : YYYY-MM-DD ou YYYY-MM-DD HH:MM:SS';
                return [null, null];
            }
        }

        return [$occurrenceId, $occurrenceDate];
    }

    /**
     * Supprime (annule) une occurrence spécifique d'un événement récurrent
     */
    public function deleteEventOccurrence($calendarId, $eventId, $userId): void
    {
        LoggingMiddleware::logEntry();

        $input = Response::getRequestParams();
        $validation = Validator::validate($input, [
            'occurrence_id' => 'optional|integer',
            'occurrence_date' => 'optional|string',
            'scope' => 'optional|string|in:only_this,all_future,all',
        ]);
        if (!$validation['valid']) {
            LogService::warning("Données de suppression d'occurrence invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        [$occurrenceId, $occurrenceDate] = self::extractOccurrenceKey($input, $keyError);
        if ($keyError !== null) {
            LogService::warning("Clé d'occurrence invalide (DELETE)", ['error' => $keyError]);
            LoggingMiddleware::logExit(400);
            Response::error($keyError, null, 400);
            return;
        }

        $cal = new Calendar();
        
        // Vérifier l'accès en écriture au calendrier
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour modifier les événements de ce calendrier', null, 403);
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
            Response::error('Événement non trouvé', null, 404);
            return;
        }
        
        // Vérifier que c'est un événement récurrent
        if (empty($existingEvent['recurrence_rule'])) {
            LogService::warning("Tentative d'annulation d'occurrence pour un événement non récurrent", [
                'event_id' => $eventId
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Cette opération n\'est possible que pour les événements récurrents', null, 400);
            return;
        }
        
        try {
            $scope = $input['scope'] ?? 'only_this';
            
            if ($scope === 'all') {
                // Supprimer l'événement entier (soft delete)
                $eventModel = new CalendarEvent();
                $eventModel->id = $eventId;
                $result = $eventModel->softDelete();
                
                if (!$result) {
                    throw new \Exception("Échec de la suppression de l'événement");
                }
                
                // Annuler toutes les occurrences de l'événement
                $cancelledCount = \ICS\Models\EventOccurrence::cancelAllForEvent($eventId);
                
                LogService::info("Événement supprimé (soft delete) via annulation de toutes les occurrences", [
                    'event_id' => $eventId,
                    'calendar_id' => $calendarId,
                    'user_id' => $userId,
                    'cancelled_occurrences' => $cancelledCount
                ]);
                
                LoggingMiddleware::logExit(200);
                Response::success('Événement supprimé avec succès (toutes les occurrences annulées)', [
                    'event_id' => $eventId,
                    'scope' => $scope,
                    'deleted_at' => date('Y-m-d H:i:s'),
                    'cancelled_occurrences' => $cancelledCount
                ]);
                return;
            }
            
            $cancelledCount = 0;
            $responseOccurrenceDate = $occurrenceDate !== null ? substr($occurrenceDate, 0, 10) : null;

            if ($scope === 'only_this') {
                // Annuler seulement cette occurrence (clé id, ou clé date = RECURRENCE-ID)
                $occurrence = $occurrenceDate !== null
                    ? \ICS\Models\EventOccurrence::resolveOrMaterializeByDate($existingEvent, $occurrenceDate)
                    : \ICS\Models\EventOccurrence::findOccurrenceWithId((int)$occurrenceId);
                if (!$occurrence) {
                    LogService::warning("Occurrence non trouvée", [
                        'occurrence_id' => $occurrenceId,
                        'occurrence_date' => $occurrenceDate,
                        'scope' => $scope,
                    ]);
                    LoggingMiddleware::logExit(404);
                    Response::error('Occurrence non trouvée', null, 404);
                    return;
                }
                $responseOccurrenceDate = $occurrence['occurrence_date'] ?? $responseOccurrenceDate;

                $occModel = new \ICS\Models\EventOccurrence();
                $occModel->id = $occurrence['id'];
                $occModel->eventId = $eventId;
                $result = $occModel->cancel();
                
                if (!$result) {
                    throw new \Exception("Échec de l'annulation de l'occurrence");
                }
                $cancelledCount = 1;
            } elseif ($scope === 'all_future') {
                // Annuler toutes les occurrences à partir de cette date (clé id ou clé date)
                $cancelledCount = $occurrenceDate !== null
                    ? \ICS\Models\EventOccurrence::cancelFromDate($existingEvent, substr($occurrenceDate, 0, 10))
                    : \ICS\Models\EventOccurrence::cancelFromId($eventId, $occurrenceId);

                if ($cancelledCount == 0) {
                    LogService::warning("Aucune occurrence future trouvée", [
                        'event_id' => $eventId,
                        'occurrence_id' => $occurrenceId,
                        'occurrence_date' => $occurrenceDate
                    ]);
                    LoggingMiddleware::logExit(404);
                    Response::error('Aucune occurrence future trouvée', null, 404);
                    return;
                }
            } else {
                LoggingMiddleware::logExit(400);
                Response::error('Scope invalide', null, 400);
                return;
            }
            
            $scopeLabel = [
                'only_this' => 'cette occurrence',
                'all_future' => 'toutes les occurrences futures',
                'all' => 'toutes les occurrences'
            ][$scope];
            
            LogService::info("Occurrences annulées", [
                'event_id' => $eventId,
                'scope' => $scope,
                'occurrence_id' => $occurrenceId,
                'occurrence_date' => $responseOccurrenceDate,
                'cancelled_count' => $cancelledCount,
                'user_id' => $userId
            ]);

            LoggingMiddleware::logExit(200);
            Response::success("Occurrences annulées avec succès ($scopeLabel)", [
                'event_id' => $eventId,
                'scope' => $scope,
                'occurrence_id' => $occurrenceId,
                'occurrence_date' => $responseOccurrenceDate,
                'cancelled_count' => $cancelledCount,
                'cancelled_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de l'annulation des occurrences", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de l\'annulation des occurrences', null, 500);
        }
    }

    /**
     * Modifie une occurrence spécifique d'un événement récurrent
     */
    public function updateEventOccurrence($calendarId, $eventId, $userId): void
    {
        LoggingMiddleware::logEntry();
        
        $input = Response::getRequestParams();
        $validation = Validator::validate($input, [
            'occurrence_id' => 'optional|integer',
            'occurrence_date' => 'optional|string',
            'title' => 'optionnal|string',
            'description' => 'optionnal|string',
            'location' => 'optionnal|string',
            'start_datetime' => 'optionnal|date_or_datetime',
            'end_datetime' => 'optionnal|date_or_datetime',
            'scope' => 'optionnal|string|in:only_this,all_future,all'
        ]);

        if (!$validation['valid']) {
            LogService::warning("Données de modification d'occurrence invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        [$occurrenceId, $occurrenceDate] = self::extractOccurrenceKey($input, $keyError);
        if ($keyError !== null) {
            LogService::warning("Clé d'occurrence invalide (PUT)", ['error' => $keyError]);
            LoggingMiddleware::logExit(400);
            Response::error($keyError, null, 400);
            return;
        }

        // Vérifier que des modifications sont fournies
        $modifications = array_intersect_key($input, array_flip(['title', 'description', 'location', 'start_datetime', 'end_datetime']));
        if (empty($modifications)) {
            LoggingMiddleware::logExit(400);
            Response::error('Aucune modification fournie', null, 400);
            return;
        }
        
        // Vérifier les dates si fournies
        if (isset($input['start_datetime']) && isset($input['end_datetime'])) {
            if (strtotime($input['end_datetime']) < strtotime($input['start_datetime'])) {
                LogService::warning("Dates d'occurrence invalides", [
                    'start_datetime' => $input['start_datetime'],
                    'end_datetime' => $input['end_datetime']
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('La date de fin doit être après la date de début', null, 400);
                return;
            }
            $modifications['occurrence_date'] = date('Y-m-d', strtotime($input['start_datetime']));
        }
        
        $cal = new Calendar();
        
        // Vérifier l'accès en écriture au calendrier
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour modifier les événements de ce calendrier', null, 403);
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
            Response::error('Événement non trouvé', null, 404);
            return;
        }
        
        // Vérifier que c'est un événement récurrent
        if (empty($existingEvent['recurrence_rule'])) {
            LogService::warning("Tentative de modification d'occurrence pour un événement non récurrent", [
                'event_id' => $eventId
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Cette opération n\'est possible que pour les événements récurrents', null, 400);
            return;
        }
        
        try {
            $scope = $input['scope'] ?? 'only_this';
            $modifiedCount = 0;
            
            if ($scope === 'all') {
                // Modifier toutes les occurrences de l'événement
                $modifiedCount = \ICS\Models\EventOccurrence::modifyAll($eventId, $modifications);
                
                if ($modifiedCount == 0) {
                    LogService::warning("Aucune occurrence trouvée pour modification", [
                        'event_id' => $eventId
                    ]);
                    LoggingMiddleware::logExit(404);
                    Response::error('Aucune occurrence trouvée', null, 404);
                    return;
                }
                
                LogService::info("Toutes les occurrences modifiées", [
                    'event_id' => $eventId,
                    'modified_count' => $modifiedCount,
                    'modifications' => $modifications,
                    'user_id' => $userId
                ]);
                
                LoggingMiddleware::logExit(200);
                Response::success('Toutes les occurrences modifiées avec succès', [
                    'event_id' => $eventId,
                    'scope' => $scope,
                    'modified_count' => $modifiedCount,
                    'modifications' => $modifications,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                return;
            }
            
            if ($scope === 'all_future') {
                // Modifier toutes les occurrences à partir de cette date (clé id ou clé date)
                $modifiedCount = $occurrenceDate !== null
                    ? \ICS\Models\EventOccurrence::modifyFromDate($existingEvent, substr($occurrenceDate, 0, 10), $modifications)
                    : \ICS\Models\EventOccurrence::modifyFromId($eventId, $occurrenceId, $modifications);

                if ($modifiedCount == 0) {
                    LogService::warning("Aucune occurrence future trouvée pour modification", [
                        'event_id' => $eventId,
                        'occurrence_id' => $occurrenceId,
                        'occurrence_date' => $occurrenceDate
                    ]);
                    LoggingMiddleware::logExit(404);
                    Response::error('Aucune occurrence future trouvée', null, 404);
                    return;
                }
                
                LogService::info("Occurrences futures modifiées", [
                    'event_id' => $eventId,
                    'from_id' => $occurrenceId,
                    'modified_count' => $modifiedCount,
                    'modifications' => $modifications,
                    'user_id' => $userId
                ]);
                
                LoggingMiddleware::logExit(200);
                Response::success('Occurrences futures modifiées avec succès', [
                    'event_id' => $eventId,
                    'scope' => $scope,
                    'occurrence_id' => $occurrenceId,
                    'occurrence_date' => $occurrenceDate !== null ? substr($occurrenceDate, 0, 10) : null,
                    'modified_count' => $modifiedCount,
                    'modifications' => $modifications,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                return;
            }

            // Scope 'only_this' - modifier seulement cette occurrence (clé id, ou clé date = RECURRENCE-ID)
            $occurrence = $occurrenceDate !== null
                ? \ICS\Models\EventOccurrence::resolveOrMaterializeByDate($existingEvent, $occurrenceDate)
                : \ICS\Models\EventOccurrence::findOccurrenceWithId((int)$occurrenceId);

            if (!$occurrence) {
                LogService::warning("Occurrence non trouvée", [
                    'event_id' => $eventId,
                    'occurrence_id' => $occurrenceId,
                    'occurrence_date' => $occurrenceDate
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Occurrence non trouvée', null, 404);
                return;
            }
            
            // Modifier l'occurrence
            $occModel = new \ICS\Models\EventOccurrence();
            $occModel->id = $occurrence['id'];
            $occModel->eventId = $eventId;
            $occModel->modify($modifications);
            
            $modifiedCount = 1;          
            LogService::info("Occurrence modifiée", [
                'event_id' => $eventId,
                'occurrence_id' => $occurrence['id'],
                'modifications' => $modifications,
                'user_id' => $userId
            ]);
            LoggingMiddleware::logExit(200);
            Response::success('Occurrence modifiée avec succès', [
                'event_id' => $eventId,
                'scope' => $scope,
                'occurrence_id' => $occurrenceId,
                'occurrence_date' => $occurrence['occurrence_date'] ?? null,
                'modified_count' => $modifiedCount,
                'modifications' => $modifications,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la modification de l'occurrence", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la modification de l\'occurrence', null, 500);
        }
    }

    /**
     * Obtient toutes les occurrences d'un calendrier depuis la table pré-calculée
    */
    public function getEventsOccurrences($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();
        
        $cal = new Calendar();
        
        // Vérifier l'accès en lecture au calendrier (utiliser canUserWrite car il n'y a pas de canUserRead)
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour accéder à ce calendrier', null, 403);
            return;
        }

        $input = Response::getRequestParams();
        $validation = Validator::validate($input, [
            'start_date' => 'optionnal|date_or_datetime',
            'end_date' => 'optionnal|date_or_datetime',
            'expand_multi_jour' => 'optionnal|boolean',
        ]);

        if (!$validation['valid']) {
            LogService::warning("Paramètres de récupération des occurrences invalides", [
                'errors' => $validation['errors']
            ]);
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }
        // Récupérer les paramètres de période
        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? null;
        $expand_multi_jour = $input['expand_multi_jour'] ?? true;
        
        try {
            // Utiliser les occurrences pré-calculées de la table event_occurrences
            $occurrences = \ICS\Models\EventOccurrence::getByCalendarId($calendarId, $startDate, $endDate, $expand_multi_jour);
            
            LogService::info("Occurrences du calendrier récupérées depuis table pré-calculée", [
                'calendar_id' => $calendarId,
                'count' => count($occurrences)
            ]);
            
            LoggingMiddleware::logExit(200);
            Response::success('Occurrences récupérées avec succès', [
                'occurrences' => $occurrences,
                'count' => count($occurrences)
            ]);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la récupération des occurrences", [
                'exception' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération des occurrences', null, 500);
        }
    }

    /**
     * GET /calendars/{id}/events/occurrences/expand?start=&end=
     *
     * Expanse à la volée les occurrences (récurrentes + non récurrentes) d'un calendrier sur
     * la plage [start, end], TZID-aware, sans dépendre de la table pré-calculée
     * event_occurrences. Endpoint additif — n'affecte pas getEventsOccurrences() ni le CRON.
     */
    public function getEventsOccurrencesExpand($calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();

        $cal = new Calendar();
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour accéder à ce calendrier', null, 403);
            return;
        }

        $input = Response::getRequestParams();
        $validation = Validator::validate($input, [
            'start' => 'required|date_or_datetime',
            'end' => 'required|date_or_datetime',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        try {
            $occurrences = \ICS\Models\EventOccurrence::getExpandedByCalendarId(
                $calendarId, $input['start'], $input['end']
            );

            LoggingMiddleware::logExit(200);
            Response::success('Occurrences récupérées avec succès', [
                'occurrences' => $occurrences,
                'count' => count($occurrences)
            ]);
        } catch (\Recurr\Exception $e) {
            LogService::warning("RRULE non supportée lors de l'expansion à la demande", [
                'calendar_id' => $calendarId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(422);
            Response::error('Règle de récurrence non supportée : ' . $e->getMessage(), null, 422);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de l'expansion des occurrences", [
                'calendar_id' => $calendarId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération des occurrences', null, 500);
        }
    }

    /**
     * GET /calendars/{id}/events/{eventId}/occurrences/expand?start=&end=
     *
     * Variante par événement de getEventsOccurrencesExpand().
     */
    public function getEventOccurrenceExpand($eventId, $calendarId, $userId): void
    {
        LoggingMiddleware::logEntry();

        $cal = new Calendar();
        if (!$cal->canUserWrite($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error('Permission insuffisante pour accéder à ce calendrier', null, 403);
            return;
        }

        $event = new CalendarEvent();
        $existingEvent = $event->findById($eventId);
        if (!$existingEvent || $existingEvent['calendar_id'] != $calendarId) {
            LoggingMiddleware::logExit(404);
            Response::error('Événement non trouvé', null, 404);
            return;
        }

        $input = Response::getRequestParams();
        $validation = Validator::validate($input, [
            'start' => 'required|date_or_datetime',
            'end' => 'required|date_or_datetime',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        try {
            $occurrences = \ICS\Models\EventOccurrence::getExpandedByEventId(
                $eventId, $calendarId, $input['start'], $input['end']
            );

            LoggingMiddleware::logExit(200);
            Response::success('Occurrences récupérées avec succès', [
                'occurrences' => $occurrences,
                'count' => count($occurrences)
            ]);
        } catch (\Recurr\Exception $e) {
            LogService::warning("RRULE non supportée lors de l'expansion à la demande", [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(422);
            Response::error('Règle de récurrence non supportée : ' . $e->getMessage(), null, 422);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de l'expansion des occurrences", [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la récupération des occurrences', null, 500);
        }
    }

    // ----------------------------------------------------------------
    // Phase 5.3 — GET /calendars/{id}/freebusy?start=...&end=...
    // ----------------------------------------------------------------
    /**
     * Retourne un VCALENDAR VFREEBUSY pour la période demandée.
     * Agrège les événements TRANSP=OPAQUE (ou sans TRANSP) comme plages occupées.
     * Nécessite Phase 2.4 (colonne transp).
     */
    public function getFreeBusy(int $calendarId, int $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'start' => 'required|date_or_datetime',
            'end'   => 'required|date_or_datetime',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Paramètres start et end requis', $validation['errors'], 400);
            return;
        }

        $cal = new Calendar();
        $permission = $cal->getUserPermissionForCalendar($calendarId, $userId);
        if (!$permission) {
            LoggingMiddleware::logExit(404);
            Response::error('Calendrier non trouvé ou accès non autorisé', null, 404);
            return;
        }

        try {
            $calendar = $cal->getById($calendarId);
            $tz       = $calendar['timezone'] ?? 'America/Montreal';

            $start = date('Y-m-d H:i:s', strtotime($input['start']));
            $endRaw = \ICS\Models\EventOccurrence::endOfDayIfDateOnly($input['end']);
            $end   = date('Y-m-d H:i:s', strtotime($endRaw));

            if ($end <= $start) {
                LoggingMiddleware::logExit(400);
                Response::error('La date de fin doit être postérieure à la date de début', null, 400);
                return;
            }

            // Récupérer les événements OPAQUE dans la période — récurrence expansée (TZID-aware,
            // exceptions appliquées), même moteur que /occurrences/expand
            $opaqueEvents = \ICS\Models\EventOccurrence::getExpandedOpaqueByCalendarId($calendarId, $start, $end);

            $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
            if (str_contains($accept, 'text/calendar')) {
                $ics = \ICS\Utils\IcsGenerator::generateFreeBusy($calendar, $opaqueEvents, $start, $end);
                header('Content-Type: text/calendar; charset=utf-8');
                header('Content-Disposition: inline; filename="freebusy.ics"');
                LoggingMiddleware::logExit(200);
                echo $ics;
                return;
            }

            // Réponse JSON par défaut
            $busySlots = array_map(fn($e) => [
                'start'   => $e['start_datetime'],
                'end'     => $e['end_datetime'],
                'summary' => ($calendar['visibility'] !== 'private' || $permission['access_level'] === 'owner')
                    ? ($e['title'] ?? null) : null,
            ], $opaqueEvents);

            LoggingMiddleware::logExit(200);
            Response::success('Disponibilités récupérées', [
                'calendar_id' => $calendarId,
                'start'       => $start,
                'end'         => $end,
                'timezone'    => $tz,
                'busy'        => $busySlots,
            ]);
        } catch (\Exception $e) {
            LogService::error('Erreur freebusy', ['exception' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors du calcul des disponibilités', null, 500);
        }
    }

}

