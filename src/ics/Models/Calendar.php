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
            INSERT INTO calendars (user_id, title, description, timezone, color, is_public, share_token)
            VALUES (?, ?, ?, ?, ?, ?, ?)
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

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";

        $stmt = $this->getDb()->prepare($sql);

        return $stmt->execute($params);
    }

    public function getUserCalendars($userId): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT c.*, 
                   COUNT(ce.id) as event_count,
                   CONCAT(?, '/calendar/', c.share_token, '.ics') as ics_url
            FROM calendars c
            LEFT JOIN calendar_events ce ON c.id = ce.calendar_id
            WHERE c.user_id = ?
            GROUP BY c.id
            ORDER BY c.created_at DESC
        ");
        
        $stmt->execute([BASE_URL, $userId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getByShareToken($shareToken): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM calendars 
            WHERE share_token = ? AND visibility = 'public' 
        ");
       
        $stmt->execute([$shareToken]);     
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getById($calendarId): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM calendars 
            WHERE id = ?
        ");
        $stmt->execute([$calendarId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function shareWith($calendarId, $userId): array
    {
        // Vérifier si le partage existe déjà
        $stmt = $this->getDb()->prepare("
            SELECT * FROM calendar_shares 
            WHERE calendar_id = ? AND user_id = ?
        ");
        $stmt->execute([$calendarId, $userId]);
        $existingShare = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingShare) {
            return $existingShare; // Retourner le partage existant
        }

        // Créer un nouveau partage
        $stmt = $this->getDb()->prepare("
            INSERT INTO calendar_shares (calendar_id, user_id, created_at)
            VALUES (?, ?, NOW())
        ");

        $stmt->execute([$calendarId, $userId]);
        $shareId = $this->getDb()->lastInsertId();

        return [
            'id' => $shareId,
            'calendar_id' => $calendarId,
            'user_id' => $userId
        ];
    }


    public function getByShareTokenUserId($shareToken, $userId): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM calendars 
            WHERE share_token = ? AND user_id = ? 
        ");

        $stmt->execute([$shareToken, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function getEventsForCalendar($calendarId, $startDate = null, $endDate = null): array
    {
        $db = \Database::getInstance();
        
        $sql = "SELECT * FROM calendar_events WHERE calendar_id = ?";
        $params = [$calendarId];
        
        if ($startDate && $endDate) {
            $sql .= " AND (start_datetime <= ? AND end_datetime >= ?)";
            $params[] = $endDate;
            $params[] = $startDate;
        }
        
        $sql .= " ORDER BY start_datetime ASC";
        
        $stmt = $db->prepare($sql);
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

}
