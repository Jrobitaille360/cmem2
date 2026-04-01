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
            if (!in_array($notification['type'], ['notification', 'email'])) {
                return [
                    'valid' => false,
                    'error' => "Le type de notification #{$index} doit être 'notification' ou 'email'",
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
     * Valide la priorité RFC 5545 (0–9).
     */
    public static function validatePriority($priority): array
    {
        if ($priority === null || $priority === '') {
            return ['valid' => true, 'error' => null];
        }
        $v = (int)$priority;
        if (!is_numeric($priority) || $v < 0 || $v > 9) {
            return ['valid' => false, 'error' => 'La priorité doit être un entier entre 0 et 9 (0=non défini, 1=haute, 5=normale, 9=basse)'];
        }
        return ['valid' => true, 'error' => null, 'value' => $v];
    }

    /**
     * Valide le champ CLASS (PUBLIC, PRIVATE, CONFIDENTIAL).
     */
    public static function validateClass($class): array
    {
        if (empty($class)) {
            return ['valid' => true, 'error' => null];
        }
        $upper = strtoupper($class);
        if (!in_array($upper, ['PUBLIC', 'PRIVATE', 'CONFIDENTIAL'], true)) {
            return ['valid' => false, 'error' => "Le champ class doit être PUBLIC, PRIVATE ou CONFIDENTIAL"];
        }
        return ['valid' => true, 'error' => null, 'value' => $upper];
    }

    /**
     * Valide le champ TRANSP (OPAQUE, TRANSPARENT).
     */
    public static function validateTransp($transp): array
    {
        if (empty($transp)) {
            return ['valid' => true, 'error' => null];
        }
        $upper = strtoupper($transp);
        if (!in_array($upper, ['OPAQUE', 'TRANSPARENT'], true)) {
            return ['valid' => false, 'error' => "Le champ transp doit être OPAQUE ou TRANSPARENT"];
        }
        return ['valid' => true, 'error' => null, 'value' => $upper];
    }

    /**
     * Valide les catégories (tableau de chaînes ou JSON string).
     */
    public static function validateCategories($categories): array
    {
        if (empty($categories)) {
            return ['valid' => true, 'error' => null, 'data' => []];
        }
        if (is_string($categories)) {
            $decoded = json_decode($categories, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['valid' => false, 'error' => 'Les catégories doivent être un tableau JSON valide', 'data' => null];
            }
            $categories = $decoded;
        }
        if (!is_array($categories)) {
            return ['valid' => false, 'error' => 'Les catégories doivent être un tableau', 'data' => null];
        }
        foreach ($categories as $i => $cat) {
            if (!is_string($cat) || trim($cat) === '') {
                return ['valid' => false, 'error' => "La catégorie #{$i} doit être une chaîne non vide", 'data' => null];
            }
        }
        return ['valid' => true, 'error' => null, 'data' => array_values($categories)];
    }

    /**
     * Valide les coordonnées géographiques (lat/lng).
     */
    public static function validateGeo($lat, $lng): array
    {
        $hasLat = ($lat !== null && $lat !== '');
        $hasLng = ($lng !== null && $lng !== '');

        if (!$hasLat && !$hasLng) {
            return ['valid' => true, 'error' => null];
        }
        if ($hasLat !== $hasLng) {
            return ['valid' => false, 'error' => 'geo_lat et geo_lng doivent être fournis ensemble'];
        }
        if (!is_numeric($lat) || $lat < -90 || $lat > 90) {
            return ['valid' => false, 'error' => 'geo_lat doit être un nombre entre -90 et 90'];
        }
        if (!is_numeric($lng) || $lng < -180 || $lng > 180) {
            return ['valid' => false, 'error' => 'geo_lng doit être un nombre entre -180 et 180'];
        }
        return ['valid' => true, 'error' => null];
    }

    /**
     * Valide les pièces jointes (tableau JSON [{url, mime_type}]).
     */
    public static function validateAttachments($attachments): array
    {
        if (empty($attachments)) {
            return ['valid' => true, 'error' => null, 'data' => []];
        }
        if (is_string($attachments)) {
            $decoded = json_decode($attachments, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['valid' => false, 'error' => 'Les pièces jointes doivent être un tableau JSON valide', 'data' => null];
            }
            $attachments = $decoded;
        }
        if (!is_array($attachments)) {
            return ['valid' => false, 'error' => 'Les pièces jointes doivent être un tableau', 'data' => null];
        }
        foreach ($attachments as $i => $att) {
            if (!is_array($att)) {
                return ['valid' => false, 'error' => "La pièce jointe #{$i} doit être un objet", 'data' => null];
            }
            // Doit avoir soit url soit data_base64
            if (empty($att['url']) && empty($att['data_base64'])) {
                return ['valid' => false, 'error' => "La pièce jointe #{$i} doit avoir un champ 'url' ou 'data_base64'", 'data' => null];
            }
            if (!empty($att['url']) && !filter_var($att['url'], FILTER_VALIDATE_URL)) {
                return ['valid' => false, 'error' => "La pièce jointe #{$i} : 'url' doit être une URL valide", 'data' => null];
            }
        }
        return ['valid' => true, 'error' => null, 'data' => array_values($attachments)];
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

        // Phase 2 — nouveaux champs
        if (isset($data['priority'])) {
            $result = self::validatePriority($data['priority']);
            if (!$result['valid']) {
                $errors['priority'] = $result['error'];
            } else {
                $validatedData['priority'] = $result['value'] ?? (int)$data['priority'];
            }
        }

        if (isset($data['class'])) {
            $result = self::validateClass($data['class']);
            if (!$result['valid']) {
                $errors['class'] = $result['error'];
            } else {
                $validatedData['class'] = $result['value'] ?? strtoupper($data['class']);
            }
        }

        if (isset($data['transp'])) {
            $result = self::validateTransp($data['transp']);
            if (!$result['valid']) {
                $errors['transp'] = $result['error'];
            } else {
                $validatedData['transp'] = $result['value'] ?? strtoupper($data['transp']);
            }
        }

        if (isset($data['categories'])) {
            $result = self::validateCategories($data['categories']);
            if (!$result['valid']) {
                $errors['categories'] = $result['error'];
            } else {
                $validatedData['categories'] = $result['data'];
            }
        }

        $hasLat = isset($data['geo_lat']) && $data['geo_lat'] !== '';
        $hasLng = isset($data['geo_lng']) && $data['geo_lng'] !== '';
        if ($hasLat || $hasLng) {
            $result = self::validateGeo($data['geo_lat'] ?? null, $data['geo_lng'] ?? null);
            if (!$result['valid']) {
                $errors['geo'] = $result['error'];
            } else {
                $validatedData['geo_lat'] = isset($data['geo_lat']) ? (float)$data['geo_lat'] : null;
                $validatedData['geo_lng'] = isset($data['geo_lng']) ? (float)$data['geo_lng'] : null;
            }
        }

        if (isset($data['attachments'])) {
            $result = self::validateAttachments($data['attachments']);
            if (!$result['valid']) {
                $errors['attachments'] = $result['error'];
            } else {
                $validatedData['attachments'] = $result['data'];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $validatedData
        ];
    }
}
