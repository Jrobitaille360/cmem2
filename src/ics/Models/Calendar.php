<?php

namespace ICS\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class Calendar extends BaseModel
{
    protected $table = 'calendars';
    
    public $id;
    public $userId;
    public $title;
    public $description;
    public $maxMembers;
    public $timezone;
    public $color;
    public $visibility;
    public $shareToken;
    public $createdAt;
    public $updatedAt;
    public $deletedAt;

    public function __construct() {
        parent::__construct();
    }

    public function create():array
    {    
        // Générer un token de partage unique
        $shareToken = bin2hex(random_bytes(32));

        $query ="
            INSERT INTO calendars (user_id, title, description, timezone, color, visibility, max_members, share_token)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = $this->getDb()->prepare($query);

        $stmt->execute([
            $this->userId,
            $this->title,
            $this->description ?? null,
            $this->timezone ?? 'America/Montreal',
            $this->color ?? '#3174ad',
            $this->visibility ?? 'private',
            $this->maxMembers ?? 1000,
            $shareToken
        ]);

        $calendarId = $this->getDb()->lastInsertId();

        return [
            'id' => $calendarId,
            'share_token' => $shareToken,
            'ics_url' => self::generateIcsUrl($shareToken)
        ];
    }
    
    public function update(): bool
    {
             
        $fields = [];
        $params = [];

        if (isset($this->title)) {
            $fields[] = 'title = ?';
            $params[] = $this->title;
        }
        if (isset($this->description)) {
            $fields[] = 'description = ?';
            $params[] = $this->description;
        }
        if (isset($this->timezone)) {
            $fields[] = 'timezone = ?';
            $params[] = $this->timezone;
        }
        if (isset($this->color)) {
            $fields[] = 'color = ?';
            $params[] = $this->color;
        }
        if (isset($this->visibility)) {
            $fields[] = 'visibility = ?';
            $params[] = $this->visibility;
        }

        if (isset($this->maxMembers)) {
            $fields[] = 'max_members = ?';
            $params[] = $this->maxMembers;
        }

        if (empty($fields)) {
            return false; // Rien à mettre à jour
        }

        $params[] = $this->id;

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($sql);

        return $stmt->execute($params);
    }

    public function getUserCalendars($userId): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT c.*, 
                   COUNT(ce.id) as event_count,
                   CONCAT(?, '/calendar/', c.share_token, '.ics') as ics_url,
                   CASE 
                       WHEN c.user_id = ? THEN 'owner'
                       WHEN c.visibility = 'public' THEN 'public'
                       WHEN cs.permission IS NOT NULL THEN CONCAT('shared_', cs.permission)
                       ELSE NULL
                   END as access_type,
                   cs.permission as share_permission
            FROM calendars c
            LEFT JOIN calendar_events ce ON c.id = ce.calendar_id AND ce.deleted_at IS NULL
            LEFT JOIN calendar_shares cs ON c.id = cs.calendar_id 
                AND cs.shared_with_user_id = ? AND cs.deleted_at IS NULL
            WHERE (c.user_id = ? OR c.visibility = 'public' OR cs.shared_with_user_id = ?) 
                AND c.deleted_at IS NULL
            GROUP BY c.id
            ORDER BY c.user_id = ? DESC, c.created_at DESC
        ");
        
        $stmt->execute([BASE_URL, $userId, $userId, $userId, $userId, $userId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getByShareToken($shareToken): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM calendars 
            WHERE share_token = ? AND deleted_at IS NULL
        ");
       
        $stmt->execute([$shareToken]);     
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getById($calendarId): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM calendars 
            WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$calendarId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function shareWith($calendarId, $targetUserId, $permission = 'read'): array
    {
        // Vérifier si le partage existe déjà
        $stmt = $this->getDb()->prepare("
            SELECT * FROM calendar_shares 
            WHERE calendar_id = ? AND shared_with_user_id = ? and deleted_at IS NULL
        ");
        $stmt->execute([$calendarId, $targetUserId]);
        $existingShare = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingShare) {
            // Mettre à jour les permissions si elles ont changé
            if ($existingShare['permission'] !== $permission) {
                $updateStmt = $this->getDb()->prepare("
                    UPDATE calendar_shares 
                    SET permission = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $updateStmt->execute([$permission, $existingShare['id']]);
                $existingShare['permission'] = $permission;
            }
            return $existingShare;
        }

        // Créer un nouveau partage
        $stmt = $this->getDb()->prepare("
            INSERT INTO calendar_shares (calendar_id, shared_with_user_id, permission, created_at)
            VALUES (?, ?, ?, NOW())
        ");

        $stmt->execute([$calendarId, $targetUserId, $permission]);
        $shareId = $this->getDb()->lastInsertId();

        return [
            'id' => $shareId,
            'calendar_id' => $calendarId,
            'shared_with_user_id' => $targetUserId,
            'permission' => $permission
        ];
    }

    public function shareWithEmail($calendarId, $email, $permission = 'read'): array
    {
        // Vérifier si le partage existe déjà
        $stmt = $this->getDb()->prepare("
            SELECT * FROM calendar_shares 
            WHERE calendar_id = ? AND shared_with_email = ? and deleted_at IS NULL
        ");
        $stmt->execute([$calendarId, $email]);
        $existingShare = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingShare) {
            // Mettre à jour les permissions si elles ont changé
            if ($existingShare['permission'] !== $permission) {
                $updateStmt = $this->getDb()->prepare("
                    UPDATE calendar_shares 
                    SET permission = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $updateStmt->execute([$permission, $existingShare['id']]);
                $existingShare['permission'] = $permission;
            }
            return $existingShare;
        }

        // Créer un nouveau partage par email
        $stmt = $this->getDb()->prepare("
            INSERT INTO calendar_shares (calendar_id, shared_with_email, permission, created_at)
            VALUES (?, ?, ?, NOW())
        ");

        $stmt->execute([$calendarId, $email, $permission]);
        $shareId = $this->getDb()->lastInsertId();

        return [
            'id' => $shareId,
            'calendar_id' => $calendarId,
            'shared_with_email' => $email,
            'permission' => $permission
        ];
    }


    public function getByShareTokenUserId($shareToken, $userId): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM calendars 
            WHERE share_token = ? AND user_id = ? AND deleted_at IS NULL
        ");

        $stmt->execute([$shareToken, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getEventsForCalendar($calendarId, $startDate = null, $endDate = null): array
    {
       
        
        $sql = "SELECT * FROM calendar_events WHERE calendar_id = ? and deleted_at IS NULL";
        $params = [$calendarId];
        
        if ($startDate && $endDate) {
            $sql .= " AND (start_datetime <= ? AND end_datetime >= ?)";
            $params[] = $endDate;
            $params[] = $startDate;
        }
        
        $sql .= " ORDER BY start_datetime ASC";
        
        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public static function generateIcsContent($calendar, $events): string
    {
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//CMEM Calendar//FR\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "X-WR-CALNAME:" . self::escapeIcsText($calendar['title']) . "\r\n";
        
        if (!empty($calendar['description'])) {
            $ics .= "X-WR-CALDESC:" . self::escapeIcsText($calendar['description']) . "\r\n";
        }
        
        $ics .= "X-WR-TIMEZONE:" . $calendar['timezone'] . "\r\n";
        
        foreach ($events as $event) {
            $ics .= self::generateEventIcs($event);
        }
        
        $ics .= "END:VCALENDAR\r\n";
        
        return $ics;
    }
    
    private static function generateEventIcs($event): string
    {
        $eventIcs = "BEGIN:VEVENT\r\n";
        $eventIcs .= "UID:event-" . $event['id'] . "@cmem-calendar.local\r\n";
        
        // Dates
        if ($event['all_day']) {
            $eventIcs .= "DTSTART;VALUE=DATE:" . date('Ymd', strtotime($event['start_datetime'])) . "\r\n";
            $eventIcs .= "DTEND;VALUE=DATE:" . date('Ymd', strtotime($event['end_datetime'] . ' +1 day')) . "\r\n";
        } else {
            $eventIcs .= "DTSTART:" . gmdate('Ymd\THis\Z', strtotime($event['start_datetime'])) . "\r\n";
            $eventIcs .= "DTEND:" . gmdate('Ymd\THis\Z', strtotime($event['end_datetime'])) . "\r\n";
        }
        
        $eventIcs .= "SUMMARY:" . self::escapeIcsText($event['title']) . "\r\n";
        
        if (!empty($event['description'])) {
            $eventIcs .= "DESCRIPTION:" . self::escapeIcsText($event['description']) . "\r\n";
        }
        
        if (!empty($event['location'])) {
            $eventIcs .= "LOCATION:" . self::escapeIcsText($event['location']) . "\r\n";
        }
        
        if (!empty($event['organizer_email'])) {
            $eventIcs .= "ORGANIZER:mailto:" . $event['organizer_email'] . "\r\n";
        }
        
        // Participants
        if (!empty($event['attendees'])) {
            $attendees = json_decode($event['attendees'], true);
            foreach ($attendees as $attendee) {
                $eventIcs .= "ATTENDEE:mailto:" . $attendee['email'] . "\r\n";
            }
        }
        
        // Règle de récurrence
        if (!empty($event['recurrence_rule'])) {
            $eventIcs .= "RRULE:" . $event['recurrence_rule'] . "\r\n";
        }
        
        $eventIcs .= "STATUS:" . strtoupper($event['status']) . "\r\n";
        $eventIcs .= "CREATED:" . gmdate('Ymd\THis\Z', strtotime($event['created_at'])) . "\r\n";
        $eventIcs .= "LAST-MODIFIED:" . gmdate('Ymd\THis\Z', strtotime($event['updated_at'])) . "\r\n";
        $eventIcs .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        
        $eventIcs .= "END:VEVENT\r\n";
        
        return $eventIcs;
    }
    
    private static function escapeIcsText($text): string
    {
        // Échapper les caractères spéciaux pour ICS
        $text = str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', ''], $text);
        return $text;
    }
    
    private static function generateIcsUrl($shareToken): string
    {
        return BASE_URL . '/calendar/' . $shareToken . '.ics';
    }

    /**
     * Vérifie si un utilisateur a accès à un calendrier et retourne le niveau de permission
     */
    public function getUserPermissionForCalendar($calendarId, $userId): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT c.*, 
                   CASE 
                       WHEN c.user_id = ? THEN 'owner'
                       WHEN c.visibility = 'public' THEN 'public'
                       WHEN cs.permission IS NOT NULL THEN cs.permission
                       ELSE NULL
                   END as access_level,
                   cs.permission as share_permission
            FROM calendars c
            LEFT JOIN calendar_shares cs ON c.id = cs.calendar_id 
                AND cs.shared_with_user_id = ? AND cs.deleted_at IS NULL
            WHERE c.id = ? AND c.deleted_at IS NULL
                AND (c.user_id = ? OR c.visibility = 'public' OR cs.shared_with_user_id = ?)
        ");
        
        $stmt->execute([$userId, $userId, $calendarId, $userId, $userId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Vérifie si un utilisateur peut écrire dans un calendrier
     */
    public function canUserWrite($calendarId, $userId): bool
    {
        $permission = $this->getUserPermissionForCalendar($calendarId, $userId);
        
        if (!$permission) {
            return false;
        }
        
        // Le propriétaire a toujours les droits en écriture
        if ($permission['access_level'] === 'owner') {
            return true;
        }
        
        // Pour les calendriers partagés, vérifier la permission
        return $permission['access_level'] === 'write';
    }

    /**
     * Trouve un partage de calendrier par user_id ou email
     */
    public function findCalendarShare($calendarId, $userId = null, $email = null): ?array
    {
        if (!$userId && !$email) {
            return null;
        }

        $sql = "SELECT * FROM calendar_shares WHERE calendar_id = ? AND deleted_at IS NULL";
        $params = [$calendarId];

        if ($userId) {
            $sql .= " AND shared_with_user_id = ?";
            $params[] = $userId;
        } else {
            $sql .= " AND shared_with_email = ?";
            $params[] = $email;
        }

        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Supprime un partage de calendrier (soft delete)
     */
    public function removeShare($calendarId, $targetUserId = null, $targetEmail = null): bool
    {
        if (!$targetUserId && !$targetEmail) {
            return false;
        }

        $sql = "UPDATE calendar_shares SET deleted_at = NOW(), updated_at = NOW() 
                WHERE calendar_id = ? AND deleted_at IS NULL";
        $params = [$calendarId];

        if ($targetUserId) {
            $sql .= " AND shared_with_user_id = ?";
            $params[] = $targetUserId;
        } else {
            $sql .= " AND shared_with_email = ?";
            $params[] = $targetEmail;
        }

        $stmt = $this->getDb()->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Vérifie si un utilisateur peut supprimer un partage
     * - Le propriétaire du calendrier peut supprimer n'importe quel partage
     * - Un utilisateur peut supprimer le partage qui le concerne
     */
    public function canUserRemoveShare($calendarId, $currentUserId, $targetUserId = null, $targetEmail = null): bool
    {
        // Récupérer le calendrier
        $calendar = $this->getById($calendarId);
        if (!$calendar) {
            return false;
        }

        // Si l'utilisateur est propriétaire du calendrier, il peut supprimer n'importe quel partage
        if ($calendar['user_id'] == $currentUserId) {
            return true;
        }

        // Si l'utilisateur veut supprimer son propre partage
        if ($targetUserId && $targetUserId == $currentUserId) {
            return true;
        }

        // Pour les partages par email, vérifier si l'utilisateur actuel correspond à l'email
        if ($targetEmail) {
            // Note: Vous pourriez vouloir ajouter une vérification pour s'assurer que 
            // l'email correspond à l'utilisateur actuel dans votre système
            // Pour l'instant, on permet seulement au propriétaire de supprimer les partages par email
            return false;
        }

        return false;
    }

}
