<?php

namespace Contacts\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Contacts\Controllers\OpportuniteController;

/**
 * OpportunitesRouteHandler — routes /opportunites/*  (CRM pipeline, Phase G-D)
 *
 *   GET    /opportunites             → board Kanban global (filtre ?etape= ?limit= ?offset=)
 *   PUT    /opportunites/{opId}      → mise à jour partielle (dont changement d'étape)
 *   PATCH  /opportunites/{opId}      → idem PUT
 *   DELETE /opportunites/{opId}      → soft-delete
 *
 * La création passe par POST /contacts/{id}/opportunites (voir ContactsRouteHandler).
 * Toutes les routes exigent un JWT valide.
 */
class OpportunitesRouteHandler extends BaseRouteHandler
{
    protected function getSupportedControllers(): array
    {
        return ['opportunites'];
    }

    protected function handleRoute(array $request): void
    {
        $user   = $request['user'];
        $method = $request['method'] ?? 'GET';
        $segs   = $request['segments'] ?? [];

        // segs[0] = 'opportunites'
        $s1 = $segs[1] ?? '';

        if ($s1 === '') {
            match ($method) {
                'GET'   => (new OpportuniteController())->board($user),
                default => Response::error('Méthode non autorisée', null, 405),
            };
            return;
        }

        if (!is_numeric($s1) || ($segs[2] ?? '') !== '') {
            Response::error('Endpoint non trouvé', null, 404);
            return;
        }
        $opId = (int) $s1;

        match ($method) {
            'PUT'    => (new OpportuniteController())->update($user, $opId),
            'PATCH'  => (new OpportuniteController())->update($user, $opId),
            'DELETE' => (new OpportuniteController())->delete($user, $opId),
            default  => Response::error('Méthode non autorisée', null, 405),
        };
    }
}
