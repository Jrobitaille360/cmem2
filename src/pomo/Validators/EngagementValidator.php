<?php

namespace Pomo\Validators;

/**
 * EngagementValidator — validation pour POST /pomo/engagement
 *
 * Phase 1A uniquement.
 * Retourne ['valid' => bool, 'errors' => array] dans chaque méthode.
 * Format erreur : ['field' => string, 'code' => string, 'message' => string]
 */
class EngagementValidator
{
    private const VALID_PLATFORMS  = ['android', 'ios', 'web', 'windows', 'macos', 'linux'];
    private const VALID_ANSWERS    = ['yes', 'no', 'maybe'];
    private const SURVEY_QUESTIONS = ['q1', 'q2', 'q3', 'q4', 'q5'];

    public static function validateWaitlist(array $data): array
    {
        $errors = [];

        // device_id
        if (empty($data['device_id'])) {
            $errors[] = ['field' => 'device_id', 'code' => 'required', 'message' => 'device_id est requis'];
        } elseif (strlen($data['device_id']) > 36) {
            $errors[] = ['field' => 'device_id', 'code' => 'too_long', 'message' => 'device_id ne doit pas dépasser 36 caractères'];
        }

        // email
        if (empty($data['email'])) {
            $errors[] = ['field' => 'email', 'code' => 'required', 'message' => 'Le courriel est requis pour une inscription waitlist'];
        } elseif (strlen($data['email']) < 3 || strlen($data['email']) > 254) {
            $errors[] = ['field' => 'email', 'code' => 'invalid_length', 'message' => 'Le courriel doit contenir entre 3 et 254 caractères'];
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = ['field' => 'email', 'code' => 'invalid_format', 'message' => 'Format de courriel invalide'];
        }

        // timestamp_utc
        if (empty($data['timestamp_utc'])) {
            $errors[] = ['field' => 'timestamp_utc', 'code' => 'required', 'message' => 'timestamp_utc est requis'];
        }

        self::validateOptionalFields($data, $errors);

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public static function validateSurvey(array $data): array
    {
        $errors = [];

        // device_id
        if (empty($data['device_id'])) {
            $errors[] = ['field' => 'device_id', 'code' => 'required', 'message' => 'device_id est requis'];
        } elseif (strlen($data['device_id']) > 36) {
            $errors[] = ['field' => 'device_id', 'code' => 'too_long', 'message' => 'device_id ne doit pas dépasser 36 caractères'];
        }

        // responses
        if (empty($data['responses']) || !is_array($data['responses'])) {
            $errors[] = ['field' => 'responses', 'code' => 'required', 'message' => 'responses est requis (objet avec clés q1–q5)'];
        } else {
            foreach (self::SURVEY_QUESTIONS as $q) {
                if (!array_key_exists($q, $data['responses'])) {
                    $errors[] = ['field' => "responses.{$q}", 'code' => 'required', 'message' => "{$q} est requis"];
                } elseif (!in_array($data['responses'][$q], self::VALID_ANSWERS, true)) {
                    $errors[] = ['field' => "responses.{$q}", 'code' => 'invalid_value', 'message' => "{$q} doit être 'yes', 'no' ou 'maybe'"];
                }
            }
        }

        // timestamp_utc
        if (empty($data['timestamp_utc'])) {
            $errors[] = ['field' => 'timestamp_utc', 'code' => 'required', 'message' => 'timestamp_utc est requis'];
        }

        self::validateOptionalFields($data, $errors);

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    private static function validateOptionalFields(array $data, array &$errors): void
    {
        if (isset($data['platform']) && !in_array($data['platform'], self::VALID_PLATFORMS, true)) {
            $errors[] = ['field' => 'platform', 'code' => 'invalid_value', 'message' => 'platform doit être : android, ios, web, windows, macos ou linux'];
        }

        if (isset($data['network_status']) && !in_array($data['network_status'], ['online', 'offline'], true)) {
            $errors[] = ['field' => 'network_status', 'code' => 'invalid_value', 'message' => 'network_status doit être online ou offline'];
        }

        if (isset($data['language']) && strlen($data['language']) > 16) {
            $errors[] = ['field' => 'language', 'code' => 'too_long', 'message' => 'language ne doit pas dépasser 16 caractères'];
        }

        if (isset($data['app_version']) && strlen($data['app_version']) > 32) {
            $errors[] = ['field' => 'app_version', 'code' => 'too_long', 'message' => 'app_version ne doit pas dépasser 32 caractères'];
        }

        if (isset($data['build_number']) && strlen($data['build_number']) > 32) {
            $errors[] = ['field' => 'build_number', 'code' => 'too_long', 'message' => 'build_number ne doit pas dépasser 32 caractères'];
        }

        if (isset($data['session_duration']) && !ctype_digit((string) $data['session_duration'])) {
            $errors[] = ['field' => 'session_duration', 'code' => 'invalid_type', 'message' => 'session_duration doit être un entier positif'];
        }
    }
}
