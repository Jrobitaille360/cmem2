<?php

namespace ICS\Utils;

use AuthGroups\Services\LogService;

/**
 * Classe utilitaire pour valider les nouveaux champs d'événement
 */
class EventValidator
{
    /**
     * Liste des fuseaux horaires valides
     */
    private static $validTimezones = [
        'America/Montreal',
        'America/Toronto',
        'America/New_York',
        'America/Chicago',
        'America/Denver',
        'America/Los_Angeles',
        'America/Vancouver',
        'Europe/Paris',
        'Europe/London',
        'Europe/Berlin',
        'UTC'
    ];

    /**
     * Valide un fuseau horaire
     * 
     * @param string $timezone Le fuseau horaire à valider
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateTimezone($timezone): array
    {
        if (empty($timezone)) {
            return ['valid' => true, 'error' => null]; // Optionnel
        }

        if (!in_array($timezone, self::$validTimezones)) {
            return [
                'valid' => false,
                'error' => 'Fuseau horaire invalide. Valeurs acceptées: ' . implode(', ', self::$validTimezones)
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Valide un lien de réunion
     * 
     * @param string $meetingLink Le lien à valider
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateMeetingLink($meetingLink): array
    {
        if (empty($meetingLink)) {
            return ['valid' => true, 'error' => null]; // Optionnel
        }

        // Vérifier que c'est une URL valide
        if (!filter_var($meetingLink, FILTER_VALIDATE_URL)) {
            return [
                'valid' => false,
                'error' => 'Le lien de réunion doit être une URL valide'
            ];
        }

        // Vérifier que l'URL commence par http ou https
        if (!preg_match('/^https?:\/\/.+/', $meetingLink)) {
            return [
                'valid' => false,
                'error' => 'Le lien de réunion doit commencer par http:// ou https://'
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Valide un code couleur hexadécimal
     * 
     * @param string $color Le code couleur à valider
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateColor($color): array
    {
        if (empty($color)) {
            return ['valid' => true, 'error' => null]; // Optionnel
        }

        // Vérifier le format hexadécimal #RRGGBB
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return [
                'valid' => false,
                'error' => 'Le format de couleur doit être au format hexadécimal #RRGGBB (ex: #4285F4)'
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Valide un tableau de notifications
     * 
     * @param mixed $notifications Les notifications à valider (JSON string ou array)
     * @return array ['valid' => bool, 'error' => string|null, 'data' => array|null]
     */
    public static function validateNotifications($notifications): array
    {
        if (empty($notifications)) {
            return ['valid' => true, 'error' => null, 'data' => []]; // Optionnel
        }

        // Si c'est une string JSON, la décoder
        if (is_string($notifications)) {
            $decoded = json_decode($notifications, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'valid' => false,
                    'error' => 'Le format des notifications est invalide (JSON invalide)',
                    'data' => null
                ];
            }
            
            $notifications = $decoded;
        }

        // Vérifier que c'est un tableau
        if (!is_array($notifications)) {
            return [
                'valid' => false,
                'error' => 'Les notifications doivent être un tableau',
                'data' => null
            ];
        }

        // Valider chaque notification
        foreach ($notifications as $index => $notification) {
            // Vérifier que c'est un objet/tableau associatif
            if (!is_array($notification)) {
                return [
                    'valid' => false,
                    'error' => "La notification #{$index} doit être un objet",
                    'data' => null
                ];
            }

            // Vérifier la présence des champs requis
            if (!isset($notification['type']) || !isset($notification['minutes'])) {
                return [
                    'valid' => false,
                    'error' => "La notification #{$index} doit contenir les champs 'type' et 'minutes'",
                    'data' => null
                ];
            }

            // Valider le type
            if (!in_array($notification['type'], ['notification', 'e-mail'])) {
                return [
                    'valid' => false,
                    'error' => "Le type de notification #{$index} doit être 'notification' ou 'e-mail'",
                    'data' => null
                ];
            }

            // Valider les minutes
            if (!is_numeric($notification['minutes']) || $notification['minutes'] < 0) {
                return [
                    'valid' => false,
                    'error' => "La durée de notification #{$index} doit être un nombre positif ou zéro",
                    'data' => null
                ];
            }
        }

        return ['valid' => true, 'error' => null, 'data' => $notifications];
    }

    /**
     * Valide tous les nouveaux champs d'un événement
     * 
     * @param array $data Les données à valider
     * @return array ['valid' => bool, 'errors' => array, 'data' => array]
     */
    public static function validateEventFields(array $data): array
    {
        $errors = [];
        $validatedData = [];

        // Valider timezone
        if (isset($data['timezone'])) {
            $result = self::validateTimezone($data['timezone']);
            if (!$result['valid']) {
                $errors['timezone'] = $result['error'];
            } else {
                $validatedData['timezone'] = $data['timezone'];
            }
        }

        // Valider meeting_link
        if (isset($data['meeting_link'])) {
            $result = self::validateMeetingLink($data['meeting_link']);
            if (!$result['valid']) {
                $errors['meeting_link'] = $result['error'];
            } else {
                $validatedData['meeting_link'] = $data['meeting_link'];
            }
        }

        // Valider color
        if (isset($data['color'])) {
            $result = self::validateColor($data['color']);
            if (!$result['valid']) {
                $errors['color'] = $result['error'];
            } else {
                $validatedData['color'] = $data['color'];
            }
        }

        // Valider notifications
        if (isset($data['notifications'])) {
            $result = self::validateNotifications($data['notifications']);
            if (!$result['valid']) {
                $errors['notifications'] = $result['error'];
            } else {
                $validatedData['notifications'] = $result['data'];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $validatedData
        ];
    }
}
