<?php

namespace AuthGroups\Services;

use Database;

/**
 * Service de rate limiting pour les endpoints d'authentification.
 *
 * Logique par endpoint :
 *   - login    : enregistre les ÉCHECS uniquement ; efface sur succès
 *   - send-code: enregistre CHAQUE appel (prévenir le bombing d'emails)
 *
 * Seuil : RATE_LIMIT_AUTH_MAX_ATTEMPTS tentatives dans RATE_LIMIT_AUTH_WINDOW_MINUTES minutes
 * par couple (email + IP). Retourne faux si la limite est atteinte.
 */
class RateLimitService
{
    // -----------------------------------------------------------------------
    // API publique
    // -----------------------------------------------------------------------

    /**
     * Vérifie si le couple (email, IP) a dépassé la limite pour un endpoint.
     *
     * @param  string $email
     * @param  string $endpoint  'login' | 'send-code'
     * @return bool   true = sous la limite (requête autorisée)
     *                false = limite dépassée → retourner 429
     */
    public static function check(string $email, string $endpoint): bool
    {
        if (!ENABLE_RATE_LIMITING) {
            return true;
        }

        $ip      = self::getClientIp();
        $max     = defined('RATE_LIMIT_AUTH_MAX_ATTEMPTS')    ? RATE_LIMIT_AUTH_MAX_ATTEMPTS    : 5;
        $window  = defined('RATE_LIMIT_AUTH_WINDOW_MINUTES')  ? RATE_LIMIT_AUTH_WINDOW_MINUTES  : 10;

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE email      = :email
                AND ip_address = :ip
                AND endpoint   = :endpoint
                AND created_at > DATE_SUB(NOW(), INTERVAL :window MINUTE)'
        );
        $stmt->execute([
            'email'    => strtolower(trim($email)),
            'ip'       => $ip,
            'endpoint' => $endpoint,
            'window'   => $window,
        ]);

        return (int) $stmt->fetchColumn() < $max;
    }

    /**
     * Enregistre une tentative (échec pour login, tout appel pour send-code).
     *
     * @param string $email
     * @param string $endpoint  'login' | 'send-code'
     */
    public static function record(string $email, string $endpoint): void
    {
        if (!ENABLE_RATE_LIMITING) {
            return;
        }

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            'INSERT INTO login_attempts (email, ip_address, endpoint)
             VALUES (:email, :ip, :endpoint)'
        );
        $stmt->execute([
            'email'    => strtolower(trim($email)),
            'ip'       => self::getClientIp(),
            'endpoint' => $endpoint,
        ]);
    }

    /**
     * Supprime les tentatives d'un couple (email, IP) sur un endpoint.
     * Appelé après un login réussi pour réinitialiser le compteur.
     *
     * @param string $email
     * @param string $endpoint
     */
    public static function clear(string $email, string $endpoint): void
    {
        if (!ENABLE_RATE_LIMITING) {
            return;
        }

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            'DELETE FROM login_attempts
              WHERE email      = :email
                AND ip_address = :ip
                AND endpoint   = :endpoint'
        );
        $stmt->execute([
            'email'    => strtolower(trim($email)),
            'ip'       => self::getClientIp(),
            'endpoint' => $endpoint,
        ]);
    }

    /**
     * Supprime les tentatives expirées (pour le cron de nettoyage).
     *
     * @return int Nombre de lignes supprimées
     */
    public static function deleteExpired(): int
    {
        $window = defined('RATE_LIMIT_AUTH_WINDOW_MINUTES') ? RATE_LIMIT_AUTH_WINDOW_MINUTES : 10;
        $db     = Database::getInstance()->getConnection();
        $stmt   = $db->prepare(
            'DELETE FROM login_attempts
              WHERE created_at < DATE_SUB(NOW(), INTERVAL :window MINUTE)'
        );
        $stmt->execute(['window' => $window]);
        return $stmt->rowCount();
    }

    // -----------------------------------------------------------------------
    // Helper privé
    // -----------------------------------------------------------------------

    /**
     * Retourne l'adresse IP réelle du client (supporte les proxies via X-Forwarded-For).
     */
    private static function getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Prendre la première IP de la chaîne (IP du client original)
            $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
