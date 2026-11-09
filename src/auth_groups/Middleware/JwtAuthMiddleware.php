<?php

namespace AuthGroups\Middleware;

use AuthGroups\Models\User;
use AuthGroups\Services\JwtService;
use AuthGroups\Services\LogService;
use AuthGroups\Utils\Response;

/**
 * Middleware d'authentification par JWT (Bearer token).
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
            LogService::warning('Accès refusé : token JWT absent', [
                'ip'     => self::getClientIp(),
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
                'route'  => $_SERVER['REQUEST_URI']    ?? 'UNKNOWN',
            ]);
            Response::error('Token JWT manquant', [
                'error'   => 'MISSING_TOKEN',
                'message' => 'Utilisez le header Authorization: Bearer <token>',
            ], 401);
            return null;
        }

        $payload = JwtService::validate($token);

        if (!$payload) {
            // Le détail de l'échec (expiré, blacklisté, malformé) est déjà logué
            // dans JwtService::validate() avec le contexte IP/route.
            Response::error('Token JWT invalide ou expiré', [
                'error'   => 'INVALID_TOKEN',
                'message' => 'Le token est invalide, malformé ou expiré. Reconnectez-vous.',
            ], 401);
            return null;
        }

        // Le payload seul ne suffit pas : un JWT émis avant une suppression de compte
        // resterait valide jusqu'à son expiration (15 jours), et continuerait
        // d'authentifier un user_id déjà purgé. La signature prouve l'émission,
        // pas que le compte existe encore.
        $userId   = (int) $payload['sub'];
        $userData = (new User())->findById($userId);

        if (!$userData || !empty($userData['deleted_at'])) {
            LogService::warning('JWT valide mais compte supprimé ou introuvable', [
                'user_id' => $userId,
                'ip'      => self::getClientIp(),
                'route'   => $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
            ]);
            Response::error('Token JWT invalide ou expiré', [
                'error'   => 'ACCOUNT_UNAVAILABLE',
                'message' => 'Le compte associé à ce token n\'est plus accessible.',
            ], 401);
            return null;
        }

        // Accès sur approbation (plan #269, Phase 2) : un compte pending ou suspended
        // s'authentifie, mais seules quelques routes de compte lui restent ouvertes.
        $accessStatus = $userData['access_status'] ?? 'approved';
        if ($accessStatus !== 'approved' && !self::isOpenToUnapproved()) {
            $code = $accessStatus === 'suspended' ? 'ACCOUNT_SUSPENDED' : 'ACCOUNT_PENDING';
            LogService::info('Accès refusé : compte non approuvé', [
                'user_id' => $userId,
                'status'  => $accessStatus,
                'route'   => $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
            ]);
            Response::error(
                $code === 'ACCOUNT_SUSPENDED' ? 'Compte suspendu' : 'Compte en attente d\'approbation',
                [
                    'code'    => $code,
                    'message' => $code === 'ACCOUNT_SUSPENDED'
                        ? 'Ce compte a été suspendu par un administrateur.'
                        : 'Ce compte doit être approuvé par un administrateur avant d\'utiliser l\'application.',
                ],
                403
            );
            return null;
        }

        return [
            'user_id'   => $userId,
            'email'     => $userData['email'] ?? $payload['email'],
            'role'      => $userData['role']  ?? $payload['role'],
            'name'      => $userData['name']  ?? ($payload['name'] ?? ''),
            'jti'       => $payload['jti']    ?? null,
            'exp'       => $payload['exp']    ?? null,
            'auth_type' => 'jwt',
        ];
    }

    /**
     * Routes ouvertes à un compte pending / suspended : GET /auth/me,
     * POST /auth/logout, DELETE /users/me (préfixe /v2 toléré).
     */
    private static function isOpenToUnapproved(): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
        $path   = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
        $path   = str_replace(defined('BASE_PATH') ? BASE_PATH : '/cmem2_API', '', $path);
        $path   = trim(str_replace('/index.php', '', $path), '/');
        if (strpos($path, 'v2/') === 0) {
            $path = substr($path, 3);
        }

        return ($method === 'GET' && $path === 'auth/me')
            || ($method === 'POST' && $path === 'auth/logout')
            || ($method === 'DELETE' && $path === 'users/me');
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

    private static function getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

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
