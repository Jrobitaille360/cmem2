<?php

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Controllers\AuthController;
use AuthGroups\Middleware\JwtAuthMiddleware;
use AuthGroups\Utils\Response;

/**
 * Gestionnaire de routes d'authentification JWT.
 *
 * Routes publiques (pas de JWT requis) :
 *   POST /auth/login
 *   POST /auth/send-code
 *   POST /auth/verify-code
 *
 * Routes protégées (JWT requis) :
 *   GET  /auth/me
 *   GET  /auth/devices
 *   DELETE /auth/devices/{device_id}
 *   POST /auth/logout
 */
class AuthRouteHandler extends BaseRouteHandler
{
    protected bool $requiresAuth = false; // auth gérée manuellement par route

    private AuthController $controller;

    public function __construct($authService = null)
    {
        parent::__construct($authService);
        $this->controller = new AuthController();
    }

    protected function getSupportedControllers(): array
    {
        return ['auth'];
    }

    protected function handleRoute(array $request): void
    {
        $action   = $request['action'];
        $method   = $request['method'];
        $segments = $request['segments'];

        match (true) {
            // POST /auth/login
            ($action === 'login' && $method === 'POST') =>
                $this->controller->login(),

            // POST /auth/send-code
            ($action === 'send-code' && $method === 'POST') =>
                $this->controller->sendCode(),

            // POST /auth/verify-code
            ($action === 'verify-code' && $method === 'POST') =>
                $this->controller->verifyCode(),

            // POST /auth/restore-account  (compte en délai de grâce, pas de JWT)
            ($action === 'restore-account' && $method === 'POST' && !isset($segments[2])) =>
                $this->controller->restoreAccount(),

            // POST /auth/restore-account/verify
            ($action === 'restore-account' && $method === 'POST' && ($segments[2] ?? '') === 'verify') =>
                $this->controller->restoreAccountVerify(),

            // POST /auth/refresh  (device token, pas de JWT requis)
            ($action === 'refresh' && $method === 'POST') =>
                $this->controller->refresh(),

            // GET /auth/me  (JWT requis)
            ($action === 'me' && $method === 'GET') =>
                $this->withAuth(fn($user) => $this->controller->me($user['user_id'])),

            // GET /auth/devices  (JWT requis)
            ($action === 'devices' && $method === 'GET' && !isset($segments[2])) =>
                $this->withAuth(fn($user) => $this->controller->listDevices($user['user_id'])),

            // DELETE /auth/devices/{device_id}  (JWT requis)
            ($action === 'devices' && $method === 'DELETE' && isset($segments[2])) =>
                $this->withAuth(fn($user) => $this->controller->revokeDevice($user['user_id'], $segments[2])),

            // GET /auth/sessions  (JWT requis) — vue unifiée sessions + appareils
            ($action === 'sessions' && $method === 'GET') =>
                $this->withAuth(fn($user) => $this->controller->listSessions($user['user_id'])),

            // DELETE /auth/sessions  (JWT requis) — déconnexion globale tous appareils
            ($action === 'sessions' && $method === 'DELETE') =>
                $this->withAuth(fn($user) => $this->controller->revokeAllSessions(
                    $user['user_id'],
                    $user['jti'] ?? null,
                    $user['exp'] ?? null
                )),

            // POST /auth/logout  (JWT obligatoire)
            ($action === 'logout' && $method === 'POST') =>
                $this->withAuth(fn($user) => $this->controller->logout(
                    $user['user_id'],
                    $user['jti']  ?? null,
                    $user['exp']  ?? null
                )),

            default => Response::error('Route d\'authentification non trouvée', null, 404)
        };
    }

    /**
     * Valide le JWT puis exécute le callback avec les données utilisateur.
     */
    private function withAuth(callable $callback): void
    {
        $user = JwtAuthMiddleware::authenticate();
        if (!$user) {
            return; // Réponse d'erreur déjà envoyée par le middleware
        }
        $callback($user);
    }
}
