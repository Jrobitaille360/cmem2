<?php

namespace Access\Routing;

use Access\Controllers\ResourceCapController;
use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;

/**
 * Caps de ressources (plan #269, Phase 3 bis, #295) — ADMINISTRATEUR+.
 *
 *   GET /caps/defaults
 *
 * Les surcharges se gèrent sous /users/{id}/caps et /groups/{id}/caps.
 */
class CapsRouteHandler extends BaseRouteHandler
{
    private ResourceCapController $controller;

    public function __construct($authService)
    {
        parent::__construct($authService);
        $this->controller = new ResourceCapController();
    }

    protected function getSupportedControllers(): array
    {
        return ['caps'];
    }

    protected function handleRoute(array $request): void
    {
        $action = $request['action'];
        $method = $request['method'];

        match (true) {
            ($action === 'defaults' && $method === 'GET' && !isset($request['segments'][2])) =>
                $this->controller->getDefaults($request['user']),

            default => Response::error('Route non trouvée', null, 404),
        };
    }
}
