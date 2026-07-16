<?php

namespace WebDevice\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class WebDevice extends BaseModel
{
    protected $table = 'web_devices';

    public function create() { throw new \LogicException('Utiliser upsertDevice()'); }
    public function update() { throw new \LogicException('Utiliser upsertDevice()'); }

    public function upsertDevice(
        ?int   $userId,
        string $appId,
        string $deviceUuid,
        string $deviceToken,
        string $tokenExpiresAt
    ): array {
        $stmt = $this->getDb()->prepare(
            "INSERT INTO {$this->table}
                 (user_id, app_id, device_uuid, device_token, token_expires_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                 user_id          = COALESCE(VALUES(user_id), user_id),
                 device_token     = VALUES(device_token),
                 token_expires_at = VALUES(token_expires_at),
                 last_seen_at     = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$userId, $appId, $deviceUuid, $deviceToken, $tokenExpiresAt]);

        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table} WHERE app_id = ? AND device_uuid = ?"
        );
        $stmt->execute([$appId, $deviceUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByAppAndUuid(string $appId, string $deviceUuid): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table} WHERE app_id = ? AND device_uuid = ?"
        );
        $stmt->execute([$appId, $deviceUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function countByUserAndApp(int $userId, string $appId): int
    {
        $stmt = $this->getDb()->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE user_id = ? AND app_id = ?"
        );
        $stmt->execute([$userId, $appId]);
        return (int) $stmt->fetchColumn();
    }

    public function findByValidToken(string $token): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table} WHERE device_token = ? AND token_expires_at > NOW()"
        );
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function touchLastSeen(int $id): void
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE {$this->table} SET last_seen_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    public function setLastReplacedAt(int $id): void
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE {$this->table} SET last_replaced_at = CURDATE() WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    public function setUserId(int $id, int $userId): void
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE {$this->table} SET user_id = ? WHERE id = ?"
        );
        $stmt->execute([$userId, $id]);
    }

    public function saveBackup(int $id, string $json): void
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE {$this->table} SET backup_json = ?, backup_saved_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$json, $id]);
    }

    public function findLatestWithBackupByUser(int $userId, string $appId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = ? AND app_id = ? AND backup_json IS NOT NULL
             ORDER BY backup_saved_at DESC LIMIT 1"
        );
        $stmt->execute([$userId, $appId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
