<?php

namespace Items\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Items\Models\Item;
use Items\Models\ItemUserAccess;
use Items\Services\ItemAccessService;

/**
 * ItemShareController — gestion des partages et du mode d'accès.
 *
 * Routes (toutes soumises au contrôle canManageShares, sauf listShares) :
 *  GET    /items/{id}/access           → (lecture du mode, inclus dans show)
 *  PUT    /items/{id}/access           → changeAccess()
 *  GET    /items/{id}/shares           → listShares()
 *  POST   /items/{id}/shares           → addShare()
 *  PUT    /items/{id}/shares/{user_id} → updateShare()
 *  DELETE /items/{id}/shares/{user_id} → removeShare()
 */
class ItemShareController
{
    private Item $itemModel;
    private ItemUserAccess $iuaModel;
    private ItemAccessService $access;

    public function __construct()
    {
        $this->itemModel = new Item();
        $this->iuaModel  = new ItemUserAccess();
        $this->access    = new ItemAccessService();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Charge l'item et vérifie qu'il existe (404) et que l'user peut gérer
     * les partages (403). Retourne l'item ou null (réponse déjà envoyée).
     */
    private function loadAndAuthorize(array $user, int $itemId): ?array
    {
        $item = $this->itemModel->findItemById($itemId);
        if (!$item) {
            Response::error('Item non trouvé', null, 404);
            return null;
        }
        if (!$this->access->canManageShares($user, $item)) {
            Response::error('Accès non autorisé — réservé au propriétaire ou à l\'administrateur', null, 403);
            return null;
        }
        return $item;
    }

    private function userExists(int $userId): bool
    {
        $pdo  = \Database::getInstance()->getConnection();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$userId]);
        return (bool) $stmt->fetch();
    }

    // ---------------------------------------------------------------
    // PUT /items/{id}/access
    // ---------------------------------------------------------------

    public function changeAccess(array $user, int $itemId): void
    {
        LoggingMiddleware::logEntry();
        $item = $this->loadAndAuthorize($user, $itemId);
        if (!$item) return;

        $p      = Response::getRequestParams();
        $access = $p['access'] ?? '';

        if (!in_array($access, ['private', 'public', 'share'], true)) {
            Response::error('access doit être private, public ou share', null, 422);
            return;
        }

        $this->itemModel->updateItem($itemId, ['access' => $access]);
        Response::success('Mode d\'accès mis à jour', ['access' => $access]);
    }

    // ---------------------------------------------------------------
    // GET /items/{id}/shares
    // ---------------------------------------------------------------

    public function listShares(array $user, int $itemId): void
    {
        LoggingMiddleware::logEntry();
        $item = $this->itemModel->findItemById($itemId);
        if (!$item) {
            Response::error('Item non trouvé', null, 404);
            return;
        }
        // Lecture des shares : autorisé à quiconque pouvant lire l'item
        if (!$this->access->canRead($user, $item)) {
            Response::error('Accès non autorisé', null, 403);
            return;
        }
        $shares = $this->iuaModel->findByItem($itemId);
        Response::success('Partages récupérés', ['shares' => $shares]);
    }

    // ---------------------------------------------------------------
    // POST /items/{id}/shares
    // ---------------------------------------------------------------

    public function addShare(array $user, int $itemId): void
    {
        LoggingMiddleware::logEntry();
        $item = $this->loadAndAuthorize($user, $itemId);
        if (!$item) return;

        $p         = Response::getRequestParams();
        $targetId  = (int) ($p['user_id']    ?? 0);
        $canUpdate = (int) ($p['can_update']  ?? 0);

        if ($targetId <= 0) {
            Response::error('user_id requis', null, 422);
            return;
        }
        if (!$this->userExists($targetId)) {
            Response::error('Utilisateur introuvable', null, 404);
            return;
        }
        if ((int) $item['owner_user_id'] === $targetId) {
            Response::error('Le propriétaire ne peut pas être ajouté comme invité', null, 422);
            return;
        }

        $this->iuaModel->upsert($itemId, $targetId, $canUpdate ? 1 : 0);
        Response::success('Partage ajouté', ['user_id' => $targetId, 'can_update' => $canUpdate ? 1 : 0], 201);
    }

    // ---------------------------------------------------------------
    // PUT /items/{id}/shares/{target_user_id}
    // ---------------------------------------------------------------

    public function updateShare(array $user, int $itemId, int $targetUserId): void
    {
        LoggingMiddleware::logEntry();
        $item = $this->loadAndAuthorize($user, $itemId);
        if (!$item) return;

        $p         = Response::getRequestParams();
        $canUpdate = (int) ($p['can_update'] ?? 0);

        $rel = $this->iuaModel->findByItemAndUser($itemId, $targetUserId);
        if (!$rel) {
            Response::error('Relation de partage introuvable', null, 404);
            return;
        }

        $this->iuaModel->upsert($itemId, $targetUserId, $canUpdate ? 1 : 0);
        Response::success('Partage mis à jour', ['user_id' => $targetUserId, 'can_update' => $canUpdate ? 1 : 0]);
    }

    // ---------------------------------------------------------------
    // DELETE /items/{id}/shares/{target_user_id}
    // ---------------------------------------------------------------

    public function removeShare(array $user, int $itemId, int $targetUserId): void
    {
        LoggingMiddleware::logEntry();
        $item = $this->loadAndAuthorize($user, $itemId);
        if (!$item) return;

        $deleted = $this->iuaModel->deleteRelation($itemId, $targetUserId);
        if (!$deleted) {
            Response::error('Relation de partage introuvable', null, 404);
            return;
        }
        Response::success('Partage supprimé', null, 200);
    }
}
