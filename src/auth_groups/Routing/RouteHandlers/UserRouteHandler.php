<?php

namespace Memories\Routing\RouteHandlers;

use Memories\Routing\BaseRouteHandler;
use Memories\Controllers\UserController;
use Memories\Utils\Response;

class UserRouteHandler extends BaseRouteHandler 
{
    private UserController $controller;
    
    public function __construct($authService) {
        parent::__construct($authService);
        $this->controller = new UserController();
    }
    
    protected function getSupportedControllers(): array {
        return ['users'];
    }
    
    protected function handleRoute(array $request): void {
        $action = $request['action'];
        $method = $request['method'];
        $id = $request['id'];
        $user = $request['user'];
        $segments = $request['segments'];
        
        match(true) {
            // POST /users/avatar
            ($action === 'avatar' && $method === 'POST' && !$id) => 
                $this->controller->uploadAvatar($user['user_id'], $user['user_id'], $user['role']),

            // POST /users/{id}/avatar
            (isset($segments[2]) && $segments[2] === 'avatar' && $method === 'POST') =>
                $this->validateIdAndCall($action, fn($targetId) => 
                    $this->controller->uploadAvatar($targetId, $user['user_id'], $user['role'])),

            // PUT /users/password
            ($action === 'password' && $method === 'PUT' && !$id) =>
                $this->controller->changePassword($user['user_id'], $user['user_id'], $user['role']),

            // PUT /users/{id}/password
            (isset($segments[2]) && $segments[2] === 'password' && $method === 'PUT') =>
                $this->validateIdAndCall($action, fn($targetId) => 
                    $this->controller->changePassword($targetId, $user['user_id'], $user['role'])),

            // POST /users/logout
            ($action === 'logout' && $method === 'POST') => 
                $this->controller->logout($user['user_id']),

            // GET /users
            ($action === '' && $method === 'GET') =>
                $this->controller->getAll($user['role']),

            // GET /users/me
            ($action === 'me' && $method === 'GET') =>
                $this->controller->getById($user['user_id'], $user['user_id'], $user['role']),
            
            // PUT /users/me
            ($action === 'me' && $method === 'PUT') =>
                $this->controller->updateProfile($user['user_id'], $user['user_id'], $user['role']),

            // DELETE /users/me
            ($action === 'me' && $method === 'DELETE') =>
                $this->controller->delete($user['user_id'], $user['user_id'], $user['role']),

            // POST /users/{id}/restore
            (isset($segments[2]) && $segments[2] === 'restore' && $method === 'POST') =>
                $this->validateIdAndCall($action, fn($targetId) => 
                    $this->controller->restore($targetId, $user['user_id'], $user['role'])),

            // GET /users/{id}
            ($action && ctype_digit($action) && $method === 'GET' && !$id) =>
                $this->validateIdAndCall($action, fn($targetId) => 
                    $this->controller->getById($targetId, $user['user_id'], $user['role'])),

            // PUT /users/{id}
            ($action && ctype_digit($action) && $method === 'PUT' && !$id) =>
                $this->validateIdAndCall($action, fn($targetId) => 
                    $this->controller->updateProfile($targetId, $user['user_id'], $user['role'])),

            // DELETE /users/{id}
            ($action && ctype_digit($action) && $method === 'DELETE' && !$id) =>
                $this->validateIdAndCall($action, fn($targetId) => 
                    $this->controller->delete($targetId, $user['user_id'], $user['role'])),

            default => Response::error('Route utilisateur non trouvée', null, 404)
        };
    }
    
    private function validateIdAndCall($id, callable $callback, string $fieldName = 'ID utilisateur'): void {
        if (!$this->validateNumericId($id, $fieldName)) {
            return;
        }
        $callback($id);
    }
}