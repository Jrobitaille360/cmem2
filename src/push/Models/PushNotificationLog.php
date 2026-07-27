<?php

namespace Push\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * Journal d'idempotence : une échéance notifiée = une ligne, quel que soit le nombre
 * d'appareils. La clé unique (owner_id, kind, entity_id, occurrence_key) empêche toute
 * renotification, y compris si le cron tourne deux fois dans la même fenêtre.
 */
class PushNotificationLog extends BaseModel
{
    protected $table = 'push_notification_log';

    public function create() { throw new \LogicException('Utiliser claim()'); }
    public function update() { throw new \LogicException('Utiliser complete()'); }

    /**
     * Réserve l'échéance. Retourne l'id de la ligne créée, ou null si elle existait déjà
     * (échéance déjà notifiée → ne rien envoyer).
     */
    public function claim(
        string $appId,
        int    $ownerId,
        string $kind,
        int    $entityId,
        string $occurrenceKey,
        string $fireAtUtc
    ): ?int {
        $stmt = $this->getDb()->prepare(
            "INSERT IGNORE INTO {$this->table}
                 (app_id, owner_id, kind, entity_id, occurrence_key, fire_at, status)
             VALUES (?, ?, ?, ?, ?, ?, 'sent')"
        );
        $stmt->execute([$appId, $ownerId, $kind, $entityId, $occurrenceKey, $fireAtUtc]);

        return $stmt->rowCount() > 0 ? (int) $this->getDb()->lastInsertId() : null;
    }

    /** Renseigne le résultat de l'envoi une fois les appareils traités. */
    public function complete(int $id, int $devices, int $delivered, ?string $error = null): void
    {
        $status = $delivered > 0 ? 'sent' : 'failed';
        $stmt   = $this->getDb()->prepare(
            "UPDATE {$this->table}
                SET devices = ?, delivered = ?, status = ?, error = ?
              WHERE id = ?"
        );
        $stmt->execute([$devices, $delivered, $status, $error !== null ? substr($error, 0, 255) : null, $id]);
    }

    /** Retire la réservation (échec avant tout envoi : l'échéance reste à traiter). */
    public function release(int $id): void
    {
        $stmt = $this->getDb()->prepare("DELETE FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function existsFor(int $ownerId, string $kind, int $entityId, string $occurrenceKey): bool
    {
        $stmt = $this->getDb()->prepare(
            "SELECT 1 FROM {$this->table}
              WHERE owner_id = ? AND kind = ? AND entity_id = ? AND occurrence_key = ?
              LIMIT 1"
        );
        $stmt->execute([$ownerId, $kind, $entityId, $occurrenceKey]);
        return (bool) $stmt->fetchColumn();
    }

    /** Purge des traces anciennes (maintenance ; sans effet sur l'idempotence courante). */
    public function purgeOlderThan(int $days = 90): int
    {
        $stmt = $this->getDb()->prepare(
            "DELETE FROM {$this->table} WHERE sent_at < (NOW() - INTERVAL ? DAY)"
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}
