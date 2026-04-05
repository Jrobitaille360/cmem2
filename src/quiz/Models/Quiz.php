<?php

namespace Quiz\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class Quiz extends BaseModel
{
    protected $table = 'quiz_quizzes';

    public $id;
    public $user_id;
    public $title;
    public $description;
    public $status;
    public $result_visibility;
    public $time_mode;
    public $total_time_sec;
    public $created_at;
    public $updated_at;

    public function create()
    {
        throw new \LogicException('Utiliser createFromData()');
    }

    public function update()
    {
        throw new \LogicException('Utiliser updateFromData()');
    }

    /** @return int ID inséré */
    public function createFromData(array $data): int
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO quiz_quizzes (user_id, title, description, status, result_visibility, time_mode, total_time_sec)
            VALUES (?, ?, ?, 'draft', ?, ?, ?)
        ");
        $stmt->execute([
            $data['user_id'],
            $data['title'],
            $data['description'] ?? null,
            $data['result_visibility'] ?? 'immediate',
            $data['time_mode'] ?? 'per_question',
            $data['total_time_sec'] ?? null,
        ]);
        return (int) $this->getDb()->lastInsertId();
    }

    public function updateFromData(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        if (isset($data['title'])) {
            $fields[] = 'title = ?';
            $params[]  = $data['title'];
        }
        if (array_key_exists('description', $data)) {
            $fields[] = 'description = ?';
            $params[]  = $data['description'];
        }
        if (isset($data['status'])) {
            $fields[] = 'status = ?';
            $params[]  = $data['status'];
        }
        if (isset($data['result_visibility'])) {
            $fields[] = 'result_visibility = ?';
            $params[]  = $data['result_visibility'];
        }
        if (isset($data['time_mode'])) {
            $fields[] = 'time_mode = ?';
            $params[]  = $data['time_mode'];
        }
        if (array_key_exists('total_time_sec', $data)) {
            $fields[] = 'total_time_sec = ?';
            $params[]  = $data['total_time_sec'];
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $stmt = $this->getDb()->prepare(
            "UPDATE quiz_quizzes SET " . implode(', ', $fields) . " WHERE id = ?"
        );
        return $stmt->execute($params);
    }

    /** Override pour éviter le filtre deleted_at */
    public function findById($id, $withTrashed = false)
    {
        $stmt = $this->getDb()->prepare("SELECT * FROM quiz_quizzes WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByUserAndId(int $userId, int $id): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM quiz_quizzes WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$id, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function listByUser(int $userId): array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM quiz_quizzes WHERE user_id = ? ORDER BY created_at DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteById(int $id): bool
    {
        $stmt = $this->getDb()->prepare("DELETE FROM quiz_quizzes WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
