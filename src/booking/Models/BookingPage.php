<?php

namespace Booking\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * booking_pages — une ligne par hôte (unique owner_id+app_id, unique app_id+slug).
 * Directive 20260813_163000_cmem_web_vers_cmem2_API__booking-public.md.
 */
class BookingPage extends BaseModel
{
    protected $table = 'booking_pages';

    public function create() { throw new \LogicException('Utiliser upsert()'); }
    public function update() { throw new \LogicException('Utiliser upsert()'); }

    /** booking_pages n'a pas de deleted_at — surcharge le findById hérité (BaseModel en suppose un). */
    public function findById($id, $withTrashed = false)
    {
        $stmt = $this->getDb()->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByOwnerAndApp(int $ownerId, string $appId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table} WHERE owner_id = ? AND app_id = ?"
        );
        $stmt->execute([$ownerId, $appId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findBySlug(string $appId, string $slug): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table} WHERE app_id = ? AND slug = ?"
        );
        $stmt->execute([$appId, $slug]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * UPSERT sur (owner_id, app_id). Retourne la ligne à jour.
     */
    public function upsert(array $data): array
    {
        $stmt = $this->getDb()->prepare(
            "INSERT INTO {$this->table}
                (owner_id, app_id, calendar_id, slug, duration_minutes, buffer_before_minutes,
                 buffer_after_minutes, timezone, horizon_days, availability_windows,
                 event_title_template, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 calendar_id            = VALUES(calendar_id),
                 slug                   = VALUES(slug),
                 duration_minutes       = VALUES(duration_minutes),
                 buffer_before_minutes  = VALUES(buffer_before_minutes),
                 buffer_after_minutes   = VALUES(buffer_after_minutes),
                 timezone               = VALUES(timezone),
                 horizon_days           = VALUES(horizon_days),
                 availability_windows   = VALUES(availability_windows),
                 event_title_template   = VALUES(event_title_template),
                 active                 = VALUES(active)"
        );
        $stmt->execute([
            $data['owner_id'],
            $data['app_id'],
            $data['calendar_id'],
            $data['slug'],
            $data['duration_minutes'],
            $data['buffer_before_minutes'],
            $data['buffer_after_minutes'],
            $data['timezone'],
            $data['horizon_days'],
            $data['availability_windows'],
            $data['event_title_template'],
            $data['active'] ? 1 : 0,
        ]);

        return $this->findByOwnerAndApp((int) $data['owner_id'], (string) $data['app_id']) ?? [];
    }

    public function deactivate(int $id): void
    {
        $this->getDb()->prepare("UPDATE {$this->table} SET active = 0 WHERE id = ?")->execute([$id]);
    }
}
