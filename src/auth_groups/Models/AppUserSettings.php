<?php

namespace AuthGroups\Models;

use PDO;

class AppUserSettings extends BaseModel
{
    protected $table = 'app_user_settings';

    public $user_id;
    public $app_id;
    public $pseudonym;

    public function create() { throw new \LogicException('Utiliser set()'); }
    public function update() { throw new \LogicException('Utiliser set()'); }

    public function get(int $userId, string $appId): ?string
    {
        $stmt = $this->getDb()->prepare(
            "SELECT pseudonym FROM {$this->table} WHERE user_id = ? AND app_id = ?"
        );
        $stmt->execute([$userId, $appId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['pseudonym'] : null;
    }

    public function set(int $userId, string $appId, string $pseudonym): void
    {
        $db = $this->getDb();
        $upd = $db->prepare(
            "UPDATE {$this->table} SET pseudonym = ? WHERE user_id = ? AND app_id = ?"
        );
        $upd->execute([$pseudonym, $userId, $appId]);

        if ($upd->rowCount() === 0) {
            $db->prepare(
                "INSERT INTO {$this->table} (user_id, app_id, pseudonym) VALUES (?, ?, ?)"
            )->execute([$userId, $appId, $pseudonym]);
        }
    }

    public function clear(int $userId, string $appId): void
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE {$this->table} SET pseudonym = NULL WHERE user_id = ? AND app_id = ?"
        );
        $stmt->execute([$userId, $appId]);
    }

    public function isAvailable(string $appId, string $pseudonym, int $excludeUserId): bool
    {
        $stmt = $this->getDb()->prepare(
            "SELECT 1 FROM {$this->table} WHERE app_id = ? AND pseudonym = ? AND user_id != ?"
        );
        $stmt->execute([$appId, $pseudonym, $excludeUserId]);
        return $stmt->fetch() === false;
    }
}
