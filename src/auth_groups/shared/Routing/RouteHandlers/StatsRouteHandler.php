<?php

namespace Memories\Routing\RouteHandlers;

use Memories\Routing\BaseRouteHandler;
use Memories\Controllers\StatsController;
use Memories\Utils\Response;

class StatsRouteHandler extends BaseRouteHandler 
{
    private StatsController $controller;
    
    public function __construct($authService) {
        parent::__construct($authService);
        $this->controller = new StatsController();
    }
    
    protected function getSupportedControllers(): array {
        return ['stats'];
    }
    
    protected function handleRoute(array $request): void {
        $action = $request['action'];
        $method = $request['method'];
        $id = $request['id'];
        $user = $request['user'];
        
        match(true) {
            // POST /stats/build - Générer toutes les statistiques
            ($action === 'build' && $method === 'POST') => 
                $this->controller->buildStats($user['user_id'], $user['role']),
                
            // GET /stats/platform - Statistiques globales de la plateforme
            ($action === 'platform' && $method === 'GET') => 
                $this->controller->getPlatformStats($user['role']),
                
            // GET /stats/groups - Statistiques par groupe
            ($action === 'groups' && $method === 'GET') => 
                $this->controller->getGroupsStats($user['role']),
                
            // GET /stats/users - Statistiques par utilisateur
            ($action === 'users' && $method === 'GET' && !$id) => 
                $this->controller->getUsersStats($user['role']),
                
            // GET /stats/users/{id} - Statistiques d'un utilisateur
            ($action === 'users' && $method === 'GET' && $id) => 
                $this->validateIdAndCall($id, fn($userId) => 
                    $this->controller->getUserStats($userId, $user['user_id'], $user['role']), 'ID utilisateur'),

            // GET /stats/my-stats
            ($action === 'my-stats' && $method === 'GET') =>
                $this->controller->getUserStats($user['user_id'], $user['user_id'], $user['role']),
                
            default => Response::error('Route statistiques non trouvée', null, 404)
        };
    }
    
    private function validateIdAndCall($id, callable $callback, string $fieldName = 'ID'): void {
        if (!$this->validateNumericId($id, $fieldName)) {
            return;
        }
        $callback($id);
    }
}