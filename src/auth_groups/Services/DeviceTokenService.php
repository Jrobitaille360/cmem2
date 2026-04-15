<?php

namespace AuthGroups\Services;

/**
 * Service de gestion des device tokens.
 *
 * Un device token est un jeton longue durée (défaut : 365 jours) lié à un
 * utilisateur ET à un identifiant d'appareil fourni par le client.
 * Il permet de renouveler automatiquement le JWT sans redemander les
 * identifiants, tant que l'appareil est reconnu.
 *
 * Table requise : device_tokens  (voir MIGRATION_JWT.sql)
 */
class DeviceTokenService
{
    const DEFAULT_EXPIRY_DAYS = 365;
    const TOKEN_BYTES         = 32;   // 256 bits d'entropie

    // -----------------------------------------------------------------------
    // Génération
    // -----------------------------------------------------------------------

    /**
     * Génère un device token, le stocke hashé en base et retourne le token
     * en clair (à transmettre UNE SEULE FOIS au client).
     *
     * @param int         $userId
     * @param string      $deviceId   UUID fourni par le client (stable par appareil)
     * @param string      $deviceName Nom lisible (ex: "iPhone 15 Safari")
     * @param string|null $familyId   UUID de la famille de rotation (null = premier token de cet appareil)
     * @return string  Token en clair
     */
    public static function generate(int $userId, string $deviceId, string $deviceName = 'Appareil inconnu', ?string $familyId = null): string
    {
        if (!self::isValidDeviceId($deviceId)) {
            LogService::warning('Device token : device_id invalide (format non-UUID)', ['device_id' => substr($deviceId, 0, 64)]);
            throw new \InvalidArgumentException('device_id doit être un UUID valide.');
        }

        // Réutiliser le family_id existant (rotation) ou en créer un nouveau (premier token)
        $familyId  = $familyId ?? self::generateUuid();

        $expiryDays = defined('DEVICE_TOKEN_EXPIRY_DAYS')
            ? (int) DEVICE_TOKEN_EXPIRY_DAYS
            : self::DEFAULT_EXPIRY_DAYS;

        $token     = bin2hex(random_bytes(self::TOKEN_BYTES));  // 64 hex chars
        $hash      = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + $expiryDays * 86400);
        $ip        = $_SERVER['REMOTE_ADDR']     ?? 'unknown';
        $ua        = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        $pdo = \Database::getInstance()->getConnection();

        // Révoquer les anciens tokens du même device (un seul token actif par device)
        $pdo->prepare(
            "UPDATE device_tokens
             SET revoked_at = NOW()
             WHERE user_id = ? AND device_id = ? AND revoked_at IS NULL"
        )->execute([$userId, $deviceId]);

        $pdo->prepare(
            "INSERT INTO device_tokens
                (user_id, device_id, device_name, family_id, token_hash, expires_at, last_ip, last_ua)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$userId, $deviceId, $deviceName, $familyId, $hash, $expiresAt, $ip, $ua]);

        LogService::info('Device token généré', [
            'user_id'     => $userId,
            'device_id'   => $deviceId,
            'device_name' => $deviceName,
            'family_id'   => $familyId,
            'expires_at'  => $expiresAt,
        ]);

        return $token;
    }

    // -----------------------------------------------------------------------
    // Validation / refresh
    // -----------------------------------------------------------------------

    /**
     * Valide un device token et retourne l'enregistrement si valide.
     * Met à jour last_used_at et last_ip.
     *
     * @return array|null  Enregistrement device_tokens ou null si invalide
     */
    public static function validate(string $token, string $deviceId): ?array
    {
        if (!self::isValidDeviceId($deviceId)) {
            LogService::warning('Device token : device_id invalide (format non-UUID)', ['device_id' => substr($deviceId, 0, 64)]);
            return null;
        }

        $hash = hash('sha256', $token);
        $pdo  = \Database::getInstance()->getConnection();

        // Recherche SANS filtre revoked_at pour détecter les replay attacks
        $stmt = $pdo->prepare(
            "SELECT * FROM device_tokens
             WHERE token_hash = ? AND device_id = ?
             LIMIT 1"
        );
        $stmt->execute([$hash, $deviceId]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$record) {
            LogService::warning('Device token invalide ou introuvable', [
                'device_id' => $deviceId,
            ]);
            return null;
        }

        // Replay attack : token révoqué présenté à nouveau
        if ($record['revoked_at'] !== null) {
            LogService::critical('Replay attack détecté : device token révoqué réutilisé', [
                'device_id' => $deviceId,
                'user_id'   => $record['user_id'],
                'family_id' => $record['family_id'] ?? null,
            ]);
            if (!empty($record['family_id'])) {
                self::revokeFamily($record['family_id']);
            }
            return null;
        }

        // Token expiré
        if (strtotime($record['expires_at']) <= time()) {
            LogService::warning('Device token expiré', [
                'device_id' => $deviceId,
                'user_id'   => $record['user_id'],
            ]);
            return null;
        }

        // Mettre à jour l'activité
        $pdo->prepare(
            "UPDATE device_tokens
             SET last_used_at = NOW(), last_ip = ?, last_ua = ?
             WHERE id = ?"
        )->execute([
            $_SERVER['REMOTE_ADDR']     ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $record['id'],
        ]);

        return $record;
    }

    // -----------------------------------------------------------------------
    // Révocation
    // -----------------------------------------------------------------------

    /**
     * Révoque un device token spécifique (par device_id + user_id).
     */
    public static function revoke(int $userId, string $deviceId): void
    {
        $pdo = \Database::getInstance()->getConnection();
        $pdo->prepare(
            "UPDATE device_tokens
             SET revoked_at = NOW()
             WHERE user_id = ? AND device_id = ? AND revoked_at IS NULL"
        )->execute([$userId, $deviceId]);

        LogService::info('Device token révoqué', [
            'user_id'   => $userId,
            'device_id' => $deviceId,
        ]);
    }

    /**
     * Révoque tous les device tokens d'un utilisateur (déconnexion globale).
     */
    public static function revokeAll(int $userId): void
    {
        $pdo = \Database::getInstance()->getConnection();
        $pdo->prepare(
            "UPDATE device_tokens SET revoked_at = NOW()
             WHERE user_id = ? AND revoked_at IS NULL"
        )->execute([$userId]);
    }

    /**
     * Liste les appareils actifs d'un utilisateur.
     */
    public static function listDevices(int $userId): array
    {
        $pdo  = \Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            "SELECT id, device_id, device_name, created_at, last_used_at,
                    expires_at, last_ip
             FROM device_tokens
             WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
             ORDER BY last_used_at DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Révoque tous les tokens d'une même famille (replay attack détecté).
     * Déconnecte tous les appareils liés à cette chaîne de rotation.
     */
    public static function revokeFamily(string $familyId): void
    {
        $pdo = \Database::getInstance()->getConnection();
        $pdo->prepare(
            "UPDATE device_tokens SET revoked_at = NOW()
             WHERE family_id = ? AND revoked_at IS NULL"
        )->execute([$familyId]);

        LogService::critical('Famille de tokens révoquée suite à un replay attack', [
            'family_id' => $familyId,
        ]);
    }

    // -----------------------------------------------------------------------
    // Helpers privés
    // -----------------------------------------------------------------------

    /**
     * Vérifie que le device_id est un UUID valide (format standard 8-4-4-4-12).
     */
    private static function isValidDeviceId(string $deviceId): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $deviceId
        );
    }

    /**
     * Génère un UUID v4 aléatoire (pour family_id).
     */
    private static function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
