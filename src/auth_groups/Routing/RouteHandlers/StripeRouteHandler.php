<?php

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Controllers\StripeController;
use AuthGroups\Utils\Response;

/**
 * Gestionnaire des routes /stripe/*
 *
 * Routes :
 *   POST /stripe/webhook  → StripeController::webhook() — sans JWT
 */
class StripeRouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = false;

    protected function getSupportedControllers(): array
    {
        return ['stripe'];
    }

    protected function handleRoute(array $request): void
    {
        $method = $request['method'];
        $s1     = $request['segments'][1] ?? '';

        if ($s1 === 'webhook' && $method === 'POST') {
            (new StripeController())->webhook();
            return;
        }

        Response::error('Endpoint non trouvé', null, 404);
    }
}
