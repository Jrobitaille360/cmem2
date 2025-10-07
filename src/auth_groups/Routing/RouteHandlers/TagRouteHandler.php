<?php

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Controllers\TagController;
use AuthGroups\Utils\Response;

class TagRouteHandler extends BaseRouteHandler 
{
    private TagController $controller;
    
    public function __construct($authService) {
        parent::__construct($authService);
        $this->controller = new TagController();
    }
    
    protected function getSupportedControllers(): array {
        return ['tags'];
    }
    
    protected function handleRoute(array $request): void {
        $action = $request['action'];
        $method = $request['method'];
        $id = $request['id'];
        $user = $request['user'];
        $segments = $request['segments'];
        
        match(true) {
            // GET /tags
            ($action === '' && $method === 'GET') => 
                $this->controller->search($user['user_id'], $user['role']),

            // POST /tags
            ($action === '' && $method === 'POST') => 
                $this->controller->create($user['user_id']),
                
            // GET /tags/{id}
            ($action && ctype_digit($action) && !$id && $method === 'GET') => 
                $this->validateIdAndCall($action, fn($tagId) => 
                    $this->controller->getById($tagId, $user['user_id'], $user['role'])),
                
            // PUT /tags/{id}
            ($action && ctype_digit($action) && !$id && $method === 'PUT') => 
                $this->validateIdAndCall($action, fn($tagId) => 
                    $this->controller->update($tagId, $user['user_id'], $user['role'])),
                
            // DELETE /tags/{id}
            ($action && ctype_digit($action) && !$id && $method === 'DELETE') => 
                $this->validateIdAndCall($action, fn($tagId) => 
                    $this->controller->delete($tagId, $user['user_id'], $user['role'])),
                
            // POST /tags/{id}/restore
            (isset($segments[2]) && $segments[2] === 'restore' && $method === 'POST') => 
                $this->validateIdAndCall($action, fn($tagId) => 
                    $this->controller->restore($tagId, $user['user_id'], $user['role'])),
                
            // GET /tags/my-tags
            ($action === 'my-tags' && $method === 'GET') => 
                $this->controller->getUserTags($user['user_id'], $user['user_id'], $user['role']),
                
            // GET /tags/by-table/{table_associate}
            ($action === 'by-table' && $method === 'GET' && $id) => 
                $this->handleByTableRoute($id, $user),
                
            // GET /tags/most-used
            ($action === 'most-used' && $method === 'GET') => 
                $this->controller->getMostUsed($user['user_id'], $user['role']),
                
            // POST /tags/get-or-create
            ($action === 'get-or-create' && $method === 'POST') => 
                $this->controller->getOrCreate($user['user_id']),
                
            // GET /tags/user/{user_id}
            ($action === 'user' && $method === 'GET' && $id) => 
                $this->validateIdAndCall($id, fn($userId) => 
                    $this->controller->getUserTags($userId, $user['user_id'], $user['role']), 'ID utilisateur'),
                
            // PUT /tags/{tagId}/{itemId} - Association/Dissociation
            ($action && ctype_digit($action) && $id && ctype_digit($id) && $method === 'PUT') => 
                $this->handleAssociateDissociate($action, $id, $user),
                
            default => Response::error('Route tag non trouvée', null, 404)
        };
    }
    
    private function validateIdAndCall($id, callable $callback, string $fieldName = 'ID du tag'): void {
        if (!$this->validateNumericId($id, $fieldName)) {
            return;
        }
        $callback($id);
    }
    
    private function handleByTableRoute($tableAssociate, array $user): void {
        if (!in_array($tableAssociate, ['groups', 'files', 'all'])) {
            Response::error('Table associée invalide', null, 400);
            return;
        }
        $this->controller->getTagsByTable($tableAssociate, $user['user_id'], $user['role']);
    }
    
    private function handleAssociateDissociate($tagId, $itemId, array $user): void {
        // Valider que les deux IDs sont numériques
        if (!$this->validateNumericId($tagId, 'ID du tag') || !$this->validateNumericId($itemId, 'ID de l\'élément')) {
            return;
        }
        
        $this->controller->associateOrDissociate($tagId, $itemId, $user['user_id'], $user['role']);
    }
}