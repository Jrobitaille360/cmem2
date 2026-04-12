<?php

namespace Puzzle\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class PuzzleDevice extends BaseModel
{
    protected $table = 'puzzle_devices';

    public function create()
    {
        throw new \LogicException('Utiliser createFromData()');
    }

    public function update()
    {
        throw new \LogicException('Utiliser les méthodes spécifiques');
    }

    /** Enregistre un nouvel appareil ou renouvelle le token si l'UUID existe déjà. */
    public function upsert(string $deviceUuid, string $deviceToken, string $tokenExpiresAt): int
    {
        $existing = $this->findByUuid($deviceUuid);

        if ($existing) {
            $stmt = $this->getDb()->prepare("
                UPDATE puzzle_devices
                SET device_token = ?, token_expires_at = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$deviceToken, $tokenExpiresAt, $existing['id']]);
            return (int) $existing['id'];
        }

        $stmt = $this->getDb()->prepare("
            INSERT INTO puzzle_devices (device_uuid, device_token, token_expires_at)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$deviceUuid, $deviceToken, $tokenExpiresAt]);
        return (int) $this->getDb()->lastInsertId();
    }

    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM puzzle_devices WHERE device_uuid = ?"
        );
        $stmt->execute([$uuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Retourne l'appareil si le token est valide (non expiré), null sinon. */
    public function findByValidToken(string $token): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM puzzle_devices WHERE device_token = ? AND token_expires_at > NOW()"
        );
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Recherche insensible à la casse (unicité). */
    public function findByPseudonymCI(string $pseudonym): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM puzzle_devices WHERE LOWER(pseudonym) = LOWER(?)"
        );
        $stmt->execute([$pseudonym]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function setPseudonym(int $id, string $pseudonym): void
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE puzzle_devices SET pseudonym = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$pseudonym, $id]);
    }

    public function clearPseudonym(int $id): void
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE puzzle_devices SET pseudonym = NULL, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    public function updateSubscription(int $id, array $data): void
    {
        $stmt = $this->getDb()->prepare("
            UPDATE puzzle_devices
            SET is_premium = ?, purchase_token = ?, product_id = ?, premium_expires_at = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $data['is_premium'],
            $data['purchase_token'],
            $data['product_id'],
            $data['premium_expires_at'],
            $id,
        ]);
    }

    public function setLastReplacedAt(int $id): void
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE puzzle_devices SET last_replaced_at = CURDATE(), updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    public function saveBackup(int $id, string $backupJson): void
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE puzzle_devices SET backup_json = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$backupJson, $id]);
    }

    public function touchLastSeen(int $id): void
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE puzzle_devices SET last_seen_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$id]);
    }
}
