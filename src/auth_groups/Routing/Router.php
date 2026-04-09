<?php

namespace AuthGroups\Routing;

use AuthGroups\Routing\RouteHandlers\{
    PublicRouteHandler,
    AuthRouteHandler,
    UserRouteHandler,
    GroupRouteHandler,
    TagRouteHandler,
    FileRouteHandler,
    StatsRouteHandler,
    SecretAdminRouteHandler,
    PlanRouteHandler,
    SubscriptionRouteHandler
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

        // D1 — factory closures : le handler n'est instancié qu'à l'usage
        $auth = $this->authService;
        $this->routeHandlers = [
            'auth'         => fn() => new AuthRouteHandler(),
            'users'        => fn() => new UserRouteHandler($auth),
            'groups'       => fn() => new GroupRouteHandler($auth),
            'tags'         => fn() => new TagRouteHandler($auth),
            'files'        => fn() => new FileRouteHandler($auth),
            'stats'        => fn() => new StatsRouteHandler($auth),
            'secret-admin' => fn() => new SecretAdminRouteHandler(),
            'plans'        => fn() => new PlanRouteHandler($auth),
            'subscription' => fn() => new SubscriptionRouteHandler($auth)
        ];

        $this->loadPluginRouteHandlers();
    }
    
    /**
     * Charge les route handlers des plugins via le PluginManager.
     * Les factories reçoivent $authService en argument.
     */
    private function loadPluginRouteHandlers(): void {
        try {
            if (!isset($GLOBALS['plugin_manager'])) {
                return;
            }

            $pluginRoutes = $GLOBALS['plugin_manager']->getPluginRouteHandlers();

            foreach ($pluginRoutes as $route => $handlerFactory) {
                if (is_callable($handlerFactory)) {
                    $auth = $this->authService;
                    $this->routeHandlers[$route] = fn() => $handlerFactory($auth);
                }
            }

            if (!empty($pluginRoutes) && defined('APP_DEBUG') && APP_DEBUG) {
                LogService::info("Routes de plugins chargées", [
                    'routes' => array_keys($pluginRoutes)
                ]);
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
        $auth = $this->authService;

        if (is_string($handler) && class_exists($handler)) {
            $this->routeHandlers[$route] = fn() => new $handler($auth);
        } elseif (is_callable($handler)) {
            $this->routeHandlers[$route] = fn() => $handler($auth);
        } elseif (is_object($handler)) {
            $this->routeHandlers[$route] = fn() => $handler;
        }

        if (defined('APP_DEBUG') && APP_DEBUG) {
            LogService::info("Route handler ajouté", [
                'route'   => $route,
                'handler' => is_string($handler) ? $handler : (is_object($handler) ? get_class($handler) : 'callable')
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
            $factory    = $this->routeHandlers[$controller] ?? null;

            if (!$factory) {
                LogService::warning('Endpoint non trouvé', $request);
                Response::error('Endpoint non trouvé', null, 404);
                return;
            }

            $factory()->handle($request);

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
        $uri = str_replace(defined('BASE_PATH') ? BASE_PATH : '/cmem2_API', '', $uri);
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