<?php

namespace Items\Services;

use Items\Models\ItemUserAccess;

/**
 * ItemAccessService — logique centralisée de contrôle d'accès aux items.
 *
 * Règles :
 *  access=private  → owner et admin uniquement
 *  access=public   → tout utilisateur JWT peut lire et modifier
 *  access=share    → owner + admin ; utilisateurs listés dans item_user_access
 *                    peuvent lire (can_update=0) ou aussi modifier (can_update=1)
 *
 * Changer access / gérer les partages → toujours owner ou admin uniquement.
 */
class ItemAccessService
{
    private ItemUserAccess $iuaModel;

    public function __construct()
    {
        $this->iuaModel = new ItemUserAccess();
    }

    // ---------------------------------------------------------------
    // Helpers internes
    // ---------------------------------------------------------------

    private function isAdmin(?array $user): bool
    {
        return $user !== null && ($user['role'] ?? '') === 'ADMINISTRATEUR';
    }

    private function isOwner(?array $user, array $item): bool
    {
        return $user !== null && (int) $user['user_id'] === (int) $item['owner_user_id'];
    }

    /**
     * Récupère (et met en cache sur l'objet) la relation item/user.
     * Evite les N+1 si les controllers passent la relation déjà chargée.
     */
    private function getRelation(int $itemId, int $userId): ?array
    {
        return $this->iuaModel->findByItemAndUser($itemId, $userId);
    }

    // ---------------------------------------------------------------
    // API publique
    // ---------------------------------------------------------------

    /**
     * L'utilisateur peut-il lire cet item ?
     */
    public function canRead(?array $user, array $item): bool
    {
        // Items public : accessibles sans JWT
        if ($item['access'] === 'public') {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if ($this->isAdmin($user) || $this->isOwner($user, $item)) {
            return true;
        }

        if ($item['access'] === 'share') {
            $rel = $this->getRelation((int) $item['id'], (int) $user['user_id']);
            return $rel !== null;
        }

        // private — non owner, non admin
        return false;
    }

    /**
     * L'utilisateur peut-il mettre à jour le contenu (categories/json_item) ?
     */
    public function canUpdate(array $user, array $item): bool
    {
        if ($this->isAdmin($user) || $this->isOwner($user, $item)) {
            return true;
        }

        $access = $item['access'];

        if ($access === 'public') {
            return true;
        }

        if ($access === 'share') {
            $rel = $this->getRelation((int) $item['id'], (int) $user['user_id']);
            return $rel !== null && (int) $rel['can_update'] === 1;
        }

        return false;
    }

    /**
     * L'utilisateur peut-il supprimer cet item (soft-delete) ?
     */
    public function canDelete(array $user, array $item): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $item);
    }

    /**
     * L'utilisateur peut-il gérer les partages (access + item_user_access) ?
     */
    public function canManageShares(array $user, array $item): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $item);
    }
}
