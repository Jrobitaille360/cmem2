<?php

namespace Booking\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Booking\Controllers\BookingPageController;
use Booking\Controllers\BookingPublicController;
use Booking\Controllers\BookingReservationController;

/**
 * BookingRouteHandler — gestionnaire unique pour toutes les routes /booking/*.
 *
 * /booking/page          → authentifié (hôte), JWT vérifié manuellement ci-dessous
 * /booking/public/*      → public, sans auth (Phase 4)
 *
 * requiresAuth = false au niveau du handler : chaque branche décide elle-même,
 * même patron que PomoRouteHandler.
 */
class BookingRouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = false;

    protected function getSupportedControllers(): array
    {
        return ['booking'];
    }

    private function requireUser(): ?array
    {
        $user = $this->authService?->authenticate();
        if (!$user) {
            Response::error('Utilisateur non authentifié', null, 401);
            return null;
        }
        return $user;
    }

    protected function handleRoute(array $request): void
    {
        $method   = $request['method']   ?? 'GET';
        $segments = $request['segments'] ?? [];

        // segments[0] = 'booking'
        // segments[1] = 'page' | 'public'
        // segments[2] = slug (ou 'cancel' pour /booking/public/cancel/{token}, Phase 5)
        // segments[3] = 'slots' | 'book' (Phase 5) | (absent = info de page)
        $action = $segments[1] ?? '';
        $slug   = $segments[2] ?? '';
        $sub    = $segments[3] ?? '';

        match (true) {
            $action === 'page' && $method === 'GET' =>
                (function () {
                    $user = $this->requireUser();
                    if ($user) { (new BookingPageController())->get($user); }
                })(),

            $action === 'page' && $method === 'PUT' =>
                (function () {
                    $user = $this->requireUser();
                    if ($user) { (new BookingPageController())->put($user); }
                })(),

            $action === 'page' && $method === 'DELETE' =>
                (function () {
                    $user = $this->requireUser();
                    if ($user) { (new BookingPageController())->delete($user); }
                })(),

            // GET /booking/public/{slug}
            $action === 'public' && $slug !== '' && $slug !== 'cancel' && $sub === '' && $method === 'GET' =>
                (new BookingPublicController())->get($slug),

            // GET /booking/public/{slug}/slots
            $action === 'public' && $slug !== '' && $sub === 'slots' && $method === 'GET' =>
                (new BookingPublicController())->slots($slug),

            // POST /booking/public/{slug}/book
            $action === 'public' && $slug !== '' && $slug !== 'cancel' && $sub === 'book' && $method === 'POST' =>
                (new BookingReservationController())->book($slug),

            // POST /booking/public/cancel/{token}
            $action === 'public' && $slug === 'cancel' && $sub !== '' && $method === 'POST' =>
                (new BookingReservationController())->cancel($sub),

            default =>
                Response::error('Endpoint non trouvé', null, 404),
        };
    }
}
