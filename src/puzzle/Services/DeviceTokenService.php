<?php

namespace Puzzle\Services;

class DeviceTokenService
{
    /**
     * Génère un token opaque de 64 chars hex (bin2hex de 32 octets aléatoires).
     */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Calcule la date d'expiration du token (NOW + PUZZLE_DEVICE_TOKEN_DAYS jours).
     */
    public function expiresAt(): string
    {
        $days = (int) (defined('PUZZLE_DEVICE_TOKEN_DAYS') ? PUZZLE_DEVICE_TOKEN_DAYS : 365);
        return date('Y-m-d H:i:s', strtotime("+{$days} days"));
    }
}
