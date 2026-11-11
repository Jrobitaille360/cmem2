<?php

namespace Access\Config;

/**
 * Caps de ressources — grille par défaut (plan accès sur approbation #269, Phase 3 bis, task #292).
 *
 * Résolution à trois niveaux (voir EntitlementService::checkQuota) :
 *   1. défaut : cette grille (reprise de l'ancien plan « monthly » de Stripe\Config\CmemPlans) ;
 *   2. par groupe : table group_resource_caps (seulement les clés de GROUP_KEYS) ;
 *   3. par utilisateur : table user_resource_caps (toutes les autres clés).
 * Une surcharge à NULL = illimité explicite.
 *
 * Règle verrouillée héritée de CmemPlans : max_journals = max_tasks / 2.
 */
final class ResourceCaps
{
    private const DEFAULTS = [
        'max_calendars'     => 25,
        'max_journals'      => 2500,
        'max_tasks'         => 5000,
        'max_devices'       => 5,
        'max_storage_mb'    => 2000,
        'max_groups'        => 10,
        'max_group_members' => 50,
        'max_contacts'      => 2000,
    ];

    /** Clés qui portent sur un groupe (surcharge par groupe) ; les autres sont perso. */
    private const GROUP_KEYS = ['max_group_members'];

    /** @return array<string,int|null> */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /** @return string[] */
    public static function groupKeys(): array
    {
        return self::GROUP_KEYS;
    }

    /** @return string[] */
    public static function userKeys(): array
    {
        return array_values(array_diff(self::keys(), self::GROUP_KEYS));
    }

    public static function isGroupKey(string $key): bool
    {
        return in_array($key, self::GROUP_KEYS, true);
    }

    public static function isUserKey(string $key): bool
    {
        return array_key_exists($key, self::DEFAULTS) && !self::isGroupKey($key);
    }
}
