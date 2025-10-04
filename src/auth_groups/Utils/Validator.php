<?php

namespace Memories\Utils;

class Validator {
    
    private $errors = [];
    
    /**
     * Valider des données selon des règles
     */
    public function validate($data, $rules): array {
        $this->errors = [];
        
        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value = $data[$field] ?? null;
            
            foreach ($fieldRules as $rule) {
                $this->applyRule($field, $value, $rule, $data);
            }
        }
        
        return [
            'valid' => empty($this->errors),
            'errors' => $this->errors
        ];
    }
    
    /**
     * Appliquer une règle de validation
     */
    private function applyRule($field, $value, $rule, $allData) {
        $parts = explode(':', $rule, 2);
        $ruleName = $parts[0];
        $parameter = $parts[1] ?? null;
        
        switch ($ruleName) {
            case 'required':
                if (empty($value) && $value !== '0' && $value !== 0) {
                    $this->addError($field, "Le champ {$field} est requis");
                }
                break;
                
            case 'string':
                if ($value !== null && !is_string($value)) {
                    $this->addError($field, "Le champ {$field} doit être une chaîne de caractères");
                }
                break;
                
            case 'email':
                if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "Le champ {$field} doit être un email valide");
                }
                break;
                
            case 'min':
                if ($value !== null) {
                    $length = is_string($value) ? strlen($value) : $value;
                    if ($length < (int)$parameter) {
                        $this->addError($field, "Le champ {$field} doit avoir au minimum {$parameter} caractères");
                    }
                }
                break;
                
            case 'max':
                if ($value !== null) {
                    $length = is_string($value) ? strlen($value) : $value;
                    if ($length > (int)$parameter) {
                        $this->addError($field, "Le champ {$field} doit avoir au maximum {$parameter} caractères");
                    }
                }
                break;
                
            case 'integer':
                if ($value !== null && !filter_var($value, FILTER_VALIDATE_INT)) {
                    $this->addError($field, "Le champ {$field} doit être un entier");
                }
                break;
                
            case 'numeric':
                if ($value !== null && !is_numeric($value)) {
                    $this->addError($field, "Le champ {$field} doit être numérique");
                }
                break;
                
            case 'boolean':
                if ($value !== null && !is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'])) {
                    $this->addError($field, "Le champ {$field} doit être un booléen");
                }
                break;
                
            case 'date':
                if ($value !== null && !$this->isValidDate($value)) {
                    $this->addError($field, "Le champ {$field} doit être une date valide (YYYY-MM-DD)");
                }
                break;
                
            case 'datetime':
                if ($value !== null && !$this->isValidDateTime($value)) {
                    $this->addError($field, "Le champ {$field} doit être une date/heure valide (YYYY-MM-DD HH:MM:SS)");
                }
                break;
                
            case 'url':
                if ($value !== null && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->addError($field, "Le champ {$field} doit être une URL valide");
                }
                break;
                
            case 'in':
                if ($value !== null) {
                    $allowedValues = explode(',', $parameter);
                    if (!in_array($value, $allowedValues)) {
                        $this->addError($field, "Le champ {$field} doit être l'une des valeurs suivantes: " . implode(', ', $allowedValues));
                    }
                }
                break;
                
            case 'unique':
                // Cette règle nécessiterait une connexion à la base de données
                // Elle pourrait être implémentée si nécessaire
                break;
                
            case 'confirmed':
                $confirmField = $field . '_confirmation';
                if ($value !== ($allData[$confirmField] ?? null)) {
                    $this->addError($field, "Le champ {$field} et sa confirmation ne correspondent pas");
                }
                break;
                
            case 'array':
                if ($value !== null && !is_array($value)) {
                    $this->addError($field, "Le champ {$field} doit être un tableau");
                }
                break;
                
            case 'json':
                if ($value !== null && json_decode($value) === null && json_last_error() !== JSON_ERROR_NONE) {
                    $this->addError($field, "Le champ {$field} doit être un JSON valide");
                }
                break;
                
            case 'alpha':
                if ($value !== null && !ctype_alpha($value)) {
                    $this->addError($field, "Le champ {$field} ne doit contenir que des lettres");
                }
                break;
                
            case 'alpha_num':
                if ($value !== null && !ctype_alnum($value)) {
                    $this->addError($field, "Le champ {$field} ne doit contenir que des lettres et des chiffres");
                }
                break;
                
            case 'regex':
                if ($value !== null && !preg_match($parameter, $value)) {
                    $this->addError($field, "Le champ {$field} ne correspond pas au format requis");
                }
                break;
                
            case 'file':
                if ($value !== null && !is_uploaded_file($value['tmp_name'] ?? '')) {
                    $this->addError($field, "Le champ {$field} doit être un fichier valide");
                }
                break;
                
            case 'image':
                if ($value !== null) {
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $mimeType = $value['type'] ?? '';
                    if (!in_array($mimeType, $allowedMimes)) {
                        $this->addError($field, "Le champ {$field} doit être une image valide (JPEG, PNG, GIF, WebP)");
                    }
                }
                break;
                
            case 'max_size':
                if ($value !== null && isset($value['size'])) {
                    $maxSize = $this->parseSize($parameter);
                    if ($value['size'] > $maxSize) {
                        $this->addError($field, "Le fichier {$field} ne doit pas dépasser " . $this->formatSize($maxSize));
                    }
                }
                break;
        }
    }
    
    /**
     * Ajouter une erreur
     */
    private function addError($field, $message) {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }
    
    /**
     * Vérifier si une date est valide
     */
    private function isValidDate($date) {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    /**
     * Vérifier si une date/heure est valide
     */
    private function isValidDateTime($datetime) {
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
        return $d && $d->format('Y-m-d H:i:s') === $datetime;
    }
    
    /**
     * Convertir une taille en octets
     */
    private function parseSize($size) {
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
    private function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
    
    /**
     * Valider les types de médias autorisés
     */
    public function validateMediaType($mediaType) {
        $allowedTypes = ['text', 'audio', 'video', 'image', 'gpx', 'summary', 'event', 'todo', 'document'];
        return in_array($mediaType, $allowedTypes);
    }
    
    /**
     * Valider les niveaux de visibilité
     */
    public function validateVisibility($visibility) {
        $allowedVisibilities = ['private', 'shared', 'public'];
        return in_array($visibility, $allowedVisibilities);
    }
    
    /**
     * Valider les rôles d'utilisateur
     */
    public function validateUserRole($role) {
        $allowedRoles = ['ADMINISTRATEUR', 'UTILISATEUR'];
        return in_array($role, $allowedRoles);
    }
    
    /**
     * Valider les rôles de groupe
     */
    public function validateGroupRole($role) {
        $allowedRoles = ['admin', 'moderator', 'member'];
        return in_array($role, $allowedRoles);
    }
    
    /**
     * Nettoyer et valider les coordonnées GPS
     */
    public function validateCoordinates($latitude, $longitude) {
        $lat = filter_var($latitude, FILTER_VALIDATE_FLOAT);
        $lon = filter_var($longitude, FILTER_VALIDATE_FLOAT);
        
        if ($lat === false || $lon === false) {
            return false;
        }
        
        return $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180;
    }
    
    /**
     * Valider une couleur hexadécimale
     */
    public function validateHexColor($color) {
        return preg_match('/^#[a-fA-F0-9]{6}$/', $color);
    }
}
