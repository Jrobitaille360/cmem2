<?php

namespace Quiz\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class Participant extends BaseModel
{
    protected $table = 'quiz_participants';

    public $id;
    public $session_id;
    public $display_name;
    public $device_id;
    public $participant_token;
    public $score;
    public $rank;
    public $joined_at;

    public function create()
    {
        throw new \LogicException('Utiliser createFromData()');
    }

    public function update()
    {
        throw new \LogicException('Utiliser updateScore()');
    }

    /** @return int ID inséré */
    public function createFromData(array $data): int
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO quiz_participants (session_id, display_name, device_id, participant_token, score)
            VALUES (?, ?, ?, ?, 0)
        ");
        $stmt->execute([
            $data['session_id'],
            $data['display_name'],
            $data['device_id'],
            $data['participant_token'],
        ]);
        return (int) $this->getDb()->lastInsertId();
    }

    public function findById($id, $withTrashed = false)
    {
        $stmt = $this->getDb()->prepare("SELECT * FROM quiz_participants WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM quiz_participants WHERE participant_token = ?"
        );
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findBySessionAndDevice(int $sessionId, string $deviceId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM quiz_participants WHERE session_id = ? AND device_id = ? LIMIT 1"
        );
        $stmt->execute([$sessionId, $deviceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function addScore(int $id, int $points): bool
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE quiz_participants SET score = score + ? WHERE id = ?"
        );
        return $stmt->execute([$points, $id]);
    }

    /** Met à jour les rangs de tous les participants d'une session */
    public function updateRanks(int $sessionId): bool
    {
        $stmt = $this->getDb()->prepare("
            UPDATE quiz_participants p
            JOIN (
                SELECT id,
                       RANK() OVER (ORDER BY score DESC) AS new_rank
                FROM quiz_participants
                WHERE session_id = ?
            ) ranked ON ranked.id = p.id
            SET p.rank = ranked.new_rank
            WHERE p.session_id = ?
        ");
        return $stmt->execute([$sessionId, $sessionId]);
    }

    public function getLeaderboard(int $sessionId): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT id, display_name, score, rank
            FROM quiz_participants
            WHERE session_id = ?
            ORDER BY score DESC, joined_at ASC
        ");
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countBySession(int $sessionId): int
    {
        $stmt = $this->getDb()->prepare(
            "SELECT COUNT(*) FROM quiz_participants WHERE session_id = ?"
        );
        $stmt->execute([$sessionId]);
        return (int) $stmt->fetchColumn();
    }

    public function updateToken(int $id, string $token): bool
    {
        $stmt = $this->getDb()->prepare(
            "UPDATE quiz_participants SET participant_token = ? WHERE id = ?"
        );
        return $stmt->execute([$token, $id]);
    }
}
