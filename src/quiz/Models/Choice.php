<?php

namespace Quiz\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class Choice extends BaseModel
{
    protected $table = 'quiz_choices';

    public $id;
    public $question_id;
    public $position;
    public $content;
    public $is_correct;

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
            INSERT INTO quiz_choices (question_id, position, content, is_correct)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['question_id'],
            $data['position'] ?? 0,
            is_array($data['content']) ? json_encode($data['content']) : $data['content'],
            isset($data['is_correct']) ? (int) $data['is_correct'] : 0,
        ]);
        return (int) $this->getDb()->lastInsertId();
    }

    public function updateFromData(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        if (isset($data['position'])) {
            $fields[] = 'position = ?';
            $params[]  = $data['position'];
        }
        if (isset($data['content'])) {
            $fields[] = 'content = ?';
            $params[]  = is_array($data['content'])
                ? json_encode($data['content'])
                : $data['content'];
        }
        if (isset($data['is_correct'])) {
            $fields[] = 'is_correct = ?';
            $params[]  = (int) $data['is_correct'];
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $stmt = $this->getDb()->prepare(
            "UPDATE quiz_choices SET " . implode(', ', $fields) . " WHERE id = ?"
        );
        return $stmt->execute($params);
    }

    public function findById($id, $withTrashed = false)
    {
        $stmt = $this->getDb()->prepare("SELECT * FROM quiz_choices WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByQuestionId(int $questionId): array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM quiz_choices WHERE question_id = ? ORDER BY position ASC"
        );
        $stmt->execute([$questionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array Associe question_id → [choices] */
    public function findByQuestionIds(array $questionIds): array
    {
        if (empty($questionIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM quiz_choices WHERE question_id IN ($placeholders) ORDER BY position ASC"
        );
        $stmt->execute($questionIds);
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['question_id']][] = $row;
        }
        return $result;
    }

    public function deleteByQuestionId(int $questionId): bool
    {
        $stmt = $this->getDb()->prepare("DELETE FROM quiz_choices WHERE question_id = ?");
        return $stmt->execute([$questionId]);
    }

    public function findCorrectByQuestion(int $questionId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM quiz_choices WHERE question_id = ? AND is_correct = 1 LIMIT 1"
        );
        $stmt->execute([$questionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
