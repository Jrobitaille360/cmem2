<?php
/**
 * Autoloader pour le module ICS Calendar
 * Module: ics - gestion des calendriers ICS avec support RFC 5545
 */

// Autoloader pour le namespace Core (nécessaire pour PluginInterface et PluginManager)
spl_autoload_register(function ($className) {
    if (strpos($className, 'Core\\') !== 0) {
        return;
    }
    
    $classNameWithoutNamespace = substr($className, 5); // Supprimer "Core\"
    $basePath = __DIR__ . '/../Core/';
    $filePath = $basePath . $classNameWithoutNamespace . '.php';
    
    if (file_exists($filePath)) {
        require_once $filePath;
    }
});

// Autoloader pour le namespace ICS
spl_autoload_register(function ($className) {
    // Vérifier si la classe appartient au namespace ICS
    if (strpos($className, 'ICS\\') !== 0) {
        return;
    }
    
    // Supprimer le préfixe ICS\ du nom de la classe
    $classNameWithoutNamespace = substr($className, 4);
    
    // Remplacer les backslashes par des slashes pour le chemin de fichier
    $classPath = str_replace('\\', '/', $classNameWithoutNamespace);
    
    // Définir le répertoire de base du module ICS
    $baseDir = __DIR__ . '/';
    
    // Construire le chemin complet vers le fichier de classe
    $filePath = $baseDir . $classPath . '.php';
    
    // Vérifier si le fichier existe et l'inclure
    if (file_exists($filePath)) {
        require_once $filePath;
        return;
    }
    
    // Essayer des emplacements alternatifs
    $alternativePaths = [
        $baseDir . 'Controllers/' . basename($classPath) . '.php',
        $baseDir . 'Models/' . basename($classPath) . '.php',
        $baseDir . 'Services/' . basename($classPath) . '.php',
        $baseDir . 'Utils/' . basename($classPath) . '.php',
        $baseDir . 'Routing/' . basename($classPath) . '.php',
        $baseDir . 'Hooks/' . basename($classPath) . '.php'
    ];
    
    foreach ($alternativePaths as $altPath) {
        if (file_exists($altPath)) {
            require_once $altPath;
            return;
        }
    }
    
    // Logging optionnel pour le débogage
    \AuthGroups\Services\LogService::debug("ICS Autoloader: Impossible de charger la classe {$className}", [
        'tested_path' => $filePath
    ]);
});

// Inclure les fichiers de configuration du module
// Les constantes de base sont maintenant disponibles via loader.php
$configFiles = [
    __DIR__ . '/config/ics_config.php'
];

foreach ($configFiles as $configFile) {
    if (file_exists($configFile)) {
        require_once $configFile;
    }
}

// Définir des constantes spécifiques au module ICS
if (!defined('ICS_MODULE_LOADED')) {
    define('ICS_MODULE_LOADED', true);
    define('ICS_MODULE_VERSION', '1.0.0');
    define('ICS_MODULE_PATH', __DIR__);
    define('ICS_ASSETS_PATH', __DIR__ . '/assets/');
    define('ICS_DOCS_PATH', __DIR__ . '/docs_ICS/');
}

// Enregistrer des alias pour la compatibilité si nécessaire
if (!class_exists('CalendarPlugin') && class_exists('ICS\\CalendarPlugin')) {
    class_alias('ICS\\CalendarPlugin', 'CalendarPlugin');
}

// Fonction pour enregistrer les route handlers ICS dans le router global
function registerICSRoutes() {
    // Vérifier si le PluginManager est disponible
    if (isset($GLOBALS['plugin_manager'])) {
        $pluginManager = $GLOBALS['plugin_manager'];
        
        // Définir les route handlers pour le module ICS
        $icsRouteHandlers = [
            'calendars' => function($authService) {
                return new \ICS\Routing\RouteHandlers\CalendarRouteHandler($authService);
            },
            'calendar' => function($authService) {
                return new \ICS\Routing\RouteHandlers\CalendarRouteHandler($authService);
            },
            'caldav' => function($authService) {
                return new \ICS\Routing\RouteHandlers\CalDAVRouteHandler($authService);
            },
            'notifications' => function($authService) {
                return new \ICS\Routing\RouteHandlers\NotificationRouteHandler($authService);
            }
        ];
        
        // Enregistrer les routes via le PluginManager
        $pluginManager->registerPluginRoutes('ics', $icsRouteHandlers);
        
        // Logging pour le débogage
        \AuthGroups\Services\LogService::info("ICS Autoloader: Routes enregistrées avec succès via PluginManager (calendars, calendar, caldav)");
        
        return true;
    }
    
    return false;
}

// Fonction alternative pour l'intégration directe avec le Router
function integrateICSWithRouter() {
    // Cette fonction peut être utilisée si l'intégration via PluginManager ne fonctionne pas
    global $routerInstance;
    
    if (isset($routerInstance) && method_exists($routerInstance, 'addRouteHandler')) {
        // Ajouter les handlers ICS directement au router si possible
        $routerInstance->addRouteHandler('calendars', '\ICS\Routing\RouteHandlers\CalendarRouteHandler');
        $routerInstance->addRouteHandler('calendar', '\ICS\Routing\RouteHandlers\CalendarRouteHandler');
        $routerInstance->addRouteHandler('caldav', '\ICS\Routing\RouteHandlers\CalDAVRouteHandler');
        
        \AuthGroups\Services\LogService::debug("ICS: Intégration directe avec le Router réussie (calendars, calendar, caldav)");
        
        return true;
    }
    
    return false;
}

// Essayer d'enregistrer les routes automatiquement
if (!registerICSRoutes()) {
    // Si l'enregistrement via PluginManager échoue, essayer l'intégration directe
    if (!integrateICSWithRouter()) {
        // Si aucune méthode ne fonctionne, enregistrer dans les globals pour usage ultérieur
        if (!isset($GLOBALS['pending_route_handlers'])) {
            $GLOBALS['pending_route_handlers'] = [];
        }
        
        $GLOBALS['pending_route_handlers']['ics'] = [
            'calendars' => '\ICS\Routing\RouteHandlers\CalendarRouteHandler',
            'calendar' => '\ICS\Routing\RouteHandlers\CalendarRouteHandler',
            'caldav' => '\ICS\Routing\RouteHandlers\CalDAVRouteHandler',
            'notifications' => '\ICS\Routing\RouteHandlers\NotificationRouteHandler'
        ];
        
        \AuthGroups\Services\LogService::debug("ICS: Routes mises en attente pour enregistrement ultérieur (calendars, calendar, caldav)");
    }
}