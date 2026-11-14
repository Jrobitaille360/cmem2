<?php

namespace Assistant\Services;

use RuntimeException;

/**
 * Chiffrement du mot de passe SMTP usager (tâche #252) : sodium_crypto_secretbox,
 * clé serveur SMTP_ENCRYPTION_KEY (.env, 32 octets encodés en base64).
 * Format stocké : base64(nonce || chiffré).
 */
class SmtpCrypto
{
    /** Clé configurée et valide (32 octets) ? */
    public static function isConfigured(): bool
    {
        return extension_loaded('sodium') && self::rawKey() !== null;
    }

    public static function encrypt(string $plain): string
    {
        $key   = self::key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    public static function decrypt(string $stored): string
    {
        $key = self::key();
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Mot de passe SMTP chiffré illisible');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key
        );
        if ($plain === false) {
            throw new RuntimeException('Déchiffrement du mot de passe SMTP impossible (clé changée ?)');
        }
        return $plain;
    }

    private static function key(): string
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('Extension PHP sodium absente');
        }
        $key = self::rawKey();
        if ($key === null) {
            throw new RuntimeException('SMTP_ENCRYPTION_KEY absente ou invalide (32 octets base64 attendus)');
        }
        return $key;
    }

    private static function rawKey(): ?string
    {
        $b64 = defined('SMTP_ENCRYPTION_KEY') ? SMTP_ENCRYPTION_KEY : '';
        $key = $b64 !== '' ? base64_decode($b64, true) : false;
        return ($key !== false && strlen($key) === 32) ? $key : null;
    }
}
