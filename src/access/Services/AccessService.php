<?php

namespace Access\Services;

use Stripe\Models\StripeSubscription;

class AccessService
{
    // Android subscriptions (Playstore) are anonymous — no user_id linkage.
    // Android clients query /v2/subscriptions/playstore/status directly via device_token.
    // This JWT-based endpoint covers Stripe (web/windows) only.

    public static function getMatrix(int $userId, string $appId): array
    {
        $stripeActive = self::isStripeActive($userId, $appId);

        $matrix = [
            'android' => $stripeActive,
            'web'     => $stripeActive,
            'windows' => $stripeActive,
        ];

        $sources = [];

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

    private static function isStripeActive(int $userId, string $appId): bool
    {
        $row = (new StripeSubscription())->findByUserAndApp($userId, $appId);
        if (!$row) {
            return false;
        }
        return \in_array($row['status'], ['active', 'trialing', 'past_due'], true);
    }
}
