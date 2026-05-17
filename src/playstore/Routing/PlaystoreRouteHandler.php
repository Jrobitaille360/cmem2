<?php

namespace Playstore\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Playstore\Controllers\DeviceController;
use Playstore\Controllers\SubscriptionController;

class PlaystoreRouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = false;

    protected function getSupportedControllers(): array
    {
        return ['playstore'];
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

        // segments[0] = 'v2'
        $s1 = $segments[1] ?? '';   // 'devices' ou 'subscriptions'
        $s2 = $segments[2] ?? '';   // 'android' ou 'playstore'
        $s3 = $segments[3] ?? '';   // action
        $s4 = $segments[4] ?? '';   // 'check'
        $s5 = $segments[5] ?? '';   // {pseudo}

        if ($s1 === 'devices' && $s2 === 'android') {
            if ($s3 === 'register' && $method === 'POST') {
                (new DeviceController())->register($user);
                return;
            }

            if ($s3 === 'pseudonym') {
                if ($s4 === 'check' && $method === 'GET') {
                    (new DeviceController())->checkPseudonym($user, $s5);
                    return;
                }

                match ($method) {
                    'GET'    => (new DeviceController())->getPseudonym($user),
                    'POST'   => (new DeviceController())->setPseudonym($user),
                    'DELETE' => (new DeviceController())->deletePseudonym($user),
                    default  => Response::error('Méthode non autorisée', null, 405),
                };
                return;
            }

            Response::error('Endpoint non trouvé', null, 404);
            return;
        }

        if ($s1 === 'subscriptions' && $s2 === 'playstore') {
            match (true) {
                ($s3 === 'verify' && $method === 'POST')  => (new SubscriptionController())->verify($user),
                ($s3 === 'status' && $method === 'GET')   => (new SubscriptionController())->getStatus($user),
                ($s3 === ''       && $method === 'DELETE') => (new SubscriptionController())->cancel($user),
                default => Response::error('Endpoint non trouvé', null, 404),
            };
            return;
        }

        Response::error('Endpoint non trouvé', null, 404);
    }
}
