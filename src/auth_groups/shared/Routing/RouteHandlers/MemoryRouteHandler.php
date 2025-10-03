<?php
namespace Memories\Routing\RouteHandlers;

use Memories\Routing\BaseRouteHandler;
use Memories\Controllers\MemoryController;
use Memories\Utils\Response;

class MemoryRouteHandler extends BaseRouteHandler 
{
    private MemoryController $controller;
    
    public function __construct($authService) {
        parent::__construct($authService);
        $this->controller = new MemoryController();
    }
    
    protected function getSupportedControllers(): array {
        return ['memories'];
    }
    
    protected function handleRoute(array $request): void {
        $action = $request['action'];
        $method = $request['method'];
        $id = $request['id'];
        $user = $request['user'];
        $segments = $request['segments'];
        
        match(true) {
            // GET /memories
            ($action === '' && $method === 'GET') => 
                $this->controller->getAll($user['user_id'], $user['role']),
                
            // GET /memories/{id}
            ($action && ctype_digit($action) && !$id && $method === 'GET') => 
                $this->validateIdAndCall($action, fn($memoryId) => 
                    $this->controller->getById($memoryId, $user['user_id'], $user['role'])),
                
            // POST /memories
            ($action === '' && $method === 'POST') => 
                $this->controller->create($user['user_id']),
                
            // PUT /memories/{id}
            ($action && ctype_digit($action) && !$id && $method === 'PUT') => 
                $this->validateIdAndCall($action, fn($memoryId) => 
                    $this->controller->update($memoryId, $user['user_id'], $user['role'])),
                
            // DELETE /memories/{id}
            ($action && ctype_digit($action) && !$id && $method === 'DELETE') => 
                $this->validateIdAndCall($action, fn($memoryId) => 
                    $this->controller->delete($memoryId, $user['user_id'], $user['role'])),
                
            // GET /memories/my
            ($action === 'my' && $method === 'GET') => 
                $this->controller->getMyMemories($user['user_id']),
                
            // GET /memories/search
            ($action === 'search' && $method === 'GET') => 
                $this->controller->search($user['user_id'], $user['role']),

            // POST /memories/{memoryId}/{elementId}/attach
            ($method === 'POST' && isset($segments[1]) && ctype_digit($segments[1]) 
                && isset($segments[2]) && ctype_digit($segments[2]) 
                && $segments[3] === 'attach') => 
                $this->validateIdAndCall($segments[1], fn($memoryId) => 
                    $this->validateIdAndCall($segments[2], fn($elementId) => 
                        $this->controller->associateElement($memoryId, $elementId, $user['user_id'], $user['role']), 'ID de l\'élément'), 'ID de la mémoire'),                          

            // POST /memories/{memoryId}/{elementId}/detach
            ($method === 'POST' && isset($segments[1]) && ctype_digit($segments[1]) 
                && isset($segments[2]) && ctype_digit($segments[2]) 
                && $segments[3] === 'detach') => 
                $this->validateIdAndCall($segments[1], fn($memoryId) => 
                    $this->validateIdAndCall($segments[2], fn($elementId) => 
                        $this->controller->dissociateElement($memoryId, $elementId, $user['user_id'], $user['role']), 'ID de l\'élément'), 'ID de la mémoire'),

            default => Response::error('Route mémoire non trouvée', null, 404)
        };
    }
    
    private function validateIdAndCall($id, callable $callback, string $fieldName = 'ID de la mémoire'): void {
        if (!$this->validateNumericId($id, $fieldName)) {
            return;
        }
        $callback($id);
    }
}