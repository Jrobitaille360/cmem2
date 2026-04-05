<?php

namespace Quiz\Validators;

/**
 * QuizValidator — validation CRUD quiz et questions
 *
 * Retourne ['valid' => bool, 'errors' => array]
 * Format erreur : ['field' => string, 'code' => string, 'message' => string]
 */
class QuizValidator
{
    private const VALID_QUIZ_STATUSES         = ['draft', 'active', 'archived'];
    private const VALID_RESULT_VISIBILITY     = ['immediate', 'simultaneous', 'end_only'];
    private const VALID_TIME_MODES            = ['per_question', 'total', 'unlimited'];
    private const VALID_QUESTION_TYPES    = ['mcq', 'truefalse', 'numerical'];
    private const MAX_TITLE_LENGTH        = 255;
    private const MAX_CHOICES_PER_QUESTION = 8;
    private const MIN_CHOICES_MCQ         = 2;

    public static function validateCreate(array $data): array
    {
        $errors = [];

        if (empty($data['title'])) {
            $errors[] = ['field' => 'title', 'code' => 'required', 'message' => 'Le titre est requis'];
        } elseif (strlen($data['title']) > self::MAX_TITLE_LENGTH) {
            $errors[] = ['field' => 'title', 'code' => 'too_long',
                'message' => 'Le titre ne doit pas dépasser ' . self::MAX_TITLE_LENGTH . ' caractères'];
        }

        if (isset($data['status']) && !in_array($data['status'], self::VALID_QUIZ_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'code' => 'invalid_value',
                'message' => "status doit être l'un de : " . implode(', ', self::VALID_QUIZ_STATUSES)];
        }
        if (isset($data['result_visibility']) && !in_array($data['result_visibility'], self::VALID_RESULT_VISIBILITY, true)) {
            $errors[] = ['field' => 'result_visibility', 'code' => 'invalid_value',
                'message' => "result_visibility doit être l'un de : " . implode(', ', self::VALID_RESULT_VISIBILITY)];
        }
        if (isset($data['time_mode']) && !in_array($data['time_mode'], self::VALID_TIME_MODES, true)) {
            $errors[] = ['field' => 'time_mode', 'code' => 'invalid_value',
                'message' => "time_mode doit être l'un de : " . implode(', ', self::VALID_TIME_MODES)];
        }
        if (isset($data['total_time_sec']) && $data['total_time_sec'] !== null
            && (!is_int($data['total_time_sec']) || $data['total_time_sec'] < 10)) {
            $errors[] = ['field' => 'total_time_sec', 'code' => 'invalid_value',
                'message' => 'total_time_sec doit être un entier >= 10'];
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public static function validateUpdate(array $data): array
    {
        $errors = [];

        if (isset($data['title'])) {
            if (empty($data['title'])) {
                $errors[] = ['field' => 'title', 'code' => 'required', 'message' => 'Le titre ne peut pas être vide'];
            } elseif (strlen($data['title']) > self::MAX_TITLE_LENGTH) {
                $errors[] = ['field' => 'title', 'code' => 'too_long',
                    'message' => 'Le titre ne doit pas dépasser ' . self::MAX_TITLE_LENGTH . ' caractères'];
            }
        }
        if (isset($data['status']) && !in_array($data['status'], self::VALID_QUIZ_STATUSES, true)) {
            $errors[] = ['field' => 'status', 'code' => 'invalid_value',
                'message' => "status doit être l'un de : " . implode(', ', self::VALID_QUIZ_STATUSES)];
        }
        if (isset($data['result_visibility']) && !in_array($data['result_visibility'], self::VALID_RESULT_VISIBILITY, true)) {
            $errors[] = ['field' => 'result_visibility', 'code' => 'invalid_value',
                'message' => "result_visibility doit être l'un de : " . implode(', ', self::VALID_RESULT_VISIBILITY)];
        }
        if (isset($data['time_mode']) && !in_array($data['time_mode'], self::VALID_TIME_MODES, true)) {
            $errors[] = ['field' => 'time_mode', 'code' => 'invalid_value',
                'message' => "time_mode doit être l'un de : " . implode(', ', self::VALID_TIME_MODES)];
        }
        if (isset($data['total_time_sec']) && $data['total_time_sec'] !== null
            && (!is_int($data['total_time_sec']) || $data['total_time_sec'] < 10)) {
            $errors[] = ['field' => 'total_time_sec', 'code' => 'invalid_value',
                'message' => 'total_time_sec doit être un entier >= 10'];
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public static function validateQuestion(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        // type
        if (empty($data['type'])) {
            $errors[] = ['field' => 'type', 'code' => 'required', 'message' => 'Le type de question est requis'];
        } elseif (!in_array($data['type'], self::VALID_QUESTION_TYPES, true)) {
            $errors[] = ['field' => 'type', 'code' => 'invalid_value',
                'message' => "type doit être l'un de : " . implode(', ', self::VALID_QUESTION_TYPES)];
        }

        // content
        if (empty($data['content'])) {
            $errors[] = ['field' => 'content', 'code' => 'required', 'message' => 'Le contenu est requis'];
        } elseif (is_array($data['content']) && empty($data['content']['text'])) {
            $errors[] = ['field' => 'content.text', 'code' => 'required',
                'message' => 'content.text est requis'];
        }

        // points
        if (isset($data['points'])) {
            if (!is_int($data['points']) && !ctype_digit((string) $data['points'])) {
                $errors[] = ['field' => 'points', 'code' => 'invalid_type', 'message' => 'points doit être un entier'];
            } elseif ((int) $data['points'] < 0) {
                $errors[] = ['field' => 'points', 'code' => 'invalid_value', 'message' => 'points doit être >= 0'];
            }
        }

        // time_limit_sec
        if (isset($data['time_limit_sec'])) {
            if (!is_int($data['time_limit_sec']) && !ctype_digit((string) $data['time_limit_sec'])) {
                $errors[] = ['field' => 'time_limit_sec', 'code' => 'invalid_type',
                    'message' => 'time_limit_sec doit être un entier'];
            } elseif ((int) $data['time_limit_sec'] < 5 || (int) $data['time_limit_sec'] > 300) {
                $errors[] = ['field' => 'time_limit_sec', 'code' => 'invalid_value',
                    'message' => 'time_limit_sec doit être entre 5 et 300 secondes'];
            }
        }

        // choices pour mcq / truefalse
        // En création ($isUpdate=false) : toujours validé
        // En mise à jour ($isUpdate=true) : validé uniquement si choices est fourni
        $type = $data['type'] ?? '';
        $choicesProvided = array_key_exists('choices', $data);
        if (in_array($type, ['mcq', 'truefalse'], true) && (!$isUpdate || $choicesProvided)) {
            $choices = $data['choices'] ?? [];

            if ($type === 'mcq' && count($choices) < self::MIN_CHOICES_MCQ) {
                $errors[] = ['field' => 'choices', 'code' => 'too_few',
                    'message' => "Une question MCQ doit avoir au moins " . self::MIN_CHOICES_MCQ . " choix"];
            }
            if ($type === 'truefalse' && count($choices) !== 2) {
                $errors[] = ['field' => 'choices', 'code' => 'invalid_count',
                    'message' => "Une question Vrai/Faux doit avoir exactement 2 choix"];
            }
            if (count($choices) > self::MAX_CHOICES_PER_QUESTION) {
                $errors[] = ['field' => 'choices', 'code' => 'too_many',
                    'message' => "Maximum " . self::MAX_CHOICES_PER_QUESTION . " choix par question"];
            }

            $correctCount = 0;
            foreach ($choices as $i => $choice) {
                if (empty($choice['content']['text'] ?? $choice['content'] ?? '')) {
                    $errors[] = ['field' => "choices[$i].content", 'code' => 'required',
                        'message' => "Le texte du choix $i est requis"];
                }
                if (!empty($choice['is_correct'])) {
                    $correctCount++;
                }
            }
            if ($correctCount === 0 && !empty($choices)) {
                $errors[] = ['field' => 'choices', 'code' => 'no_correct_answer',
                    'message' => "Au moins un choix doit être correct"];
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }
}
