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
        $action = $request['action'];
        $method = $request['method'];
        $id = $request['id'];
        $segments = $request['segments'];
        
        // Routes disponibles:
        // POST   /api-keys              - Créer une nouvelle clé
        // GET    /api-keys              - Lister toutes les clés
        // GET    /api-keys/:id          - Obtenir une clé spécifique
        // DELETE /api-keys/:id          - Révoquer une clé
        // POST   /api-keys/:id/regenerate - Régénérer une clé
        
        match(true) {
            // POST /api-keys - Créer une nouvelle clé
            ($action === '' && $method === 'POST') =>
                $this->controller->create(),
            
            // GET /api-keys - Liste de toutes les clés
            ($action === '' && $method === 'GET') =>
                $this->controller->list(),
            
            // GET /api-keys/:id - Détails d'une clé
            ($action && ctype_digit($action) && $method === 'GET' && !$id) =>
                $this->validateIdAndCall($action, fn($keyId) => 
                    $this->controller->get($keyId)),
            
            // DELETE /api-keys/:id - Révoquer une clé
            ($action && ctype_digit($action) && $method === 'DELETE' && !$id) =>
                $this->validateIdAndCall($action, fn($keyId) => 
                    $this->controller->revoke($keyId)),
            
            // POST /api-keys/:id/regenerate - Régénérer une clé
            (isset($segments[2]) && $segments[2] === 'regenerate' && $method === 'POST') =>
                $this->validateIdAndCall($action, fn($keyId) => 
                    $this->controller->regenerate($keyId)),
            
            default => Response::error('Route API key non trouvée', null, 404)
        };
    }
    
    private function validateIdAndCall($id, callable $callback, string $fieldName = 'ID de clé API'): void
    {
        if (!$this->validateNumericId($id, $fieldName)) {
            return;
        }
        $callback($id);
    }
}
