<?php

namespace Quiz\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class Session extends BaseModel
{
    protected $table = 'quiz_sessions';

    public $id;
    public $quiz_id;
    public $host_user_id;
    public $session_code;
    public $status;
    public $current_question_idx;
    public $created_at;
    public $started_at;
    public $ended_at;

    public function create()
    {
        throw new \LogicException('Utiliser createFromData()');
    }

    public function update()
    {
        throw new \LogicException('Utiliser updateStatus()');
    }

    /** @return int ID inséré */
    public function createFromData(array $data): int
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO quiz_sessions (quiz_id, host_user_id, session_code, status, current_question_idx)
            VALUES (?, ?, ?, 'waiting', -1)
        ");
        $stmt->execute([
            $data['quiz_id'],
            $data['host_user_id'],
            $data['session_code'],
        ]);
        return (int) $this->getDb()->lastInsertId();
    }

    public function findById($id, $withTrashed = false)
    {
        $stmt = $this->getDb()->prepare("SELECT * FROM quiz_sessions WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByIdAndHost(int $id, int $hostUserId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM quiz_sessions WHERE id = ? AND host_user_id = ?"
        );
        $stmt->execute([$id, $hostUserId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM quiz_sessions WHERE session_code = ?"
        );
        $stmt->execute([$code]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function codeExists(string $code): bool
    {
        $stmt = $this->getDb()->prepare(
            "SELECT id FROM quiz_sessions WHERE session_code = ? LIMIT 1"
        );
        $stmt->execute([$code]);
        return (bool) $stmt->fetchColumn();
    }

    public function advanceQuestion(int $id, int $newIdx): bool
    {
        $stmt = $this->getDb()->prepare("
            UPDATE quiz_sessions
            SET status = 'active', current_question_idx = ?, started_at = COALESCE(started_at, NOW())
            WHERE id = ?
        ");
        return $stmt->execute([$newIdx, $id]);
    }

    public function end(int $id): bool
    {
        $stmt = $this->getDb()->prepare("
            UPDATE quiz_sessions
            SET status = 'ended', ended_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$id]);
    }

    /** Historique des sessions d'un hôte */
    public function listByHost(int $hostUserId): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT s.*, q.title AS quiz_title
            FROM quiz_sessions s
            JOIN quiz_quizzes q ON q.id = s.quiz_id
            WHERE s.host_user_id = ?
            ORDER BY s.created_at DESC
        ");
        $stmt->execute([$hostUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listByQuiz(int $quizId): array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM quiz_sessions WHERE quiz_id = ? ORDER BY created_at DESC"
        );
        $stmt->execute([$quizId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
