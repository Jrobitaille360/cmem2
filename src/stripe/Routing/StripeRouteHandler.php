<?php

namespace Stripe\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Stripe\Controllers\BillingController;
use Stripe\Controllers\SubscriptionController;

class StripeRouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = false;

    protected function getSupportedControllers(): array
    {
        return ['stripe'];
    }

    protected function handleRoute(array $request): void
    {
        $method   = $request['method']   ?? 'GET';
        $segments = $request['segments'] ?? [];

        // segments[0] = 'v2'
        $s1 = $segments[1] ?? '';
        $s2 = $segments[2] ?? '';
        $s3 = $segments[3] ?? '';

        // Routes /v2/billing/*
        if ($s1 === 'billing') {
            if ($s2 === 'webhook' && $method === 'POST') {
                (new BillingController())->webhook();
                return;
            }

            $user = $this->authService->authenticate();
            if (!$user) {
                Response::error('Authentification requise', null, 401);
                return;
            }

            match (true) {
                ($s2 === 'checkout' && $method === 'POST') => (new BillingController())->checkout($user),
                ($s2 === 'portal'   && $method === 'POST') => (new BillingController())->portal($user),
                default => Response::error('Endpoint non trouvé', null, 404),
            };
            return;
        }

        // Routes /v2/subscriptions/stripe/*
        if ($s1 === 'subscriptions' && $s2 === 'stripe') {
            $user = $this->authService->authenticate();
            if (!$user) {
                Response::error('Authentification requise', null, 401);
                return;
            }

            match (true) {
                ($s3 === 'status' && $method === 'GET')    => (new SubscriptionController())->getStatus($user),
                ($s3 === ''       && $method === 'DELETE') => (new SubscriptionController())->cancel($user),
                default => Response::error('Endpoint non trouvé', null, 404),
            };
            return;
        }

        Response::error('Endpoint non trouvé', null, 404);
    }
}
