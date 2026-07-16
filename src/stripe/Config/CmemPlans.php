<?php

namespace Stripe\Config;

/**
 * Caps cmem par plan — config statique, pas de table DB.
 * Valeurs actées avec cmem_web le 2026-07-15 (voir docs/PLAN_monetisation-stripe-caps-phase7a.md).
 * Règle verrouillée : max_journals = max_tasks / 2, à respecter pour tout futur ajustement.
 * Codes alignés sur stripe_subscriptions.plan ('monthly'/'yearly') + 'free'/'ami' sans abonnement actif.
 */
class CmemPlans
{
    private const PLANS = [
        'free' => [
            'max_calendars'      => 3,
            'max_journals'       => 100,
            'max_tasks'          => 200,
            'max_devices'        => 2,
            'max_storage_mb'     => 100,
            'max_groups'         => 1,
            'max_group_members'  => 5,
        ],
        'monthly' => [
            'max_calendars'      => 25,
            'max_journals'       => 2500,
            'max_tasks'          => 5000,
            'max_devices'        => 5,
            'max_storage_mb'     => 2000,
            'max_groups'         => 10,
            'max_group_members'  => 50,
        ],
        'yearly' => [
            'max_calendars'      => 25,
            'max_journals'       => 2500,
            'max_tasks'          => 5000,
            'max_devices'        => 5,
            'max_storage_mb'     => 2000,
            'max_groups'         => 10,
            'max_group_members'  => 50,
        ],
        'ami' => [
            'max_calendars'      => 25,
            'max_journals'       => 2500,
            'max_tasks'          => 5000,
            'max_devices'        => 5,
            'max_storage_mb'     => 2000,
            'max_groups'         => 10,
            'max_group_members'  => 50,
        ],
    ];

    public static function get(string $plan): array
    {
        return self::PLANS[$plan] ?? self::PLANS['free'];
    }

    public static function codes(): array
    {
        return array_keys(self::PLANS);
    }
}
