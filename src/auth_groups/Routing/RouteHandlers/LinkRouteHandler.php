<?php

namespace AuthGroups\Routing\RouteHandlers;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Controllers\LinkController;
use AuthGroups\Utils\Response;

/**
 * LinkRouteHandler — routes /links/* (JWT requis)
 *
 *   POST   /links           → créer un lien
 *   GET    /links?type=&id=  → liens entrants + sortants d'une entité
 *   DELETE /links/{id}       → supprimer un lien (owner-scoped)
 */
class LinkRouteHandler extends BaseRouteHandler
{
    protected function getSupportedControllers(): array
    {
        return ['links'];
    }

    protected function handleRoute(array $request): void
    {
        $user   = $request['user'];
        $method = $request['method'] ?? 'GET';
        $segs   = $request['segments'] ?? [];
        $s1     = $segs[1] ?? ''; // {id} pour DELETE

        if ($s1 === '') {
            match ($method) {
                'GET'  => (new LinkController())->listForEntity($user),
                'POST' => (new LinkController())->create($user),
                default => Response::error('Méthode non autorisée', null, 405),
            };
            return;
        }

        if (!$this->validateNumericId($s1, 'link id')) {
            return;
        }

        if ($method === 'DELETE') {
            (new LinkController())->delete($user, (int) $s1);
            return;
        }

        Response::error('Endpoint non trouvé', null, 404);
    }
}
