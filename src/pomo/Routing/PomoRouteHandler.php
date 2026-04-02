<?php

namespace Pomo\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Pomo\Controllers\EngagementController;

/**
 * PomoRouteHandler — gestionnaire unique pour toutes les routes /pomo/*
 *
 * Phase 1A : POST /pomo/engagement — public, sans auth
 * Phase 1B : POST /pomo/support    — JWT Bearer (à implémenter)
 * Phase 2  : /pomo/sync/*          — JWT Bearer (à implémenter)
 * Phase 3  : /pomo/stripe/webhook  — public, signature Stripe (à implémenter)
 *
 * Auth conditionnelle : requiresAuth = false ici ; les sous-routes protégées
 * vérifient elles-mêmes la présence du JWT via $this->authService.
 */
class PomoRouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = false;

    protected function getSupportedControllers(): array
    {
        return ['pomo'];
    }

    protected function handleRoute(array $request): void
    {
        $method   = $request['method']   ?? 'GET';
        $segments = $request['segments'] ?? [];

        // segments[0] = 'pomo'
        // segments[1] = action (engagement, support, sync, stripe)
        // segments[2] = sous-ressource (ex. sessions, tasks…)
        $action = $segments[1] ?? '';
        $sub    = $segments[2] ?? '';

        match (true) {
            // POST /pomo/engagement
            ($action === 'engagement' && $method === 'POST') =>
                (new EngagementController())->submit(),

            default =>
                Response::error('Endpoint non trouvé', null, 404),
        };
    }
}
