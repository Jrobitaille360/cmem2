<?php

namespace ICS\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use ICS\Controllers\NotificationController;
use AuthGroups\Utils\Response;

/**
 * Gère les routes sous /notifications
 *
 *   POST   /notifications/attendee-reply       → handleAttendeeReply  [Phase 3.3]
 *   GET|PUT /notifications/defaults            → NotificationSettingsController (#487)
 *   GET     /notifications/devices             → NotificationSettingsController (#487)
 *   PUT|DELETE /notifications/devices/{id}     → NotificationSettingsController (#487)
 *
 * Retirées en #497 : /notifications/email[/{id}|/test], /notifications/send-email (→ 404).
 */
class NotificationRouteHandler extends BaseRouteHandler
{
    private NotificationController $controller;

    public function __construct($authService)
    {
        parent::__construct($authService);
        $this->controller = new NotificationController();
    }

    protected function getSupportedControllers(): array
    {
        return ['notifications'];
    }

    protected function handleRoute(array $request): void
    {
        $method   = $request['method'];
        $segments = $request['segments'];
        $user     = $request['user'];

        // segments[0] = 'notifications'
        // segments[1] = 'attendee-reply' | 'defaults' | 'devices'
        // segments[2] = '{id}' (devices, optionnel)

        $sub   = $segments[1] ?? '';
        $third = $segments[2] ?? '';

        match (true) {

            // POST /notifications/attendee-reply  — Phase 3.3 iTIP REPLY
            ($sub === 'attendee-reply' && $method === 'POST') =>
                $this->controller->handleAttendeeReply($user['user_id']),

            // Rappels unifiés (#487, contrat #236 §4-§5) — contrôleur AuthGroups
            ($sub === 'defaults' && $third === '' && $method === 'GET') =>
                (new \AuthGroups\Controllers\NotificationSettingsController())->getDefaults($user),
            ($sub === 'defaults' && $third === '' && $method === 'PUT') =>
                (new \AuthGroups\Controllers\NotificationSettingsController())->putDefaults($user),
            ($sub === 'devices' && $third === '' && $method === 'GET') =>
                (new \AuthGroups\Controllers\NotificationSettingsController())->listDevices($user),
            ($sub === 'devices' && ctype_digit($third) && $method === 'PUT') =>
                (new \AuthGroups\Controllers\NotificationSettingsController())->updateDevice($user, (int) $third),
            ($sub === 'devices' && ctype_digit($third) && $method === 'DELETE') =>
                (new \AuthGroups\Controllers\NotificationSettingsController())->deleteDevice($user, (int) $third),

            default => Response::error('Route notifications non trouvée', null, 404),
        };
    }
}
