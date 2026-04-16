<?php

namespace Items\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Items\Models\Item;
use Items\Services\ItemAccessService;

/**
 * ItemController — CRUD principal et endpoints catégories.
 *
 * Routes :
 *  GET    /items                  → list()
 *  POST   /items                  → create()
 *  GET    /items/categories       → listCategories()
 *  GET    /items/categories/{name} → byCategory()
 *  GET    /items/{id}             → show()
 *  PUT    /items/{id}             → update()
 *  DELETE /items/{id}             → delete()
 */
class ItemController
{
    private Item $model;
    private ItemAccessService $access;

    public function __construct()
    {
        $this->model  = new Item();
        $this->access = new ItemAccessService();
    }

    // ---------------------------------------------------------------
    // GET /items
    // ---------------------------------------------------------------

    public function list(array $user): void
    {
        LoggingMiddleware::logEntry();
        $p = Response::getRequestParams();

        $filters = [
            'owner'          => in_array($p['owner'] ?? 'me', ['me', 'all'], true) ? ($p['owner'] ?? 'me') : 'me',
            'access'         => $p['access'] ?? null,
            'category_match' => ($p['category_match'] ?? 'any') === 'all' ? 'all' : 'any',
            'limit'          => (int) ($p['limit']  ?? 50),
            'offset'         => (int) ($p['offset'] ?? 0),
        ];

        // Catégories : accepte virgule-separated OU tableau
        if (!empty($p['category'])) {
            $raw = is_array($p['category']) ? $p['category'] : explode(',', $p['category']);
            $filters['categories'] = array_values(array_filter(array_map('trim', $raw)));
        }

        $items = $this->model->findAccessibleByUser((int) $user['user_id'], $filters);
        Response::success('Items récupérés', ['items' => $items, 'count' => count($items)]);
    }

    // ---------------------------------------------------------------
    // POST /items
    // ---------------------------------------------------------------

    public function create(array $user): void
    {
        LoggingMiddleware::logEntry();
        $p = Response::getRequestParams();

        $access = $p['access'] ?? 'private';
        if (!in_array($access, ['private', 'public', 'share'], true)) {
            Response::error('access doit être private, public ou share', null, 422);
            return;
        }

        $categoriesRaw = $p['categories'] ?? [];
        if (is_string($categoriesRaw)) {
            $categoriesRaw = json_decode($categoriesRaw, true) ?? [];
        }
        $categoriesJson = empty($categoriesRaw) ? null : json_encode(array_values($categoriesRaw));

        $jsonItem = null;
        if (isset($p['json_item'])) {
            $jsonItem = is_string($p['json_item']) ? $p['json_item'] : json_encode($p['json_item']);
        }

        $id = $this->model->createItem([
            'owner_user_id' => (int) $user['user_id'],
            'access'        => $access,
            'categories'    => $categoriesJson,
            'json_item'     => $jsonItem,
        ]);

        $item = $this->model->findItemById($id);
        Response::success('Item créé', ['item' => $this->model->decodeRow($item)], 201);
    }

    // ---------------------------------------------------------------
    // GET /items/categories
    // ---------------------------------------------------------------

    public function listCategories(array $user): void
    {
        LoggingMiddleware::logEntry();
        $cats = $this->model->findDistinctCategories((int) $user['user_id']);
        Response::success('Catégories récupérées', ['categories' => $cats]);
    }

    // ---------------------------------------------------------------
    // GET /items/categories/{name}
    // ---------------------------------------------------------------

    public function byCategory(array $user, string $name): void
    {
        LoggingMiddleware::logEntry();
        $name = trim($name);
        if ($name === '') {
            Response::error('Nom de catégorie requis', null, 400);
            return;
        }

        $p = Response::getRequestParams();

        $filters = [
            'owner'          => 'all',
            'categories'     => [$name],
            'category_match' => 'any',
            'limit'          => (int) ($p['limit']  ?? 50),
            'offset'         => (int) ($p['offset'] ?? 0),
        ];

        $items = $this->model->findAccessibleByUser((int) $user['user_id'], $filters);
        Response::success('Items par catégorie', ['items' => $items, 'count' => count($items)]);
    }

    // ---------------------------------------------------------------
    // GET /items/{id}
    // ---------------------------------------------------------------

    public function show(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $item = $this->model->findItemById($id);
        if (!$item) {
            Response::error('Item non trouvé', null, 404);
            return;
        }
        if (!$this->access->canRead($user, $item)) {
            Response::error('Accès non autorisé', null, 403);
            return;
        }
        Response::success('Item récupéré', ['item' => $this->model->decodeRow($item)]);
    }

    // ---------------------------------------------------------------
    // PUT /items/{id}
    // ---------------------------------------------------------------

    public function update(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $item = $this->model->findItemById($id);
        if (!$item) {
            Response::error('Item non trouvé', null, 404);
            return;
        }
        if (!$this->access->canUpdate($user, $item)) {
            Response::error('Accès non autorisé', null, 403);
            return;
        }

        $p    = Response::getRequestParams();
        $data = [];

        if (array_key_exists('categories', $p)) {
            $cats = $p['categories'];
            if (is_string($cats)) {
                $cats = json_decode($cats, true) ?? [];
            }
            $data['categories'] = empty($cats) ? null : json_encode(array_values($cats));
        }
        if (array_key_exists('json_item', $p)) {
            $ji = $p['json_item'];
            $data['json_item'] = is_string($ji) ? $ji : json_encode($ji);
        }

        if (empty($data)) {
            Response::error('Aucune donnée à mettre à jour', null, 422);
            return;
        }

        $this->model->updateItem($id, $data);
        $updated = $this->model->findItemById($id);
        Response::success('Item mis à jour', ['item' => $this->model->decodeRow($updated)]);
    }

    // ---------------------------------------------------------------
    // DELETE /items/{id}
    // ---------------------------------------------------------------

    public function delete(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $item = $this->model->findItemById($id);
        if (!$item) {
            Response::error('Item non trouvé', null, 404);
            return;
        }
        if (!$this->access->canDelete($user, $item)) {
            Response::error('Accès non autorisé', null, 403);
            return;
        }
        $this->model->softDeleteItem($id);
        Response::success('Item supprimé', null, 204);
    }
}
