<?php

namespace AuthGroups\Utils;

use AuthGroups\Utils\ColorName;

class Validator {

    /**
     * Valider des données selon des règles
     */
    public static function validate($data, $rules): array {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                self::applyRule($field, $value, $rule, $data, $errors);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Appliquer une règle de validation
     */
    private static function applyRule($field, $value, $rule, $allData, array &$errors): void {
        $parts = explode(':', $rule, 2);
        $ruleName = $parts[0];
        $parameter = $parts[1] ?? null;
        
        switch ($ruleName) {
            case 'required':
                if (!isset($value) || $value === '') {
                    self::addError($field, "Le champ {$field} est requis", $errors);
                }
                break;
                
            case 'string':
                if ($value !== null && !is_string($value)) {
                    self::addError($field, "Le champ {$field} doit être une chaîne de caractères", $errors);
                }
                break;

            case 'email':
                if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    self::addError($field, "Le champ {$field} doit être un email valide", $errors);
                }
                break;

            case 'min':
                if ($value !== null) {
                    $length = is_string($value) ? strlen($value) : $value;
                    if ($length < (int)$parameter) {
                        self::addError($field, "Le champ {$field} doit avoir au minimum {$parameter} caractères", $errors);
                    }
                }
                break;

            case 'max':
                if ($value !== null) {
                    $length = is_string($value) ? strlen($value) : $value;
                    if ($length > (int)$parameter) {
                        self::addError($field, "Le champ {$field} doit avoir au maximum {$parameter} caractères", $errors);
                    }
                }
                break;

            case 'integer':
                if ($value !== null && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    self::addError($field, "Le champ {$field} doit être un entier", $errors);
                }
                break;

            case 'numeric':
                if ($value !== null && !is_numeric($value)) {
                    self::addError($field, "Le champ {$field} doit être numérique", $errors);
                }
                break;

            case 'boolean':
                if ($value !== null && !is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'])) {
                    self::addError($field, "Le champ {$field} doit être un booléen", $errors);
                }
                break;

            case 'date':
                if ($value !== null && !self::isValidDate($value)) {
                    self::addError($field, "Le champ {$field} doit être une date valide (YYYY-MM-DD)", $errors);
                }
                break;

            case 'datetime':
                if ($value !== null && !self::isValidDateTime($value)) {
                    self::addError($field, "Le champ {$field} doit être une date/heure valide (YYYY-MM-DD HH:MM:SS)", $errors);
                }
                break;

            case 'date_or_datetime':
                if ($value !== null && !self::isValidDate($value) && !self::isValidDateTime($value)) {
                    self::addError($field, "Le champ {$field} doit être une date ou une date/heure valide", $errors);
                }
                break;

            case 'url':
                if ($value !== null && !filter_var($value, FILTER_VALIDATE_URL)) {
                    self::addError($field, "Le champ {$field} doit être une URL valide", $errors);
                }
                break;

            case 'in':
                if ($value !== null) {
                    $allowedValues = explode(',', $parameter);
                    if (!in_array($value, $allowedValues)) {
                        self::addError($field, "Le champ {$field} doit être l'une des valeurs suivantes: " . implode(', ', $allowedValues), $errors);
                    }
                }
                break;

            case 'unique':
                // Cette règle nécessiterait une connexion à la base de données
                break;

            case 'confirmed':
                $confirmField = $field . '_confirmation';
                if ($value !== ($allData[$confirmField] ?? null)) {
                    self::addError($field, "Le champ {$field} et sa confirmation ne correspondent pas", $errors);
                }
                break;

            case 'array':
                if ($value !== null && !is_array($value)) {
                    self::addError($field, "Le champ {$field} doit être un tableau", $errors);
                }
                break;

            case 'json':
                if ($value !== null && json_decode($value) === null && json_last_error() !== JSON_ERROR_NONE) {
                    self::addError($field, "Le champ {$field} doit être un JSON valide", $errors);
                }
                break;

            case 'alpha':
                if ($value !== null && !ctype_alpha($value)) {
                    self::addError($field, "Le champ {$field} ne doit contenir que des lettres", $errors);
                }
                break;

            case 'alpha_num':
                if ($value !== null && !ctype_alnum($value)) {
                    self::addError($field, "Le champ {$field} ne doit contenir que des lettres et des chiffres", $errors);
                }
                break;

            case 'regex':
                if ($value !== null && !preg_match($parameter, $value)) {
                    self::addError($field, "Le champ {$field} ne correspond pas au format requis", $errors);
                }
                break;

            case 'file':
                if ($value !== null && !is_uploaded_file($value['tmp_name'] ?? '')) {
                    self::addError($field, "Le champ {$field} doit être un fichier valide", $errors);
                }
                break;

            case 'image':
                if ($value !== null) {
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $mimeType = $value['type'] ?? '';
                    if (!in_array($mimeType, $allowedMimes)) {
                        self::addError($field, "Le champ {$field} doit être une image valide (JPEG, PNG, GIF, WebP)", $errors);
                    }
                }
                break;

            case 'max_size':
                if ($value !== null && isset($value['size'])) {
                    $maxSize = self::parseSize($parameter);
                    if ($value['size'] > $maxSize) {
                        self::addError($field, "Le fichier {$field} ne doit pas dépasser " . self::formatSize($maxSize), $errors);
                    }
                }
                break;

            case 'color':
                if ($value !== null && !self::validateColor($value)) {
                    self::addError($field, "Le champ {$field} doit être une couleur valide (ex: #RRGGBB, RED, rgb(255,0,0), hsl(120,50%,50%))", $errors);
                }
                break;

            case 'hex_color':
                if ($value !== null && !self::validateHexColor($value)) {
                    self::addError($field, "Le champ {$field} doit être une couleur hexadécimale valide (#RRGGBB)", $errors);
                }
                break;
        }
    }

    /**
     * Ajouter une erreur
     */
    private static function addError(string $field, string $message, array &$errors): void {
        if (!isset($errors[$field])) {
            $errors[$field] = [];
        }
        $errors[$field][] = $message;
    }
    
    /**
     * Vérifier si une date est valide
     * Accepte plusieurs formats: Y-m-d, d/m/Y, m/d/Y, Y/m/d, d-m-Y, m-d-Y
     */
    public static function isValidDate($date) {
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'Y/m/d', 'd-m-Y', 'm-d-Y'];
        
        foreach ($formats as $format) {
            $d = \DateTime::createFromFormat($format, $date);
            if ($d && $d->format($format) === $date) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Vérifier si une date/heure est valide
     * Accepte plusieurs formats avec ou sans secondes, avec différents séparateurs
     */
    public static function isValidDateTime($datetime) {
        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'm/d/Y H:i:s',
            'm/d/Y H:i',
            'Y/m/d H:i:s',
            'Y/m/d H:i',
            'd-m-Y H:i:s',
            'd-m-Y H:i',
            'm-d-Y H:i:s',
            'm-d-Y H:i',
            'Y-m-d\TH:i:s',          // Format ISO 8601
            'Y-m-d\TH:i:s.v',        // Format ISO 8601 avec millisecondes
            'Y-m-d\TH:i:s.u',        // Format ISO 8601 avec microsecondes
            'Y-m-d\TH:i:sP',         // Format ISO 8601 avec timezone
            'Y-m-d\TH:i:s.vP',       // Format ISO 8601 avec millisecondes et timezone
            'Y-m-d\TH:i:s\Z',        // Format ISO 8601 UTC
            'Y-m-d\TH:i:s.v\Z',      // Format ISO 8601 UTC avec millisecondes
        ];
        
        foreach ($formats as $format) {
            $d = \DateTime::createFromFormat($format, $datetime);
            if ($d && $d->format($format) === $datetime) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Convertir une taille en octets
     */
    public static  function parseSize($size) {
        $units = ['B' => 1, 'KB' => 1024, 'MB' => 1048576, 'GB' => 1073741824];
        
        if (is_numeric($size)) {
            return (int)$size;
        }
        
        if (preg_match('/^(\d+(\.\d+)?)\s*(B|KB|MB|GB)$/i', $size, $matches)) {
            $value = (float)$matches[1];
            $unit = strtoupper($matches[3]);
            return (int)($value * $units[$unit]);
        }
        
        return 0;
    }
    
    /**
     * Formater une taille en unité lisible
     */
    public static  function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
    
    /**
     * Valider les types de médias autorisés
     */
    public static  function validateMediaType($mediaType) {
        $allowedTypes = ['text', 'audio', 'video', 'image', 'gpx', 'summary', 'event', 'todo', 'document'];
        return in_array($mediaType, $allowedTypes);
    }
    
    /**
     * Valider les niveaux de visibilité
     */
    public static  function validateVisibility($visibility) {
        $allowedVisibilities = ['private', 'shared', 'public'];
        return in_array($visibility, $allowedVisibilities);
    }
    
    /**
     * Valider les rôles d'utilisateur
     */
    public static  function validateUserRole($role) {
        $allowedRoles = ['SUPERADMINISTRATEUR', 'ADMINISTRATEUR', 'UTILISATEUR'];
        return in_array($role, $allowedRoles);
    }
    
    /**
     * Valider les rôles de groupe
     */
    public static  function validateGroupRole($role) {
        $allowedRoles = ['admin', 'moderator', 'member'];
        return in_array($role, $allowedRoles);
    }
    
    /**
     * Nettoyer et valider les coordonnées GPS
     */
    public static  function validateCoordinates($latitude, $longitude) {
        $lat = filter_var($latitude, FILTER_VALIDATE_FLOAT);
        $lon = filter_var($longitude, FILTER_VALIDATE_FLOAT);
        
        if ($lat === false || $lon === false) {
            return false;
        }
        
        return $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180;
    }
    
    /**
     * Valider une couleur (tous formats supportés par ColorName)
     */
    public static  function validateColor($color) {
        if (empty($color)) {
            return false;
        }
        
        // Utilise la méthode stringToColor de ColorName qui supporte:
        // - Noms de couleurs (RED, BLUE, etc.)
        // - Hexadécimal (#RRGGBB, 0xRRGGBB, %23RRGGBB)
        // - RGB(r, g, b)
        // - RGBA(r, g, b, a)
        // - HSL(h, s%, l%)
        // - HSLA(h, s%, l%, a)
        $result = ColorName::stringToColor($color);
        
        return $result !== null;
    }
    
    /**
     * Valider une couleur hexadécimale
     */
    public static  function validateHexColor($color) {
        // Méthode mise à jour pour utiliser ColorName
        if (empty($color)) {
            return false;
        }
        
        // Vérifier le format hexadécimal strict #RRGGBB
        if (!preg_match('/^#[a-fA-F0-9]{6}$/', $color)) {
            return false;
        }
        
        // Vérifier que ColorName peut le parser
        $result = ColorName::stringToColor($color);
        return $result !== null;
    }
}
