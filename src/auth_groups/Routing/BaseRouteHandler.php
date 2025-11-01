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
     * Retourne true si la route a été traitée, false sinon
     */
    public function handle(array $request): bool {
        if ($this->requiresAuth) {
            $user = $this->authService?->authenticate();
            if (!$user) {
                Response::error('Utilisateur non authentifié', null, 401);
                return true;
            }
            $request['user'] = $user;
            
            // Mettre à jour l'activité de la session si l'utilisateur est authentifié avec API Key
            $this->updateUserActivity($user);
        }
        $result = $this->handleRoute($request);
        LoggingMiddleware::logExit(200);
        return $result === false ? false : true;
    }
    
    /**
     * Met à jour l'activité de l'utilisateur si authentifié avec API Key
     */
    protected function updateUserActivity($user): void {
        if (!$user || !isset($user['api_key_id'])) {
            return;
        }
        
        try {
            $userSessionService = new UserSessionService();
            $userSessionService->updateActivity($user['id'], $user['api_key_id']);
        } catch (Exception $e) {
            // Log l'erreur mais ne pas interrompre le processus
            Response::error("Erreur mise à jour activité session: " . $e->getMessage(), null, 500);
        }
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