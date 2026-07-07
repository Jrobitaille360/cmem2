<?php

/**
 * Point d'entrée principal - API simplifiée et refactorisée
 * Version 2.1.0 avec architecture modulaire et configuration modulaire
 */
// Autoloader Composer
require_once __DIR__ . '/vendor/autoload.php';

// Configuration modulaire (remplace config.php et database.php)
require_once __DIR__ . '/src/auth_groups/loader.php';

use AuthGroups\Routing\Router;
use AuthGroups\Services\LogService;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;

// Démarrer le logging

LoggingMiddleware::logEntry();

// CORS : écho de l'origin exact s'il figure dans ALLOWED_ORIGINS (requis pour les
// clients navigateur qui envoient Authorization), sinon fallback '*' (compat clients existants)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Vary: Origin');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: ' . implode(', ', ALLOWED_METHODS));
header('Access-Control-Allow-Headers: ' . implode(', ', ALLOWED_HEADERS));
header('Access-Control-Max-Age: 86400');

// Gérer les requêtes OPTIONS (préflight, sans auth) — sauf CalDAV qui a ses propres headers
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$isCalDAVRequest = strpos($requestUri, '/caldav') !== false;

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS' && !$isCalDAVRequest) {
    LoggingMiddleware::logExit(204);
    http_response_code(204);
    exit();
}

// Vérifier le mode maintenance
if (MAINTENANCE_MODE) {
    Response::error(MAINTENANCE_MESSAGE, null, 503);
    LoggingMiddleware::logExit(503);
    exit();
}

// Initialiser le PluginManager et charger les plugins (calendars, caldav, notifications…)
$pluginManager = \Core\PluginManager::getInstance();
$pluginManager->loadPlugins();
$GLOBALS['plugin_manager'] = $pluginManager;

// Initialiser et lancer le router
try {
    $router = new Router();
    $router->handleRequest();
} catch (Exception $e) {
    LogService::error('Erreur fatale dans l\'API', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    Response::error('Erreur serveur critique', null, 500);
    LoggingMiddleware::logExit(500);
}
