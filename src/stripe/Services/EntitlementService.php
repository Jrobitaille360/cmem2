<?php

namespace Stripe\Services;

use AuthGroups\Models\User;
use Stripe\Config\CmemPlans;
use Stripe\Models\StripeSubscription;

/**
 * Résolution du plan effectif cmem — phase 7a.
 * Ordre de priorité : stripe_subscriptions actif (tenant cmem) > users.cmem_plan_override > 'free'.
 * Tenant cmem = app_id 'cmemweb' (primaire) + 'cmem' (alias legacy, voir docs/stripe/TENANT_CMEMWEB.md).
 */
class EntitlementService
{
    private const ACTIVE_STATUSES = ['trialing', 'active', 'past_due'];

    /** app_id de la famille tenant cmem — 'cmemweb' primaire, 'cmem' conservé en alias. */
    private const CMEM_APP_IDS = ['cmemweb', 'cmem'];

    /**
     * Caps cmem effectifs pour un user (utilisé par les points d'enforcement).
     */
    public static function getFeaturesForUser(int $userId): array
    {
        $userData = (new User())->findById($userId);
        $override = $userData['cmem_plan_override'] ?? null;
        return self::getEffectivePlanForCmem($userId, $override)['features'];
    }

    /**
     * Vérifie un quota cmem. Retourne null si sous la limite, sinon le payload d'erreur
     * QUOTA_EXCEEDED (à passer tel quel dans Response::error(..., $payload, 403)).
     */
    public static function checkQuota(int $userId, string $resourceKey, int $currentCount): ?array
    {
        $features = self::getFeaturesForUser($userId);
        $limit    = $features[$resourceKey] ?? null;

        if ($limit !== null && $currentCount >= $limit) {
            return [
                'code'     => 'QUOTA_EXCEEDED',
                'resource' => $resourceKey,
                'limit'    => $limit,
                'current'  => $currentCount,
            ];
        }

        return null;
    }

    public static function getEffectivePlanForCmem(int $userId, ?string $planOverride): array
    {
        $sub = (new StripeSubscription())->findByUserAndApps($userId, self::CMEM_APP_IDS);

        if ($sub && in_array($sub['status'], self::ACTIVE_STATUSES, true)) {
            $code = $sub['plan'];
            return [
                'code'     => $code,
                'source'   => 'stripe',
                'status'   => $sub['status'],
                'features' => CmemPlans::get($code),
            ];
        }

        if ($planOverride) {
            return [
                'code'     => $planOverride,
                'source'   => 'override',
                'status'   => null,
                'features' => CmemPlans::get($planOverride),
            ];
        }

        return [
            'code'     => 'free',
            'source'   => 'default',
            'status'   => null,
            'features' => CmemPlans::get('free'),
        ];
    }
}
