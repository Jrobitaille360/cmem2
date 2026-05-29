<?php

namespace Puzzle\Routing;

use Access\Services\AccessService;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Playstore\Models\AndroidDevice;
use Puzzle\Controllers\AdminController;
use Puzzle\Controllers\CarouselController;
use Puzzle\Controllers\ThemeController;
use Puzzle\Controllers\ImageDeliveryController;
use Puzzle\Controllers\SyncController;
use Puzzle\Controllers\SharedController;
use WebDevice\Models\WebDevice;

/**
 * PuzzleRouteHandler — gestionnaire unique pour toutes les routes /puzzle/* et /v2/puzzle/*
 *
 * Auth conditionnelle :
 *  - POST /puzzle/auth/register-device              → sans auth (legacy)
 *  - POST /puzzle/auth/link-device                  → JWT cmem2 (Bearer)
 *  - POST /v2/puzzle/auth/link-device               → JWT cmem2 (Bearer)
 *  - GET  /v2/puzzle/carousel                       → device_token (android_devices ou web_devices)
 *  - POST /v2/puzzle/carousel/replace-one           → device_token
 *  - POST /v2/puzzle/carousel/replace-all           → device_token + premium
 *  - GET  /v2/puzzle/themes[/{slug}/images]         → device_token + premium
 *  - GET  /v2/puzzle/thumb/{uid}                    → device_token
 *  - GET  /v2/puzzle/image/{uid}                    → device_token
 *  - GET|POST /v2/puzzle/backup                     → device_token + premium
 *  - /v2/puzzle/shared[/...]                        → device_token + premium
 *
 * requiresAuth = false : le middleware de base est ignoré ; chaque branche
 * gère elle-même son niveau d'authentification.
 *
 * Offset de segment : +1 quand appelé via /v2/puzzle/* (segments[0] = 'v2').
 */
class PuzzleRouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = false;

    protected function getSupportedControllers(): array
    {
        return ['puzzle'];
    }

    protected function handleRoute(array $request): void
    {
        $method   = $request['method']   ?? 'GET';
        $segments = $request['segments'] ?? [];

        // Décalage +1 quand appelé via /v2/puzzle/*
        $off = ($segments[0] === 'v2') ? 1 : 0;

        $s1 = $segments[1 + $off] ?? '';   // auth | carousel | themes | thumb | image | backup | shared | admin
        $s2 = $segments[2 + $off] ?? '';
        $s3 = $segments[3 + $off] ?? '';

        // -------------------------------------------------------------------
        // /puzzle/admin/*  (JWT cmem2 + rôle ADMINISTRATEUR)
        // -------------------------------------------------------------------
        if ($s1 === 'admin') {
            $this->handleAdminRoute($segments, $off, $method);
            return;
        }

        // -------------------------------------------------------------------
        // /puzzle/auth/link-device  (conservé — lie device_token à compte JWT)
        // Toutes les autres routes /puzzle/auth/* sont supprimées (migrées v2.7.0)
        // -------------------------------------------------------------------
        if ($s1 === 'auth') {
            if ($s2 === 'link-device' && $method === 'POST') {
                $user = $this->requireAnyJwt();
                if ($user === null) return;
                $this->handleLinkDevice($user);
                return;
            }

            Response::error('Endpoint supprimé — utilisez /v2/devices/* et /v2/subscriptions/*', null, 410);
            return;
        }

        // -------------------------------------------------------------------
        // /puzzle/carousel/*  ou  /v2/puzzle/carousel/*
        // -------------------------------------------------------------------
        if ($s1 === 'carousel') {
            $device = $this->requireDeviceToken();
            if ($device === null) return;

            if ($s2 === '' && $method === 'GET') {
                (new CarouselController())->getCarousel($device);
            } elseif ($s2 === 'replace-one' && $method === 'POST') {
                (new CarouselController())->replaceOne($device);
            } elseif ($s2 === 'replace-all' && $method === 'POST') {
                if (!$this->requirePremium($device)) return;
                (new CarouselController())->replaceAll($device);
            } else {
                Response::error('Endpoint non trouvé', null, 404);
            }
            return;
        }

        // -------------------------------------------------------------------
        // /puzzle/themes[/{slug}/images]
        // -------------------------------------------------------------------
        if ($s1 === 'themes') {
            $device = $this->requireDeviceToken();
            if ($device === null) return;
            if (!$this->requirePremium($device)) return;

            if ($s2 === '' && $method === 'GET') {
                (new ThemeController())->getThemes($device);
            } elseif ($s2 !== '' && $s3 === 'images' && $method === 'GET') {
                (new ThemeController())->getThemeImages($s2, $device);
            } else {
                Response::error('Endpoint non trouvé', null, 404);
            }
            return;
        }

        // -------------------------------------------------------------------
        // /puzzle/thumb/{uid}  ou  /puzzle/thumb/theme/{slug}
        // -------------------------------------------------------------------
        if ($s1 === 'thumb') {
            $device = $this->requireDeviceToken();
            if ($device === null) return;

            if ($s2 === 'theme' && $s3 !== '') {
                (new ImageDeliveryController())->serveThemeThumb($s3);
            } elseif ($s2 !== '') {
                (new ImageDeliveryController())->serveThumb($s2, $device);
            } else {
                Response::error('Endpoint non trouvé', null, 404);
            }
            return;
        }

        // -------------------------------------------------------------------
        // /puzzle/image/{uid}
        // -------------------------------------------------------------------
        if ($s1 === 'image') {
            $device = $this->requireDeviceToken();
            if ($device === null) return;

            if ($s2 !== '' && $method === 'GET') {
                (new ImageDeliveryController())->serveImage($s2, $device);
            } else {
                Response::error('Endpoint non trouvé', null, 404);
            }
            return;
        }

        // -------------------------------------------------------------------
        // /puzzle/backup[/claim]
        // -------------------------------------------------------------------
        if ($s1 === 'backup') {
            $device = $this->requireDeviceToken();
            if ($device === null) return;
            if (!$this->requirePremium($device)) return;

            if ($s2 === 'claim' && $method === 'POST') {
                (new SyncController())->claimBackup($device);
                return;
            }

            match ($method) {
                'POST' => (new SyncController())->saveBackup($device),
                'GET'  => (new SyncController())->getBackup($device),
                default => Response::error('Endpoint non trouvé', null, 404),
            };
            return;
        }

        // -------------------------------------------------------------------
        // /puzzle/shared[/*]
        // -------------------------------------------------------------------
        if ($s1 === 'shared') {
            $device = $this->requireDeviceToken();
            if ($device === null) return;
            if (!$this->requirePremium($device)) return;

            if ($s2 === '') {
                match ($method) {
                    'GET'  => (new SharedController())->listShared($device),
                    'POST' => (new SharedController())->createShared($device),
                    default => Response::error('Méthode non autorisée', null, 405),
                };
                return;
            }

            $sharedUid = $s2;

            if ($s3 === '' && $method === 'DELETE') {
                (new SharedController())->deleteShared($sharedUid, $device);
            } elseif ($s3 === 'state' && $method === 'GET') {
                (new SharedController())->getState($sharedUid, $device);
            } elseif ($s3 === 'pick' && $method === 'POST') {
                (new SharedController())->pick($sharedUid, $device);
            } elseif ($s3 === 'drop' && $method === 'POST') {
                (new SharedController())->drop($sharedUid, $device);
            } elseif ($s3 === 'events' && $method === 'GET') {
                (new SharedController())->getEvents($sharedUid, $device);
            } elseif ($s3 === 'leave' && $method === 'POST') {
                (new SharedController())->leave($sharedUid, $device);
            } else {
                Response::error('Endpoint non trouvé', null, 404);
            }
            return;
        }

        Response::error('Endpoint non trouvé', null, 404);
    }

    // -----------------------------------------------------------------------
    // Admin
    // -----------------------------------------------------------------------

    private function handleAdminRoute(array $segments, int $off, string $method): void
    {
        $user = $this->requireAdminJwt();
        if ($user === null) return;

        $s2 = $segments[2 + $off] ?? '';
        $s3 = $segments[3 + $off] ?? '';
        $s4 = $segments[4 + $off] ?? '';
        $s5 = $segments[5 + $off] ?? '';

        if ($s2 === 'images') {
            (new AdminController())->handleImages($s3, $s4, $method, $user);
            return;
        }
        if ($s2 === 'themes') {
            (new AdminController())->handleThemes($s3, $s4, $s5, $method, $user);
            return;
        }

        if ($s2 === 'thumb' && $method === 'GET') {
            if ($s3 === 'theme' && $s4 !== '') {
                (new ImageDeliveryController())->serveThemeThumb($s4);
            } elseif ($s3 !== '') {
                (new ImageDeliveryController())->serveThumb($s3);
            } else {
                Response::error('Endpoint non trouvé', null, 404);
            }
            return;
        }

        if ($s2 === 'image' && $s3 !== '' && $method === 'GET') {
            (new ImageDeliveryController())->serveImage($s3);
            return;
        }

        Response::error('Endpoint non trouvé', null, 404);
    }

    // -----------------------------------------------------------------------
    // Helpers JWT
    // -----------------------------------------------------------------------

    private function requireAnyJwt(): ?array
    {
        $user = $this->authService?->authenticate();
        if (!$user) {
            Response::error('Authentification requise', null, 401);
            return null;
        }
        return $user;
    }

    private function requireAdminJwt(): ?array
    {
        $user = $this->authService?->authenticate();
        if (!$user) {
            Response::error('Authentification requise', null, 401);
            return null;
        }
        if ($user['role'] !== 'ADMINISTRATEUR') {
            Response::error('Accès refusé : rôle ADMINISTRATEUR requis', null, 403);
            return null;
        }
        return $user;
    }

    // -----------------------------------------------------------------------
    // Device token — cherche dans android_devices puis web_devices
    // -----------------------------------------------------------------------

    private function requireDeviceToken(): ?array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($authHeader, 'Bearer ')) {
            Response::error('device_token requis (Authorization: Bearer <token>)', ['code' => 'DEVICE_NOT_FOUND'], 401);
            return null;
        }

        $token = substr($authHeader, 7);

        $androidModel = new AndroidDevice();
        $device       = $androidModel->findByValidToken($token);
        if ($device) {
            $androidModel->touchLastSeen((int) $device['id']);
            $device['_device_type'] = 'android';
        } else {
            $webModel = new WebDevice();
            $device   = $webModel->findByValidToken($token);
            if ($device) {
                $webModel->touchLastSeen((int) $device['id']);
                $device['_device_type'] = 'web';
            }
        }

        if (!$device) {
            Response::error('Token d\'appareil inconnu ou expiré', ['code' => 'DEVICE_NOT_FOUND'], 401);
            return null;
        }

        $device['is_premium']         = 0;
        $device['premium_expires_at'] = null;

        if (!empty($device['user_id'])) {
            $result = AccessService::getMatrix((int) $device['user_id'], $device['app_id'] ?? 'puzzle');
            $matrix = $result['matrix'];

            if ($matrix['android'] || $matrix['web'] || $matrix['windows']) {
                $device['is_premium'] = 1;
                foreach ($result['sources'] as $src) {
                    if (!empty($src['expires_at'])) {
                        $device['premium_expires_at'] = $src['expires_at'];
                        break;
                    }
                }
            }
        }

        return $device;
    }

    // -----------------------------------------------------------------------
    // Premium guard — trust is_premium set by requireDeviceToken()
    // -----------------------------------------------------------------------

    private function requirePremium(array $device): bool
    {
        if (defined('PUZZLE_DEBUG_PREMIUM') && \PUZZLE_DEBUG_PREMIUM) {
            return true;
        }

        if (!$device['is_premium']) {
            Response::error('Abonnement requis', ['code' => 'SUBSCRIPTION_REQUIRED'], 403);
            return false;
        }
        return true;
    }

    // -----------------------------------------------------------------------
    // POST /puzzle/auth/link-device  (inline — lie device_token à un compte)
    // -----------------------------------------------------------------------

    private function handleLinkDevice(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input       = Response::getRequestParams();
        $deviceToken = trim($input['device_token'] ?? '');

        if ($deviceToken === '') {
            LoggingMiddleware::logExit(422);
            Response::error('device_token requis', ['field' => 'device_token'], 422);
            return;
        }

        $androidModel = new AndroidDevice();
        $device       = $androidModel->findByValidToken($deviceToken);

        if ($device) {
            $androidModel->setUserId((int) $device['id'], (int) $user['user_id']);
        } else {
            $webModel = new WebDevice();
            $device   = $webModel->findByValidToken($deviceToken);
            if ($device) {
                $webModel->setUserId((int) $device['id'], (int) $user['user_id']);
            }
        }

        if (!$device) {
            LoggingMiddleware::logExit(404);
            Response::error('Token d\'appareil inconnu ou expiré', ['code' => 'DEVICE_NOT_FOUND'], 404);
            return;
        }

        LoggingMiddleware::logExit(200);
        Response::success('Appareil lié au compte', ['device_id' => (int) $device['id']]);
    }
}
