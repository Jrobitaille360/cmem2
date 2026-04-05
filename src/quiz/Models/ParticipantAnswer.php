<?php

namespace Quiz\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class ParticipantAnswer extends BaseModel
{
    protected $table = 'quiz_participant_answers';

    public $id;
    public $participant_id;
    public $session_id;
    public $question_id;
    public $value;
    public $is_correct;
    public $points_earned;
    public $response_time_ms;
    public $created_at;

    public function create()
    {
        throw new \LogicException('Utiliser createFromData()');
    }

    public function update()
    {
        throw new \LogicException('Non supporté');
    }

    /** @return int ID inséré */
    public function createFromData(array $data): int
    {
        $stmt = $this->getDb()->prepare("
            INSERT INTO quiz_participant_answers
                (participant_id, session_id, question_id, value, is_correct, points_earned, response_time_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['participant_id'],
            $data['session_id'],
            $data['question_id'],
            $data['value'],
            (int) ($data['is_correct']       ?? 0),
            (int) ($data['points_earned']     ?? 0),
            (int) ($data['response_time_ms']  ?? 0),
        ]);
        return (int) $this->getDb()->lastInsertId();
    }

    public function findById($id, $withTrashed = false)
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM quiz_participant_answers WHERE id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function existsByParticipantAndQuestion(int $participantId, int $questionId): bool
    {
        $stmt = $this->getDb()->prepare("
            SELECT id FROM quiz_participant_answers
            WHERE participant_id = ? AND question_id = ?
            LIMIT 1
        ");
        $stmt->execute([$participantId, $questionId]);
        return (bool) $stmt->fetchColumn();
    }

    public function findBySessionAndQuestion(int $sessionId, int $questionId): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT a.*, p.display_name
            FROM quiz_participant_answers a
            JOIN quiz_participants p ON p.id = a.participant_id
            WHERE a.session_id = ? AND a.question_id = ?
        ");
        $stmt->execute([$sessionId, $questionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Agrégation résultats par question pour une session */
    public function getResultsBySession(int $sessionId): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT
                a.question_id,
                COUNT(*)                                     AS total_answers,
                SUM(a.is_correct)                            AS correct_count,
                ROUND(AVG(a.response_time_ms))               AS avg_response_time_ms,
                ROUND(AVG(a.is_correct) * 100, 1)            AS correct_pct
            FROM quiz_participant_answers a
            WHERE a.session_id = ?
            GROUP BY a.question_id
        ");
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByParticipant(int $participantId): array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM quiz_participant_answers WHERE participant_id = ? ORDER BY created_at ASC"
        );
        $stmt->execute([$participantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
