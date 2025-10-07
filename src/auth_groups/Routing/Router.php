<?php

namespace AuthGroups\Routing;

use AuthGroups\Routing\RouteHandlers\{
    PublicRouteHandler,
    UserRouteHandler,
    GroupRouteHandler,
   // MemoryRouteHandler,
   // ElementRouteHandler,
    TagRouteHandler,
    FileRouteHandler,
    StatsRouteHandler,
    DataRouteHandler,
    SecretAdminRouteHandler
};
use AuthGroups\Services\{AuthService, LogService};
use AuthGroups\Utils\Response;
use AuthGroups\Middleware\LoggingMiddleware;
use Exception;

class Router 
{
    private array $routeHandlers = [];
    private AuthService $authService;
    
    public function __construct() {
        $this->authService = new AuthService();
        $this->initializeRouteHandlers();
    }
    
    private PublicRouteHandler $publicHandler;

    private function initializeRouteHandlers(): void {
        $this->publicHandler = new PublicRouteHandler();
        $this->routeHandlers = [
            'users' => new UserRouteHandler($this->authService),
            'groups' => new GroupRouteHandler($this->authService),
            'tags' => new TagRouteHandler($this->authService),
            'files' => new FileRouteHandler($this->authService),
            'stats' => new StatsRouteHandler($this->authService),
            'data' => new DataRouteHandler($this->authService),
            'secret-admin' => new SecretAdminRouteHandler()
        ];
    }
    
    public function handleRequest(): void {
        try {
            $request = $this->parseRequest();

            // Si pas de segments, afficher les informations de l'API
            if (empty($request['controller'])) {
                $this->showAPIInfo();
                return;
            }

            // On tente d'abord le handler public
            $publicResult = $this->publicHandler->handle($request);
            if ($publicResult === true) {
                return;
            }

            // Sinon, on essaie les autres handlers
            $controller = $request['controller'];
            $handler = $this->routeHandlers[$controller] ?? null;

            if (!$handler) {
                LogService::warning('Endpoint non trouvé', $request);
                Response::error('Endpoint non trouvé', null, 404);
                return;
            }

            $handler->handle($request);

        } catch (Exception $e) {
            LogService::error('Erreur dans Router', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            Response::error('Erreur serveur: ' . $e->getMessage(), null, 500);
        }
    }
    
    private function parseRequest(): array {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        // Nettoyer l'URI - s'assurer que $uri n'est pas null
        $uri = $uri ?? '';
        $uri = str_replace('/cmem2_API', '', $uri);
        $uri = str_replace('/index.php', '', $uri);
        $uri = trim($uri, '/');
        
        $segments = $uri ? explode('/', $uri) : [];
        
        return [
            'method' => $method,
            'controller' => $segments[0] ?? '',
            'action' => $segments[1] ?? '',
            'id' => $segments[2] ?? null,
            'segments' => $segments
        ];
    }
    
    // getRouteHandler n'est plus utilisé
    
    private function showAPIInfo(): void {
        $info = [
            'name' => 'AuthGroups API',
            'version' => '1.1.0',
            'description' => 'API REST pour la gestion d\'authentification et de groupes',
            'architecture' => 'Architecture modulaire avec gestionnaires de routes séparés',
            'performance' => 'Optimisée avec chargement sélectif des composants',
            'modules' => [
                'users' => 'Gestion des utilisateurs et authentification',
                'groups' => 'Gestion des groupes et invitations',
                'tags' => 'Système de tags et catégorisation',
                'files' => 'Upload et gestion de fichiers',
                'stats' => 'Statistiques et analytics',
                'data' => 'Synchronisation hors-ligne'
            ],
            'improvements' => [
                'Architecture modulaire implementée',
                'Séparation claire des responsabilités',
                'Maintenabilité optimisée',
                'Performance améliorée',
                'Tests unitaires facilitéss',
                'Gestionnaires de routes spécialisés'
            ]
        ];
        LoggingMiddleware::logExit(200);
        Response::success('API_info', $info);
    }
}