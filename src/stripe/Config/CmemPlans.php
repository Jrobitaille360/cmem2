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
            'max_contacts'      => 50,
        ],
        'monthly' => [
            'max_calendars'      => 25,
            'max_journals'       => 2500,
            'max_tasks'          => 5000,
            'max_devices'        => 5,
            'max_storage_mb'     => 2000,
            'max_groups'         => 10,
            'max_group_members'  => 50,
            'max_contacts'      => 2000,
        ],
        'yearly' => [
            'max_calendars'      => 25,
            'max_journals'       => 2500,
            'max_tasks'          => 5000,
            'max_devices'        => 5,
            'max_storage_mb'     => 2000,
            'max_groups'         => 10,
            'max_group_members'  => 50,
            'max_contacts'      => 2000,
        ],
        'team' => [
            'max_calendars'      => 25,
            'max_journals'       => 2500,
            'max_tasks'          => 5000,
            'max_devices'        => 5,
            'max_storage_mb'     => 2000,
            'max_groups'         => 10,
            'max_group_members'  => 50,
            'max_contacts'      => 2000,
        ],
        'ami' => [
            'max_calendars'      => 25,
            'max_journals'       => 2500,
            'max_tasks'          => 5000,
            'max_devices'        => 5,
            'max_storage_mb'     => 2000,
            'max_groups'         => 10,
            'max_group_members'  => 50,
            'max_contacts'      => 2000,
        ],
    ];

    /**
     * Classement des plans pour la résolution du meilleur plan effectif (perso vs groupes actifs,
     * directive plan-equipe 20260813). Un plan absent vaut le rang de 'free'.
     */
    private const RANK = [
        'free'    => 0,
        'monthly' => 1,
        'yearly'  => 1,
        'team'    => 2,
        'ami'     => 3,
    ];

    public static function get(string $plan): array
    {
        return self::PLANS[$plan] ?? self::PLANS['free'];
    }

    public static function codes(): array
    {
        return array_keys(self::PLANS);
    }

    /**
     * Codes assignables manuellement via users.cmem_plan_override (admin).
     * Distinct de codes() : 'monthly'/'yearly' viennent de Stripe, pas d'une assignation manuelle.
     */
    public static function overridableCodes(): array
    {
        return ['ami'];
    }

    public static function rank(string $plan): int
    {
        return self::RANK[$plan] ?? self::RANK['free'];
    }
}
