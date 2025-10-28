<?php

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Controllers\SecretAdminController;
use AuthGroups\Utils\Response;

/**
 * Gestionnaire de routes pour l'endpoint admin secret
 * Ne doit pas être documenté publiquement
 * 
 * SÉCURITÉ RENFORCÉE : Double authentification requise
 * 1. Token JWT valide avec rôle ADMINISTRATEUR
 * 2. Clé secrète admin dans le request
 */
class SecretAdminRouteHandler extends BaseRouteHandler 
{
    protected bool $requiresAuth = true; // Authentification JWT requise
    private SecretAdminController $controller;
    private \AuthGroups\Controllers\SecretApiKeyController $apiKeyController;
    
    public function __construct() {
        // Passer l'AuthService pour l'authentification JWT
        parent::__construct(new \AuthGroups\Services\AuthService());
        $this->controller = new SecretAdminController();
        $this->apiKeyController = new \AuthGroups\Controllers\SecretApiKeyController();
    }
    
    protected function getSupportedControllers(): array {
        return ['secret-admin'];
    }
    
    protected function handleRoute(array $request) {
        $controller = $request['controller'];
        $action = $request['action'];
        $method = $request['method'];
        $id = $request['id'] ?? null;
        $segments = $request['segments'] ?? [];
        $user = $request['user'] ?? null;
        
        // S'assurer que $id est une chaîne ou null pour éviter les erreurs de type
        $id = $id !== null ? (string)$id : null;
        
        // Vérification supplémentaire : l'utilisateur doit être ADMINISTRATEUR
        if (!$user || $user['role'] !== 'ADMINISTRATEUR') {
            \AuthGroups\Services\LogService::warning('Tentative d\'accès admin secret sans privilèges admin', [
                'user_id' => $user['user_id'] ?? null,
                'role' => $user['role'] ?? 'inconnu',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'endpoint' => "/{$controller}/{$action}"
            ]);
            
            Response::error('Privilèges administrateur requis', null, 403);
            return true;
        }
        
        return match(true) {
            // POST /secret-admin/execute-procedure
            ($controller === 'secret-admin' && $action === 'execute-procedure' && $method === 'POST') => 
                $this->controller->executeProcedure($user),
                
            // GET /secret-admin/procedures  
            ($controller === 'secret-admin' && $action === 'procedures' && $method === 'GET') => 
                $this->controller->listProcedures($user),
                
            // === NOUVELLES ROUTES POUR LA GESTION DES API KEYS ===
            
            // POST /secret-admin/api-keys - Créer une API key
            ($controller === 'secret-admin' && $action === 'api-keys' && $method === 'POST' && !$id) => 
                $this->apiKeyController->create($user),
                
            // GET /secret-admin/api-keys - Lister les API keys (sans ID)
            ($controller === 'secret-admin' && $action === 'api-keys' && $method === 'GET' && !$id) => 
                $this->apiKeyController->list($user),
                
            // GET /secret-admin/api-keys/{id} - Détails d'une API key (avec ID numérique)
            ($controller === 'secret-admin' && $action === 'api-keys' && $method === 'GET' && $id && ctype_digit($id)) => 
                $this->validateIdAndCall($id, fn($keyId) => 
                    $this->apiKeyController->get($keyId, $user)),
                    
            // DELETE /secret-admin/api-keys/{id} - Révoquer une API key
            ($controller === 'secret-admin' && $action === 'api-keys' && $method === 'DELETE' && $id && ctype_digit($id)) => 
                $this->validateIdAndCall($id, fn($keyId) => 
                    $this->apiKeyController->revoke($keyId, $user)),
                    
            // POST /secret-admin/api-keys/{id}/regenerate - Régénérer une API key
            ($controller === 'secret-admin' && $action === 'api-keys' && $id && ctype_digit($id) && 
             isset($segments[4]) && $segments[4] === 'regenerate' && $method === 'POST') => 
                $this->validateIdAndCall($id, fn($keyId) => 
                    $this->apiKeyController->regenerate($keyId, $user)),
                
            // Route par défaut - non autorisée
            default => false
        };
    }
    
    /**
     * Valider un ID numérique et appeler la fonction callback
     */
    private function validateIdAndCall($id, callable $callback, string $fieldName = 'ID'): void
    {
        if (!$id || !ctype_digit((string)$id)) {
            \AuthGroups\Services\LogService::warning('ID invalide fourni', [
                'field' => $fieldName,
                'value' => $id,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            Response::error("$fieldName invalide", [
                'error' => 'INVALID_ID',
                'message' => "$fieldName doit être un nombre entier positif"
            ], 400);
            return;
        }
        
        $callback((int)$id);
    }
}