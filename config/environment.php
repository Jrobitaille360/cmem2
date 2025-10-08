<?php
/**
 * Configuration de l'environnement partagée
 * Module: shared - configuration commune à tous les modules
 */

// Charger les fichiers .env modulaires s'ils existent
$envFiles = [
    __DIR__ . '/../.env.auth_groups',        // Infrastructure obligatoire
    ];

foreach ($envFiles as $envFile) {
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $name = trim($parts[0]);
                $value = trim($parts[1]);
                $_ENV[$name] = $value;
            }
        }
    }
}

// Fallback : charger .env unique s'il existe (compatibilité)
if (file_exists(__DIR__ . '/../../../.env')) {
    $lines = file(__DIR__ . '/../../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            // Ne pas écraser si déjà défini par les .env modulaires
            if (!isset($_ENV[$name])) {
                $_ENV[$name] = $value;
            }
        }
    }
}

// Configuration de l'environnement
define('APP_ENV', $_ENV['APP_ENV'] ?? 'development');
define('APP_DEBUG', $_ENV['APP_DEBUG'] ?? true);
define('APP_URL', $_ENV['APP_URL'] ?? 'http://localhost');
define('APP_NAME', $_ENV['APP_NAME'] ?? 'Collective Memories API');
define('APP_VERSION', $_ENV['APP_VERSION'] ?? '2.0.0');

// Configuration de l'API
define('API_VERSION', 'v1');
define('BASE_URL', $_ENV['BASE_URL'] ?? (APP_ENV === 'production' ? 'https://cmem1.journauxdebord.com' : 'http://localhost'));

// Configuration CORS - Variables d'environnement
$allowedOrigins = $_ENV['ALLOWED_ORIGINS'] ?? 'https://cmem1.journauxdebord.com,http://localhost:3000,http://localhost:8080,http://127.0.0.1:3000';
define('ALLOWED_ORIGINS', explode(',', $allowedOrigins));
define('ALLOWED_METHODS', explode(',', $_ENV['ALLOWED_METHODS'] ?? 'GET,POST,PUT,DELETE,OPTIONS'));
define('ALLOWED_HEADERS', explode(',', $_ENV['ALLOWED_HEADERS'] ?? 'Content-Type,Authorization,X-Requested-With'));

// Configuration du timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Montreal');

// Configuration des uploads généraux
define('UPLOAD_DIR', __DIR__ . '/../../' . ($_ENV['UPLOAD_DIR'] ?? 'uploads/'));
define('TMP_ASSETS_DIR', __DIR__ . '/../../' . ($_ENV['TMP_ASSETS_DIR'] ?? 'tmp_assets/'));
define('MAX_FILE_SIZE', (int)($_ENV['MAX_FILE_SIZE'] ?? 10485760)); // 10MB par défaut

// Configuration de sécurité
define('ENABLE_RATE_LIMITING', filter_var($_ENV['ENABLE_RATE_LIMITING'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('MAX_REQUESTS_PER_MINUTE', (int)($_ENV['MAX_REQUESTS_PER_MINUTE'] ?? 60));
define('ENABLE_REQUEST_LOGGING', filter_var($_ENV['ENABLE_REQUEST_LOGGING'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Configuration de la maintenance
define('MAINTENANCE_MODE', filter_var($_ENV['MAINTENANCE_MODE'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
define('MAINTENANCE_MESSAGE', $_ENV['MAINTENANCE_MESSAGE'] ?? 'Application en maintenance. Veuillez réessayer plus tard.');

// Configuration des uploads spécifiques
define('MAX_IMAGE_SIZE', (int)($_ENV['MAX_IMAGE_SIZE'] ?? 5242880)); // 5MB par défaut
define('MAX_VIDEO_SIZE', (int)($_ENV['MAX_VIDEO_SIZE'] ?? 52428800)); // 50MB par défaut
define('MAX_AUDIO_SIZE', (int)($_ENV['MAX_AUDIO_SIZE'] ?? 10485760)); // 10MB par défaut
define('MAX_DOCUMENT_SIZE', (int)($_ENV['MAX_DOCUMENT_SIZE'] ?? 10485760)); // 10MB par défaut

// Types de fichiers autorisés
define('ALLOWED_IMAGE_TYPES', explode(',', $_ENV['ALLOWED_IMAGE_TYPES'] ?? 'image/jpeg,image/png,image/gif'));
define('ALLOWED_VIDEO_TYPES', explode(',', $_ENV['ALLOWED_VIDEO_TYPES'] ?? 'video/mp4,video/webm'));
define('ALLOWED_DOCUMENT_TYPES', explode(',', $_ENV['ALLOWED_DOCUMENT_TYPES'] ?? 'application/pdf'));
define('ALLOWED_AUDIO_TYPES', explode(',', $_ENV['ALLOWED_AUDIO_TYPES'] ?? 'audio/mpeg,audio/wav'));

define('ALLOWED_FILE_TYPES', array_merge(
    ALLOWED_IMAGE_TYPES,
    ALLOWED_VIDEO_TYPES,
    ALLOWED_DOCUMENT_TYPES,
    ALLOWED_AUDIO_TYPES
));

// Configuration des erreurs
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Création du dossier uploads s'il n'existe pas
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
    mkdir(UPLOAD_DIR . 'temp/', 0755, true);
}

// Création du dossier tmp_assets s'il n'existe pas
if (!file_exists(TMP_ASSETS_DIR)) {
    mkdir(TMP_ASSETS_DIR, 0755, true);
    mkdir(TMP_ASSETS_DIR . 'downloads/', 0755, true);
}



// Configuration des logs
define('LOG_ENABLED', filter_var($_ENV['LOG_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN));
define('LOG_LEVEL', $_ENV['LOG_LEVEL'] ?? 'debug');
define('LOG_DIR', __DIR__ . '/../../' . ($_ENV['LOG_DIR'] ?? 'logs/'));
define('LOG_MAX_FILE_SIZE', (int)($_ENV['LOG_MAX_FILE_SIZE'] ?? 10485760)); // 10MB par défaut
define('LOG_ARCHIVE_AFTER_DAYS', (int)($_ENV['LOG_ARCHIVE_AFTER_DAYS'] ?? 7));
define('LOG_DELETE_AFTER_WEEKS', (int)($_ENV['LOG_DELETE_AFTER_WEEKS'] ?? 12));
define('LOG_TIMEZONE', $_ENV['LOG_TIMEZONE'] ?? 'America/Toronto');

// Configuration des niveaux de log
define('LOG_LEVELS', [
    'emergency' => 0,
    'alert'     => 1,
    'critical'  => 2,
    'error'     => 3,
    'warning'   => 4,
    'notice'    => 5,
    'info'      => 6,
    'debug'     => 7
]);

// Configuration des fichiers de log par module
define('LOG_FILES', [
    'auth_groups' => 'auth_groups.log',
    'shared' => 'shared.log',
    'api' => 'api.log',
    'database' => 'database.log',
    'errors' => 'errors.log',
    'security' => 'security.log'
]);

// Configuration de rotation des logs
define('LOG_ROTATION_ENABLED', filter_var($_ENV['LOG_ROTATION_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('LOG_ROTATION_MAX_FILES', (int)($_ENV['LOG_ROTATION_MAX_FILES'] ?? 10));
define('LOG_COMPRESS_ROTATED', filter_var($_ENV['LOG_COMPRESS_ROTATED'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Configuration des logs de performance
define('LOG_PERFORMANCE', filter_var($_ENV['LOG_PERFORMANCE'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
define('LOG_SLOW_QUERIES', filter_var($_ENV['LOG_SLOW_QUERIES'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('SLOW_QUERY_THRESHOLD', (float)($_ENV['SLOW_QUERY_THRESHOLD'] ?? 1.0)); // secondes

// Configuration des logs de sécurité
define('LOG_SECURITY_EVENTS', filter_var($_ENV['LOG_SECURITY_EVENTS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('LOG_FAILED_LOGINS', filter_var($_ENV['LOG_FAILED_LOGINS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('LOG_SUSPICIOUS_ACTIVITY', filter_var($_ENV['LOG_SUSPICIOUS_ACTIVITY'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Configuration d'envoi des logs critiques
define('EMAIL_CRITICAL_LOGS', filter_var($_ENV['EMAIL_CRITICAL_LOGS'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
define('CRITICAL_LOG_EMAIL', $_ENV['CRITICAL_LOG_EMAIL'] ?? '');
define('LOG_EMAIL_THRESHOLD', $_ENV['LOG_EMAIL_THRESHOLD'] ?? 'error');

// Création du dossier logs s'il n'existe pas
if (LOG_ENABLED && !file_exists(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

    
  /**
 * Configuration de l'authentification et des groupes
 * Module: auth_groups
 */

// Configuration JWT
define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? 'Zjz1vB^D4xkEWss7TV9zXC3^r$uPfFaQz5A!xxG$^CKnX*3S!bEh4b3*3UcK2*s1');
define('JWT_ALGORITHM', $_ENV['JWT_ALGORITHM'] ?? 'HS256');
define('JWT_EXPIRATION', (int)($_ENV['JWT_EXPIRATION'] ?? 86400)); // 24 heures par défaut

// Configuration de l'authentification
define('AUTH_AUTO_LOGOUT_BEFORE_LOGIN', filter_var($_ENV['AUTH_AUTO_LOGOUT_BEFORE_LOGIN'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('AUTH_AUTO_LOGOUT_LOG_LEVEL', $_ENV['AUTH_AUTO_LOGOUT_LOG_LEVEL'] ?? 'info');
define('AUTH_AUTO_LOGOUT_ALL_TOKENS', filter_var($_ENV['AUTH_AUTO_LOGOUT_ALL_TOKENS'] ?? 'false', FILTER_VALIDATE_BOOLEAN));

// Configuration des tokens de validation
define('VALID_TOKEN_EXPIRATION', (int)($_ENV['VALID_TOKEN_EXPIRATION'] ?? 3600)); // 1 heure par défaut
define('VALID_TOKEN_CLEANUP_INTERVAL', (int)($_ENV['VALID_TOKEN_CLEANUP_INTERVAL'] ?? 1800)); // 30 minutes

// Configuration de l'administration secrète
define('SECRET_ADMIN_ENDPOINT', $_ENV['SECRET_ADMIN_ENDPOINT'] ?? 'super_secret_admin_endpoint_change_this_in_production');
define('ADMIN_SECRET_KEY', $_ENV['ADMIN_SECRET_KEY'] ?? 'ultra_secret_admin_token_change_this_immediately_in_production');

// Configuration de l'administration
define('ADMIN_SESSION_TIMEOUT', (int)($_ENV['ADMIN_SESSION_TIMEOUT'] ?? 1800)); // 30 minutes par défaut
define('ADMIN_MAX_FAILED_ATTEMPTS', (int)($_ENV['ADMIN_MAX_FAILED_ATTEMPTS'] ?? 3));
define('ADMIN_LOCKOUT_DURATION', (int)($_ENV['ADMIN_LOCKOUT_DURATION'] ?? 900)); // 15 minutes
define('ADMIN_REQUIRE_IP_WHITELIST', filter_var($_ENV['ADMIN_REQUIRE_IP_WHITELIST'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
define('ADMIN_ALLOWED_IPS', $_ENV['ADMIN_ALLOWED_IPS'] ?? '127.0.0.1,::1');

// Logs d'administration
define('LOG_ADMIN_ACCESS', filter_var($_ENV['LOG_ADMIN_ACCESS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('LOG_ADMIN_ACTIONS', filter_var($_ENV['LOG_ADMIN_ACTIONS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('ADMIN_LOG_RETENTION_DAYS', (int)($_ENV['ADMIN_LOG_RETENTION_DAYS'] ?? 90));

// Sécurité renforcée pour l'admin
define('ADMIN_REQUIRE_2FA', filter_var($_ENV['ADMIN_REQUIRE_2FA'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
define('ADMIN_SESSION_REGENERATE_ID', filter_var($_ENV['ADMIN_SESSION_REGENERATE_ID'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('ADMIN_CSRF_PROTECTION', filter_var($_ENV['ADMIN_CSRF_PROTECTION'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Configuration des invitations de groupes
define('GROUP_INVITATION_EXPIRATION', (int)($_ENV['GROUP_INVITATION_EXPIRATION'] ?? 604800)); // 7 jours par défaut
define('MAX_GROUP_MEMBERS', (int)($_ENV['MAX_GROUP_MEMBERS'] ?? 50));
define('MAX_GROUPS_PER_USER', (int)($_ENV['MAX_GROUPS_PER_USER'] ?? 10));

// Configuration des utilisateurs
define('MAX_USERNAME_LENGTH', (int)($_ENV['MAX_USERNAME_LENGTH'] ?? 50));
define('MIN_PASSWORD_LENGTH', (int)($_ENV['MIN_PASSWORD_LENGTH'] ?? 8));
define('REQUIRE_EMAIL_VERIFICATION', filter_var($_ENV['REQUIRE_EMAIL_VERIFICATION'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Configuration des sessions utilisateur
define('MAX_CONCURRENT_SESSIONS', (int)($_ENV['MAX_CONCURRENT_SESSIONS'] ?? 5));
define('SESSION_TIMEOUT', (int)($_ENV['SESSION_TIMEOUT'] ?? 3600)); // 1 heure par défaut  

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


/**
 * Configuration des tags pour le module auth_groups
 * Module: auth_groups - gestion des étiquettes et catégories
 */

// Configuration des tags
define('MAX_TAGS_PER_ITEM', (int)($_ENV['MAX_TAGS_PER_ITEM'] ?? 10));
define('MAX_TAG_LENGTH', (int)($_ENV['MAX_TAG_LENGTH'] ?? 50));
define('MIN_TAG_LENGTH', (int)($_ENV['MIN_TAG_LENGTH'] ?? 2));

// Configuration des catégories de tags
define('MAX_TAG_CATEGORIES', (int)($_ENV['MAX_TAG_CATEGORIES'] ?? 20));
define('MAX_CATEGORY_NAME_LENGTH', (int)($_ENV['MAX_CATEGORY_NAME_LENGTH'] ?? 30));

// Configuration des couleurs de tags
define('DEFAULT_TAG_COLORS', explode(',', $_ENV['DEFAULT_TAG_COLORS'] ?? '#007bff,#28a745,#ffc107,#dc3545,#6f42c1,#fd7e14,#20c997,#6c757d'));
define('ALLOW_CUSTOM_TAG_COLORS', filter_var($_ENV['ALLOW_CUSTOM_TAG_COLORS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Configuration des permissions de tags
define('ALLOW_USER_CREATE_TAGS', filter_var($_ENV['ALLOW_USER_CREATE_TAGS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('REQUIRE_TAG_APPROVAL', filter_var($_ENV['REQUIRE_TAG_APPROVAL'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
define('ALLOW_TAG_SUGGESTIONS', filter_var($_ENV['ALLOW_TAG_SUGGESTIONS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Configuration de la recherche de tags
define('TAG_SEARCH_MIN_LENGTH', (int)($_ENV['TAG_SEARCH_MIN_LENGTH'] ?? 1));
define('TAG_SEARCH_MAX_RESULTS', (int)($_ENV['TAG_SEARCH_MAX_RESULTS'] ?? 50));
define('ENABLE_TAG_AUTOCOMPLETE', filter_var($_ENV['ENABLE_TAG_AUTOCOMPLETE'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Configuration de la popularité des tags
define('TRACK_TAG_USAGE', filter_var($_ENV['TRACK_TAG_USAGE'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('TAG_TRENDING_PERIOD_DAYS', (int)($_ENV['TAG_TRENDING_PERIOD_DAYS'] ?? 30));
define('MAX_TRENDING_TAGS', (int)($_ENV['MAX_TRENDING_TAGS'] ?? 20));