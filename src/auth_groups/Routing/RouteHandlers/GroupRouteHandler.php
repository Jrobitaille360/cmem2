<?php

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Controllers\GroupController;
use AuthGroups\Utils\Response;

class GroupRouteHandler extends BaseRouteHandler 
{
    private GroupController $controller;
    
    public function __construct($authService) {
        parent::__construct($authService);
        $this->controller = new GroupController();
    }
    
    protected function getSupportedControllers(): array {
        return ['groups'];
    }
    
    protected function handleRoute(array $request): void {
        $action = $request['action'];
        $method = $request['method'];
        $id = $request['id'];
        $user = $request['user'];
        $segments = $request['segments'];
        
        match(true) {
            // POST /groups
            ($action === '' && $method === 'POST') => 
                $this->controller->create($user['user_id']),

            // GET /groups/search
            ($action === 'search' && $method === 'GET') => 
                $this->controller->search($user['user_id'], $user['role']),

            // GET /groups/my-groups
            ($action === 'my-groups' && $method === 'GET') => 
                $this->controller->getUserGroups($user['user_id'], $user['user_id'], $user['role']),

            // GET /groups/my-invitations
            ($action === 'my-invitations' && $method === 'GET') => 
                $this->controller->myInvitations($user['email']),

            // GET /groups/user/{user_id}
            ($action === 'user' && $method === 'GET' && $id) => 
                $this->validateIdAndCall($id, fn($userId) => 
                    $this->controller->getUserGroups($userId, $user['user_id'], $user['role']), 'ID utilisateur'),

            // POST /groups/{id}/restore
            (isset($segments[2]) && $segments[2] === 'restore' && $method === 'POST') => 
                $this->validateIdAndCall($action, fn($groupId) => 
                    $this->controller->restore($groupId, $user['user_id'], $user['role'])),

            // POST /groups/{id}/invite
            (isset($segments[2]) && $segments[2] === 'invite' && $method === 'POST') => 
                $this->validateIdAndCall($action, fn($groupId) => 
                    $this->controller->invite($groupId, $user['user_id'], $user['role'])),

            // POST /groups/{id}/leave
            (isset($segments[2]) && $segments[2] === 'leave' && $method === 'POST') => 
                $this->validateIdAndCall($action, fn($groupId) => 
                    $this->controller->leave($groupId, $user['user_id'])),

            // GET /groups/{id}/members
            (isset($segments[2]) && $segments[2] === 'members' && $method === 'GET') => 
                $this->validateIdAndCall($action, fn($groupId) => 
                    $this->controller->getMembers($groupId, $user['user_id'], $user['role'])),
            
            // PUT /groups/{group_id}/members/{user_id}
            (isset($segments[2]) && $segments[2] === 'members' && $method === 'PUT' 
            && isset($segments[3])) =>
                $this->controller->updateUserRole( 
                    $user['user_id'], 
                    $user['role'], 
                    $segments[3],
                    $segments[1]),

            // GET /groups/{id}
            ($action && ctype_digit($action) && !$id && $method === 'GET') => 
                $this->validateIdAndCall($action, fn($groupId) => 
                    $this->controller->getById($groupId, $user['user_id'], $user['role'])),
                
            // PUT /groups/{id} - 
            ($action && ctype_digit($action) && !$id && $method === 'PUT') => 
                $this->validateIdAndCall($action, fn($groupId) => 
                    $this->controller->update($groupId, $user['user_id'], $user['role'])),
                
            // DELETE /groups/{id}
            ($action && ctype_digit($action) && !$id && $method === 'DELETE') => 
                $this->validateIdAndCall($action, fn($groupId) => 
                    $this->controller->delete($groupId, $user['user_id'], $user['role'])),
                
            default => Response::error('Route groupe non trouvée', null, 404)
        };
    }
   
    private function validateIdAndCall($id, callable $callback, string $fieldName = 'ID du groupe'): void {
        if (!$this->validateNumericId($id, $fieldName)) {
            return;
        }
        $callback($id);
    }
}