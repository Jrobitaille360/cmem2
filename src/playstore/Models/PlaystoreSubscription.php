<?php

namespace Playstore\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class PlaystoreSubscription extends BaseModel
{
    protected $table = 'playstore_subscriptions';

    public $id;
    public $device_uuid;
    public $app_id;
    public $purchase_token;
    public $product_id;
    public $status;
    public $expires_at;
    public $verified_at;
    public $created_at;
    public $updated_at;

    public function create() { throw new \LogicException('Utiliser upsertSubscription()'); }
    public function update() { throw new \LogicException('Utiliser les méthodes spécifiques'); }

    public function upsertSubscription(
        string  $deviceUuid,
        string  $appId,
        string  $purchaseToken,
        string  $productId,
        string  $status,
        ?string $expiresAt,
        ?string $verifiedAt
    ): void {
        $stmt = $this->getDb()->prepare(
            "INSERT INTO {$this->table}
                 (device_uuid, app_id, purchase_token, product_id, status, expires_at, verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 purchase_token = VALUES(purchase_token),
                 product_id     = VALUES(product_id),
                 status         = VALUES(status),
                 expires_at     = VALUES(expires_at),
                 verified_at    = VALUES(verified_at),
                 updated_at     = UTC_TIMESTAMP()"
        );
        $stmt->execute([$deviceUuid, $appId, $purchaseToken, $productId, $status, $expiresAt, $verifiedAt]);
    }

    public function findByDevice(string $deviceUuid, string $appId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table}
             WHERE device_uuid = ? AND app_id = ?
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $stmt->execute([$deviceUuid, $appId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findActive(string $deviceUuid, string $appId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table}
             WHERE device_uuid = ? AND app_id = ? AND status = 'active'
             LIMIT 1"
        );
        $stmt->execute([$deviceUuid, $appId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function markCancelled(string $deviceUuid, string $appId): int
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE {$this->table}
             SET status = 'cancelled', updated_at = UTC_TIMESTAMP()
             WHERE device_uuid = ? AND app_id = ? AND status = 'active'"
        );
        $stmt->execute([$deviceUuid, $appId]);
        return $stmt->rowCount();
    }

    public function expireByToken(string $purchaseToken, string $appId): bool
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE {$this->table}
             SET status = 'expired', updated_at = UTC_TIMESTAMP()
             WHERE purchase_token = ? AND app_id = ? AND status = 'active'"
        );
        $stmt->execute([$purchaseToken, $appId]);
        return $stmt->rowCount() > 0;
    }

    public function expireStale(string $deviceUuid, string $appId): void
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE {$this->table}
             SET status = 'expired', updated_at = UTC_TIMESTAMP()
             WHERE device_uuid = ? AND app_id = ? AND status = 'active' AND expires_at < UTC_TIMESTAMP()"
        );
        $stmt->execute([$deviceUuid, $appId]);
    }
}
