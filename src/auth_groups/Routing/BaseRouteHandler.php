<?php

namespace AuthGroups\Routing;

use AuthGroups\Services\AuthService;
use AuthGroups\Services\UserSessionService;
use AuthGroups\Utils\Response;
use AuthGroups\Middleware\LoggingMiddleware;
use Exception;

abstract class BaseRouteHandler implements RouteHandlerInterface 
{
    protected ?AuthService $authService;
    protected bool $requiresAuth = true;
    
    public function __construct(?AuthService $authService = null) {
        $this->authService = $authService;
    }
    
    /**
     * Retourne la liste des middleware à exécuter avant handleRoute().
     * Chaque middleware est un callable(array $request): array|false.
     * Retourner false interrompt le pipeline (réponse d'erreur déjà envoyée).
     * Les sous-classes peuvent surcharger pour ajouter leurs propres étapes.
     */
    protected function getMiddlewares(): array {
        if (!$this->requiresAuth) {
            return [];
        }

        return [
            function(array $request): array|false {
                $user = $this->authService?->authenticate();
                if (!$user) {
                    Response::error('Utilisateur non authentifié', null, 401);
                    return false;
                }
                $request['user'] = $user;
                return $request;
            }
        ];
    }

    /**
     * Exécute le pipeline middleware séquentiellement.
     * Retourne le $request enrichi, ou false si un middleware a interrompu la chaîne.
     */
    protected function runMiddleware(array $request): array|false {
        foreach ($this->getMiddlewares() as $middleware) {
            $request = $middleware($request);
            if ($request === false) {
                return false;
            }
        }
        return $request;
    }

    /**
     * Point d'entrée public — exécute le pipeline puis délègue à handleRoute().
     */
    public function handle(array $request): bool {
        $request = $this->runMiddleware($request);
        if ($request === false) {
            return true; // Réponse d'erreur déjà envoyée par le middleware
        }
        $result = $this->handleRoute($request);
        LoggingMiddleware::logExit(200);
        return $result === false ? false : true;
    }
    
    /**
     * Retourne true si la route a été traitée, false sinon
     */
    abstract protected function handleRoute(array $request);
    
    public function canHandle(string $controller): bool {
        return in_array($controller, $this->getSupportedControllers());
    }
    
    abstract protected function getSupportedControllers(): array;
    
    /**
     * Valider qu'un ID est numérique
     */
    protected function validateNumericId($id, string $fieldName = 'ID'): bool {
        if (!is_numeric($id)) {
            Response::error("{$fieldName} doit être numérique", null, 400);
            return false;
        }
        return true;
    }
}