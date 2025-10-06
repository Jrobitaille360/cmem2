<?php
/**
 * Configuration de l'environnement partagée
 * Module: shared - configuration commune à tous les modules
 */

// Charger les fichiers .env modulaires s'ils existent
$envFiles = [
    __DIR__ . '/../../.env.auth_groups',        // Infrastructure obligatoire
    //__DIR__ . '/../../.env.memories_elements'   // Module optionnel
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