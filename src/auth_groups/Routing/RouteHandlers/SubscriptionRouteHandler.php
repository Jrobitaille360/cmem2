<?php

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Controllers\SubscriptionController;
use AuthGroups\Services\AuthService;
use AuthGroups\Utils\Response;

/**
 * Gestionnaire des routes /subscription/*
 *
 * Toutes les routes nécessitent un JWT valide.
 *
 * Routes :
 *   GET    /subscription/status[?app_id=xxx]  → SubscriptionController::getStatus()
 *   POST   /subscription/verify               → SubscriptionController::verify()
 *   DELETE /subscription/cancel               → SubscriptionController::cancel()
 */
class SubscriptionRouteHandler extends BaseRouteHandler
{
    private SubscriptionController $subscriptionController;
    protected bool $requiresAuth = true;

    public function __construct(?AuthService $authService = null)
    {
        parent::__construct($authService);
        $this->subscriptionController = new SubscriptionController();
    }

    protected function getSupportedControllers(): array
    {
        return ['subscription'];
    }

    protected function handleRoute(array $request): void
    {
        $method   = $request['method'];
        $segments = $request['segments'];
        $s1       = $segments[1] ?? '';

        match (true) {
            // GET /subscription/status[?app_id=xxx]
            ($method === 'GET' && $s1 === 'status') =>
                $this->subscriptionController->getStatus($request),

            // POST /subscription/verify
            ($method === 'POST' && $s1 === 'verify') =>
                $this->subscriptionController->verify($request),

            // DELETE /subscription/cancel
            ($method === 'DELETE' && $s1 === 'cancel') =>
                $this->subscriptionController->cancel($request),

            default => Response::error('Route subscription non trouvée', null, 404),
        };
    }
}
