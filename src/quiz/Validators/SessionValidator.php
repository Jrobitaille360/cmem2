<?php

namespace Quiz\Validators;

/**
 * SessionValidator — validation join et answer
 *
 * Retourne ['valid' => bool, 'errors' => array]
 * Format erreur : ['field' => string, 'code' => string, 'message' => string]
 */
class SessionValidator
{
    public static function validateJoin(array $data): array
    {
        $errors = [];

        // session_code
        if (empty($data['session_code'])) {
            $errors[] = ['field' => 'session_code', 'code' => 'required',
                'message' => 'session_code est requis'];
        } elseif (!preg_match('/^[A-Z0-9]{6,8}$/i', $data['session_code'])) {
            $errors[] = ['field' => 'session_code', 'code' => 'invalid_format',
                'message' => 'session_code doit contenir 6 à 8 caractères alphanumériques'];
        }

        // display_name
        if (empty($data['display_name'])) {
            $errors[] = ['field' => 'display_name', 'code' => 'required',
                'message' => 'display_name est requis'];
        } elseif (strlen($data['display_name']) > 64) {
            $errors[] = ['field' => 'display_name', 'code' => 'too_long',
                'message' => 'display_name ne doit pas dépasser 64 caractères'];
        } elseif (strlen(trim($data['display_name'])) < 1) {
            $errors[] = ['field' => 'display_name', 'code' => 'blank',
                'message' => 'display_name ne peut pas être vide'];
        }

        // device_id
        if (empty($data['device_id'])) {
            $errors[] = ['field' => 'device_id', 'code' => 'required',
                'message' => 'device_id est requis'];
        } elseif (strlen($data['device_id']) > 36) {
            $errors[] = ['field' => 'device_id', 'code' => 'too_long',
                'message' => 'device_id ne doit pas dépasser 36 caractères'];
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public static function validateAnswer(array $data): array
    {
        $errors = [];

        if (!isset($data['question_id'])) {
            $errors[] = ['field' => 'question_id', 'code' => 'required',
                'message' => 'question_id est requis'];
        } elseif (!is_int($data['question_id']) && !ctype_digit((string) $data['question_id'])) {
            $errors[] = ['field' => 'question_id', 'code' => 'invalid_type',
                'message' => 'question_id doit être un entier'];
        }

        if (!isset($data['value']) || $data['value'] === '' || $data['value'] === null) {
            $errors[] = ['field' => 'value', 'code' => 'required',
                'message' => 'value est requis (choice_id ou valeur numérique)'];
        }

        if (!isset($data['response_time_ms'])) {
            $errors[] = ['field' => 'response_time_ms', 'code' => 'required',
                'message' => 'response_time_ms est requis'];
        } elseif ((int) $data['response_time_ms'] < 0) {
            $errors[] = ['field' => 'response_time_ms', 'code' => 'invalid_value',
                'message' => 'response_time_ms doit être >= 0'];
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }
}
