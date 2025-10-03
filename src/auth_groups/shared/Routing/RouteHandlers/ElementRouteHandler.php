<?php

namespace Memories\Routing\RouteHandlers;

use Memories\Routing\BaseRouteHandler;
use Memories\Controllers\ElementController;
use Memories\Utils\Response;

class ElementRouteHandler extends BaseRouteHandler 
{
    private ElementController $controller;
    
    public function __construct($authService) {
        parent::__construct($authService);
        $this->controller = new ElementController();
    }
    
    protected function getSupportedControllers(): array {
        return ['elements'];
    }
    
    protected function handleRoute(array $request): void {
        $action = $request['action'];
        $method = $request['method'];
        $id = $request['id'];
        $user = $request['user'];
        
        match(true) {
            // GET /elements
            ($action === '' && $method === 'GET') => 
                $this->controller->getAll($user['user_id'], $user['role']),

            // POST /elements
            ($action === '' && $method === 'POST') => 
                $this->controller->create($user['user_id']),

            // GET /elements/{id} - l'ID est dans action quand id est null
            ($action && ctype_digit($action) && !$id && $method === 'GET') => 
                $this->validateNumericId($action) ? $this->controller->getById($action, $user['user_id'], $user['role']) : Response::error('ID invalide', null, 400),
                
            // PUT /elements/{id} - l'ID est dans action quand id est null
            ($action && ctype_digit($action) && !$id && $method === 'PUT') => 
                $this->validateNumericId($action) ? $this->controller->update($action, $user['user_id'], $user['role']) : Response::error('ID invalide', null, 400),
                
            // DELETE /elements/{id} - l'ID est dans action quand id est null
            ($action && ctype_digit($action) && !$id && $method === 'DELETE') => 
                $this->validateNumericId($action) ? $this->controller->delete($action, $user['user_id'], $user['role']) : Response::error('ID invalide', null, 400),
                
            default => Response::error('Route élément non trouvée', null, 404)
        };
    }
}