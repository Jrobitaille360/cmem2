<?php

namespace WebDevice\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use WebDevice\Controllers\DeviceController;
use WebDevice\Controllers\WebDeviceController;

/**
 * /v2/devices/{android|web|windows}/* — enregistrement d'appareil et pseudonyme.
 * Extrait de l'ancien routeur Play Store (#298) : le lien
 * d'appareil n'a rien à voir avec le paiement et survit au retrait du Play Store.
 */
class DeviceRouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = false;

    protected function getSupportedControllers(): array
    {
        return ['devices'];
    }

    protected function handleRoute(array $request): void
    {
        $method   = $request['method']   ?? 'GET';
        $segments = $request['segments'] ?? [];

        // segments[0] = 'v2', segments[1] = 'devices'
        $s1 = $segments[1] ?? '';   // 'devices'
        $s2 = $segments[2] ?? '';   // 'android', 'web' ou 'windows'
        $s3 = $segments[3] ?? '';   // action
        $s4 = $segments[4] ?? '';   // 'check'
        $s5 = $segments[5] ?? '';   // {pseudo}

        // --------------------------------------------------------------------
        // /v2/devices/android/*
        // --------------------------------------------------------------------
        if ($s1 === 'devices' && $s2 === 'android') {
            if ($s3 === 'register' && $method === 'POST') {
                // JWT optionnel — anonyme si absent
                $user = $this->authService->authenticate();
                (new DeviceController())->register($user ?: null);
                return;
            }

            // Toutes les autres routes /devices/android/* requièrent JWT
            $user = $this->authService->authenticate();
            if (!$user) {
                Response::error('Authentification requise', null, 401);
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

        // --------------------------------------------------------------------
        // /v2/devices/web/*
        // --------------------------------------------------------------------
        if ($s1 === 'devices' && $s2 === 'web') {
            if ($s3 === 'register' && $method === 'POST') {
                // JWT optionnel — anonyme si absent
                $user = $this->authService->authenticate();
                (new WebDeviceController())->register($user ?: null);
                return;
            }

            $user = $this->authService->authenticate();
            if (!$user) {
                Response::error('Authentification requise', null, 401);
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

        // --------------------------------------------------------------------
        // /v2/devices/windows/*  — stocké dans web_devices (même table que web)
        // --------------------------------------------------------------------
        if ($s1 === 'devices' && $s2 === 'windows') {
            if ($s3 === 'register' && $method === 'POST') {
                // JWT optionnel — anonyme si absent
                $user = $this->authService->authenticate();
                (new WebDeviceController())->register($user ?: null);
                return;
            }

            $user = $this->authService->authenticate();
            if (!$user) {
                Response::error('Authentification requise', null, 401);
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

        Response::error('Endpoint non trouvé', null, 404);
    }
}
