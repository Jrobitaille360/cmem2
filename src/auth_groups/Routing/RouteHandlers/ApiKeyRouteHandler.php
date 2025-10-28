<?php
/**
 * Gestionnaire de routes pour les clés API
 */

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Controllers\ApiKeyController;
use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;

class ApiKeyRouteHandler extends BaseRouteHandler
{
    private ApiKeyController $controller;
    
    public function __construct($authService)
    {
        parent::__construct($authService);
        $this->controller = new ApiKeyController();
    }
    
    protected function getSupportedControllers(): array
    {
        return ['api-keys'];
    }
    
    protected function handleRoute(array $request): void
    {
        // SÉCURITÉ : La gestion des API keys a été déplacée vers le système d'administration secret
        // Toutes les opérations sur les API keys nécessitent maintenant l'authentification 
        // via secretadminkey pour des raisons de sécurité renforcée
        
        \AuthGroups\Services\LogService::warning('Tentative d\'accès aux API keys via endpoint public désactivé', [
            'method' => $request['method'],
            'action' => $request['action'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        Response::error('Endpoint déplacé - Utilisez le système d\'administration secret pour gérer les API keys', [
            'message' => 'La gestion des API keys nécessite maintenant une authentification renforcée',
            'details' => 'Contactez votre administrateur système pour l\'accès aux fonctionnalités de gestion des API keys'
        ], 410); // 410 Gone - Resource permanently moved
    }
    
    private function validateIdAndCall($id, callable $callback, string $fieldName = 'ID de clé API'): void
    {
        if (!$this->validateNumericId($id, $fieldName)) {
            return;
        }
        $callback($id);
    }
}
