<?php

namespace Memories\Routing\RouteHandlers;

use Memories\Routing\BaseRouteHandler;
use Memories\Controllers\DataController;
use Memories\Utils\Response;

class DataRouteHandler extends BaseRouteHandler 
{
    private DataController $controller;
    
    public function __construct($authService) {
        parent::__construct($authService);
        $this->controller = new DataController();
    }
    
    protected function getSupportedControllers(): array {
        return ['data'];
    }
    
    protected function handleRoute(array $request): void {
        $action = $request['action'];
        $method = $request['method'];
        $user = $request['user'];
        
        match(true) {
            // POST /data/merge - Synchronisation des données hors-ligne
            ($action === 'merge' && $method === 'POST') => 
                $this->controller->mergeOfflineData($user['user_id']),
                
            default => Response::error('Route données non trouvée', null, 404)
        };
    }
}