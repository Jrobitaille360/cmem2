<?php
/**
 * Configuration des uploads multimédia pour le module memories_elements
 * Module: memories_elements - gestion des fichiers de mémoires
 */

// Configuration générale des uploads de mémoires
define('MEMORY_UPLOAD_DIR', UPLOAD_DIR . 'memories/');
define('MEMORY_TEMP_DIR', TMP_ASSETS_DIR . 'memories/');

// Configuration des images de mémoires
define('MAX_MEMORY_IMAGE_SIZE', (int)($_ENV['MAX_MEMORY_IMAGE_SIZE'] ?? 10485760)); // 10MB par défaut
define('MEMORY_IMAGE_MAX_WIDTH', (int)($_ENV['MEMORY_IMAGE_MAX_WIDTH'] ?? 4096));
define('MEMORY_IMAGE_MAX_HEIGHT', (int)($_ENV['MEMORY_IMAGE_MAX_HEIGHT'] ?? 4096));
define('MEMORY_THUMBNAIL_WIDTH', (int)($_ENV['MEMORY_THUMBNAIL_WIDTH'] ?? 300));
define('MEMORY_THUMBNAIL_HEIGHT', (int)($_ENV['MEMORY_THUMBNAIL_HEIGHT'] ?? 300));

// Configuration des vidéos de mémoires
define('MAX_MEMORY_VIDEO_SIZE', (int)($_ENV['MAX_MEMORY_VIDEO_SIZE'] ?? 104857600)); // 100MB par défaut
define('MAX_MEMORY_VIDEO_DURATION', (int)($_ENV['MAX_MEMORY_VIDEO_DURATION'] ?? 600)); // 10 minutes par défaut
define('MEMORY_VIDEO_THUMBNAIL_TIME', (int)($_ENV['MEMORY_VIDEO_THUMBNAIL_TIME'] ?? 5)); // Seconde pour la miniature

// Configuration des fichiers audio de mémoires
define('MAX_MEMORY_AUDIO_SIZE', (int)($_ENV['MAX_MEMORY_AUDIO_SIZE'] ?? 52428800)); // 50MB par défaut
define('MAX_MEMORY_AUDIO_DURATION', (int)($_ENV['MAX_MEMORY_AUDIO_DURATION'] ?? 1800)); // 30 minutes par défaut

// Configuration des documents de mémoires
define('MAX_MEMORY_DOCUMENT_SIZE', (int)($_ENV['MAX_MEMORY_DOCUMENT_SIZE'] ?? 20971520)); // 20MB par défaut
define('MEMORY_DOCUMENT_PREVIEW_PAGES', (int)($_ENV['MEMORY_DOCUMENT_PREVIEW_PAGES'] ?? 3));

// Types de fichiers autorisés pour les mémoires
define('ALLOWED_MEMORY_IMAGE_TYPES', explode(',', $_ENV['ALLOWED_MEMORY_IMAGE_TYPES'] ?? 'image/jpeg,image/png,image/gif,image/webp,image/bmp,image/tiff'));
define('ALLOWED_MEMORY_VIDEO_TYPES', explode(',', $_ENV['ALLOWED_MEMORY_VIDEO_TYPES'] ?? 'video/mp4,video/webm,video/ogg,video/avi,video/mov,video/wmv'));
define('ALLOWED_MEMORY_AUDIO_TYPES', explode(',', $_ENV['ALLOWED_MEMORY_AUDIO_TYPES'] ?? 'audio/mpeg,audio/wav,audio/ogg,audio/mp3,audio/flac,audio/aac'));
define('ALLOWED_MEMORY_DOCUMENT_TYPES', explode(',', $_ENV['ALLOWED_MEMORY_DOCUMENT_TYPES'] ?? 'application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document'));

// Tous les types de fichiers autorisés pour les mémoires
define('ALLOWED_MEMORY_FILE_TYPES', array_merge(
    ALLOWED_MEMORY_IMAGE_TYPES,
    ALLOWED_MEMORY_VIDEO_TYPES,
    ALLOWED_MEMORY_AUDIO_TYPES,
    ALLOWED_MEMORY_DOCUMENT_TYPES
));

// Configuration de traitement des médias
define('ENABLE_IMAGE_COMPRESSION', filter_var($_ENV['ENABLE_IMAGE_COMPRESSION'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('IMAGE_COMPRESSION_QUALITY', (int)($_ENV['IMAGE_COMPRESSION_QUALITY'] ?? 85));
define('ENABLE_VIDEO_COMPRESSION', filter_var($_ENV['ENABLE_VIDEO_COMPRESSION'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
define('GENERATE_MULTIPLE_RESOLUTIONS', filter_var($_ENV['GENERATE_MULTIPLE_RESOLUTIONS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Création des dossiers s'ils n'existent pas
if (!file_exists(MEMORY_UPLOAD_DIR)) {
    mkdir(MEMORY_UPLOAD_DIR, 0755, true);
    mkdir(MEMORY_UPLOAD_DIR . 'images/', 0755, true);
    mkdir(MEMORY_UPLOAD_DIR . 'videos/', 0755, true);
    mkdir(MEMORY_UPLOAD_DIR . 'audio/', 0755, true);
    mkdir(MEMORY_UPLOAD_DIR . 'documents/', 0755, true);
    mkdir(MEMORY_UPLOAD_DIR . 'thumbnails/', 0755, true);
}

if (!file_exists(MEMORY_TEMP_DIR)) {
    mkdir(MEMORY_TEMP_DIR, 0755, true);
}