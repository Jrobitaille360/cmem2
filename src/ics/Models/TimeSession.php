<?php

namespace ICS\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * Modèle Session de temps — Directive D3 (2026-08-14)
 * Table : time_sessions
 */
class TimeSession extends BaseModel
{
    protected $table = 'time_sessions';

    public $id;
    public $todoId;
    public $userId;
    public $startedAt;
    public $endedAt;
    public $note;
    /** Algorithme de chiffrement client (ex. AES-GCM-256) ; NULL = note en clair */
    public $encAlg;
    /** Vecteur d'initialisation base64 ; NULL = note en clair */
    public $encIv;
    public $clearEncAlg = false;    // true = remise à NULL explicite (retour au clair)
    public $clearEncIv  = false;    // true = remise à NULL explicite (retour au clair)

    public function __construct()
    {
        parent::__construct();
    }

    public function create(): array
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO time_sessions (todo_id, user_id, started_at, note, enc_alg, enc_iv)
            VALUES (?, ?, NOW(), ?, ?, ?)
        ");
        $stmt->execute([
            $this->todoId,
            $this->userId,
            $this->note ?? null,
            $this->encAlg ?? null,
            $this->encIv ?? null,
        ]);

        $id = (int)$this->getDb()->lastInsertId();
        return $this->getById($id);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->getDb()->prepare("SELECT * FROM time_sessions WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getByTodoId(int $todoId): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM time_sessions WHERE todo_id = ? ORDER BY started_at DESC
        ");
        $stmt->execute([$todoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActiveForUser(int $userId): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT * FROM time_sessions WHERE user_id = ? AND ended_at IS NULL LIMIT 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function stop(int $id): array
    {
        $stmt = $this->getDb()->prepare("
            UPDATE time_sessions SET ended_at = NOW() WHERE id = ? AND ended_at IS NULL
        ");
        $stmt->execute([$id]);
        return $this->getById($id);
    }

    public function update(): bool
    {
        $fields = [];
        $params = [];

        foreach (['startedAt' => 'started_at', 'endedAt' => 'ended_at', 'note' => 'note'] as $prop => $col) {
            if (isset($this->$prop)) {
                $fields[] = "{$col} = ?";
                $params[] = $this->$prop;
            }
        }

        if ($this->clearEncAlg) {
            $fields[] = 'enc_alg = NULL';
        } elseif (isset($this->encAlg)) {
            $fields[] = 'enc_alg = ?';
            $params[] = $this->encAlg;
        }

        if ($this->clearEncIv) {
            $fields[] = 'enc_iv = NULL';
        } elseif (isset($this->encIv)) {
            $fields[] = 'enc_iv = ?';
            $params[] = $this->encIv;
        }

        if (empty($fields)) {
            return true;
        }

        $params[] = $this->id;
        $stmt = $this->getDb()->prepare("UPDATE time_sessions SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($params);
    }

    public function deleteById(int $id): bool
    {
        $stmt = $this->getDb()->prepare("DELETE FROM time_sessions WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function isOwner(int $sessionId, int $userId): bool
    {
        $stmt = $this->getDb()->prepare("SELECT id FROM time_sessions WHERE id = ? AND user_id = ?");
        $stmt->execute([$sessionId, $userId]);
        return $stmt->fetchColumn() !== false;
    }
}
