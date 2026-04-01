<?php

namespace ICS\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use ICS\Controllers\NotificationController;
use AuthGroups\Utils\Response;

/**
 * Gère les routes sous /notifications
 *
 *   GET    /notifications/email                → listEmailNotifications
 *   POST   /notifications/email/test           → sendTestEmail
 *   DELETE /notifications/email/{id}           → cancelEmailNotification
 *   POST   /notifications/attendee-reply       → handleAttendeeReply  [Phase 3.3]
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
        // segments[1] = 'email' (toujours, pour l'instant)
        // segments[2] = '{id}' ou 'test' (optionnel)

        $sub   = $segments[1] ?? '';   // 'email'
        $third = $segments[2] ?? '';   // id numérique ou 'test'

        match (true) {

            // POST /notifications/send-email
            ($sub === 'send-email' && $method === 'POST') =>
                $this->controller->sendEmailForOccurrence($user['user_id']),

            // POST /notifications/attendee-reply  — Phase 3.3 iTIP REPLY
            ($sub === 'attendee-reply' && $method === 'POST') =>
                $this->controller->handleAttendeeReply($user['user_id']),

            // GET /notifications/email
            ($sub === 'email' && $method === 'GET' && $third === '') =>
                $this->controller->listEmailNotifications($user['user_id']),

            // POST /notifications/email/test
            ($sub === 'email' && $method === 'POST' && $third === 'test') =>
                $this->controller->sendTestEmail($user['user_id']),

            // DELETE /notifications/email/{id}
            ($sub === 'email' && $method === 'DELETE' && ctype_digit($third) && $third !== '') =>
                $this->controller->cancelEmailNotification((int)$third, $user['user_id']),

            default => Response::error('Route notifications non trouvée', null, 404),
        };
    }
}
