<?php

namespace Memories\Models;

use PDO;

class Tag extends BaseModel {
    protected $table = 'tags';
    
    // Propriétés basées sur le nouveau schéma
    public $name;
    public $table_associate;
    public $color;
    public $tag_owner; // Propriété manquante ajoutée
    
    public function __construct() {
        parent::__construct();
    }
    
    /**
     * Créer un nouveau tag
     */
    public function create() {
        $query = "INSERT INTO {$this->table} 
                 (name, table_associate, color, tag_owner) 
                 VALUES (:name, :table_associate, :color, :tag_owner)";
        
        $stmt = $this->getDb()->prepare($query);
        
        // Nettoyage des données
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->table_associate = $this->table_associate ?? 'memories';
        $this->color = $this->color ?? '#3498db';
        
        // Validation que tag_owner est défini
        if (empty($this->tag_owner)) {
            throw new \InvalidArgumentException("tag_owner est requis pour créer un tag");
        }
        
        // Liaison des paramètres
        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':table_associate', $this->table_associate);
        $stmt->bindParam(':color', $this->color);
        $stmt->bindParam(':tag_owner', $this->tag_owner, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            $this->id = $this->getDb()->lastInsertId();
            return true;
        }
        return false;
    }
    
    /**
     * Mettre à jour un tag
     */
    public function update() {
        $query = "UPDATE {$this->table} 
                 SET name = :name, color = :color, table_associate = :table_associate 
                 WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->getDb()->prepare($query);
        
        $this->name = htmlspecialchars(strip_tags($this->name));
        
        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':color', $this->color);
        $stmt->bindParam(':table_associate', $this->table_associate);
        $stmt->bindParam(':id', $this->id);
        
        return $stmt->execute();
    }

    public function exists() {
        $name = htmlspecialchars(strip_tags($this->name));
        $tagOwner = $this->tag_owner;
        $tableAssociate = $this->table_associate;
        $query = "SELECT COUNT(*) as count FROM {$this->table} WHERE name = :name";
        if ($name === null) {
            throw new \InvalidArgumentException("Le nom du tag est requis pour vérifier l'existence");
        }
        if ($tagOwner !== null) {
            $query .= " AND tag_owner = :tag_owner";
        }
        if ($tableAssociate !== null) {
            $query .= " AND table_associate = :table_associate";
        }
        
        // Utiliser la méthode du trait pour exclure les supprimés
        $query = self::withoutDeleted($query);
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':name', $name);
        if ($tagOwner !== null) {
            $stmt->bindParam(':tag_owner', $tagOwner, PDO::PARAM_INT);
        }
        if ($tableAssociate !== null) {
            $stmt->bindParam(':table_associate', $tableAssociate);
        }

        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }
    
    /**
     * Trouver un tag par nom et propriétaire
     */
    public function findByName($name, $tagOwner = null) {
        $query = "SELECT * FROM {$this->table} WHERE name = :name";
        
        if ($tagOwner !== null) {
            $query .= " AND tag_owner = :tag_owner";
        }
        
        // Utiliser la méthode du trait pour exclure les supprimés
        $query = self::withoutDeleted($query);
        $query .= " LIMIT 1";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':name', $name);
        if ($tagOwner !== null) {
            $stmt->bindParam(':tag_owner', $tagOwner, PDO::PARAM_INT);
        }
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->hydrate($row);
            return $row;
        }
        return false;
    }
    
    /**
     * Obtenir tous les tags par table associée et propriétaire
     */
    public function findByTable($tableAssociate, $tagOwner = null, $page = 1, $limit = 50) {
        $offset = ($page - 1) * $limit;
        $query = "SELECT * FROM {$this->table} WHERE (table_associate = :table_associate OR table_associate = 'all')";
        
        if ($tagOwner !== null) {
            $query .= " AND tag_owner = :tag_owner";
        }
        
        $query = self::withoutDeleted($query);
        $query .= " ORDER BY name ASC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':table_associate', $tableAssociate);
        if ($tagOwner !== null) {
            $stmt->bindParam(':tag_owner', $tagOwner, PDO::PARAM_INT);
        }
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtenir tous les tags d'un propriétaire
     */
    public function findByOwner($tagOwner, $page = 1, $limit = 50) {
        $offset = ($page - 1) * $limit;
        $query = "SELECT * FROM {$this->table} WHERE tag_owner = :tag_owner";
        $query = self::withoutDeleted($query);
        $query .= " ORDER BY name ASC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':tag_owner', $tagOwner, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Rechercher des tags
     */
    public function search($searchTerm, $tableAssociate = null, $tagOwner = null, $page = 1, $limit = 20) {
        $offset = ($page - 1) * $limit;
        
        $query = "SELECT * FROM {$this->table}";

        $where = [];
        
        if ($searchTerm) {
            $searchTerm = "%{$searchTerm}%";
            $where[] = "name LIKE :search";
        }

        if ($tableAssociate) {
            $where[] = "(table_associate = :table_associate OR table_associate = 'all')";
        }
        
        if ($tagOwner !== null) {
            $where[] = "tag_owner = :tag_owner";
        }

        if (!empty($where)) {
            $query .= " WHERE " . implode(" AND ", $where);
        }
        
        $query = self::withoutDeleted($query);
        $query .= " ORDER BY name ASC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->getDb()->prepare($query);
        if($searchTerm) {
            $stmt->bindParam(':search', $searchTerm);
        }
        if ($tableAssociate) {
            $stmt->bindParam(':table_associate', $tableAssociate);
        }
        if ($tagOwner !== null) {
            $stmt->bindParam(':tag_owner', $tagOwner, PDO::PARAM_INT);
        }
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtenir ou créer un tag par nom pour un propriétaire spécifique
     */
    public function getOrCreate($name, $tagOwner, $tableAssociate = 'memories', $color = '#3498db') {
        // Essayer de trouver le tag existant pour ce propriétaire
        if ($this->findByName($name, $tagOwner)) {
            return $this->toArray();
        }
        
        // Créer un nouveau tag s'il n'existe pas
        $this->name = $name;
        $this->table_associate = $tableAssociate;
        $this->color = $color;
        $this->tag_owner = $tagOwner;
        
        if ($this->create()) {
            return $this->toArray();
        }
        
        return false;
    }
    
    /**
     * Obtenir les tags les plus utilisés pour un propriétaire
     */
    public function getMostUsed($tableAssociate, $tagOwner = null, $limit = 10) {
        // Pour les tags 'all', on compte les utilisations sur toutes les tables
        if ($tableAssociate === 'all') {
            $query = "SELECT t.*, 
                     COALESCE(
                         (SELECT COUNT(*) FROM memory_tag_relations mr WHERE mr.tag_id = t.id AND mr.deleted_at IS NULL) +
                         (SELECT COUNT(*) FROM element_tag_relations er WHERE er.tag_id = t.id AND er.deleted_at IS NULL) +
                         (SELECT COUNT(*) FROM file_tag_relations fr WHERE fr.tag_id = t.id AND fr.deleted_at IS NULL) +
                         (SELECT COUNT(*) FROM group_tag_relations gr WHERE gr.tag_id = t.id AND gr.deleted_at IS NULL),
                         0
                     ) as usage_count
                     FROM {$this->table} t 
                     WHERE t.table_associate = 'all' AND t.deleted_at IS NULL";
        } else {
            $relationTable = $this->getRelationTable($tableAssociate);
            $query = "SELECT t.*, COUNT(r.tag_id) as usage_count 
                     FROM {$this->table} t 
                     LEFT JOIN {$relationTable} r ON t.id = r.tag_id AND r.deleted_at IS NULL
                     WHERE (t.table_associate = :table_associate OR t.table_associate = 'all') AND t.deleted_at IS NULL";
        }
        
        if ($tagOwner !== null) {
            $query .= " AND t.tag_owner = :tag_owner";
        }
        
        if ($tableAssociate !== 'all') {
            $query .= " GROUP BY t.id";
        }
        
        $query .= " ORDER BY usage_count DESC, t.name ASC 
                   LIMIT :limit";
        
        $stmt = $this->getDb()->prepare($query);
        if ($tableAssociate !== 'all') {
            $stmt->bindParam(':table_associate', $tableAssociate);
        }
        if ($tagOwner !== null) {
            $stmt->bindParam(':tag_owner', $tagOwner, PDO::PARAM_INT);
        }
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtenir le nombre d'utilisations d'un tag
     */
    public function getUsageCount() {
        if($this->table_associate === 'all') {
            // Count tag usage in all related tables (groups, memories, elements, files)
            $count = 0;
            $tables = ['groups', 'memories', 'elements', 'files'];
            foreach ($tables as $table) {
                $relationTable = $this->getRelationTable($table);
                $query = "SELECT COUNT(*) as count FROM {$relationTable} 
                         WHERE tag_id = :tag_id AND deleted_at IS NULL";
                $stmt = $this->getDb()->prepare($query);
                $stmt->bindParam(':tag_id', $this->id, PDO::PARAM_INT);
                $stmt->execute();
                
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $count += $result['count'] ?? 0;
            }
            return $count;
        } else {
            $relationTable = $this->getRelationTable($this->table_associate);
            $query = "SELECT COUNT(*) as count FROM {$relationTable} 
                     WHERE tag_id = :tag_id AND deleted_at IS NULL";
            $stmt = $this->getDb()->prepare($query);
            $stmt->bindParam(':tag_id', $this->id, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] ?? 0;
        }
    }
    
    /**
     * Vérifier si l'utilisateur peut modifier ce tag
     */
    public function canEdit($userId) {
        return $this->tag_owner == $userId;
    }
    
    /**
     * Vérifier si l'utilisateur peut voir ce tag
     */
    public function canView($userId) {
        // Pour l'instant, seul le propriétaire peut voir ses tags
        // Peut être étendu pour inclure les tags partagés
        return $this->tag_owner == $userId;
    }
    
    /**
     * Obtenir la table de relation correspondante
     */
    private function getRelationTable($tableAssociate) {
        switch ($tableAssociate) {
            case 'memories':
                return 'memory_tag_relations';
            case 'elements':
                return 'element_tag_relations';
            case 'files':
                return 'file_tag_relations';
            case 'groups':
                return 'group_tag_relations';
            case 'all':
                // Pour 'all', on ne peut pas utiliser une seule table de relation
                throw new \InvalidArgumentException("Pour table_associate = 'all', utilisez une logique spécifique dans chaque méthode");
            default:
                throw new \InvalidArgumentException("Table associée non valide: {$tableAssociate}");
        }
    }
    
    /**
     * Valider les données du tag
     */
    public function validate() {
        $errors = [];
        
        if (empty($this->name)) {
            $errors[] = "Le nom du tag est requis";
        }
        
        if (strlen($this->name) > 100) {
            $errors[] = "Le nom du tag ne peut pas dépasser 100 caractères";
        }
        
        if (empty($this->tag_owner)) {
            $errors[] = "Le propriétaire du tag est requis";
        }
        
        $validTables = ['groups', 'memories', 'elements', 'files', 'all'];
        if (!empty($this->table_associate) && !in_array($this->table_associate, $validTables)) {
            $errors[] = "Table associée non valide";
        }
        
        if (!empty($this->color) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $this->color)) {
            $errors[] = "Format de couleur non valide (utilisez #RRGGBB)";
        }
        
        return $errors;
    }
    
    /**
     * Hydrater l'objet avec les données de la base
     */
    public function hydrate($data) {
        if (isset($data['id'])) $this->id = $data['id'];
        if (isset($data['name'])) $this->name = $data['name'];
        if (isset($data['table_associate'])) $this->table_associate = $data['table_associate'];
        if (isset($data['color'])) $this->color = $data['color'];
        if (isset($data['tag_owner'])) $this->tag_owner = $data['tag_owner'];
        if (isset($data['created_at'])) $this->created_at = $data['created_at'];
        if (isset($data['updated_at'])) $this->updated_at = $data['updated_at'];
        if (isset($data['deleted_at'])) $this->deleted_at = $data['deleted_at'];
    }
    
    /**
     * Associer un tag à un élément
     */
    public function associateToItem($itemId, $tableAssociate) {
        $relationTable = $this->getRelationTable($tableAssociate);
        $itemColumn = $this->getItemColumnName($tableAssociate);
        
        // Vérifier si l'association existe déjà (même si supprimée)
        $query = "SELECT * FROM {$relationTable} WHERE {$itemColumn} = :item_id AND tag_id = :tag_id";
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':item_id', $itemId, PDO::PARAM_INT);
        $stmt->bindParam(':tag_id', $this->id, PDO::PARAM_INT);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            // L'association existe, la restaurer si elle était supprimée
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row['deleted_at']) {
                $updateQuery = "UPDATE {$relationTable} 
                              SET deleted_at = NULL, updated_at = CURRENT_TIMESTAMP 
                              WHERE {$itemColumn} = :item_id AND tag_id = :tag_id";
                $updateStmt = $this->getDb()->prepare($updateQuery);
                $updateStmt->bindParam(':item_id', $itemId, PDO::PARAM_INT);
                $updateStmt->bindParam(':tag_id', $this->id, PDO::PARAM_INT);
                return $updateStmt->execute();
            }
            // L'association existe déjà et n'est pas supprimée
            return true;
        }
        
        // Créer une nouvelle association
        $insertQuery = "INSERT INTO {$relationTable} ({$itemColumn}, tag_id) VALUES (:item_id, :tag_id)";
        $insertStmt = $this->getDb()->prepare($insertQuery);
        $insertStmt->bindParam(':item_id', $itemId, PDO::PARAM_INT);
        $insertStmt->bindParam(':tag_id', $this->id, PDO::PARAM_INT);
        
        return $insertStmt->execute();
    }
    
    /**
     * Dissocier un tag d'un élément (soft delete)
     */
    public function dissociateFromItem($itemId, $tableAssociate) {
        $relationTable = $this->getRelationTable($tableAssociate);
        $itemColumn = $this->getItemColumnName($tableAssociate);
        
        $query = "UPDATE {$relationTable} 
                  SET deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP 
                  WHERE {$itemColumn} = :item_id AND tag_id = :tag_id AND deleted_at IS NULL";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':item_id', $itemId, PDO::PARAM_INT);
        $stmt->bindParam(':tag_id', $this->id, PDO::PARAM_INT);
        
        return $stmt->execute();
    }
    
    /**
     * Vérifier si un tag est associé à un élément
     */
    public function isAssociatedToItem($itemId, $tableAssociate) {
        $relationTable = $this->getRelationTable($tableAssociate);
        $itemColumn = $this->getItemColumnName($tableAssociate);
        
        $query = "SELECT COUNT(*) as count FROM {$relationTable} 
                  WHERE {$itemColumn} = :item_id AND tag_id = :tag_id AND deleted_at IS NULL";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':item_id', $itemId, PDO::PARAM_INT);
        $stmt->bindParam(':tag_id', $this->id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }
    
    /**
     * Obtenir le nom de la colonne pour l'élément selon le type de table
     */
    private function getItemColumnName($tableAssociate) {
        switch ($tableAssociate) {
            case 'memories':
                return 'memory_id';
            case 'elements':
                return 'element_id';
            case 'files':
                return 'file_id';
            case 'groups':
                return 'group_id';
            default:
                throw new \InvalidArgumentException("Table associée non valide: {$tableAssociate}");
        }
    }

    /**
     * Convertir l'objet en tableau
     */
    public function toArray() {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'table_associate' => $this->table_associate,
            'color' => $this->color,
            'tag_owner' => $this->tag_owner,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at
        ];
    }
}
