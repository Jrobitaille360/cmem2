<?php

namespace ICS\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * Modèle VJOURNAL — Phase 5.2
 * Table : calendar_journals
 */
class CalendarJournal extends BaseModel
{
    protected $table = 'calendar_journals';

    public $id;
    public $calendarId;
    public $userId;
    public $uid;
    public $summary;
    public $description;
    public $dtstart;
    public $status;
    public $categories;
    public $url;
    public $relatedTo;
    public $organizerEmail;
    public $organizerName;
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
            INSERT INTO calendar_journals
                (calendar_id, user_id, uid, summary, description, dtstart,
                 status, categories, url, related_to, organizer_email, organizer_name,
                 sequence, timezone)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $stmt->execute([
            $this->calendarId,
            $this->userId,
            $this->uid,
            $this->summary,
            $this->description ?? null,
            $this->dtstart ?? null,
            $this->status ?? 'DRAFT',
            isset($this->categories) ? json_encode($this->categories) : null,
            $this->url ?? null,
            $this->relatedTo ?? null,
            $this->organizerEmail ?? null,
            $this->organizerName ?? null,
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
            'summary'        => 'summary',
            'description'    => 'description',
            'dtstart'        => 'dtstart',
            'status'         => 'status',
            'url'            => 'url',
            'relatedTo'      => 'related_to',
            'organizerEmail' => 'organizer_email',
            'organizerName'  => 'organizer_name',
            'timezone'       => 'timezone',
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

    public function getByCalendarId(int $calendarId): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM {$this->table}
            WHERE calendar_id = ? AND deleted_at IS NULL
            ORDER BY dtstart DESC, created_at DESC
        ");
        $stmt->execute([$calendarId]);
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

    public function isOwner(int $journalId, int $calendarId, int $userId): bool
    {
        $stmt = $this->getDb()->prepare("
            SELECT id FROM {$this->table}
            WHERE id = ? AND calendar_id = ? AND user_id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$journalId, $calendarId, $userId]);
        return $stmt->fetchColumn() !== false;
    }

    private function decode(array $row): array
    {
        if (isset($row['categories']) && is_string($row['categories'])) {
            $row['categories'] = json_decode($row['categories'], true) ?? [];
        }
        return $row;
    }
}
