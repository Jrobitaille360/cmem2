<?php

namespace Playstore\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class PlaystoreSubscription extends BaseModel
{
    protected $table = 'playstore_subscriptions';

    public $id;
    public $user_id;
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
        int     $userId,
        string  $appId,
        string  $purchaseToken,
        string  $productId,
        string  $status,
        ?string $expiresAt,
        ?string $verifiedAt
    ): void {
        $stmt = $this->getDb()->prepare(
            "INSERT INTO {$this->table}
                 (user_id, app_id, purchase_token, product_id, status, expires_at, verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 status      = VALUES(status),
                 expires_at  = VALUES(expires_at),
                 verified_at = VALUES(verified_at),
                 updated_at  = NOW()"
        );
        $stmt->execute([$userId, $appId, $purchaseToken, $productId, $status, $expiresAt, $verifiedAt]);
    }

    public function findLatestActive(int $userId, string $appId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = ? AND app_id = ? AND status = 'active'
             ORDER BY expires_at DESC
             LIMIT 1"
        );
        $stmt->execute([$userId, $appId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function markCancelled(int $userId, string $appId): int
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE {$this->table}
             SET status = 'cancelled', updated_at = NOW()
             WHERE user_id = ? AND app_id = ? AND status = 'active'"
        );
        $stmt->execute([$userId, $appId]);
        return $stmt->rowCount();
    }

    public function expireStale(int $userId, string $appId): void
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE {$this->table}
             SET status = 'expired', updated_at = NOW()
             WHERE user_id = ? AND app_id = ? AND status = 'active' AND expires_at < NOW()"
        );
        $stmt->execute([$userId, $appId]);
    }
}
