<?php

namespace Access\Services;

use Playstore\Models\PlaystoreSubscription;
use Stripe\Models\StripeSubscription;

class AccessService
{
    private const PLAYSTORE_PLATFORMS = ['android', 'web', 'windows'];
    private const STRIPE_PLATFORMS    = ['web', 'windows'];

    public static function getMatrix(int $userId, string $appId): array
    {
        $playstoreActive = self::isPlaystoreActive($userId, $appId);
        $stripeActive    = self::isStripeActive($userId, $appId);

        $matrix = [
            'android' => $playstoreActive,
            'web'     => $playstoreActive || $stripeActive,
            'windows' => $playstoreActive || $stripeActive,
        ];

        $sources = [];

        if ($playstoreActive) {
            $row       = (new PlaystoreSubscription())->findLatestActive($userId, $appId);
            $sources[] = [
                'provider'   => 'playstore',
                'status'     => 'active',
                'expires_at' => $row['expires_at'] ?? null,
            ];
        }

        if ($stripeActive) {
            $row       = (new StripeSubscription())->findByUserAndApp($userId, $appId);
            $sources[] = [
                'provider'   => 'stripe',
                'status'     => $row['status'] ?? 'active',
                'expires_at' => $row['expires_at'] ?? null,
            ];
        }

        return ['matrix' => $matrix, 'sources' => $sources];
    }

    private static function isPlaystoreActive(int $userId, string $appId): bool
    {
        $row = (new PlaystoreSubscription())->findLatestActive($userId, $appId);
        return $row !== null;
    }

    private static function isStripeActive(int $userId, string $appId): bool
    {
        $row = (new StripeSubscription())->findByUserAndApp($userId, $appId);
        if (!$row) {
            return false;
        }
        return \in_array($row['status'], ['active', 'trialing', 'past_due'], true);
    }
}
