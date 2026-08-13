<?php

namespace Stripe\Services;

use Stripe\Models\StripeSubscription;
use Stripe\Services\StripeService;

class StripeSubscriptionService
{
    public static function getStatus(int $userId, string $appId, ?int $groupId = null): array
    {
        $row = $groupId
            ? (new StripeSubscription())->findByGroupAndApp($groupId, $appId)
            : (new StripeSubscription())->findByUserAndApp($userId, $appId);

        if (!$row) {
            return [
                'is_premium'           => false,
                'status'               => null,
                'expires_at'           => null,
                'plan'                 => null,
                'is_trial'             => false,
                'trial_end'            => null,
                'cancel_at_period_end' => false,
                'provider'             => 'stripe',
            ];
        }

        $isPremium = \in_array($row['status'], ['active', 'trialing', 'past_due'], true);

        return [
            'is_premium'           => $isPremium,
            'status'               => $row['status'],
            'expires_at'           => $row['expires_at'],
            'plan'                 => $row['plan'],
            'is_trial'             => (bool) $row['is_trial'],
            'trial_end'            => $row['trial_end'],
            'cancel_at_period_end' => (bool) $row['cancel_at_period_end'],
            'provider'             => 'stripe',
        ];
    }

    public static function cancel(int $userId, string $appId, ?int $groupId = null): void
    {
        $model = new StripeSubscription();
        $row   = $groupId ? $model->findByGroupAndApp($groupId, $appId) : $model->findByUserAndApp($userId, $appId);

        if (!$row) {
            throw new \RuntimeException('Aucun abonnement Stripe actif');
        }

        if (!empty($row['stripe_subscription_id'])) {
            StripeService::cancelSubscription($row['stripe_subscription_id']);
        } elseif ($groupId) {
            (new StripeSubscription())->updateByGroupAndApp($groupId, $appId, ['cancel_at_period_end' => 1]);
        } else {
            (new StripeSubscription())->updateByUserAndApp($userId, $appId, ['cancel_at_period_end' => 1]);
        }
    }
}
