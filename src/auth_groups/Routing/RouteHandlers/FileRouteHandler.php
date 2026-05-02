<?php

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Controllers\FileController;
use AuthGroups\Utils\Response;

class FileRouteHandler extends BaseRouteHandler 
{
    private FileController $controller;
    
    public function __construct($authService) {
        parent::__construct($authService);
        $this->controller = new FileController();
    }
    
    protected function getSupportedControllers(): array {
        return ['files'];
    }

    /**
     * JWT optionnel pour GET /files/{id} et GET /files/{id}/info :
     * si absent ou invalide, on injecte un utilisateur guest (user_id=null, role='guest')
     * pour permettre l'accès aux fichiers grand-public sans authentification.
     */
    protected function getMiddlewares(): array {
        return [
            function(array $request): array|false {
                $action = $request['action'] ?? '';
                $id     = $request['id']     ?? '';
                $method = $request['method'] ?? '';

                $isOptionalAuth = $method === 'GET'
                    && ctype_digit((string) $action)
                    && (!$id || $id === 'info');

                $user = $this->authService?->authenticate();

                if (!$user) {
                    if ($isOptionalAuth) {
                        $request['user'] = ['user_id' => null, 'role' => 'guest'];
                        return $request;
                    }
                    Response::error('Utilisateur non authentifié', null, 401);
                    return false;
                }

                $request['user'] = $user;
                return $request;
            }
        ];
    }
    
    protected function handleRoute(array $request): void {
        $action = $request['action'];
        $method = $request['method'];
        $id = $request['id'];
        $user = $request['user'];
        
        match(true) {
            // GET /files?folder=<slug>
            ($action === '' && $method === 'GET') =>
                $this->controller->listByFolder($user['user_id'], $user['role']),

            // POST /files
            ($action === '' && $method === 'POST') =>
                $this->controller->upload($user['user_id'], $user['role']),
                
            // GET /files/{id}
            ($action && ctype_digit($action) && !$id && $method === 'GET') => 
                $this->validateIdAndCall($action, fn($fileId) => 
                    $this->controller->download($fileId, $user['user_id'], $user['role'])),

            // GET /files/{id}/info
            ($action && ctype_digit($action) && $id === 'info' && $method === 'GET') =>
                $this->validateIdAndCall($action, fn($fileId) =>
                    $this->controller->getFileInfo($fileId, $user['user_id'], $user['role'])),
                
            // DELETE /files/{id}
            ($action && ctype_digit($action) && !$id && $method === 'DELETE') => 
                $this->validateIdAndCall($action, fn($fileId) => 
                    $this->controller->delete($fileId, $user['user_id'], $user['role'])),

            // POST /files/{id}/restore
            ($action && ctype_digit($action) && $id === 'restore' && $method === 'POST') =>
                $this->validateIdAndCall($action, fn($fileId) =>
                    $this->controller->restore($fileId, $user['user_id'], $user['role'])),

            // PATCH /files/{id}/accessibility
            ($action && ctype_digit($action) && $id === 'accessibility' && $method === 'PATCH') =>
                $this->validateIdAndCall($action, fn($fileId) =>
                    $this->controller->updateAccessibility($fileId, $user['user_id'], $user['role'])),
                
            // GET /files/user/{user_id}
            ($action === 'user' && $method === 'GET' && $id) => 
                $this->validateIdAndCall($id, fn($userId) => 
                    $this->controller->getUserFiles($userId, $user['user_id'], $user['role']), 'ID utilisateur'),
                
            default => Response::error('Route fichier non trouvée', null, 404)
        };
    }
    
    private function validateIdAndCall($id, callable $callback, string $fieldName = 'ID du fichier'): void {
        if (!$this->validateNumericId($id, $fieldName)) {
            return;
        }
        $callback($id);
    }
}