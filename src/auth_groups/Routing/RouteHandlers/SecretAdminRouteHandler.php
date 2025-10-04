<?php

namespace Memories\Routing\RouteHandlers;

use Memories\Routing\BaseRouteHandler;
use Memories\Controllers\SecretAdminController;
use Memories\Utils\Response;

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
    
    public function __construct() {
        // Passer l'AuthService pour l'authentification JWT
        parent::__construct(new \Memories\Services\AuthService());
        $this->controller = new SecretAdminController();
    }
    
    protected function getSupportedControllers(): array {
        return ['secret-admin'];
    }
    
    protected function handleRoute(array $request) {
        $controller = $request['controller'];
        $action = $request['action'];
        $method = $request['method'];
        $user = $request['user'] ?? null;
        
        // Vérification supplémentaire : l'utilisateur doit être ADMINISTRATEUR
        if (!$user || $user['role'] !== 'ADMINISTRATEUR') {
            \Memories\Services\LogService::warning('Tentative d\'accès admin secret sans privilèges admin', [
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
                
            // Route par défaut - non autorisée
            default => false
        };
    }
}