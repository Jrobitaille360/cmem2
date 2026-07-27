<?php

namespace Push\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Push\Controllers\PushController;

/**
 * PushRouteHandler — routes /push/* (notifications push web, directive cmem_web 20260726_140426)
 *
 *   GET    /push/vapid-public-key
 *   POST   /push/subscribe
 *   DELETE /push/subscribe
 *   GET    /push/preferences
 *   PUT    /push/preferences
 *
 * Toutes les routes exigent un JWT valide.
 */
class PushRouteHandler extends BaseRouteHandler
{
    protected function getSupportedControllers(): array
    {
        return ['push'];
    }

    protected function handleRoute(array $request): void
    {
        $user   = $request['user'];
        $method = $request['method'] ?? 'GET';
        $segs   = $request['segments'] ?? [];

        // segs[0] = 'push'
        $section = $segs[1] ?? '';

        if (($segs[2] ?? '') !== '') {
            Response::error('Endpoint non trouvé', null, 404);
            return;
        }

        $controller = new PushController();

        switch ($section) {
            case 'vapid-public-key':
                match ($method) {
                    'GET'   => $controller->vapidPublicKey($user),
                    default => Response::error('Méthode non autorisée', null, 405),
                };
                return;

            case 'subscribe':
                match ($method) {
                    'POST'   => $controller->subscribe($user),
                    'DELETE' => $controller->unsubscribe($user),
                    default  => Response::error('Méthode non autorisée', null, 405),
                };
                return;

            case 'preferences':
                match ($method) {
                    'GET'   => $controller->getPreferences($user),
                    'PUT'   => $controller->putPreferences($user),
                    'PATCH' => $controller->putPreferences($user),
                    default => Response::error('Méthode non autorisée', null, 405),
                };
                return;

            default:
                Response::error('Endpoint non trouvé', null, 404);
        }
    }
}
