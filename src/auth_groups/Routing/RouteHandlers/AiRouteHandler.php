<?php

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Controllers\AiController;
use AuthGroups\Utils\Response;

/**
 * Routes du proxy IA — directive cmem_web 20260810_140000_ai-proxy.
 *
 *   POST /ai/summarize → résumé d'agenda (quota + gating tenant_modules)
 */
class AiRouteHandler extends BaseRouteHandler
{
    private AiController $controller;

    public function __construct($authService)
    {
        parent::__construct($authService);
        $this->controller = new AiController();
    }

    protected function getSupportedControllers(): array
    {
        return ['ai'];
    }

    protected function handleRoute(array $request): void
    {
        $action = $request['action'];
        $method = $request['method'];
        $user   = $request['user'];

        match (true) {
            ($action === 'summarize' && $method === 'POST') =>
                $this->controller->summarize($user),

            default => Response::error('Route ai non trouvée', null, 404)
        };
    }
}
