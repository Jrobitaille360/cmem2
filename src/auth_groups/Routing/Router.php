<?php

namespace AuthGroups\Routing;

use AuthGroups\Routing\RouteHandlers\{
    PublicRouteHandler,
    UserRouteHandler,
    GroupRouteHandler,
    TagRouteHandler,
    FileRouteHandler,
    StatsRouteHandler,
    SecretAdminRouteHandler,
    PlanRouteHandler
};
use AuthGroups\Services\{AuthService, LogService};
use AuthGroups\Utils\Response;
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
            'secret-admin' => new SecretAdminRouteHandler(),
            'plans' => new PlanRouteHandler($this->authService)
        ];
        
        // Intégrer les route handlers des plugins
        $this->loadPluginRouteHandlers();
    }
    
    /**
     * Charge les route handlers des plugins via le PluginManager
     */
    private function loadPluginRouteHandlers(): void {
        try {
            // Essayer de charger via le PluginManager
            if (isset($GLOBALS['plugin_manager'])) {
                $pluginManager = $GLOBALS['plugin_manager'];
                $pluginRoutes = $pluginManager->getPluginRouteHandlers();
                
                foreach ($pluginRoutes as $route => $handlerFactory) {
                    if (is_callable($handlerFactory)) {
                        $this->routeHandlers[$route] = $handlerFactory($this->authService);
                    }
                }
                
                if (!empty($pluginRoutes) && defined('APP_DEBUG') && APP_DEBUG) {
                    LogService::info("Routes de plugins chargées", [
                        'routes' => array_keys($pluginRoutes)
                    ]);
                }
            }
            
            // Charger les routes en attente depuis les globals
            if (isset($GLOBALS['pending_route_handlers'])) {
                foreach ($GLOBALS['pending_route_handlers'] as $pluginName => $routes) {
                    foreach ($routes as $route => $handlerClass) {
                        if (class_exists($handlerClass)) {
                            $this->routeHandlers[$route] = new $handlerClass($this->authService);
                            
                            if (defined('APP_DEBUG') && APP_DEBUG) {
                                LogService::info("Route de plugin chargée", [
                                    'plugin' => $pluginName,
                                    'route' => $route,
                                    'handler' => $handlerClass
                                ]);
                            }
                        }
                    }
                }
                
                // Nettoyer les routes en attente après chargement
                unset($GLOBALS['pending_route_handlers']);
            }
            
        } catch (Exception $e) {
            LogService::warning("Erreur lors du chargement des routes de plugins", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Méthode publique pour ajouter un route handler (pour l'intégration directe)
     */
    public function addRouteHandler(string $route, $handler): void {
        if (is_string($handler) && class_exists($handler)) {
            $this->routeHandlers[$route] = new $handler($this->authService);
        } elseif (is_object($handler)) {
            $this->routeHandlers[$route] = $handler;
        }
        
        if (defined('APP_DEBUG') && APP_DEBUG) {
            LogService::info("Route handler ajouté", [
                'route' => $route,
                'handler' => is_string($handler) ? $handler : get_class($handler)
            ]);
        }
    }
    
    public function handleRequest(): void {
        try {
            $request = $this->parseRequest();

/*             // Si pas de segments, afficher les informations de l'API
            if (empty($request['controller'])) {
                $this->showAPIInfo();
                return;
            } */

            // On tente d'abord le handler public
            $publicResult = $this->publicHandler->handle($request);
            if ($publicResult === true) {
                return;
            }

            // Essayer les autres handlers
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
    
}