<?php

namespace Items\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * Model ItemUserAccess — table `item_user_access`
 *
 * Gère les relations de partage : qui peut lire/modifier quel item.
 */
class ItemUserAccess extends BaseModel
{
    protected $table = 'item_user_access';

    public $id;
    public $item_id;
    public $user_id;
    public $can_update;
    public $created_at;

    // ---------------------------------------------------------------
    // Méthodes abstraites requises par BaseModel
    // (non utilisées directement — on passe par upsert)
    // ---------------------------------------------------------------

    public function create()
    {
        return $this->upsert((int) $this->item_id, (int) $this->user_id, (int) $this->can_update);
    }

    public function update()
    {
        return $this->upsert((int) $this->item_id, (int) $this->user_id, (int) $this->can_update);
    }

    // ---------------------------------------------------------------
    // Lecture
    // ---------------------------------------------------------------

    /**
     * Retourne toutes les relations de partage d'un item.
     */
    public function findByItem(int $itemId): array
    {
        $stmt = $this->getDb()->prepare(
            'SELECT iua.*, u.name AS user_name, u.email AS user_email
             FROM item_user_access iua
             JOIN users u ON u.id = iua.user_id
             WHERE iua.item_id = ?
             ORDER BY iua.created_at ASC'
        );
        $stmt->execute([$itemId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retourne la relation pour un item + user donné, ou null si absente.
     */
    public function findByItemAndUser(int $itemId, int $userId): ?array
    {
        $stmt = $this->getDb()->prepare(
            'SELECT * FROM item_user_access WHERE item_id = ? AND user_id = ?'
        );
        $stmt->execute([$itemId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ---------------------------------------------------------------
    // Écriture
    // ---------------------------------------------------------------

    /**
     * Insère ou met à jour la relation item/user.
     */
    public function upsert(int $itemId, int $userId, int $canUpdate): bool
    {
        $stmt = $this->getDb()->prepare(
            'INSERT INTO item_user_access (item_id, user_id, can_update, created_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE can_update = VALUES(can_update)'
        );
        $stmt->execute([$itemId, $userId, $canUpdate]);
        return true;
    }

    /**
     * Supprime la relation item/user.
     */
    public function deleteRelation(int $itemId, int $userId): bool
    {
        $stmt = $this->getDb()->prepare(
            'DELETE FROM item_user_access WHERE item_id = ? AND user_id = ?'
        );
        $stmt->execute([$itemId, $userId]);
        return $stmt->rowCount() > 0;
    }
}
