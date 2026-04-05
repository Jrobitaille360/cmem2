<?php

namespace Quiz\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class Question extends BaseModel
{
    protected $table = 'quiz_questions';

    public $id;
    public $quiz_id;
    public $position;
    public $type;
    public $content;
    public $points;
    public $time_limit_sec;
    public $created_at;

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
        $nextPos = $this->getNextPosition((int) $data['quiz_id']);
        $stmt    = $this->getDb()->prepare("
            INSERT INTO quiz_questions (quiz_id, position, type, content, points, time_limit_sec)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['quiz_id'],
            $data['position'] ?? $nextPos,
            $data['type'],
            is_array($data['content']) ? json_encode($data['content']) : $data['content'],
            $data['points']         ?? 100,
            $data['time_limit_sec'] ?? 30,
        ]);
        return (int) $this->getDb()->lastInsertId();
    }

    public function updateFromData(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        foreach (['type', 'points', 'time_limit_sec', 'position'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[]  = $data[$field];
            }
        }
        if (isset($data['content'])) {
            $fields[] = 'content = ?';
            $params[]  = is_array($data['content'])
                ? json_encode($data['content'])
                : $data['content'];
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $stmt = $this->getDb()->prepare(
            "UPDATE quiz_questions SET " . implode(', ', $fields) . " WHERE id = ?"
        );
        return $stmt->execute($params);
    }

    public function findById($id, $withTrashed = false)
    {
        $stmt = $this->getDb()->prepare("SELECT * FROM quiz_questions WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByQuizId(int $quizId): array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY position ASC"
        );
        $stmt->execute([$quizId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByIdAndQuiz(int $id, int $quizId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM quiz_questions WHERE id = ? AND quiz_id = ?"
        );
        $stmt->execute([$id, $quizId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function deleteById(int $id): bool
    {
        $stmt = $this->getDb()->prepare("DELETE FROM quiz_questions WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function countByQuiz(int $quizId): int
    {
        $stmt = $this->getDb()->prepare(
            "SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = ?"
        );
        $stmt->execute([$quizId]);
        return (int) $stmt->fetchColumn();
    }

    private function getNextPosition(int $quizId): int
    {
        $stmt = $this->getDb()->prepare(
            "SELECT COALESCE(MAX(position), -1) + 1 FROM quiz_questions WHERE quiz_id = ?"
        );
        $stmt->execute([$quizId]);
        return (int) $stmt->fetchColumn();
    }
}
