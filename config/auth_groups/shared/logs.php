<?php
/**
 * Configuration des logs partagée
 * Module: shared - système de logs commun à tous les modules
 */

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
    'memories_elements' => 'memories_elements.log',
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
    
    // Créer les sous-dossiers pour les différents types de logs
    mkdir(LOG_DIR . 'auth_groups/', 0755, true);
    mkdir(LOG_DIR . 'memories_elements/', 0755, true);
    mkdir(LOG_DIR . 'shared/', 0755, true);
    mkdir(LOG_DIR . 'archived/', 0755, true);
}