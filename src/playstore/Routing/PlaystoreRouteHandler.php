<?php

namespace Playstore\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Playstore\Controllers\DeviceController;
use Playstore\Controllers\SubscriptionController;
use WebDevice\Controllers\WebDeviceController;

class PlaystoreRouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = false;

    protected function getSupportedControllers(): array
    {
        return ['playstore'];
    }

    protected function handleRoute(array $request): void
    {
        $method   = $request['method']   ?? 'GET';
        $segments = $request['segments'] ?? [];

        // segments[0] = 'v2'
        $s1 = $segments[1] ?? '';   // 'devices' ou 'subscriptions'
        $s2 = $segments[2] ?? '';   // 'android', 'web' ou 'playstore'
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

        // --------------------------------------------------------------------
        // /v2/subscriptions/playstore/*
        // Auth : X-Device-Token (Android, jamais de JWT — pas d'email requis)
        // --------------------------------------------------------------------
        if ($s1 === 'subscriptions' && $s2 === 'playstore') {
            $deviceToken = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '';

            if (!$deviceToken) {
                Response::error('X-Device-Token requis', null, 401);
                return;
            }

            $device = (new \Playstore\Models\AndroidDevice())->findByValidToken($deviceToken);

            if (!$device) {
                Response::error('Device token invalide ou expiré', null, 401);
                return;
            }

            match (true) {
                ($s3 === 'verify' && $method === 'POST')   => (new SubscriptionController())->verify($device),
                ($s3 === 'status' && $method === 'GET')    => (new SubscriptionController())->getStatus($device),
                ($s3 === ''       && $method === 'DELETE') => (new SubscriptionController())->cancel($device),
                default => Response::error('Endpoint non trouvé', null, 404),
            };
            return;
        }

        Response::error('Endpoint non trouvé', null, 404);
    }
}
