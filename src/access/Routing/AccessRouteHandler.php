<?php

namespace Access\Routing;

use Access\Controllers\AccessController;
use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;

class AccessRouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = false;

    protected function getSupportedControllers(): array
    {
        return ['access'];
    }

    protected function handleRoute(array $request): void
    {
        $user = $this->authService->authenticate();
        if (!$user) {
            Response::error('Authentification requise', null, 401);
            return;
        }

        $method   = $request['method']   ?? 'GET';
        $segments = $request['segments'] ?? [];
        $s2       = $segments[2]         ?? '';

        if ($s2 === 'status' && $method === 'GET') {
            (new AccessController())->getStatus($user);
            return;
        }

        Response::error('Endpoint non trouvé', null, 404);
    }
}
