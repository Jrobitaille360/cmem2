<?php

namespace Items\Routing;

use AuthGroups\Routing\BaseRouteHandler;
use AuthGroups\Utils\Response;
use Items\Controllers\ItemController;
use Items\Controllers\ItemShareController;

/**
 * ItemRouteHandler — routes /items/*
 *
 * Arbre de dispatch :
 *
 *   GET    /items                            → ItemController::list
 *   POST   /items                            → ItemController::create
 *   GET    /items/categories                 → ItemController::listCategories
 *   GET    /items/categories/{name}          → ItemController::byCategory
 *   GET    /items/{id}                       → ItemController::show
 *   PUT    /items/{id}                       → ItemController::update
 *   DELETE /items/{id}                       → ItemController::delete
 *   PUT    /items/{id}/access                → ItemShareController::changeAccess
 *   GET    /items/{id}/shares                → ItemShareController::listShares
 *   POST   /items/{id}/shares                → ItemShareController::addShare
 *   PUT    /items/{id}/shares/{target_user}  → ItemShareController::updateShare
 *   DELETE /items/{id}/shares/{target_user}  → ItemShareController::removeShare
 *
 * Note : le segment "categories" est testé AVANT de tenter un cast numérique
 * pour éviter la collision de routes.
 */
class ItemRouteHandler extends BaseRouteHandler
{
    // Auth résolue de façon optionnelle : JWT si présent, null sinon.
    // GET /items/{id} est accessible sans JWT pour les items public.
    // Toutes les routes d'écriture vérifient $user !== null avant de continuer.
    protected bool $requiresAuth = false;

    protected function getMiddlewares(): array
    {
        return [
            function(array $request): array {
                $request['user'] = $this->authService?->authenticate();
                return $request;
            }
        ];
    }

    protected function getSupportedControllers(): array
    {
        return ['items'];
    }

    private function requireAuth(?array $user): bool
    {
        if ($user === null) {
            Response::error('Utilisateur non authentifié', null, 401);
            return false;
        }
        return true;
    }

    protected function handleRoute(array $request): void
    {
        $user   = $request['user'];   // ?array — null si pas de JWT valide
        $method = $request['method'] ?? 'GET';
        $segs   = $request['segments'] ?? [];

        // segs[0] = 'items'
        $s1 = $segs[1] ?? '';   // id | 'categories' | ''
        $s2 = $segs[2] ?? '';   // 'shares' | 'access' | nom de catégorie | ''
        $s3 = $segs[3] ?? '';   // target_user_id | ''

        // ------------------------------------------------------------------
        // GET  /items
        // POST /items
        // ------------------------------------------------------------------
        if ($s1 === '') {
            if (!$this->requireAuth($user)) return;
            match ($method) {
                'GET'  => (new ItemController())->list($user),
                'POST' => (new ItemController())->create($user),
                default => Response::error('Méthode non autorisée', null, 405),
            };
            return;
        }

        // ------------------------------------------------------------------
        // /items/categories[/{name}]
        // Doit être testé AVANT le cast numérique de $s1
        // ------------------------------------------------------------------
        if ($s1 === 'categories') {
            if (!$this->requireAuth($user)) return;
            if ($method !== 'GET') {
                Response::error('Méthode non autorisée', null, 405);
                return;
            }
            if ($s2 === '') {
                (new ItemController())->listCategories($user);
            } else {
                (new ItemController())->byCategory($user, urldecode($s2));
            }
            return;
        }

        // ------------------------------------------------------------------
        // Routes numériques : /items/{id}[/shares|access[/{user_id}]]
        // ------------------------------------------------------------------
        if (!is_numeric($s1)) {
            Response::error('Endpoint non trouvé', null, 404);
            return;
        }

        $itemId = (int) $s1;

        // /items/{id}
        if ($s2 === '') {
            match ($method) {
                // GET /items/{id} : accessible sans JWT pour les items public
                'GET'    => (new ItemController())->show($user, $itemId),
                'PUT'    => $this->requireAuth($user) ? (new ItemController())->update($user, $itemId) : null,
                'DELETE' => $this->requireAuth($user) ? (new ItemController())->delete($user, $itemId) : null,
                default  => Response::error('Méthode non autorisée', null, 405),
            };
            return;
        }

        // /items/{id}/access
        if ($s2 === 'access') {
            if (!$this->requireAuth($user)) return;
            if ($method === 'PUT') {
                (new ItemShareController())->changeAccess($user, $itemId);
            } else {
                Response::error('Méthode non autorisée', null, 405);
            }
            return;
        }

        // /items/{id}/shares[/{target_user_id}]
        if ($s2 === 'shares') {
            if (!$this->requireAuth($user)) return;
            if ($s3 === '') {
                match ($method) {
                    'GET'  => (new ItemShareController())->listShares($user, $itemId),
                    'POST' => (new ItemShareController())->addShare($user, $itemId),
                    default => Response::error('Méthode non autorisée', null, 405),
                };
                return;
            }

            if (!is_numeric($s3)) {
                Response::error('user_id doit être numérique', null, 400);
                return;
            }
            $targetId = (int) $s3;
            match ($method) {
                'PUT'    => (new ItemShareController())->updateShare($user, $itemId, $targetId),
                'DELETE' => (new ItemShareController())->removeShare($user, $itemId, $targetId),
                default  => Response::error('Méthode non autorisée', null, 405),
            };
            return;
        }

        Response::error('Endpoint non trouvé', null, 404);
    }
}
