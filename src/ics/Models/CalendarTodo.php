<?php

namespace ICS\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * Modèle VTODO — Phase 5.1
 * Table : calendar_todos
 */
class CalendarTodo extends BaseModel
{
    protected $table = 'calendar_todos';

    /** Fenêtre (jours) au-delà de laquelle un élément soft-deleted n'est plus restaurable via l'API */
    public const RESTORE_RETENTION_DAYS = 30;

    public $id;
    public $calendarId;
    public $userId;
    public $uid;
    public $title;
    public $description;
    public $due;
    public $dtstart;
    public $completed;
    public $status;
    public $priority;
    public $percentComplete;
    public $location;
    public $categories;
    public $url;
    public $relatedTo;
    public $recurrenceRule;
    public $organizerEmail;
    public $organizerName;
    public $attendees;
    public $sequence;
    public $timezone;
    public $isAllDay;

    public function __construct()
    {
        parent::__construct();
    }

    private static function generateUuidV4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function isValidUid(string $uid): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uid
        );
    }

    public function create(): array
    {
        // Préserver l'UID ICS si valide (import), sinon en générer un nouveau conforme RFC 5545 §3.8.4.7
        if (empty($this->uid) || !self::isValidUid($this->uid)) {
            $this->uid = self::generateUuidV4();
        }

        $stmt = $this->getDb()->prepare("
            INSERT INTO calendar_todos
                (calendar_id, user_id, uid, title, description, due, dtstart, completed,
                 status, priority, percent_complete, location, categories, url,
                 related_to, recurrence_rule, organizer_email, organizer_name, attendees, sequence, timezone, is_all_day)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $stmt->execute([
            $this->calendarId,
            $this->userId,
            $this->uid,
            $this->title,
            $this->description ?? null,
            $this->due ?? null,
            $this->dtstart ?? null,
            $this->completed ?? null,
            $this->status ?? 'NEEDS-ACTION',
            $this->priority ?? 0,
            $this->percentComplete ?? 0,
            $this->location ?? null,
            isset($this->categories) ? json_encode($this->categories) : null,
            $this->url ?? null,
            $this->relatedTo ?? null,
            $this->recurrenceRule ?? null,
            $this->organizerEmail ?? null,
            $this->organizerName ?? null,
            isset($this->attendees) ? json_encode($this->attendees) : null,
            $this->sequence ?? 0,
            $this->timezone ?? 'America/Montreal',
            isset($this->isAllDay) ? (int)(bool)$this->isAllDay : 0,
        ]);

        $id = (int)$this->getDb()->lastInsertId();
        return $this->getById($id);
    }

    public function update(): bool
    {
        $fields = [];
        $params = [];

        $map = [
            'title'            => 'title',
            'description'      => 'description',
            'due'              => 'due',
            'dtstart'          => 'dtstart',
            'completed'        => 'completed',
            'status'           => 'status',
            'priority'         => 'priority',
            'percentComplete'  => 'percent_complete',
            'location'         => 'location',
            'url'              => 'url',
            'relatedTo'        => 'related_to',
            'recurrenceRule'   => 'recurrence_rule',
            'organizerEmail'   => 'organizer_email',
            'organizerName'    => 'organizer_name',
            'timezone'         => 'timezone',
            'isAllDay'         => 'is_all_day',
        ];

        foreach ($map as $prop => $col) {
            if (isset($this->$prop)) {
                $fields[] = "$col = ?";
                $params[] = $this->$prop;
            }
        }

        if (isset($this->categories)) {
            $fields[] = 'categories = ?';
            $params[] = json_encode($this->categories);
        }
        if (isset($this->attendees)) {
            $fields[] = 'attendees = ?';
            $params[] = json_encode($this->attendees);
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'sequence = sequence + 1';
        $params[] = $this->id;

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields)
             . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";

        return $this->getDb()->prepare($sql)->execute($params);
    }

    public function countByUserId(int $userId): int
    {
        $stmt = $this->getDb()->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE user_id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM {$this->table} WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decode($row) : null;
    }

    public function getByUid(string $uid): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM {$this->table} WHERE uid = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decode($row) : null;
    }

    public function getByCalendarId(int $calendarId, ?string $status = null): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE calendar_id = ? AND deleted_at IS NULL AND project_id IS NULL";
        $params = [$calendarId];

        if ($status !== null) {
            $sql .= " AND status = ?";
            $params[] = strtoupper($status);
        }

        $sql .= " ORDER BY due ASC, created_at ASC";

        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'decode'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function softDeleteById(int $id): bool
    {
        $stmt = $this->getDb()->prepare("
            UPDATE {$this->table} SET deleted_at = NOW(), updated_at = NOW()
            WHERE id = ? AND deleted_at IS NULL
        ");
        return $stmt->execute([$id]);
    }

    /**
     * Récupère les tâches soft-deleted d'un calendrier (corbeille), triées deleted_at DESC.
     * Limité à la fenêtre de rétention (RESTORE_RETENTION_DAYS).
     */
    public function getDeletedByCalendarId(int $calendarId, int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;
        $stmt = $this->getDb()->prepare("
            SELECT * FROM {$this->table}
            WHERE calendar_id = :calendar_id
              AND deleted_at IS NOT NULL
              AND deleted_at >= NOW() - INTERVAL " . self::RESTORE_RETENTION_DAYS . " DAY
            ORDER BY deleted_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':calendar_id', $calendarId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'decode'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function isOwner(int $todoId, int $calendarId, int $userId): bool
    {
        $stmt = $this->getDb()->prepare("
            SELECT id FROM {$this->table}
            WHERE id = ? AND calendar_id = ? AND user_id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$todoId, $calendarId, $userId]);
        return $stmt->fetchColumn() !== false;
    }

    private function decode(array $row): array
    {
        if (isset($row['categories']) && is_string($row['categories'])) {
            $row['categories'] = json_decode($row['categories'], true) ?? [];
        }
        if (isset($row['attendees']) && is_string($row['attendees'])) {
            $row['attendees'] = json_decode($row['attendees'], true) ?? [];
        }
        if (isset($row['is_all_day'])) {
            $row['is_all_day'] = (bool) $row['is_all_day'];
        }
        if (isset($row['priority'])) {
            $row['priority'] = (int) $row['priority'];
        }
        if (isset($row['percent_complete'])) {
            $row['percent_complete'] = (int) $row['percent_complete'];
        }
        return $row;
    }
}
