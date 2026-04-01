<?php

namespace ICS\Controllers;

use ICS\Models\EmailNotificationQueue;
use ICS\Models\CalendarEvent;
use ICS\Services\EmailNotificationService;
use AuthGroups\Services\EmailService;
use AuthGroups\Services\LogService;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;

/**
 * Gère les endpoints du système de notifications email.
 *
 * Routes :
 *   GET    /notifications/email                  → listEmailNotifications()
 *   DELETE /notifications/email/{id}             → cancelEmailNotification()
 *   POST   /notifications/email/test             → sendTestEmail()
 *   GET    /users/me/notification-preferences    → getPreferences()
 *   PUT    /users/me/notification-preferences    → updatePreferences()
 */
class NotificationController
{
    // ------------------------------------------------------------------
    // POST /notifications/send-email
    // ------------------------------------------------------------------

    /**
     * Déclenche l'envoi immédiat d'un courriel de rappel pour une occurrence.
     *
     * Corps requis : event_id, calendar_id, occurrence_date, recurrence_index
     */
    public function sendEmailForOccurrence(int $userId): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $eventId          = isset($input['event_id'])          ? (int)$input['event_id']          : null;
        $occurrenceDate   = isset($input['occurrence_date'])   ? trim($input['occurrence_date'])   : null;
        $recurrenceIndex  = isset($input['recurrence_index'])  ? (int)$input['recurrence_index']  : 0;

        if (!$eventId || !$occurrenceDate) {
            LoggingMiddleware::logExit(400);
            Response::error('Paramètres requis : event_id, occurrence_date', null, 400);
            return;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $occurrenceDate)) {
            LoggingMiddleware::logExit(400);
            Response::error('occurrence_date doit être au format YYYY-MM-DD', null, 400);
            return;
        }

        try {
            $ok = EmailNotificationService::sendEmailNow($userId, $eventId, $occurrenceDate, $recurrenceIndex);

            if (!$ok) {
                LoggingMiddleware::logExit(500);
                Response::error('Échec de l\'envoi du courriel', null, 500);
                return;
            }

            LoggingMiddleware::logExit(200);
            Response::success('Courriel envoyé', ['message' => 'Courriel envoyé']);
        } catch (\Exception $e) {
            LogService::error('NotificationController::sendEmailForOccurrence', ['error' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur', null, 500);
        }
    }

    // ------------------------------------------------------------------
    // GET /notifications/email
    // ------------------------------------------------------------------

    /**
     * Liste les notifications email planifiées de l'utilisateur courant.
     *
     * Query params : status, from, to, sort  (voir spec §2.1)
     */
    public function listEmailNotifications(int $userId): void
    {
        LoggingMiddleware::logEntry();

        $status = $_GET['status'] ?? 'pending';
        $from   = $_GET['from']   ?? null;
        $to     = $_GET['to']     ?? null;
        $sort   = $_GET['sort']   ?? 'fire_at_asc';

        // Validation status
        $allowedStatuses = ['pending', 'sent', 'failed', 'all'];
        if (!in_array($status, $allowedStatuses, true)) {
            LoggingMiddleware::logExit(400);
            Response::error('Valeur status invalide', ['status' => 'Doit être : pending, sent, failed ou all'], 400);
            return;
        }

        // Validation sort
        $allowedSorts = ['fire_at_asc', 'fire_at_desc'];
        if (!in_array($sort, $allowedSorts, true)) {
            LoggingMiddleware::logExit(400);
            Response::error('Valeur sort invalide', ['sort' => 'Doit être : fire_at_asc ou fire_at_desc'], 400);
            return;
        }

        // Defaults dates
        if (!$from) {
            $from = date('Y-m-d');
        }
        if (!$to) {
            $to = date('Y-m-d', strtotime('+30 days'));
        }

        try {
            $rows = EmailNotificationQueue::listForUser($userId, $status, $from, $to, $sort);
            LoggingMiddleware::logExit(200);
            Response::success('Notifications récupérées', [
                'data'  => $rows,
                'total' => count($rows),
            ]);
        } catch (\Exception $e) {
            LogService::error('NotificationController::listEmailNotifications', ['error' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur', null, 500);
        }
    }

    // ------------------------------------------------------------------
    // DELETE /notifications/email/{id}
    // ------------------------------------------------------------------

    /**
     * Annule une notification email planifiée.
     */
    public function cancelEmailNotification(int $notificationId, int $userId): void
    {
        LoggingMiddleware::logEntry();

        try {
            $result = EmailNotificationQueue::cancelOne($notificationId, $userId);

            switch ($result['reason'] ?? '') {
                case 'not_found':
                    LoggingMiddleware::logExit(404);
                    Response::error('Notification introuvable ou déjà envoyée', null, 404);
                    return;

                case 'forbidden':
                    LoggingMiddleware::logExit(403);
                    Response::error('Accès refusé', null, 403);
                    return;

                case 'already_sent':
                    LoggingMiddleware::logExit(409);
                    Response::error(
                        'La notification a déjà été envoyée, non annulable',
                        ['code' => 'ALREADY_SENT'],
                        409
                    );
                    return;
            }

            LoggingMiddleware::logExit(200);
            Response::success('Notification annulée', ['message' => 'Notification annulée']);
        } catch (\Exception $e) {
            LogService::error('NotificationController::cancelEmailNotification', ['error' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur', null, 500);
        }
    }

    // ------------------------------------------------------------------
    // POST /notifications/email/test
    // ------------------------------------------------------------------

    /**
     * Envoie immédiatement un email de test (validation SMTP).
     */
    public function sendTestEmail(int $userId): void
    {
        LoggingMiddleware::logEntry();

        $input = Response::getRequestParams();

        // Récupère l'email cible (paramètre ou email du compte)
        $targetEmail = null;
        if (!empty($input['email'])) {
            $targetEmail = trim($input['email']);
            if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
                LoggingMiddleware::logExit(400);
                Response::error('Adresse email invalide', null, 400);
                return;
            }
        } else {
            $prefs = EmailNotificationService::getPreferences($userId);
            $targetEmail = $prefs['notification_email'] ?: $prefs['account_email'];
        }

        try {
            $emailService = new EmailService();
            $subject = '[CMEM] Email de test — notifications';
            $body    = "Ceci est un email de test envoyé depuis CMEM.\n\n"
                     . "Si vous recevez ce message, votre configuration SMTP est fonctionnelle.\n";

            $ok = $emailService->sendEmail($targetEmail, $subject, $body, false);

            if (!$ok) {
                LoggingMiddleware::logExit(500);
                Response::error('Échec de l\'envoi SMTP', ['code' => 'SMTP_ERROR'], 500);
                return;
            }

            LogService::info('NotificationController: email de test envoyé', [
                'user_id'  => $userId,
                'to'       => $targetEmail,
            ]);
            LoggingMiddleware::logExit(200);
            Response::success("Email de test envoyé à {$targetEmail}", [
                'message' => "Email de test envoyé à {$targetEmail}",
            ]);
        } catch (\Exception $e) {
            LogService::error('NotificationController::sendTestEmail', ['error' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur', null, 500);
        }
    }

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

        $notificationEmail = null;
        if (array_key_exists('notification_email', $input)) {
            $val = $input['notification_email'];
            if ($val !== null && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                LoggingMiddleware::logExit(400);
                Response::error('notification_email invalide', null, 400);
                return;
            }
            $notificationEmail = ($val === null) ? '' : $val; // '' → effacer
        }

        try {
            $updated = EmailNotificationService::updatePreferences($userId, $enabled, $notificationEmail);
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
