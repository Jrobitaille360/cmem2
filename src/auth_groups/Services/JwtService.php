<?php

namespace AuthGroups\Services;

use AuthGroups\Models\JwtBlacklist;
use RuntimeException;

/**
 * Service JWT - implémentation pure PHP, sans dépendance externe.
 * Algorithme : HS256 (HMAC-SHA256)
 * Durée de validité : JWT_EXPIRY_DAYS jours (défaut 15)
 */
class JwtService
{
    const ALGORITHM = 'HS256';
    const DEFAULT_EXPIRY_DAYS = 15;

    // -----------------------------------------------------------------------
    // API publique
    // -----------------------------------------------------------------------

    /**
     * Génère un token JWT signé pour un utilisateur.
     *
     * @param array $user  Doit contenir : id, email, role, name
     * @return string      Token JWT
     */
    public static function generate(array $user): string
    {
        $secret      = self::getSecret();
        $expiryDays  = defined('JWT_EXPIRY_DAYS') ? (int) JWT_EXPIRY_DAYS : self::DEFAULT_EXPIRY_DAYS;
        $now         = time();

        $header  = self::base64UrlEncode(json_encode([
            'alg' => self::ALGORITHM,
            'typ' => 'JWT',
        ]));

        $payload = self::base64UrlEncode(json_encode([
            'iss'   => defined('BASE_URL') ? BASE_URL : 'cmem2-api',
            'iat'   => $now,
            'exp'   => $now + ($expiryDays * 86400),
            'jti'   => self::generateJti(),
            'sub'   => (int) $user['id'],
            'email' => $user['email'],
            'role'  => $user['role']  ?? 'UTILISATEUR',
            'name'  => $user['name']  ?? '',
        ]));

        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
        );

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * Valide un token JWT et retourne le payload décodé, ou null si invalide/expiré.
     *
     * @param string $token
     * @return array|null
     */
    public static function validate(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            LogService::warning('JWT malformé : nombre de segments incorrect');
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // Vérifier la signature
        $secret            = self::getSecret();
        $expectedSignature = self::base64UrlEncode(
            hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true)
        );

        if (!hash_equals($expectedSignature, $signatureB64)) {
            LogService::warning('JWT : signature invalide');
            return null;
        }

        // Décoder le payload
        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) {
            LogService::warning('JWT : payload non décodable');
            return null;
        }

        // Vérifier l'expiration
        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            LogService::warning('JWT expiré', ['exp' => $payload['exp'] ?? null]);
            return null;
        }

        // Vérifier la blacklist (tokens révoqués via logout)
        if (isset($payload['jti'])) {
            $blacklist = new JwtBlacklist();
            if ($blacklist->isBlacklisted($payload['jti'])) {
                LogService::warning('JWT révoqué (blacklisté)', ['jti' => $payload['jti']]);
                return null;
            }
        }

        return $payload;
    }

    /**
     * Retourne la date d'expiration du prochain token généré (format MySQL).
     */
    public static function getExpiresAt(): string
    {
        $expiryDays = defined('JWT_EXPIRY_DAYS') ? (int) JWT_EXPIRY_DAYS : self::DEFAULT_EXPIRY_DAYS;
        return date('Y-m-d H:i:s', time() + ($expiryDays * 86400));
    }

    // -----------------------------------------------------------------------
    // Helpers privés
    // -----------------------------------------------------------------------

    private static function getSecret(): string
    {
        if (!defined('JWT_SECRET') || JWT_SECRET === '') {
            throw new RuntimeException(
                'JWT_SECRET non configuré. Ajoutez JWT_SECRET dans .env.'
            );
        }
        return JWT_SECRET;
    }

    /**
     * Génère un UUID v4 aléatoire pour le claim jti.
     */
    private static function generateJti(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
