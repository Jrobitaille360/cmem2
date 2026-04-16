<?php

namespace Items\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * Model Item — table `items`
 *
 * Gère les items avec contrôle d'accès (private/public/share),
 * catégories JSON et blob json_item arbitraire.
 */
class Item extends BaseModel
{
    protected $table = 'items';

    public $id;
    public $owner_user_id;
    public $access;
    public $categories;
    public $json_item;
    public $created_at;
    public $updated_at;
    public $deleted_at;

    // ---------------------------------------------------------------
    // Méthodes abstraites requises par BaseModel
    // ---------------------------------------------------------------

    public function create()
    {
        $stmt = $this->getDb()->prepare(
            'INSERT INTO items (owner_user_id, access, categories, json_item, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $this->owner_user_id,
            $this->access,
            $this->categories,
            $this->json_item,
        ]);
        $this->id = (int) $this->getDb()->lastInsertId();
        return $this->id;
    }

    public function update()
    {
        $stmt = $this->getDb()->prepare(
            'UPDATE items SET access=?, categories=?, json_item=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL'
        );
        $stmt->execute([$this->access, $this->categories, $this->json_item, $this->id]);
        return $stmt->rowCount();
    }

    // ---------------------------------------------------------------
    // Création explicite (retourne l'id)
    // ---------------------------------------------------------------

    /**
     * Crée un item et retourne son id.
     *
     * @param array $data  ['owner_user_id', 'access', 'categories', 'json_item']
     */
    public function createItem(array $data): int
    {
        $stmt = $this->getDb()->prepare(
            'INSERT INTO items (owner_user_id, access, categories, json_item, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $data['owner_user_id'],
            $data['access'] ?? 'private',
            $data['categories'] ?? null,
            $data['json_item']   ?? null,
        ]);
        return (int) $this->getDb()->lastInsertId();
    }

    // ---------------------------------------------------------------
    // Lecture
    // ---------------------------------------------------------------

    /**
     * Trouve un item par id (soft-delete guard).
     */
    public function findItemById(int $id): ?array
    {
        $stmt = $this->getDb()->prepare(
            'SELECT * FROM items WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Liste les items accessibles pour un utilisateur donné.
     *
     * Logique :
     *  - access=private  → owner uniquement
     *  - access=share    → owner OU user présent dans item_user_access
     *  - access=public   → tous
     *
     * Filtres optionnels (tableau $filters) :
     *  - access   : 'private'|'public'|'share'
     *  - owner    : 'me' (défaut) | 'all'
     *  - categories : tableau de chaînes (filtre OR par défaut, AND si category_match=all)
     *  - category_match : 'any' (défaut) | 'all'
     *  - limit    : int (défaut 50)
     *  - offset   : int (défaut 0)
     *
     * @return array  Liste d'items avec json_item et categories décodés
     */
    public function findAccessibleByUser(int $userId, array $filters = []): array
    {
        $ownerMode     = $filters['owner']          ?? 'me';
        $filterAccess  = $filters['access']          ?? null;
        $categories    = $filters['categories']      ?? [];
        $categoryMatch = $filters['category_match']  ?? 'any';
        $limit         = min((int) ($filters['limit']  ?? 50), 200);
        $offset        = max((int) ($filters['offset'] ?? 0), 0);

        $params = [];
        $where  = ['i.deleted_at IS NULL'];

        // Contrainte owner
        if ($ownerMode === 'me') {
            $where[]  = 'i.owner_user_id = ?';
            $params[] = $userId;
        } else {
            // all = items propres + items share/public accessibles
            $where[]  = '(
                i.owner_user_id = ?
                OR i.access = \'public\'
                OR (i.access = \'share\' AND EXISTS (
                    SELECT 1 FROM item_user_access iua
                    WHERE iua.item_id = i.id AND iua.user_id = ?
                ))
            )';
            $params[] = $userId;
            $params[] = $userId;
        }

        // Filtre access
        if ($filterAccess && in_array($filterAccess, ['private', 'public', 'share'], true)) {
            $where[]  = 'i.access = ?';
            $params[] = $filterAccess;
        }

        // Filtre catégories
        if (!empty($categories)) {
            $catClauses = [];
            foreach ($categories as $cat) {
                $catClauses[] = 'JSON_CONTAINS(i.categories, JSON_QUOTE(?))';
                $params[]     = $cat;
            }
            $glue     = ($categoryMatch === 'all') ? ' AND ' : ' OR ';
            $where[]  = '(' . implode($glue, $catClauses) . ')';
        }

        $whereSQL = implode(' AND ', $where);

        $sql = "SELECT i.* FROM items i
                WHERE {$whereSQL}
                ORDER BY i.created_at DESC
                LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'decodeRow'], $rows);
    }

    // ---------------------------------------------------------------
    // Mise à jour
    // ---------------------------------------------------------------

    /**
     * Met à jour categories et/ou json_item d'un item existant.
     *
     * @param int   $id
     * @param array $data  Clés optionnelles : 'categories', 'json_item'
     */
    public function updateItem(int $id, array $data): bool
    {
        $sets   = [];
        $params = [];

        if (array_key_exists('categories', $data)) {
            $sets[]   = 'categories = ?';
            $params[] = $data['categories'];
        }
        if (array_key_exists('json_item', $data)) {
            $sets[]   = 'json_item = ?';
            $params[] = $data['json_item'];
        }
        if (array_key_exists('access', $data)) {
            $sets[]   = 'access = ?';
            $params[] = $data['access'];
        }

        if (empty($sets)) {
            return false;
        }

        $sets[]   = 'updated_at = NOW()';
        $params[] = $id;

        $sql = 'UPDATE items SET ' . implode(', ', $sets) . ' WHERE id = ? AND deleted_at IS NULL';
        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    // ---------------------------------------------------------------
    // Suppression (soft-delete)
    // ---------------------------------------------------------------

    public function softDeleteItem(int $id): bool
    {
        $stmt = $this->getDb()->prepare(
            'UPDATE items SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    // ---------------------------------------------------------------
    // Catégories
    // ---------------------------------------------------------------

    /**
     * Retourne toutes les catégories distinctes des items accessibles à un user,
     * triées alphabétiquement.
     *
     * Compatible MySQL 8+ (JSON_TABLE) et MariaDB/MySQL 5.7 (extraction PHP).
     */
    public function findDistinctCategories(int $userId): array
    {
        // Récupérer tous les champs categories des items accessibles
        $sql = "SELECT i.categories
                FROM items i
                WHERE i.deleted_at IS NULL
                  AND i.categories IS NOT NULL
                  AND (
                      i.owner_user_id = ?
                      OR i.access = 'public'
                      OR (i.access = 'share' AND EXISTS (
                          SELECT 1 FROM item_user_access iua
                          WHERE iua.item_id = i.id AND iua.user_id = ?
                      ))
                  )";

        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute([$userId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $all = [];
        foreach ($rows as $jsonStr) {
            if (!$jsonStr) continue;
            $cats = json_decode($jsonStr, true);
            if (is_array($cats)) {
                foreach ($cats as $cat) {
                    if (is_string($cat) && $cat !== '') {
                        $all[$cat] = true;
                    }
                }
            }
        }

        $result = array_keys($all);
        sort($result);
        return $result;
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Décode categories et json_item depuis JSON vers tableau PHP.
     */
    public function decodeRow(array $row): array
    {
        if (isset($row['categories']) && is_string($row['categories'])) {
            $row['categories'] = json_decode($row['categories'], true) ?? [];
        }
        if (isset($row['json_item']) && is_string($row['json_item'])) {
            $row['json_item'] = json_decode($row['json_item'], true);
        }
        return $row;
    }
}
