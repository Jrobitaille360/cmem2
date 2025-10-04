<?php

namespace Memories\Routing\RouteHandlers;

use Memories\Routing\BaseRouteHandler;
use Memories\Controllers\FileController;
use Memories\Utils\Response;

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
    
    protected function handleRoute(array $request): void {
        $action = $request['action'];
        $method = $request['method'];
        $id = $request['id'];
        $user = $request['user'];
        
        match(true) {
            // POST /files
            ($action === '' && $method === 'POST') => 
                $this->controller->upload($user['user_id']),
                
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