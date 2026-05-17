<?php

namespace AuthGroups\Routing\RouteHandlers;

use Access\Routing\AccessRouteHandler;
use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Playstore\Routing\PlaystoreRouteHandler;
use Stripe\Routing\StripeRouteHandler;

/**
 * V2RouteHandler — dispatch /v2/{module}/* vers les handlers de modules v2.
 *
 * segments[0] = 'v2'
 * segments[1] = module : devices, subscriptions, billing, access, puzzle
 */
class V2RouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = false;

    protected function getSupportedControllers(): array
    {
        return ['v2'];
    }

    protected function handleRoute(array $request): void
    {
        $segments = $request['segments'] ?? [];
        $s1 = $segments[1] ?? '';
        $s2 = $segments[2] ?? '';

        $handler = match (true) {
            $s1 === 'devices'                              => new PlaystoreRouteHandler($this->authService),
            $s1 === 'subscriptions' && $s2 === 'playstore' => new PlaystoreRouteHandler($this->authService),
            $s1 === 'subscriptions' && $s2 === 'stripe'    => new StripeRouteHandler($this->authService),
            $s1 === 'billing'                              => new StripeRouteHandler($this->authService),
            $s1 === 'access'                               => new AccessRouteHandler($this->authService),
            default                                        => null,
        };

        if ($handler === null) {
            Response::error('Endpoint non trouvé', null, 404);
            return;
        }

        $handler->handle($request);
    }
}
