<?php

/**
 * Point d'entrée principal - API simplifiée et refactorisée
 * Version 2.1.0 avec architecture modulaire et configuration modulaire
 */
// Autoloader Composer
require_once __DIR__ . '/vendor/autoload.php';

// Configuration modulaire (remplace config.php et database.php)
require_once __DIR__ . '/config/loader.php';

use AuthGroups\Routing\Router;
use AuthGroups\Services\LogService;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;

// Démarrer le logging

LoggingMiddleware::logEntry();

// Configuration CORS (maintenant gérée par la configuration modulaire)
$allowedOrigins = ALLOWED_ORIGINS;
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins) || in_array('*', $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: ' . implode(', ', ALLOWED_METHODS));
header('Access-Control-Allow-Headers: ' . implode(', ', ALLOWED_HEADERS));
header('Access-Control-Max-Age: 86400');

// Gérer les requêtes OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    LoggingMiddleware::logExit(200);
    http_response_code(200);
    exit();
}

// Vérifier le mode maintenance
if (MAINTENANCE_MODE) {
    Response::error(MAINTENANCE_MESSAGE, null, 503);
    LoggingMiddleware::logExit(503);
    exit();
}

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
