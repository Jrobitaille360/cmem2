<?php

namespace Memories\Utils;

class JsonValidator 
{
    /**
     * Valider que le JSON est bien formé avant de le traiter
     */
    public static function validateAndDecode(string $json): array {
        if (empty($json)) {
            throw new \InvalidArgumentException("JSON vide");
        }
        
        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException("JSON malformé: " . json_last_error_msg());
        }
        
        if (!is_array($data)) {
            throw new \InvalidArgumentException("JSON doit être un objet");
        }
        
        return $data;
    }
}