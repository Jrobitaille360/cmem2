<?php

namespace Memories\Utils;

use Memories\Services\LogService;

class FileValidator {
    
    /**
     * Valider un fichier uploadé
     */
    public static function validateUploadedFile($file, $type = 'any') {
        $errors = [];
        // Vérifier si le fichier a été uploadé
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            $errors[] = 'Aucun fichier n\'a été uploadé';
            return ['valid' => false, 'errors' => $errors];
        }
        
        // Vérifier les erreurs d'upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = self::getUploadErrorMessage($file['error']);
            return ['valid' => false, 'errors' => $errors];
        }
        
        // Vérifier la taille du fichier
        $maxSize = self::getMaxSizeForType($type);
        if ($file['size'] > $maxSize) {
            $maxSizeMB = round($maxSize / 1024 / 1024, 1);
            $errors[] = "Le fichier est trop volumineux. Taille maximale autorisée : {$maxSizeMB}MB";
        }
        
        // Vérifier le type MIME
        $allowedTypes = self::getAllowedTypesForCategory($type);
        $fileMimeType = mime_content_type($file['tmp_name']);
        
        if (!in_array($fileMimeType, $allowedTypes)) {
            $errors[] = "Type de fichier non autorisé. Types acceptés : " . implode(', ', $allowedTypes);
        }
        
        // Vérifier l'extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = self::getAllowedExtensionsForCategory($type);
        
        if (!in_array($extension, $allowedExtensions)) {
            $errors[] = "Extension de fichier non autorisée. Extensions acceptées : " . implode(', ', $allowedExtensions);
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'file_info' => [
                'original_name' => $file['name'],
                'mime_type' => $fileMimeType,
                'size' => $file['size'],
                'extension' => $extension
            ]
        ];
    }
    
    /**
     * Obtenir les types MIME autorisés pour une catégorie
     */
    public static function getAllowedTypesForCategory($category) {
        switch (strtolower($category)) {
            case 'image':
                return ALLOWED_IMAGE_TYPES;
            case 'video':
                return ALLOWED_VIDEO_TYPES;
            case 'document':
                return ALLOWED_DOCUMENT_TYPES;
            case 'audio':
                return ALLOWED_AUDIO_TYPES;
            case 'any':
            default:
                return ALLOWED_FILE_TYPES;
        }
    }
    
    /**
     * Obtenir les extensions autorisées pour une catégorie
     */
    public static function getAllowedExtensionsForCategory($category) {
        $extensions = [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'video' => ['mp4', 'webm', 'ogg', 'avi', 'mov'],
            'document' => ['pdf', 'doc', 'docx', 'txt', 'xls', 'xlsx'],
            'audio' => ['mp3', 'wav', 'ogg']
        ];
        
        if ($category === 'any') {
            return array_merge(...array_values($extensions));
        }
        
        return $extensions[strtolower($category)] ?? [];
    }
    
    /**
     * Obtenir la taille maximale pour un type de fichier
     */
    public static function getMaxSizeForType($type) {
        switch (strtolower($type)) {
            case 'image':
                return MAX_IMAGE_SIZE;
            default:
                return MAX_FILE_SIZE;
        }
    }
    
    /**
     * Générer un nom de fichier sécurisé et unique
     */
    public static function generateSecureFileName($originalName, $prefix = '') {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        
        // Nettoyer le nom de base
        $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);
        $baseName = substr($baseName, 0, 50); // Limiter la longueur
        
        // Ajouter un timestamp et un hash unique
        $timestamp = time();
        $hash = substr(md5(uniqid()), 0, 8);
        
        $fileName = ($prefix ? $prefix . '_' : '') . $baseName . '_' . $timestamp . '_' . $hash . '.' . $extension;
        
        return $fileName;
    }
    
    /**
     * Obtenir le message d'erreur pour un code d'erreur d'upload
     */
    private static function getUploadErrorMessage($errorCode) {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return 'Le fichier dépasse la limite de taille définie dans php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'Le fichier dépasse la limite de taille définie dans le formulaire';
            case UPLOAD_ERR_PARTIAL:
                return 'Le fichier n\'a été que partiellement uploadé';
            case UPLOAD_ERR_NO_FILE:
                return 'Aucun fichier n\'a été uploadé';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Dossier temporaire manquant';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Impossible d\'écrire le fichier sur le disque';
            case UPLOAD_ERR_EXTENSION:
                return 'Upload arrêté par une extension PHP';
            default:
                return 'Erreur inconnue lors de l\'upload';
        }
    }
    
    /**
     * Créer les dossiers d'upload s'ils n'existent pas
     */
    public static function ensureUploadDirectories() {
        $directories = [
            UPLOAD_DIR,
            UPLOAD_DIR . 'avatars/',
            UPLOAD_DIR . 'memories/',
            UPLOAD_DIR . 'memories/images/',
            UPLOAD_DIR . 'memories/videos/',
            UPLOAD_DIR . 'memories/documents/',
            UPLOAD_DIR . 'memories/audio/',
            UPLOAD_DIR . 'temp/'
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                
                // Créer un fichier .htaccess pour sécuriser le dossier
                $htaccessContent = "Options -Indexes\nDeny from all\n<Files ~ \"\\.(jpg|jpeg|png|gif|webp|mp4|webm|ogg|avi|mov|pdf|doc|docx|txt|xls|xlsx|mp3|wav)$\">\nAllow from all\n</Files>";
                file_put_contents($dir . '.htaccess', $htaccessContent);
            }
        }
    }
}
