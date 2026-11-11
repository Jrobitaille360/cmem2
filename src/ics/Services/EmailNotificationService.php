<?php

namespace ICS\Services;

use ICS\Models\EmailNotificationQueue;
use ICS\Utils\IcsGenerator;
use AuthGroups\Services\EmailService;
use AuthGroups\Services\LogService;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PDO;
use DateTime;
use DateTimeZone;
use DateInterval;

/**
 * Gère la planification, l'annulation et l'envoi des notifications email
 * liées aux événements du calendrier.
 *
 * Points d'entrée pour le CalendarController :
 *   - scheduleEmailsForEvent()   → après createEvent()
 *   - rescheduleEmailsForEvent() → après updateEvent() si notifications fournie
 *   - cancelEmailsForEvent()     → après deleteEvent()
 *
 * Point d'entrée pour le cron :
 *   - processDueNotifications()
 */
class EmailNotificationService
{
    // ------------------------------------------------------------------
    // Phase 3.4 — Invitations email avec pièce jointe .ics
    // ------------------------------------------------------------------

    /**
     * Envoie les invitations iTIP (METHOD:REQUEST) à tous les attendees d'un événement.
     *
     * Génère un .ics METHOD:REQUEST et l'envoie en pièce jointe multipart/mixed.
     * Compatible Outlook, Gmail, Apple Mail.
     *
     * @param array  $event            Ligne DB de l'événement (avec attendees JSON)
     * @param string $calendarTimezone Timezone du calendrier parent
     * @return array ['sent' => [emails], 'failed' => [emails]]
     */
    public static function sendInvitationEmails(array $event, string $calendarTimezone): array
    {
        $attendees = \is_string($event['attendees'] ?? null)
            ? json_decode($event['attendees'], true)
            : ($event['attendees'] ?? null);

        if (empty($attendees) || !\is_array($attendees)) {
            return ['sent' => [], 'failed' => []];
        }

        $icsContent = IcsGenerator::generateInvitationIcs($event, $calendarTimezone);

        $sent   = [];
        $failed = [];

        foreach ($attendees as $attendee) {
            if (empty($attendee['email'])) {
                continue;
            }
            $ok = self::sendInvitationToAttendee(
                $event,
                $icsContent,
                $attendee['email'],
                $attendee['name'] ?? null
            );
            if ($ok) {
                $sent[] = $attendee['email'];
            } else {
                $failed[] = $attendee['email'];
            }
        }

        LogService::info('EmailNotificationService::sendInvitationEmails', [
            'event_id' => $event['id'] ?? null,
            'sent'     => \count($sent),
            'failed'   => \count($failed),
        ]);

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Envoie une invitation à un seul destinataire.
     * Utilise PHPMailer directement pour l'attachement multipart/mixed + .ics.
     */
    private static function sendInvitationToAttendee(
        array $event,
        string $icsContent,
        string $recipientEmail,
        ?string $recipientName
    ): bool {
        $smtpHost     = $_ENV['SMTP_HOST']          ?? $_ENV['MAIL_HOST']          ?? 'localhost';
        $smtpPort     = (int)($_ENV['SMTP_PORT']    ?? $_ENV['MAIL_PORT']          ?? 587);
        $smtpUser     = $_ENV['SMTP_USERNAME']       ?? $_ENV['MAIL_USERNAME']      ?? '';
        $smtpPass     = $_ENV['SMTP_PASSWORD']       ?? $_ENV['MAIL_PASSWORD']      ?? '';
        $smtpSecure   = $_ENV['SMTP_SECURE']         ?? 'tls';
        $fromEmail    = $_ENV['MAIL_FROM_ADDRESS']   ?? 'noreply@cmem.local';
        $fromName     = $_ENV['MAIL_FROM_NAME']      ?? 'CMEM Calendrier';
        $isDevMode    = ($_ENV['APP_ENV']            ?? 'production') === 'development';

        if ($isDevMode) {
            LogService::info('EmailNotificationService: invitation (dev — non envoyée)', [
                'to'       => $recipientEmail,
                'event_id' => $event['id'] ?? null,
            ]);
            return true;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host     = $smtpHost;
            $mail->Port     = $smtpPort;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
            $mail->SMTPAuth = !empty($smtpUser);

            if ($smtpSecure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($smtpSecure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            if ($smtpHost === 'localhost' || $smtpHost === '127.0.0.1') {
                $mail->SMTPAuth    = false;
                $mail->SMTPSecure  = false;
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($recipientEmail, $recipientName ?? '');

            $mail->Subject = 'Invitation : ' . ($event['title'] ?? 'Événement');
            $mail->CharSet = 'UTF-8';

            // Corps texte de l'invitation
            $body = self::buildInvitationBody($event);
            $mail->isHTML(false);
            $mail->Body = $body;

            // Pièce jointe .ics avec Content-Type calendar (RFC 6047)
            $mail->addStringAttachment(
                $icsContent,
                'invitation.ics',
                PHPMailer::ENCODING_8BIT,
                'text/calendar; method=REQUEST; charset=UTF-8'
            );

            $mail->send();

            LogService::info('EmailNotificationService: invitation envoyée', [
                'to'       => $recipientEmail,
                'event_id' => $event['id'] ?? null,
            ]);
            return true;

        } catch (PHPMailerException $e) {
            LogService::warning('EmailNotificationService: échec invitation', [
                'to'       => $recipientEmail,
                'event_id' => $event['id'] ?? null,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Corps texte d'une invitation (RFC 6047 — texte brut lisible si le client n'affiche pas le .ics).
     */
    private static function buildInvitationBody(array $event): string
    {
        $timezone = $event['timezone'] ?? 'America/Montreal';

        try {
            $tz    = new DateTimeZone($timezone);
            $start = new DateTime($event['start_datetime'], $tz);
            $end   = new DateTime($event['end_datetime'],   $tz);

            $dateStr = self::formatDateFr($start);
            $timeStr = $start->format('H:i') . ' – ' . $end->format('H:i') . " ({$timezone})";
        } catch (\Exception $e) {
            $dateStr = $event['start_datetime'] ?? '';
            $timeStr = $event['end_datetime']   ?? '';
        }

        $organizer = !empty($event['organizer_name'])
            ? $event['organizer_name']
            : (!empty($event['organizer_email']) ? $event['organizer_email'] : 'CMEM');

        $body  = "Vous êtes invité(e) à l'événement suivant :\n\n";
        $body .= "  Titre      : " . ($event['title'] ?? '') . "\n";
        $body .= "  Date       : {$dateStr}\n";
        $body .= "  Heure      : {$timeStr}\n";
        $body .= "  Organisateur : {$organizer}\n";

        if (!empty($event['location'])) {
            $body .= "  Lieu       : {$event['location']}\n";
        }
        if (!empty($event['meeting_link'])) {
            $body .= "  Lien       : {$event['meeting_link']}\n";
        }
        if (!empty($event['description'])) {
            $desc  = mb_substr(strip_tags($event['description']), 0, 300);
            $body .= "\n  Note       : {$desc}\n";
        }

        $body .= "\nUn fichier .ics est joint à ce message pour l'ajouter à votre calendrier.\n";
        $body .= "\nCet email a été envoyé automatiquement par CMEM.\n";
        return $body;
    }

    // ------------------------------------------------------------------
    // Envoi immédiat déclenché par le client
    // ------------------------------------------------------------------

    /**
     * Envoie immédiatement le courriel de rappel pour une occurrence spécifique.
     * Appelé par POST /notifications/send-email.
     *
     * @param int    $userId           Propriétaire authentifié
     * @param int    $eventId
     * @param string $occurrenceDate   "Y-m-d"
     * @param int    $recurrenceIndex
     * @return bool  true si envoyé, false sinon
     */
    public static function sendEmailNow(
        int $userId,
        int $eventId,
        string $occurrenceDate,
        int $recurrenceIndex
    ): bool {
        $event = self::loadEventData($eventId);
        if (!$event) {
            LogService::warning('EmailNotificationService::sendEmailNow: événement introuvable', [
                'event_id' => $eventId,
            ]);
            return false;
        }

        $occurrence = self::loadOccurrence($eventId, $occurrenceDate, $recurrenceIndex);
        $data       = self::resolveOccurrenceData($event, $occurrence);

        $userInfo = self::getUserNotificationInfo($userId);
        if (!$userInfo) {
            return false;
        }
        $recipientEmail = $userInfo['notification_email'] ?: $userInfo['email'];

        $subject = "Rappel : {$data['title']}";
        $body    = self::buildBody($data, 0);

        $emailService = new EmailService();
        $ok = $emailService->sendEmail($recipientEmail, $subject, $body, false);

        if ($ok) {
            LogService::info('EmailNotificationService::sendEmailNow: courriel envoyé', [
                'user_id'          => $userId,
                'event_id'         => $eventId,
                'occurrence_date'  => $occurrenceDate,
                'recurrence_index' => $recurrenceIndex,
                'recipient'        => $recipientEmail,
            ]);
        } else {
            LogService::warning('EmailNotificationService::sendEmailNow: échec SMTP', [
                'event_id'  => $eventId,
                'recipient' => $recipientEmail,
            ]);
        }

        return $ok;
    }

    // ------------------------------------------------------------------
    // Planification
    // ------------------------------------------------------------------

    /**
     * Planifie les notifications email d'un événement nouvellement créé.
     *
     * @param array $event   Données de l'événement (résultat de CalendarEvent::create())
     * @param int   $userId  ID du propriétaire
     */
    public static function scheduleEmailsForEvent(array $event, int $userId): void
    {
        $notifications = self::parseNotifications($event['notifications'] ?? null);
        if (empty($notifications)) {
            return;
        }

        $userInfo = self::getUserNotificationInfo($userId);
        if (!$userInfo || !$userInfo['email_notifications_enabled']) {
            return;
        }

        $recipientEmail = $userInfo['notification_email'] ?: $userInfo['email'];
        $timezone       = $event['timezone'] ?? 'America/Montreal';
        $eventId        = (int)$event['id'];
        $calendarId     = (int)$event['calendar_id'];

        foreach ($notifications as $notif) {
            if (strtoupper($notif['type'] ?? '') !== 'EMAIL') {
                continue;
            }
            $minutes = (int)($notif['minutes_before'] ?? $notif['minutes'] ?? 0);
            if ($minutes <= 0) {
                continue;
            }

            $fireAt = self::calcFireAt($event['start_datetime'], $timezone, $minutes);
            if ($fireAt === null || $fireAt <= new DateTime('now', new DateTimeZone('UTC'))) {
                // fire_at déjà passé → ignorer silencieusement (R1 à la planification)
                continue;
            }

            $entry                = new EmailNotificationQueue();
            $entry->userId        = $userId;
            $entry->eventId       = $eventId;
            $entry->calendarId    = $calendarId;
            $entry->occurrenceKey = "{$eventId}_0_" . substr($event['start_datetime'], 0, 10);
            $entry->fireAt        = $fireAt->format('Y-m-d H:i:s');
            $entry->minutesBefore = $minutes;
            $entry->recipientEmail = $recipientEmail;
            $entry->schedule();
        }
    }

    /**
     * Annule les emails en attente et replanifie selon les nouvelles données.
     * N'est appelé que si le champ `notifications` était présent dans la requête PUT.
     *
     * @param int   $eventId  ID de l'événement
     * @param array $event    Données mises à jour (résultat de CalendarEvent::findById())
     * @param int   $userId   ID du propriétaire
     */
    public static function rescheduleEmailsForEvent(int $eventId, array $event, int $userId): void
    {
        if (!empty($event['recurrence_rule']) || !empty($event['rdate'])) {
            // Série : les lignes en attente sont supprimées (pas annulées) pour que le
            // balayage planRecurringReminders() les recrée selon les nouveaux rappels —
            // une ligne 'cancelled' bloque la replanification (annulation manuelle).
            EmailNotificationQueue::deletePendingForEvent($eventId);
        } else {
            EmailNotificationQueue::cancelPendingForEvent($eventId);
        }
        self::scheduleEmailsForEvent($event, $userId);
    }

    /**
     * Planifie les rappels EMAIL de chaque occurrence des événements récurrents sur une
     * fenêtre glissante (cron, directive #438).
     *
     * - Occurrences calculées à la volée (RRULE/RDATE, fuseau de l'événement), exceptions
     *   appliquées : occurrence annulée/EXDATE → pas de rappel, occurrence déplacée →
     *   rappel calé sur la nouvelle heure.
     * - Clé d'idempotence : (event_id, occurrence_key, minutes_before). Une ligne existante,
     *   quel que soit son statut (dont 'cancelled' par l'usager), n'est jamais recréée.
     * - Ligne en attente devenue sans objet (occurrence exclue/déplacée hors fenêtre) :
     *   supprimée ; fire_at d'une ligne en attente recalé si l'occurrence a bougé.
     *
     * @param int $horizonHours Fenêtre de planification (fire_at ≤ maintenant + horizon)
     * @return array ['events', 'planned', 'updated', 'removed']
     */
    public static function planRecurringReminders(int $horizonHours = 48): array
    {
        $stats = ['events' => 0, 'planned' => 0, 'updated' => 0, 'removed' => 0];
        $db    = self::getDb();
        $utc   = new DateTimeZone('UTC');

        $now      = new DateTime('now', $utc);
        $minFire  = (clone $now)->modify('-' . self::PLAN_GRACE_MINUTES . ' minutes');
        $maxFire  = (clone $now)->modify("+{$horizonHours} hours");

        $stmt = $db->query(
            "SELECT e.*, c.user_id AS owner_id
             FROM calendar_events e
             JOIN calendars c ON c.id = e.calendar_id AND c.deleted_at IS NULL
             WHERE e.deleted_at IS NULL
               AND (e.status IS NULL OR e.status != 'cancelled')
               AND ((e.recurrence_rule IS NOT NULL AND e.recurrence_rule != '')
                    OR (e.rdate IS NOT NULL AND e.rdate != ''))
               AND e.notifications LIKE '%EMAIL%'"
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $event) {
            try {
                self::planEventReminders($db, $event, $now, $minFire, $maxFire, $stats);
            } catch (\Throwable $e) {
                LogService::warning('EmailNotificationService::planRecurringReminders: événement ignoré', [
                    'event_id' => $event['id'] ?? null,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /** Marge de retard acceptée pour planifier un rappel dont l'heure vient de passer (cron en retard). */
    private const PLAN_GRACE_MINUTES = 15;

    private static function planEventReminders(
        PDO $db,
        array $event,
        DateTime $now,
        DateTime $minFire,
        DateTime $maxFire,
        array &$stats
    ): void {
        $minutesList = [];
        foreach (self::parseNotifications($event['notifications'] ?? null) as $notif) {
            $m = (int)($notif['minutes_before'] ?? $notif['minutes'] ?? 0);
            if (strtoupper($notif['type'] ?? '') === 'EMAIL' && $m > 0) {
                $minutesList[$m] = true;
            }
        }
        if (empty($minutesList)) {
            return;
        }

        $userId   = (int)$event['owner_id'];
        $userInfo = self::getUserNotificationInfo($userId);
        if (!$userInfo || !$userInfo['email_notifications_enabled']) {
            return;
        }
        $recipientEmail = $userInfo['notification_email'] ?: $userInfo['email'];
        $eventId  = (int)$event['id'];
        $timezone = $event['timezone'] ?: 'America/Montreal';
        $tz       = new DateTimeZone($timezone);
        $stats['events']++;

        // Plage d'expansion (fuseau de l'événement) : couvre la fenêtre des fire_at
        // + le plus grand délai de rappel, avec un jour de marge de chaque côté
        // (occurrences déplacées d'un jour à l'autre).
        $maxMinutes = max(array_keys($minutesList));
        $rangeStart = (clone $minFire)->setTimezone($tz)->modify('-1 day');
        $rangeEnd   = (clone $maxFire)->setTimezone($tz)->modify("+{$maxMinutes} minutes")->modify('+1 day');

        $occurrences = \ICS\Models\EventOccurrence::expandEventInRange(
            $event,
            $rangeStart->format('Y-m-d H:i:s'),
            $rangeEnd->format('Y-m-d H:i:s')
        );

        // Rappels voulus : "occurrence_key|minutes" → fire_at UTC
        $desired = [];
        foreach ($occurrences as $occ) {
            $key = "{$eventId}_0_" . $occ['occurrence_date'];
            foreach (array_keys($minutesList) as $m) {
                $fireAt = self::calcFireAt($occ['start_datetime'], $timezone, $m);
                if ($fireAt === null || $fireAt < $minFire || $fireAt > $maxFire) {
                    continue;
                }
                $desired["{$key}|{$m}"] = $fireAt->format('Y-m-d H:i:s');
            }
        }

        $rowsStmt = $db->prepare(
            "SELECT id, occurrence_key, minutes_before, fire_at, status
             FROM email_notification_queue WHERE event_id = ?"
        );
        $rowsStmt->execute([$eventId]);
        $existing = [];
        foreach ($rowsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing["{$row['occurrence_key']}|{$row['minutes_before']}"][] = $row;
        }

        $minStr = $minFire->format('Y-m-d H:i:s');
        $maxStr = $maxFire->format('Y-m-d H:i:s');

        // Lignes en attente dans la fenêtre devenues sans objet → supprimées
        foreach ($existing as $k => $rows) {
            if (isset($desired[$k])) {
                continue;
            }
            foreach ($rows as $row) {
                if ($row['status'] === 'pending' && $row['fire_at'] >= $minStr && $row['fire_at'] <= $maxStr) {
                    $db->prepare("DELETE FROM email_notification_queue WHERE id = ? AND status = 'pending'")
                       ->execute([$row['id']]);
                    $stats['removed']++;
                }
            }
        }

        foreach ($desired as $k => $fireAt) {
            if (isset($existing[$k])) {
                foreach ($existing[$k] as $row) {
                    if ($row['status'] === 'pending' && $row['fire_at'] !== $fireAt) {
                        $db->prepare(
                            "UPDATE email_notification_queue SET fire_at = ?, updated_at = NOW()
                             WHERE id = ? AND status = 'pending'"
                        )->execute([$fireAt, $row['id']]);
                        $stats['updated']++;
                    }
                }
                continue;
            }

            [$occKey, $m] = explode('|', $k);
            $entry                 = new EmailNotificationQueue();
            $entry->userId         = $userId;
            $entry->eventId        = $eventId;
            $entry->calendarId     = (int)$event['calendar_id'];
            $entry->occurrenceKey  = $occKey;
            $entry->fireAt         = $fireAt;
            $entry->minutesBefore  = (int)$m;
            $entry->recipientEmail = $recipientEmail;
            $entry->schedule();
            $stats['planned']++;
        }
    }

    /**
     * Annule toutes les notifications en attente pour un événement supprimé. (R3)
     */
    public static function cancelEmailsForEvent(int $eventId): void
    {
        EmailNotificationQueue::cancelPendingForEvent($eventId);
    }

    // ------------------------------------------------------------------
    // Envoi (cron)
    // ------------------------------------------------------------------

    /**
     * Traite les notifications dues et les envoie.
     * À appeler depuis le script cron.
     *
     * @param int $batchSize Nombre maximum de notifications à traiter par exécution
     * @return array Statistiques : ['sent', 'failed', 'skipped']
     */
    public static function processDueNotifications(int $batchSize = 50): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $rows  = EmailNotificationQueue::getDueNotifications($batchSize);

        if (empty($rows)) {
            return $stats;
        }

        $emailService = new EmailService();

        foreach ($rows as $row) {
            // R4 : si l'utilisateur a désactivé ses notifications → passer
            if (!(bool)$row['email_notifications_enabled']) {
                $stats['skipped']++;
                continue;
            }

            $eventData = self::loadEventData((int)$row['event_id']);
            if (!$eventData) {
                EmailNotificationQueue::markAttemptFailed((int)$row['id'], 'Événement introuvable');
                $stats['failed']++;
                continue;
            }

            // Série : données de l'occurrence visée (date réelle, exceptions) ;
            // occurrence annulée depuis la planification → pas d'envoi.
            $eventData = self::resolveReminderOccurrence($eventData, (string)$row['occurrence_key']);
            if ($eventData === null) {
                EmailNotificationQueue::cancelOneById((int)$row['id']);
                $stats['skipped']++;
                continue;
            }

            $subject = self::buildSubject($eventData, (int)$row['minutes_before']);
            $body    = self::buildBody($eventData, (int)$row['minutes_before']);

            $ok = $emailService->sendEmail($row['recipient_email'], $subject, $body, false);

            if ($ok) {
                EmailNotificationQueue::markSent((int)$row['id']);
                $stats['sent']++;
                LogService::info('EmailNotificationService: email envoyé', [
                    'notification_id' => $row['id'],
                    'event_id'        => $row['event_id'],
                    'recipient'       => $row['recipient_email'],
                ]);
            } else {
                EmailNotificationQueue::markAttemptFailed(
                    (int)$row['id'],
                    'Échec SMTP (attempt ' . ($row['attempt_count'] + 1) . ')'
                );
                $stats['failed']++;
                LogService::warning('EmailNotificationService: échec envoi email', [
                    'notification_id' => $row['id'],
                    'event_id'        => $row['event_id'],
                ]);
            }
        }

        return $stats;
    }

    // ------------------------------------------------------------------
    // Préférences utilisateur
    // ------------------------------------------------------------------

    /**
     * Retourne les préférences de notification d'un utilisateur.
     */
    public static function getPreferences(int $userId): ?array
    {
        $db   = self::getDb();
        $stmt = $db->prepare(
            "SELECT email_notifications_enabled, notification_email, email
             FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'email_notifications_enabled' => (bool)$row['email_notifications_enabled'],
            'notification_email'          => $row['notification_email'],
            'account_email'               => $row['email'],
        ];
    }

    /**
     * Met à jour les préférences de notification d'un utilisateur.
     *
     * @param int        $userId
     * @param bool|null  $enabled          null = ne pas modifier
     * @param string|null $notificationEmail null = ne pas modifier ('' = effacer)
     * @return array Préférences mises à jour
     */
    public static function updatePreferences(
        int $userId,
        ?bool $enabled,
        ?string $notificationEmail
    ): ?array {
        $db     = self::getDb();
        $fields = [];
        $params = [];

        if ($enabled !== null) {
            $fields[] = 'email_notifications_enabled = ?';
            $params[] = $enabled ? 1 : 0;
        }
        if ($notificationEmail !== null) {
            $fields[] = 'notification_email = ?';
            $params[] = ($notificationEmail === '') ? null : $notificationEmail;
        }

        if (!empty($fields)) {
            $params[] = $userId;
            $db->prepare(
                "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?"
            )->execute($params);
        }

        return self::getPreferences($userId);
    }

    // ------------------------------------------------------------------
    // Helpers privés
    // ------------------------------------------------------------------

    /**
     * Calcule fire_at en UTC à partir de start_datetime (dans le timezone de l'événement).
     */
    private static function calcFireAt(string $startDatetime, string $timezone, int $minutes): ?DateTime
    {
        try {
            $tz    = new DateTimeZone($timezone);
            $start = new DateTime($startDatetime, $tz);
            $start->sub(new DateInterval("PT{$minutes}M"));
            $start->setTimezone(new DateTimeZone('UTC'));
            return $start;
        } catch (\Exception $e) {
            LogService::warning('EmailNotificationService: calcul fire_at échoué', [
                'start'    => $startDatetime,
                'timezone' => $timezone,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Décode le champ notifications (JSON ou tableau).
     */
    private static function parseNotifications(mixed $raw): array
    {
        if (empty($raw)) {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }

    /**
     * Récupère email et préférences de notification d'un utilisateur.
     */
    private static function getUserNotificationInfo(int $userId): ?array
    {
        $db   = self::getDb();
        $stmt = $db->prepare(
            "SELECT email, email_notifications_enabled, notification_email
             FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Charge les données d'un événement pour la composition de l'email.
     */
    private static function loadEventData(int $eventId): ?array
    {
        $db   = self::getDb();
        $stmt = $db->prepare(
            "SELECT id, title, start_datetime, end_datetime, timezone,
                    location, meeting_link, description, recurrence_rule, rdate
             FROM calendar_events
             WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$eventId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Charge une occurrence par event_id + date + recurrence_index.
     * Retourne null si elle n'existe pas (occurrence non-modifiée).
     */
    private static function loadOccurrence(int $eventId, string $occurrenceDate, int $recurrenceIndex): ?array
    {
        $db   = self::getDb();
        $stmt = $db->prepare(
            "SELECT modified_title, modified_start_datetime, modified_end_datetime, modified_location
             FROM event_occurrences
             WHERE event_id = ? AND occurrence_date = ? AND recurrence_index = ?
             LIMIT 1"
        );
        $stmt->execute([$eventId, $occurrenceDate, $recurrenceIndex]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Données d'envoi d'un rappel : pour une série, l'occurrence désignée par
     * occurrence_key ("{eventId}_{idx}_{Y-m-d}") — date réelle, exceptions appliquées.
     * Retourne null si l'occurrence est annulée (EXDATE / DELETE only_this).
     */
    private static function resolveReminderOccurrence(array $event, string $occurrenceKey): ?array
    {
        $isSeries = !empty($event['recurrence_rule']) || !empty($event['rdate']);
        if (!$isSeries || !preg_match('/_(\d{4}-\d{2}-\d{2})$/', $occurrenceKey, $m)) {
            return $event;
        }
        $occDate = $m[1];

        $stmt = self::getDb()->prepare(
            "SELECT is_cancelled, is_modified, modified_title, modified_start_datetime,
                    modified_end_datetime, modified_location
             FROM event_occurrences
             WHERE event_id = ? AND occurrence_date = ? AND (is_cancelled = 1 OR is_modified = 1)
             LIMIT 1"
        );
        $stmt->execute([(int)$event['id'], $occDate]);
        $exception = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($exception && !empty($exception['is_cancelled'])) {
            return null;
        }

        // Occurrence non modifiée : date de la clé + heure locale de l'événement de base.
        $tz        = new DateTimeZone($event['timezone'] ?: 'America/Montreal');
        $baseStart = new DateTime($event['start_datetime'], $tz);
        $baseEnd   = new DateTime($event['end_datetime'], $tz);
        $start     = new DateTime($occDate . ' ' . $baseStart->format('H:i:s'), $tz);
        $end       = (clone $start)->add($baseStart->diff($baseEnd));

        $occurrenceEvent = array_merge($event, [
            'start_datetime' => $start->format('Y-m-d H:i:s'),
            'end_datetime'   => $end->format('Y-m-d H:i:s'),
        ]);

        return $exception ? self::resolveOccurrenceData($occurrenceEvent, $exception) : $occurrenceEvent;
    }

    /**
     * Fusionne les champs modifiés de l'occurrence avec les champs de l'événement de base.
     * Les champs de l'occurrence ont priorité si non nuls.
     */
    private static function resolveOccurrenceData(array $event, ?array $occurrence): array
    {
        return [
            'title'          => ($occurrence['modified_title']          ?? null) ?: $event['title'],
            'start_datetime' => ($occurrence['modified_start_datetime'] ?? null) ?: $event['start_datetime'],
            'end_datetime'   => ($occurrence['modified_end_datetime']   ?? null) ?: $event['end_datetime'],
            // NULL = pas de surcharge ; '' = lieu volontairement vidé sur cette occurrence
            'location'       => ($occurrence['modified_location'] ?? null) !== null
                ? $occurrence['modified_location']
                : ($event['location'] ?? null),
            'meeting_link'   => $event['meeting_link']   ?? null,
            'description'    => $event['description']    ?? null,
            'timezone'       => $event['timezone']       ?? 'America/Montreal',
        ];
    }

    /**
     * Construit le sujet de l'email (§4 spec).
     */
    private static function buildSubject(array $event, int $minutes): string
    {
        return "[CMEM] Rappel : {$event['title']} dans {$minutes} minutes";
    }

    /**
     * Construit le corps texte de l'email (§4 spec).
     */
    private static function buildBody(array $event, int $minutes): string
    {
        $timezone = $event['timezone'] ?? 'America/Montreal';

        try {
            $tz    = new DateTimeZone($timezone);
            $start = new DateTime($event['start_datetime'], $tz);
            $end   = new DateTime($event['end_datetime'],   $tz);

            $dateStr  = self::formatDateFr($start);
            $timeStr  = $start->format('H:i') . ' – ' . $end->format('H:i') . " ({$timezone})";
        } catch (\Exception $e) {
            $dateStr = $event['start_datetime'];
            $timeStr = $event['end_datetime'];
        }

        $body  = "Rappel pour votre événement :\n\n";
        $body .= "  Titre  : {$event['title']}\n";
        $body .= "  Date   : {$dateStr}\n";
        $body .= "  Heure  : {$timeStr}\n";

        if (!empty($event['location'])) {
            $body .= "  Lieu   : {$event['location']}\n";
        }
        if (!empty($event['meeting_link'])) {
            $body .= "  Lien   : {$event['meeting_link']}\n";
        }
        if (!empty($event['description'])) {
            $desc  = mb_substr(strip_tags($event['description']), 0, 300);
            $body .= "\n  Note   : {$desc}\n";
        }

        $body .= "\nCet email a été envoyé automatiquement par CMEM.\n";
        return $body;
    }

    /**
     * Formate une date en français lisible.
     */
    private static function formatDateFr(DateTime $dt): string
    {
        $jours  = ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'];
        $mois   = ['','janvier','février','mars','avril','mai','juin',
                   'juillet','août','septembre','octobre','novembre','décembre'];

        $dow = (int)$dt->format('w');
        $d   = (int)$dt->format('j');
        $m   = (int)$dt->format('n');
        $y   = $dt->format('Y');

        return "{$jours[$dow]} {$d} {$mois[$m]} {$y}";
    }

    /**
     * Connexion PDO partagée.
     */
    private static function getDb(): PDO
    {
        require_once __DIR__ . '/../../auth_groups/database.php';
        return \Database::getInstance()->getConnection();
    }
}
