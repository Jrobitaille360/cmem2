<?php
/**
 * Configuration du module ICS Calendar
 * Plugin: ICS - Gestion des calendriers au format ICS
 */

// ============================================================================
// CONFIGURATION GÉNÉRALE ICS
// ============================================================================

// Version du plugin ICS
define('ICS_VERSION', '1.0.0');

// Format et compatibilité
define('ICS_RFC_VERSION', '5545'); // RFC 5545 compliance
define('ICS_PRODID', '-//CMEM Calendar//FR');
define('ICS_CALSCALE', 'GREGORIAN');

// ============================================================================
// LIMITES ET QUOTAS
// ============================================================================

// Limites par utilisateur
define('ICS_MAX_CALENDARS_PER_USER', $_ENV['ICS_MAX_CALENDARS_PER_USER'] ?? 100);
define('ICS_MAX_EVENTS_PER_CALENDAR', $_ENV['ICS_MAX_EVENTS_PER_CALENDAR'] ?? 10000);
define('ICS_MAX_ATTENDEES_PER_EVENT', $_ENV['ICS_MAX_ATTENDEES_PER_EVENT'] ?? 100);

// Limites de contenu
define('ICS_MAX_CALENDAR_TITLE_LENGTH', 255);
define('ICS_MAX_EVENT_TITLE_LENGTH', 255);
define('ICS_MAX_DESCRIPTION_LENGTH', 1000);
define('ICS_MAX_LOCATION_LENGTH', 255);

// Token de partage
define('ICS_SHARE_TOKEN_LENGTH', 64);
define('ICS_SHARE_TOKEN_ALGO', 'sha256');

// ============================================================================
// CONFIGURATION TIMEZONE
// ============================================================================

// Timezone par défaut
define('ICS_DEFAULT_TIMEZONE', $_ENV['ICS_DEFAULT_TIMEZONE'] ?? 'America/Montreal');

// Timezones supportées (liste restreinte pour performance)
define('ICS_SUPPORTED_TIMEZONES', [
    'America/Montreal',
    'America/New_York', 
    'America/Los_Angeles',
    'Europe/Paris',
    'Europe/London',
    'Asia/Tokyo',
    'Australia/Sydney',
    'UTC'
]);

// ============================================================================
// FONCTIONNALITÉS ACTIVÉES
// ============================================================================

// Partage
define('ICS_ENABLE_PUBLIC_SHARING', $_ENV['ICS_ENABLE_PUBLIC_SHARING'] ?? true);
define('ICS_ENABLE_USER_SHARING', $_ENV['ICS_ENABLE_USER_SHARING'] ?? true);
define('ICS_ENABLE_EMAIL_SHARING', $_ENV['ICS_ENABLE_EMAIL_SHARING'] ?? false);

// Fonctionnalités événements
define('ICS_ENABLE_RECURRENCE', $_ENV['ICS_ENABLE_RECURRENCE'] ?? true);
define('ICS_ENABLE_ATTENDEES', $_ENV['ICS_ENABLE_ATTENDEES'] ?? true);
define('ICS_ENABLE_ALL_DAY_EVENTS', $_ENV['ICS_ENABLE_ALL_DAY_EVENTS'] ?? true);
define('ICS_ENABLE_REMINDERS', $_ENV['ICS_ENABLE_REMINDERS'] ?? false);

// Import/Export
define('ICS_ENABLE_IMPORT', $_ENV['ICS_ENABLE_IMPORT'] ?? false);
define('ICS_ENABLE_CALDAV', $_ENV['ICS_ENABLE_CALDAV'] ?? false);

// ============================================================================
// URLS ET ENDPOINTS
// ============================================================================

// URL de base pour les fichiers ICS publics
define('ICS_BASE_URL', $_ENV['ICS_BASE_URL'] ?? BASE_URL);
define('ICS_PUBLIC_PATH', '/calendar/');
define('ICS_PRIVATE_PATH', '/calendars/');

// Endpoints
define('ICS_API_PREFIX', '/calendars');
define('ICS_PUBLIC_ENDPOINT', '/calendar/{token}.ics');

// ============================================================================
// SÉCURITÉ
// ============================================================================

// Permissions
define('ICS_PERMISSIONS', [
    'calendar.create' => 'Créer des calendriers',
    'calendar.read' => 'Lire les calendriers',
    'calendar.update' => 'Modifier les calendriers', 
    'calendar.delete' => 'Supprimer les calendriers',
    'calendar.share' => 'Partager les calendriers',
    'event.create' => 'Créer des événements',
    'event.read' => 'Lire les événements',
    'event.update' => 'Modifier les événements',
    'event.delete' => 'Supprimer les événements'
]);

// Validation
define('ICS_VALIDATE_EMAIL_FORMAT', true);
define('ICS_VALIDATE_TIMEZONE', true);
define('ICS_VALIDATE_DATETIME_FORMAT', true);

// Rate limiting (par utilisateur)
define('ICS_RATE_LIMIT_CREATE_CALENDAR', $_ENV['ICS_RATE_LIMIT_CREATE_CALENDAR'] ?? 10); // par heure
define('ICS_RATE_LIMIT_CREATE_EVENT', $_ENV['ICS_RATE_LIMIT_CREATE_EVENT'] ?? 100); // par heure
define('ICS_RATE_LIMIT_ICS_DOWNLOAD', $_ENV['ICS_RATE_LIMIT_ICS_DOWNLOAD'] ?? 1000); // par heure

// ============================================================================
// PERFORMANCE ET CACHE
// ============================================================================

// Cache des fichiers ICS générés
define('ICS_ENABLE_CACHE', $_ENV['ICS_ENABLE_CACHE'] ?? true);
define('ICS_CACHE_TTL', $_ENV['ICS_CACHE_TTL'] ?? 3600); // 1 heure
define('ICS_CACHE_DIR', UPLOAD_DIR . '/cache/ics/');

// Pagination
define('ICS_DEFAULT_EVENTS_PER_PAGE', 50);
define('ICS_MAX_EVENTS_PER_PAGE', 200);

// ============================================================================
// INTÉGRATIONS EXTERNES
// ============================================================================

// Compatibilité clients
define('ICS_GOOGLE_CALENDAR_COMPAT', true);
define('ICS_OUTLOOK_COMPAT', true); 
define('ICS_APPLE_CALENDAR_COMPAT', true);
define('ICS_THUNDERBIRD_COMPAT', true);

// Notifications (si module notification existe)
define('ICS_ENABLE_NOTIFICATIONS', $_ENV['ICS_ENABLE_NOTIFICATIONS'] ?? false);
define('ICS_NOTIFY_ON_INVITE', true);
define('ICS_NOTIFY_ON_UPDATE', true);

// ============================================================================
// LOGS ET DEBUG
// ============================================================================

// Logging spécifique ICS
define('ICS_LOG_ENABLED', $_ENV['ICS_LOG_ENABLED'] ?? LOG_ENABLED);
define('ICS_LOG_LEVEL', $_ENV['ICS_LOG_LEVEL'] ?? 'INFO'); // DEBUG, INFO, WARNING, ERROR
define('ICS_LOG_FILE', LOG_DIR . 'ics.log');

// Debug
define('ICS_DEBUG_MODE', $_ENV['ICS_DEBUG_MODE'] ?? APP_DEBUG);
define('ICS_DEBUG_GENERATE_ICS', false); // Log le contenu ICS généré

// ============================================================================
// COULEURS PAR DÉFAUT
// ============================================================================

define('ICS_DEFAULT_COLORS', [
    '#3174ad', // Bleu par défaut
    '#4CAF50', // Vert
    '#FF5722', // Orange
    '#9C27B0', // Violet
    '#F44336', // Rouge
    '#00BCD4', // Cyan
    '#FF9800', // Orange foncé
    '#795548'  // Brun
]);

// ============================================================================
// VALIDATION DE LA CONFIGURATION ICS
// ============================================================================

function validateIcsConfiguration(): array {
    $errors = [];
    
    // Vérifier les répertoires
    if (ICS_ENABLE_CACHE && !is_dir(ICS_CACHE_DIR)) {
        if (!mkdir(ICS_CACHE_DIR, 0755, true)) {
            $errors[] = 'Impossible de créer le répertoire cache ICS: ' . ICS_CACHE_DIR;
        }
    }
    
    // Vérifier les limites
    if (ICS_MAX_CALENDARS_PER_USER < 1 || ICS_MAX_CALENDARS_PER_USER > 1000) {
        $errors[] = 'ICS_MAX_CALENDARS_PER_USER doit être entre 1 et 1000';
    }
    
    if (ICS_MAX_EVENTS_PER_CALENDAR < 1 || ICS_MAX_EVENTS_PER_CALENDAR > 50000) {
        $errors[] = 'ICS_MAX_EVENTS_PER_CALENDAR doit être entre 1 et 50000';
    }
    
    // Vérifier la timezone par défaut
    if (!in_array(ICS_DEFAULT_TIMEZONE, ICS_SUPPORTED_TIMEZONES)) {
        $errors[] = 'ICS_DEFAULT_TIMEZONE non supportée: ' . ICS_DEFAULT_TIMEZONE;
    }
    
    // Vérifier les URLs
    if (empty(ICS_BASE_URL)) {
        $errors[] = 'ICS_BASE_URL ne peut pas être vide';
    }
    
    return $errors;
}

// Exécuter la validation
$ics_errors = validateIcsConfiguration();
if (!empty($ics_errors) && APP_DEBUG) {
    echo "<!-- Erreurs configuration ICS:\n";
    foreach ($ics_errors as $error) {
        echo "- $error\n";
    }
    echo "-->\n";
}

// ============================================================================
// CONSTANTES DÉRIVÉES (calculées automatiquement)
// ============================================================================

// URL complète pour les fichiers ICS publics
define('ICS_PUBLIC_URL', rtrim(ICS_BASE_URL, '/') . ICS_PUBLIC_PATH);

// Statuts d'événements supportés
define('ICS_EVENT_STATUSES', ['confirmed', 'tentative', 'cancelled']);

// Types de récurrence supportés (basique)
define('ICS_RECURRENCE_TYPES', ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY']);

// ============================================================================
// HELPERS DE CONFIGURATION
// ============================================================================

/**
 * Vérifie si une fonctionnalité ICS est activée
 */
function icsFeatureEnabled(string $feature): bool {
    $featureMap = [
        'public_sharing' => ICS_ENABLE_PUBLIC_SHARING,
        'user_sharing' => ICS_ENABLE_USER_SHARING,
        'recurrence' => ICS_ENABLE_RECURRENCE,
        'attendees' => ICS_ENABLE_ATTENDEES,
        'all_day' => ICS_ENABLE_ALL_DAY_EVENTS,
        'cache' => ICS_ENABLE_CACHE,
        'notifications' => ICS_ENABLE_NOTIFICATIONS
    ];
    
    return $featureMap[$feature] ?? false;
}

/**
 * Obtient une couleur par défaut aléatoire
 */
function icsGetRandomColor(): string {
    return ICS_DEFAULT_COLORS[array_rand(ICS_DEFAULT_COLORS)];
}

/**
 * Génère l'URL complète d'un fichier ICS
 */
function icsGeneratePublicUrl(string $shareToken): string {
    return ICS_PUBLIC_URL . $shareToken . '.ics';
}