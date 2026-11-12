<?php

namespace Access\Services;

use Access\Config\ResourceCaps;

/**
 * Plan effectif et caps cmem.
 *
 * Depuis le retrait de Stripe (plan accès sur approbation #269, tâche #300), il n'existe
 * plus d'abonnement : le plan effectif vaut users.cmem_plan_override (seul « ami » est
 * assignable) ou 'free'. Il ne décide plus de rien — la disponibilité des modules vient de
 * user_module_grants (ModuleGrantService) et les caps de ResourceCapService. Le champ
 * « plan » reste exposé (/auth/me, GET /modules) pour ne pas casser le contrat client.
 */
class EntitlementService
{
    /** Codes assignables via users.cmem_plan_override (admin). */
    public const OVERRIDABLE_PLAN_CODES = ['ami'];

    /**
     * Caps cmem effectifs pour un user (utilisé par les points d'enforcement).
     * Phase 3 bis (#294) : défaut ResourceCaps + surcharge utilisateur.
     */
    public static function getFeaturesForUser(int $userId): array
    {
        return (new ResourceCapService())->effectiveForUser($userId);
    }

    /**
     * Vérifie un quota cmem. Retourne null si sous la limite, sinon le payload d'erreur
     * QUOTA_EXCEEDED (à passer tel quel dans Response::error(..., $payload, 403)).
     * Ressource de groupe (max_group_members) : passer $groupId → surcharge du groupe.
     */
    public static function checkQuota(int $userId, string $resourceKey, int $currentCount, ?int $groupId = null): ?array
    {
        $features = ($groupId !== null && ResourceCaps::isGroupKey($resourceKey))
            ? (new ResourceCapService())->effectiveForGroup($groupId)
            : self::getFeaturesForUser($userId);
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

    /** Plan effectif d'un usager : override admin, sinon 'free'. */
    public static function getEffectivePlanForCmem(int $userId, ?string $planOverride): array
    {
        $plan = $planOverride
            ? ['code' => $planOverride, 'source' => 'override', 'status' => null]
            : ['code' => 'free',        'source' => 'default',  'status' => null];

        $plan['features'] = self::getFeaturesForUser($userId);
        return $plan;
    }

    /** Plan effectif d'un groupe : toujours 'free' (plus d'abonnement de groupe). */
    public static function getEffectivePlanForGroup(int $groupId): array
    {
        return [
            'code'     => 'free',
            'source'   => 'default',
            'status'   => null,
            'features' => (new ResourceCapService())->effectiveForGroup($groupId),
        ];
    }
}
