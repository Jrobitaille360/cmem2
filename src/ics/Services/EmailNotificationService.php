<?php

namespace ICS\Services;

use ICS\Utils\IcsGenerator;
use AuthGroups\Services\LogService;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PDO;
use DateTime;
use DateTimeZone;

/**
 * Courriels liés au calendrier : invitations iTIP (METHOD:REQUEST) aux participants,
 * et préférence de compte « rappels par courriel » (users.email_notifications_enabled).
 *
 * Les rappels courriel eux-mêmes sont sélectionnés et envoyés par le cron unifié
 * (src/notifications/send_reminders.php, #487) ; l'ancienne file email_notification_queue
 * a été retirée (#497).
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
    // Préférences utilisateur
    // ------------------------------------------------------------------

    /**
     * Retourne les préférences de notification d'un utilisateur.
     */
    public static function getPreferences(int $userId): ?array
    {
        $db   = self::getDb();
        $stmt = $db->prepare(
            "SELECT email_notifications_enabled, email
             FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'email_notifications_enabled' => (bool)$row['email_notifications_enabled'],
            'account_email'               => $row['email'],
        ];
    }

    /**
     * Met à jour les préférences de notification d'un utilisateur.
     *
     * @param int       $userId
     * @param bool|null $enabled null = ne pas modifier
     * @return array Préférences mises à jour
     */
    public static function updatePreferences(int $userId, ?bool $enabled): ?array
    {
        if ($enabled !== null) {
            self::getDb()->prepare('UPDATE users SET email_notifications_enabled = ? WHERE id = ?')
                ->execute([$enabled ? 1 : 0, $userId]);
        }

        return self::getPreferences($userId);
    }

    // ------------------------------------------------------------------
    // Helpers privés
    // ------------------------------------------------------------------
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
