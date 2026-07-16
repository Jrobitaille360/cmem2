<?php

namespace Stripe\Services;

use Stripe\Config\CmemPlans;
use Stripe\Models\StripeSubscription;

/**
 * Résolution du plan effectif cmem — phase 7a.
 * Ordre de priorité : stripe_subscriptions actif (app_id='cmem') > users.cmem_plan_override > 'free'.
 */
class EntitlementService
{
    private const ACTIVE_STATUSES = ['trialing', 'active', 'past_due'];

    public static function getEffectivePlanForCmem(int $userId, ?string $planOverride): array
    {
        $sub = (new StripeSubscription())->findByUserAndApp($userId, 'cmem');

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
