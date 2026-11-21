<?php

namespace ICS\Controllers;

use ICS\Models\CalendarEvent;
use ICS\Services\EmailNotificationService;
use AuthGroups\Services\LogService;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;

/**
 * Préférence courriel du compte et réponses iTIP des participants.
 * (File /notifications/email et /notifications/send-email retirées — #497.)
 *
 * Routes :
 *   POST   /notifications/attendee-reply         → handleAttendeeReply()
 *   GET    /users/me/notification-preferences    → getPreferences()
 *   PUT    /users/me/notification-preferences    → updatePreferences()
 */
class NotificationController
{
    // ------------------------------------------------------------------
    // GET /users/me/notification-preferences
    // ------------------------------------------------------------------

    public function getPreferences(int $userId): void
    {
        LoggingMiddleware::logEntry();
        try {
            $prefs = EmailNotificationService::getPreferences($userId);
            if (!$prefs) {
                LoggingMiddleware::logExit(404);
                Response::error('Utilisateur introuvable', null, 404);
                return;
            }
            LoggingMiddleware::logExit(200);
            Response::success('Préférences de notification', $prefs);
        } catch (\Exception $e) {
            LogService::error('NotificationController::getPreferences', ['error' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur', null, 500);
        }
    }

    // ------------------------------------------------------------------
    // POST /notifications/attendee-reply  — Phase 3.3 iTIP REPLY
    // ------------------------------------------------------------------

    /**
     * Traite la réponse RSVP d'un participant à un événement (iTIP METHOD:REPLY).
     *
     * Met à jour le champ `partstat` de l'attendee correspondant dans
     * calendar_events.attendees (JSON).
     *
     * Corps requis : event_id, attendee_email, partstat
     *   partstat : ACCEPTED | DECLINED | TENTATIVE
     */
    public function handleAttendeeReply(int $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $eventId       = isset($input['event_id'])       ? (int)$input['event_id']        : null;
        $attendeeEmail = isset($input['attendee_email']) ? trim($input['attendee_email'])  : null;
        $partstat      = isset($input['partstat'])       ? strtoupper(trim($input['partstat'])) : null;

        if (!$eventId || !$attendeeEmail || !$partstat) {
            LoggingMiddleware::logExit(400);
            Response::error('Paramètres requis : event_id, attendee_email, partstat', null, 400);
            return;
        }

        $validPartstats = ['ACCEPTED', 'DECLINED', 'TENTATIVE'];
        if (!\in_array($partstat, $validPartstats, true)) {
            LoggingMiddleware::logExit(400);
            Response::error(
                'partstat invalide',
                ['partstat' => 'Doit être : ACCEPTED, DECLINED ou TENTATIVE'],
                400
            );
            return;
        }

        if (!filter_var($attendeeEmail, FILTER_VALIDATE_EMAIL)) {
            LoggingMiddleware::logExit(400);
            Response::error('attendee_email invalide', null, 400);
            return;
        }

        try {
            $model = new CalendarEvent();
            $row   = $model->getEventById($eventId);

            if (!$row) {
                LoggingMiddleware::logExit(404);
                Response::error('Événement introuvable', null, 404);
                return;
            }

            // Vérifier que l'utilisateur est propriétaire ou que l'email correspond
            $isOwner    = (int)$row['user_id'] === $userId;
            $isAttendee = false;
            $attendees  = !empty($row['attendees'])
                ? (is_string($row['attendees']) ? json_decode($row['attendees'], true) : $row['attendees'])
                : [];

            foreach ($attendees as &$att) {
                if (isset($att['email']) && strtolower($att['email']) === strtolower($attendeeEmail)) {
                    $isAttendee = true;
                    $att['partstat'] = $partstat;
                    break;
                }
            }
            unset($att);

            if (!$isOwner && !$isAttendee) {
                LoggingMiddleware::logExit(403);
                Response::error('Accès refusé', null, 403);
                return;
            }

            if (!$isAttendee) {
                LoggingMiddleware::logExit(404);
                Response::error('Attendee introuvable dans cet événement', null, 404);
                return;
            }

            // Persister le tableau mis à jour
            $model->id        = $eventId;
            $model->attendees = $attendees;
            $model->update();

            LogService::info('NotificationController: RSVP mis à jour', [
                'event_id'       => $eventId,
                'attendee_email' => $attendeeEmail,
                'partstat'       => $partstat,
                'by_user_id'     => $userId,
            ]);
            LoggingMiddleware::logExit(200);
            Response::success('Réponse RSVP enregistrée', [
                'event_id'       => $eventId,
                'attendee_email' => $attendeeEmail,
                'partstat'       => $partstat,
            ]);
        } catch (\Exception $e) {
            LogService::error('NotificationController::handleAttendeeReply', ['error' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur', null, 500);
        }
    }

    // ------------------------------------------------------------------
    // PUT /users/me/notification-preferences
    // ------------------------------------------------------------------

    public function updatePreferences(int $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $enabled           = isset($input['email_notifications_enabled'])
            ? (bool)$input['email_notifications_enabled']
            : null;

        // Retiré en #497 : les rappels partent toujours à l'adresse du compte.
        if (array_key_exists('notification_email', $input)) {
            LoggingMiddleware::logExit(422);
            Response::error('notification_email retiré : les rappels sont envoyés à l\'adresse du compte',
                ['code' => 'FIELD_REMOVED', 'field' => 'notification_email'], 422);
            return;
        }

        try {
            $updated = EmailNotificationService::updatePreferences($userId, $enabled);
            if (!$updated) {
                LoggingMiddleware::logExit(404);
                Response::error('Utilisateur introuvable', null, 404);
                return;
            }
            LoggingMiddleware::logExit(200);
            Response::success('Préférences mises à jour', $updated);
        } catch (\Exception $e) {
            LogService::error('NotificationController::updatePreferences', ['error' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur', null, 500);
        }
    }
}
