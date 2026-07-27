<?php

namespace Push\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * Subscription Web Push d'un appareil.
 *
 * Unicité (owner_id, endpoint_hash) : un ré-abonnement du même appareil met à jour
 * la ligne existante. L'endpoint complet est conservé en TEXT (jusqu'à ~1 Ko chez
 * certains services de push) ; son SHA-256 porte l'index unique.
 */
class PushSubscription extends BaseModel
{
    protected $table = 'push_subscriptions';

    public function create() { throw new \LogicException('Utiliser upsert()'); }
    public function update() { throw new \LogicException('Utiliser upsert()'); }

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * Crée ou met à jour la subscription.
     *
     * @return array{subscription: array, created: bool}
     */
    public function upsert(
        int     $ownerId,
        string  $appId,
        string  $endpoint,
        string  $p256dh,
        string  $auth,
        ?string $deviceLabel
    ): array {
        $hash     = self::hash($endpoint);
        $existing = $this->findByOwnerAndEndpoint($ownerId, $endpoint);

        if ($existing) {
            $stmt = $this->getDb()->prepare(
                "UPDATE {$this->table}
                    SET app_id = ?, endpoint = ?, p256dh = ?, auth = ?, device_label = ?
                  WHERE id = ?"
            );
            $stmt->execute([$appId, $endpoint, $p256dh, $auth, $deviceLabel, $existing['id']]);

            return ['subscription' => $this->findById((int) $existing['id']), 'created' => false];
        }

        $stmt = $this->getDb()->prepare(
            "INSERT INTO {$this->table}
                 (app_id, owner_id, endpoint, endpoint_hash, p256dh, auth, device_label)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$appId, $ownerId, $endpoint, $hash, $p256dh, $auth, $deviceLabel]);

        return ['subscription' => $this->findById((int) $this->getDb()->lastInsertId()), 'created' => true];
    }

    public function findById($id, $withTrashed = false)
    {
        $stmt = $this->getDb()->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByOwnerAndEndpoint(int $ownerId, string $endpoint): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table} WHERE owner_id = ? AND endpoint_hash = ?"
        );
        $stmt->execute([$ownerId, self::hash($endpoint)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<int, array> */
    public function listByOwner(int $ownerId): array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table} WHERE owner_id = ? ORDER BY id ASC"
        );
        $stmt->execute([$ownerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteByOwnerAndEndpoint(int $ownerId, string $endpoint): bool
    {
        $stmt = $this->getDb()->prepare(
            "DELETE FROM {$this->table} WHERE owner_id = ? AND endpoint_hash = ?"
        );
        $stmt->execute([$ownerId, self::hash($endpoint)]);
        return $stmt->rowCount() > 0;
    }

    /** Purge d'un endpoint rejeté par le service de push (410 Gone / 404). */
    public function deleteById(int $id): bool
    {
        $stmt = $this->getDb()->prepare("DELETE FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function touchLastSeen(int $id): void
    {
        $stmt = $this->getDb()->prepare("UPDATE {$this->table} SET last_seen_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
    }

    /**
     * Identifiants des usagers possédant au moins une subscription.
     *
     * @return array<int, int>
     */
    public function ownersWithSubscriptions(?int $onlyOwnerId = null): array
    {
        $sql    = "SELECT DISTINCT owner_id FROM {$this->table}";
        $params = [];
        if ($onlyOwnerId !== null) {
            $sql .= " WHERE owner_id = ?";
            $params[] = $onlyOwnerId;
        }
        $stmt = $this->getDb()->prepare($sql . " ORDER BY owner_id ASC");
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function toContract(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'app_id'       => $row['app_id'],
            'endpoint'     => $row['endpoint'],
            'device_label' => $row['device_label'],
            'created_at'   => $row['created_at'],
            'last_seen_at' => $row['last_seen_at'],
        ];
    }
}
