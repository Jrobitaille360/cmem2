<?php
/**
 * Configuration des uploads pour le module auth_groups
 * Module: auth_groups - avatars, fichiers de profil et documents groupes
 */

// Configuration des avatars utilisateur
define('AVATAR_UPLOAD_DIR', UPLOAD_DIR . 'avatars/');
define('MAX_AVATAR_SIZE', (int)($_ENV['MAX_AVATAR_SIZE'] ?? 2097152)); // 2MB par défaut
define('AVATAR_MAX_WIDTH', (int)($_ENV['AVATAR_MAX_WIDTH'] ?? 400));
define('AVATAR_MAX_HEIGHT', (int)($_ENV['AVATAR_MAX_HEIGHT'] ?? 400));
define('ALLOWED_AVATAR_TYPES', explode(',', $_ENV['ALLOWED_AVATAR_TYPES'] ?? 'image/jpeg,image/png,image/webp'));

// Configuration des fichiers de groupes
define('GROUP_FILES_UPLOAD_DIR', UPLOAD_DIR . 'groups/');
define('MAX_GROUP_FILE_SIZE', (int)($_ENV['MAX_GROUP_FILE_SIZE'] ?? 10485760)); // 10MB par défaut
define('MAX_FILES_PER_GROUP', (int)($_ENV['MAX_FILES_PER_GROUP'] ?? 100));

// Configuration des documents partagés dans les groupes
define('GROUP_DOCUMENTS_DIR', UPLOAD_DIR . 'groups/documents/');
define('ALLOWED_GROUP_DOCUMENT_TYPES', explode(',', $_ENV['ALLOWED_GROUP_DOCUMENT_TYPES'] ?? 'application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document'));

// Configuration des images de groupes (bannières, logos)
define('GROUP_IMAGES_DIR', UPLOAD_DIR . 'groups/images/');
define('MAX_GROUP_IMAGE_SIZE', (int)($_ENV['MAX_GROUP_IMAGE_SIZE'] ?? 5242880)); // 5MB par défaut
define('GROUP_IMAGE_MAX_WIDTH', (int)($_ENV['GROUP_IMAGE_MAX_WIDTH'] ?? 1200));
define('GROUP_IMAGE_MAX_HEIGHT', (int)($_ENV['GROUP_IMAGE_MAX_HEIGHT'] ?? 600));

// Configuration des fichiers temporaires pour les groupes
define('GROUP_TEMP_DIR', TMP_ASSETS_DIR . 'groups/');

// Création des dossiers s'ils n'existent pas
if (!file_exists(AVATAR_UPLOAD_DIR)) {
    mkdir(AVATAR_UPLOAD_DIR, 0755, true);
}

if (!file_exists(GROUP_FILES_UPLOAD_DIR)) {
    mkdir(GROUP_FILES_UPLOAD_DIR, 0755, true);
    mkdir(GROUP_DOCUMENTS_DIR, 0755, true);
    mkdir(GROUP_IMAGES_DIR, 0755, true);
}

if (!file_exists(GROUP_TEMP_DIR)) {
    mkdir(GROUP_TEMP_DIR, 0755, true);
}