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
    public $organizerEmail;
    public $organizerName;
    public $attendees;
    public $sequence;
    public $timezone;

    public function __construct()
    {
        parent::__construct();
    }

    public function create(): array
    {
        $this->uid = \Ramsey\Uuid\Uuid::uuid4()->toString() . '@cmem2';

        $stmt = $this->getDb()->prepare("
            INSERT INTO calendar_todos
                (calendar_id, user_id, uid, title, description, due, dtstart, completed,
                 status, priority, percent_complete, location, categories, url,
                 related_to, organizer_email, organizer_name, attendees, sequence, timezone)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
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
            $this->organizerEmail ?? null,
            $this->organizerName ?? null,
            isset($this->attendees) ? json_encode($this->attendees) : null,
            $this->sequence ?? 0,
            $this->timezone ?? 'America/Montreal',
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
            'organizerEmail'   => 'organizer_email',
            'organizerName'    => 'organizer_name',
            'timezone'         => 'timezone',
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
        $sql = "SELECT * FROM {$this->table} WHERE calendar_id = ? AND deleted_at IS NULL";
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

    public function softDelete(int $id): bool
    {
        $stmt = $this->getDb()->prepare("
            UPDATE {$this->table} SET deleted_at = NOW(), updated_at = NOW()
            WHERE id = ? AND deleted_at IS NULL
        ");
        return $stmt->execute([$id]);
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
        return $row;
    }
}
