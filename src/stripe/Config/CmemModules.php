<?php

namespace Stripe\Config;

/**
 * Registre de modules cmem — config statique, pas de table DB (même parti pris que CmemPlans).
 * Directive cmem_web 20260727_144926 (modules-gating).
 *
 * Trois états, à ne pas fusionner :
 *   disponible → décidé ici, par le plan effectif (EntitlementService)
 *   activé     → décidé par l'usager, stocké dans tenant_modules.enabled
 *   quota      → décidé par le serveur, stocké dans tenant_modules.quota_used
 *
 * Calibrage du rétro-fit (acté avec cmem_web le 2026-07-27) :
 *   les 4 pans déjà en production (projet, contacts, crm, ged) restent disponibles sur
 *   TOUS les plans, y compris Gratuit — pas de clause grand-père, pas de date de bascule.
 *   Le plan « ami » donne accès à l'ensemble des modules.
 */
class CmemModules
{
    /** Enum figée dès la v1 — doit rester alignée sur l'ENUM SQL de tenant_modules.module_key. */
    public const KEYS = ['projet', 'contacts', 'crm', 'ged', 'ia', 'caldav', 'booking', 'push_avance'];

    /** Modules déjà livrés : allumés par défaut, aucun compte ne perd l'accès. */
    private const ENABLED_BY_DEFAULT = ['projet', 'contacts', 'crm', 'ged'];

    /** Modules disponibles par plan. 'ami' = tout. */
    private const AVAILABLE = [
        'free'    => ['projet', 'contacts', 'crm', 'ged'],
        'monthly' => ['projet', 'contacts', 'crm', 'ged', 'ia'],
        'yearly'  => ['projet', 'contacts', 'crm', 'ged', 'ia'],
        'ami'     => self::KEYS,
    ];

    /**
     * Limites d'usage par plan, pour les modules à coût variable.
     * Un module absent de cette table n'a pas de quota (quota null dans GET /modules).
     */
    private const QUOTAS = [
        'ia' => [
            'free'    => 0,
            'monthly' => 30,
            'yearly'  => 30,
            'ami'     => 30,
        ],
    ];

    public static function isValidKey(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    public static function availableFor(string $planCode): array
    {
        return self::AVAILABLE[$planCode] ?? self::AVAILABLE['free'];
    }

    public static function isAvailable(string $planCode, string $key): bool
    {
        return in_array($key, self::availableFor($planCode), true);
    }

    public static function isEnabledByDefault(string $key): bool
    {
        return in_array($key, self::ENABLED_BY_DEFAULT, true);
    }

    public static function hasQuota(string $key): bool
    {
        return isset(self::QUOTAS[$key]);
    }

    /** Limite d'appels de la période pour ce module et ce plan, ou null si aucun quota. */
    public static function quotaLimit(string $planCode, string $key): ?int
    {
        if (!isset(self::QUOTAS[$key])) {
            return null;
        }
        return self::QUOTAS[$key][$planCode] ?? self::QUOTAS[$key]['free'];
    }

    /**
     * Fin de la période de quota en cours : premier instant du mois suivant.
     * Sert de valeur par défaut quand aucune ligne tenant_modules n'existe encore.
     */
    public static function nextQuotaReset(): string
    {
        return (new \DateTime('first day of next month 00:00:00'))->format('Y-m-d H:i:s');
    }
}
