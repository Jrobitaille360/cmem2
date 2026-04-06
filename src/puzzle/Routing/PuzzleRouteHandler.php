<?php

namespace Puzzle\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Puzzle\Controllers\AuthController;
use Puzzle\Controllers\CarouselController;
use Puzzle\Controllers\ThemeController;
use Puzzle\Controllers\ImageDeliveryController;
use Puzzle\Controllers\SyncController;
use Puzzle\Controllers\SharedController;
use Puzzle\Models\PuzzleDevice;

/**
 * PuzzleRouteHandler — gestionnaire unique pour toutes les routes /puzzle/*
 *
 * Auth conditionnelle :
 *  - POST /puzzle/auth/register-device              → sans auth
 *  - POST /puzzle/auth/verify-subscription          → device_token (Bearer)
 *  - POST /puzzle/auth/pseudonym                    → device_token (Bearer)
 *  - GET  /puzzle/carousel                          → device_token
 *  - POST /puzzle/carousel/replace-one              → device_token
 *  - POST /puzzle/carousel/replace-all              → device_token + premium
 *  - GET  /puzzle/themes                            → device_token + premium
 *  - GET  /puzzle/themes/{slug}/images              → device_token + premium
 *  - GET  /puzzle/thumb/{uid}                       → device_token
 *  - GET  /puzzle/image/{uid}                       → device_token
 *  - GET  /puzzle/thumb/theme/{slug}                → device_token
 *  - GET/POST /puzzle/backup                        → device_token + premium
 *  - /puzzle/shared[/...]                           → device_token + premium
 *
 * requiresAuth = false : le middleware de base est ignoré ; chaque branche
 * gère elle-même son niveau d'authentification.
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

        // segments[0] = 'puzzle'
        $s1 = $segments[1] ?? '';   // auth | carousel | themes | thumb | image | backup | shared
        $s2 = $segments[2] ?? '';   // sous-route
        $s3 = $segments[3] ?? '';   // sous-ressource
        $s4 = $segments[4] ?? '';   // action

        // -------------------------------------------------------------------
        // /puzzle/auth/*
        // -------------------------------------------------------------------
        if ($s1 === 'auth') {
            if ($s2 === 'register-device' && $method === 'POST') {
                (new AuthController())->registerDevice();
                return;
            }

            $device = $this->requireDeviceToken();
            if ($device === null) return;

            if ($s2 === 'verify-subscription' && $method === 'POST') {
                (new AuthController())->verifySubscription($device);
            } elseif ($s2 === 'pseudonym' && $method === 'POST') {
                (new AuthController())->setPseudonym($device);
            } else {
                Response::error('Endpoint non trouvé', null, 404);
            }
            return;
        }

        // -------------------------------------------------------------------
        // /puzzle/carousel/*
        // -------------------------------------------------------------------
        if ($s1 === 'carousel') {
            $device = $this->requireDeviceToken();
            if ($device === null) return;

            if ($s2 === '' && $method === 'GET') {
                (new CarouselController())->getCarousel($device);
            } elseif ($s2 === 'replace-one' && $method === 'POST') {
                (new CarouselController())->replaceOne($device);
            } elseif ($s2 === 'replace-all' && $method === 'POST') {
                $this->requirePremium($device);
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
                (new ImageDeliveryController())->serveThumb($s2);
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
                (new ImageDeliveryController())->serveImage($s2);
            } else {
                Response::error('Endpoint non trouvé', null, 404);
            }
            return;
        }

        // -------------------------------------------------------------------
        // /puzzle/backup
        // -------------------------------------------------------------------
        if ($s1 === 'backup') {
            $device = $this->requireDeviceToken();
            if ($device === null) return;
            if (!$this->requirePremium($device)) return;

            match ($method) {
                'POST' => (new SyncController())->saveBackup($device),
                'GET'  => (new SyncController())->getBackup($device),
                default => Response::error('Méthode non autorisée', null, 405),
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

            // GET/POST /puzzle/shared
            if ($s2 === '') {
                match ($method) {
                    'GET'  => (new SharedController())->listShared($device),
                    'POST' => (new SharedController())->createShared($device),
                    default => Response::error('Méthode non autorisée', null, 405),
                };
                return;
            }

            // /puzzle/shared/{shared_uid}[/state|/move|/events|/leave]
            $sharedUid = $s2;

            if ($s3 === '' && $method === 'DELETE') {
                (new SharedController())->deleteShared($sharedUid, $device);
            } elseif ($s3 === 'state' && $method === 'GET') {
                (new SharedController())->getState($sharedUid, $device);
            } elseif ($s3 === 'move' && $method === 'POST') {
                (new SharedController())->move($sharedUid, $device);
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
    // Helpers d'authentification
    // -----------------------------------------------------------------------

    /**
     * Valide le device_token depuis Authorization: Bearer <token>.
     * Retourne les données de l'appareil ou envoie HTTP 401.
     */
    private function requireDeviceToken(): ?array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($authHeader, 'Bearer ')) {
            Response::error('device_token requis (Authorization: Bearer <token>)', ['code' => 'DEVICE_NOT_FOUND'], 401);
            return null;
        }

        $token  = substr($authHeader, 7);
        $device = (new PuzzleDevice())->findByValidToken($token);

        if (!$device) {
            Response::error('Token d\'appareil inconnu ou expiré', ['code' => 'DEVICE_NOT_FOUND'], 401);
            return null;
        }

        // Mettre à jour last_seen_at (fire and forget)
        (new PuzzleDevice())->touchLastSeen((int) $device['id']);

        return $device;
    }

    /**
     * Vérifie que l'appareil est abonné actif.
     * Envoie HTTP 403 et retourne false si non.
     */
    private function requirePremium(array $device): bool
    {
        if (!$device['is_premium'] || strtotime($device['premium_expires_at'] ?? '0') < time()) {
            Response::error('Abonnement requis', ['code' => 'SUBSCRIPTION_REQUIRED'], 403);
            return false;
        }
        return true;
    }
}
