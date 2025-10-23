<?php
/**
 * Loader de configuration modulaire
 * Charge toutes les configurations selon l'architecture modulaire
 * 
 * Architecture:
 * - auth_groups/shared/ : Configurations communes obligatoires (environnement, base de données, logs)
 * - auth_groups/       : Configurations d'authentification, utilisateurs, groupes, tags
 */

// Charger d'abord les configurations communes obligatoires (ordre important)
require_once __DIR__ . '/environment.php';
require_once __DIR__ . '/database.php';

// Charger l'autoloader AuthGroups en premier
require_once __DIR__ . '/autoloader.php';

// Charger les configurations du module auth_groups
// Note: pas de fichier de config spécifique pour l'instant
// require_once __DIR__ . '/auth_groups/uploads.php';


/**
 * Validation de la configuration
 * Vérifie que toutes les constantes essentielles sont définies
 */
function validateConfiguration(): array {
    $errors = [];
    
    // Validation des configurations partagées
    if (!defined('APP_ENV')) $errors[] = 'APP_ENV non défini';
    if (!defined('BASE_URL')) $errors[] = 'BASE_URL non défini';
    if (!defined('LOG_DIR')) $errors[] = 'LOG_DIR non défini';
    
    // Validation du module auth_groups
    if (!defined('JWT_SECRET')) {
        $errors[] = 'JWT_SECRET non défini';
    } elseif (strlen(JWT_SECRET) < 32 || JWT_SECRET === 'your-secret-key-change-this-in-production') {
        $errors[] = 'JWT_SECRET doit être une clé sécurisée d\'au moins 32 caractères (changez la valeur par défaut)';
    }
    if (!defined('JWT_EXPIRATION')) $errors[] = 'JWT_EXPIRATION non défini';
    
    
    // Validation des répertoires critiques
    if (!is_dir(UPLOAD_DIR)) $errors[] = 'Répertoire UPLOAD_DIR inaccessible: ' . UPLOAD_DIR;
    if (!is_dir(LOG_DIR)) $errors[] = 'Répertoire LOG_DIR inaccessible: ' . LOG_DIR;
    
    return $errors;
}

/**
 * Initialisation des répertoires requis
 * Crée tous les répertoires nécessaires s'ils n'existent pas
 */
function initializeDirectories(): void {
    // Répertoires partagés
    if (!file_exists(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    if (!file_exists(TMP_ASSETS_DIR)) mkdir(TMP_ASSETS_DIR, 0755, true);
    
    // Répertoires du module auth_groups
    if (!file_exists(AVATAR_UPLOAD_DIR)) mkdir(AVATAR_UPLOAD_DIR, 0755, true);
    if (!file_exists(GROUP_FILES_UPLOAD_DIR)) mkdir(GROUP_FILES_UPLOAD_DIR, 0755, true);
    
    }

/**
 * Affichage des informations de configuration (debug uniquement)
 */
function displayConfigurationInfo(): void {
    // Fonction désactivée pour éviter les problèmes de headers HTTP
    return;
    
    /* echo "<!-- Configuration Modulaire Chargée:\n";
    echo "- Environnement: " . APP_ENV . "\n";
    echo "- Base URL: " . BASE_URL . "\n";
    echo "- Version API: " . API_VERSION . "\n";
    echo "-->\n"; */
}

// Exécuter l'initialisation
$config_errors = validateConfiguration();

if (!empty($config_errors)) {
    if (APP_DEBUG) {
        echo "<pre>Erreurs de configuration:\n";
        foreach ($config_errors as $error) {
            echo "- $error\n";
        }
        echo "</pre>";
    }
    
    // Log les erreurs si possible
    if (LOG_ENABLED && defined('LOG_DIR') && is_dir(LOG_DIR)) {
        error_log("Configuration errors: " . implode(', ', $config_errors), 3, LOG_DIR . 'errors.log');
    }
    
    // En production, arrêter l'exécution si erreurs critiques
    if (APP_ENV === 'production') {
        http_response_code(500);
        die('Configuration error');
    }
} else {
    // Initialiser les répertoires si la configuration est valide
    initializeDirectories();
    
    // Afficher les infos de debug si nécessaire
    displayConfigurationInfo();
}

// ============================================================================
// CHARGEMENT DES PLUGINS (après toute la configuration de base)
// ============================================================================

function loadPlugins(): void {
    try {
        // Charger l'autoloader pour auth_groups en premier
        require_once __DIR__ . '/autoloader.php';
        
        // Charger les classes Core nécessaires
        require_once __DIR__ . '/../Core/PluginInterface.php';
        require_once __DIR__ . '/../Core/PluginManager.php';

        $pluginManager = \Core\PluginManager::getInstance();
        
        // Charger tous les plugins disponibles
        $pluginManager->loadPlugins();
        
        // Récupérer la liste des plugins chargés
        $loadedPlugins = $pluginManager->getLoadedPlugins();
        
        // Extraire les noms des plugins
        $loadedPluginNames = array_column($loadedPlugins, 'name');
        
        // Vérifier quels plugins sont activés
        $pluginCalendarEnabled = in_array('ICS Calendar', $loadedPluginNames) || in_array('calendar', $loadedPluginNames);
        $pluginNotificationsEnabled = in_array('Notifications', $loadedPluginNames) || in_array('notifications', $loadedPluginNames);
        $pluginAnalyticsEnabled = in_array('Analytics', $loadedPluginNames) || in_array('analytics', $loadedPluginNames);
        
        // Stocker l'instance du PluginManager dans les globals pour un accès ultérieur
        $GLOBALS['plugin_manager'] = $pluginManager;
        
        // Définir les constantes pour les plugins
        if (!defined('LOADED_PLUGINS')) {
            define('LOADED_PLUGINS', $loadedPluginNames);
        }

        if (!defined('PLUGIN_CALENDAR_ENABLED')) {
            define('PLUGIN_CALENDAR_ENABLED', $pluginCalendarEnabled);
        }

        if (!defined('PLUGIN_NOTIFICATIONS_ENABLED')) {
            define('PLUGIN_NOTIFICATIONS_ENABLED', $pluginNotificationsEnabled);
        }

        if (!defined('PLUGIN_ANALYTICS_ENABLED')) {
            define('PLUGIN_ANALYTICS_ENABLED', $pluginAnalyticsEnabled);
        }
        
        if (APP_DEBUG && !empty($loadedPluginNames)) {
            error_log("Plugins chargés: " . implode(', ', $loadedPluginNames));
        }
        
    } catch (\Exception $e) {
        error_log("Erreur lors du chargement des plugins: " . $e->getMessage());
        
        // Définir des constantes par défaut en cas d'échec
        if (!defined('LOADED_PLUGINS')) {
            define('LOADED_PLUGINS', []);
        }
        if (!defined('PLUGIN_CALENDAR_ENABLED')) {
            define('PLUGIN_CALENDAR_ENABLED', false);
        }
        if (!defined('PLUGIN_NOTIFICATIONS_ENABLED')) {
            define('PLUGIN_NOTIFICATIONS_ENABLED', false);
        }
        if (!defined('PLUGIN_ANALYTICS_ENABLED')) {
            define('PLUGIN_ANALYTICS_ENABLED', false);
        }
    }
}

// Charger les plugins après toutes les configurations
loadPlugins();

