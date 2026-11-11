<?php

namespace Access\Services;

use AuthGroups\Models\User;

/**
 * Matrice d'accès GET /v2/access/status — plan accès sur approbation (#269, tâche #299).
 *
 * Plus d'abonnement : l'accès découle de users.access_status (approbation admin),
 * identique sur toutes les plateformes. La source unique « approval » expose le
 * statut et les modules disponibles (grants effectifs, groupes inclus).
 * app_id reste accepté par le contrat mais n'influe plus sur le résultat.
 */
class AccessService
{
    public static function getMatrix(int $userId, string $appId): array
    {
        $user    = (new User())->findById($userId);
        $status  = $user['access_status'] ?? 'approved';
        $granted = $status === 'approved';

        return [
            'matrix'  => [
                'android' => $granted,
                'web'     => $granted,
                'windows' => $granted,
            ],
            'sources' => [[
                'provider'   => 'approval',
                'status'     => $status,
                'expires_at' => null,
                'modules'    => $granted ? (new ModuleGrantService())->effectiveGrantsFor($userId) : [],
            ]],
        ];
    }
}
