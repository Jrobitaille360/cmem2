<?php

namespace AuthGroups\Routing\RouteHandlers;

use Access\Routing\AccessRouteHandler;
use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Puzzle\Routing\PuzzleRouteHandler;
use WebDevice\Routing\DeviceRouteHandler;

/**
 * V2RouteHandler — dispatch /v2/{module}/* vers les handlers de modules v2.
 *
 * segments[0] = 'v2'
 * segments[1] = module : devices, access, puzzle
 * (/v2/billing/* et /v2/subscriptions/* retirés avec Stripe / Play Store — #300)
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
            $s1 === 'puzzle'                               => new PuzzleRouteHandler($this->authService),
            $s1 === 'devices'                              => new DeviceRouteHandler($this->authService),
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
