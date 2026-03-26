<?php

namespace AuthGroups\Middleware;

use AuthGroups\Services\JwtService;
use AuthGroups\Services\LogService;
use AuthGroups\Utils\Response;

/**
 * Middleware d'authentification par JWT (Bearer token).
 * Remplace ApiKeyAuthMiddleware.
 */
class JwtAuthMiddleware
{
    /**
     * Valide le JWT présent dans la requête.
     * Retourne les données de l'utilisateur ou null (+ réponse d'erreur déjà envoyée).
     *
     * @return array|null  ['user_id', 'email', 'role', 'name', 'auth_type']
     */
    public static function authenticate(): ?array
    {
        $token = self::getTokenFromRequest();

        if (!$token) {
            Response::error('Token JWT manquant', [
                'error'   => 'MISSING_TOKEN',
                'message' => 'Utilisez le header Authorization: Bearer <token>',
            ], 401);
            return null;
        }

        $payload = JwtService::validate($token);

        if (!$payload) {
            Response::error('Token JWT invalide ou expiré', [
                'error'   => 'INVALID_TOKEN',
                'message' => 'Le token est invalide, malformé ou expiré. Reconnectez-vous.',
            ], 401);
            return null;
        }

        return [
            'user_id'   => (int) $payload['sub'],
            'email'     => $payload['email'],
            'role'      => $payload['role'],
            'name'      => $payload['name'] ?? '',
            'jti'       => $payload['jti']  ?? null,
            'exp'       => $payload['exp']  ?? null,
            'auth_type' => 'jwt',
        ];
    }

    /**
     * Indique si un token Bearer est présent dans la requête (sans le valider).
     */
    public static function hasToken(): bool
    {
        return self::getTokenFromRequest() !== null;
    }

    // -----------------------------------------------------------------------
    // Helpers privés
    // -----------------------------------------------------------------------

    private static function getTokenFromRequest(): ?string
    {
        // 1. Header Authorization standard
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
                return trim($matches[1]);
            }
        }

        // 2. Apache mod_rewrite
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            if (preg_match('/^Bearer\s+(.+)$/i', $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $matches)) {
                return trim($matches[1]);
            }
        }

        // 3. apache_request_headers() fallback
        if (function_exists('apache_request_headers')) {
            $headers    = apache_request_headers();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
            if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }
}
