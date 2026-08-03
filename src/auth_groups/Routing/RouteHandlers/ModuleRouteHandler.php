<?php

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Controllers\ModuleController;
use AuthGroups\Utils\Response;

/**
 * Routes du registre de modules — directive cmem_web 20260727_144926.
 *
 *   GET   /modules          → état des 8 modules pour l'usager du JWT
 *   PATCH /modules/{key}    → active / désactive un module
 */
class ModuleRouteHandler extends BaseRouteHandler
{
    private ModuleController $controller;

    public function __construct($authService)
    {
        parent::__construct($authService);
        $this->controller = new ModuleController();
    }

    protected function getSupportedControllers(): array
    {
        return ['modules'];
    }

    protected function handleRoute(array $request): void
    {
        $action = $request['action'];
        $method = $request['method'];
        $user   = $request['user'];

        match (true) {
            ($action === '' && $method === 'GET') =>
                $this->controller->index($user),

            ($action !== '' && in_array($method, ['PATCH', 'PUT'], true)) =>
                $this->controller->update($user, $action),

            default => Response::error('Route modules non trouvée', null, 404)
        };
    }
}
