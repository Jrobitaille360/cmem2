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

    /** Fenêtre (jours) au-delà de laquelle un élément soft-deleted n'est plus restaurable via l'API */
    public const RESTORE_RETENTION_DAYS = 30;

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
    public $clearRelatedTo = false; // true = remise à NULL explicite (retrait du lien)

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

        if ($this->clearRelatedTo) {
            $fields[] = 'related_to = NULL';
        } elseif (isset($this->relatedTo)) {
            $fields[] = 'related_to = ?';
            $params[] = $this->relatedTo;
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

    /**
     * Récupère les journaux soft-deleted d'un calendrier (corbeille), triés deleted_at DESC.
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
