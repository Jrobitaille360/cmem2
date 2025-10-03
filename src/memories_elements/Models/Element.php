<?php

namespace Memories\Models;

use PDO;

/**
 * Modèle Element simplifié utilisant Database::getInstance()
 * Version simplifiée sans injection de dépendance
 */
class Element extends BaseModel {
    protected $table = 'elements';
    
    // Propriétés basées sur le nouveau schéma
    public $title;
    public $filename;
    public $owner_id;
    public $content;
    public $media_type;
    public $visibility;
    public $created_at;
    public $updated_at;

    /**
     * Créer un nouvel élément
     */
    public function create() {
        $query = "INSERT INTO {$this->table} 
                 (title, filename, owner_id, content, media_type, visibility) 
                 VALUES (:title, :filename, :owner_id, :content, :media_type, :visibility)";
        
        $stmt = $this->getDb()->prepare($query);
        
        // Nettoyage des données
        $this->title = htmlspecialchars(strip_tags($this->title));
        $this->filename = $this->filename ? htmlspecialchars(strip_tags($this->filename)) : null;
        $this->content = htmlspecialchars($this->content, ENT_NOQUOTES);
        $this->media_type = $this->media_type ?? 'text';
        $this->visibility = $this->visibility ?? 'private';
        
        // Liaison des paramètres
        $stmt->bindParam(':title', $this->title);
        $stmt->bindParam(':filename', $this->filename);
        $stmt->bindParam(':owner_id', $this->owner_id);
        $stmt->bindParam(':content', $this->content);
        $stmt->bindParam(':media_type', $this->media_type);
        $stmt->bindParam(':visibility', $this->visibility);
        
        if ($stmt->execute()) {
            $this->id = $this->getDb()->lastInsertId();
            return true;
        }
        return false;
    }

    /**
     * Trouver un élément par ID
     */
    public function findById($id, $withTrashed = false) {
        $query = "SELECT * FROM {$this->table} WHERE id = :id";
        
        if (!$withTrashed) {
            $query .= " AND deleted_at IS NULL";
        }
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Mettre à jour un élément
     */
    public function update() {
        $query = "UPDATE {$this->table} SET 
                 title = :title,
                 filename = :filename,
                 content = :content,
                 media_type = :media_type,
                 visibility = :visibility,
                 updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->getDb()->prepare($query);
        
        // Nettoyage des données
        $this->title = htmlspecialchars(strip_tags($this->title));
        $this->filename = $this->filename ? htmlspecialchars(strip_tags($this->filename)) : null;
        $this->content = htmlspecialchars($this->content, ENT_NOQUOTES);
        
        $stmt->bindParam(':id', $this->id);
        $stmt->bindParam(':title', $this->title);
        $stmt->bindParam(':filename', $this->filename);
        $stmt->bindParam(':content', $this->content);
        $stmt->bindParam(':media_type', $this->media_type);
        $stmt->bindParam(':visibility', $this->visibility);
        
        return $stmt->execute();
    }

    public function getAccessibleElements($userId, $limit = 20, $offset = 0) {
        $query = "SELECT e.*, u.name as owner_name,
                         CASE 
                             WHEN e.owner_id = :user_id1 THEN 1
                             WHEN e.visibility = 'public' THEN 1  
                             WHEN e.visibility = 'shared' AND EXISTS (
                                 SELECT 1 FROM memory_element_relations mer 
                                 INNER JOIN memories m ON mer.memory_id = m.id 
                                 INNER JOIN memory_group_relations mgr ON m.id = mgr.memory_id
                                 INNER JOIN group_members gm ON mgr.group_id = gm.group_id 
                                 WHERE mer.element_id = e.id 
                                 AND gm.user_id = :user_id2 
                                 AND mer.deleted_at IS NULL 
                                 AND m.deleted_at IS NULL
                                 AND mgr.deleted_at IS NULL 
                                 AND gm.deleted_at IS NULL
                             ) THEN 1
                             ELSE 0
                         END as can_access
                 FROM {$this->table} e
                 LEFT JOIN users u ON e.owner_id = u.id
                 WHERE e.deleted_at IS NULL 
                 AND u.deleted_at IS NULL
                 HAVING can_access = 1
                 ORDER BY e.created_at DESC 
                 LIMIT :limit OFFSET :offset";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':user_id1', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id2', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtenir tous les éléments d'un utilisateur
     */
    public function getByOwnerId($ownerId, $limit = 20, $offset = 0) {
        $query = "SELECT * FROM {$this->table} 
                 WHERE owner_id = :owner_id 
                 AND deleted_at IS NULL 
                 ORDER BY created_at DESC 
                 LIMIT :limit OFFSET :offset";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':owner_id', $ownerId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtenir les éléments publics
     */
    public function getPublicElements($limit = 20, $offset = 0) {
        $query = "SELECT e.*, u.name as owner_name
                 FROM {$this->table} e 
                 LEFT JOIN users u ON e.owner_id = u.id
                 WHERE e.visibility = 'public' 
                 AND e.deleted_at IS NULL 
                 AND u.deleted_at IS NULL
                 ORDER BY e.created_at DESC 
                 LIMIT :limit OFFSET :offset";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtenir les éléments par type de média
     */
    public function getByMediaType($mediaType, $ownerId = null, $limit = 20, $offset = 0) {
        $query = "SELECT * FROM {$this->table} 
                 WHERE media_type = :media_type 
                 AND deleted_at IS NULL";
        
        $params = [':media_type' => $mediaType];
        
        if ($ownerId) {
            $query .= " AND owner_id = :owner_id";
            $params[':owner_id'] = $ownerId;
        } else {
            $query .= " AND visibility = 'public'";
        }
        
        $query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->getDb()->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Rechercher des éléments par titre ou contenu
     */
    public function search($searchTerm, $ownerId = null, $limit = 20) {
        $searchPattern = "%{$searchTerm}%";
        
        $query = "SELECT e.*, u.name as owner_name
                 FROM {$this->table} e 
                 LEFT JOIN users u ON e.owner_id = u.id
                 WHERE (e.title LIKE :search1 OR e.content LIKE :search2) 
                 AND e.deleted_at IS NULL 
                 AND u.deleted_at IS NULL";
        
        $params = [
            ':search1' => $searchPattern,
            ':search2' => $searchPattern
        ];
        
        if ($ownerId) {
            $query .= " AND e.owner_id = :owner_id";
            $params[':owner_id'] = $ownerId;
        } else {
            $query .= " AND e.visibility = 'public'";
        }
        
        $query .= " ORDER BY e.title ASC LIMIT :limit";
        
        $stmt = $this->getDb()->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtenir les éléments associés à une mémoire
     */
    public function getByMemoryId($memoryId, $limit = 50, $offset = 0) {
        $query = "SELECT e.*, mer.position, mer.created_at as associated_at
                 FROM {$this->table} e
                 INNER JOIN memory_element_relations mer ON e.id = mer.element_id
                 WHERE mer.memory_id = :memory_id 
                 AND e.deleted_at IS NULL 
                 AND mer.deleted_at IS NULL
                 ORDER BY mer.position ASC, mer.created_at ASC
                 LIMIT :limit OFFSET :offset";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':memory_id', $memoryId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Associer un élément à une mémoire
     */
    public function associateWithMemory($elementId, $memoryId, $position = null) {
        // Vérifier si l'association existe déjà
        $checkQuery = "SELECT COUNT(*) as count FROM memory_elements 
                      WHERE memory_id = :memory_id 
                      AND element_id = :element_id 
                      AND deleted_at IS NULL";
        
        $stmt = $this->getDb()->prepare($checkQuery);
        $stmt->bindParam(':memory_id', $memoryId, PDO::PARAM_INT);
        $stmt->bindParam(':element_id', $elementId, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['count'] > 0) {
            return false; // Association déjà existante
        }

        // Si aucune position spécifiée, prendre la suivante
        if ($position === null) {
            $posQuery = "SELECT COALESCE(MAX(position), 0) + 1 as next_position 
                        FROM memory_elements 
                        WHERE memory_id = :memory_id AND deleted_at IS NULL";
            
            $posStmt = $this->getDb()->prepare($posQuery);
            $posStmt->bindParam(':memory_id', $memoryId, PDO::PARAM_INT);
            $posStmt->execute();
            
            $posResult = $posStmt->fetch(PDO::FETCH_ASSOC);
            $position = $posResult['next_position'];
        }

        $query = "INSERT INTO memory_elements (memory_id, element_id, position) 
                 VALUES (:memory_id, :element_id, :position)";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':memory_id', $memoryId, PDO::PARAM_INT);
        $stmt->bindParam(':element_id', $elementId, PDO::PARAM_INT);
        $stmt->bindParam(':position', $position, PDO::PARAM_INT);
        
        return $stmt->execute();
    }

    /**
     * Dissocier un élément d'une mémoire
     */
    public function dissociateFromMemory($elementId, $memoryId) {
        $query = "UPDATE memory_elements SET deleted_at = CURRENT_TIMESTAMP
                 WHERE memory_id = :memory_id 
                 AND element_id = :element_id 
                 AND deleted_at IS NULL";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':memory_id', $memoryId, PDO::PARAM_INT);
        $stmt->bindParam(':element_id', $elementId, PDO::PARAM_INT);
        
        return $stmt->execute();
    }

    /**
     * Compter les éléments d'un utilisateur
     */
    public function countByOwner($ownerId) {
        $query = "SELECT COUNT(*) as total FROM {$this->table} 
                 WHERE owner_id = :owner_id AND deleted_at IS NULL";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':owner_id', $ownerId, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$result['total'];
    }

    /**
     * Compter les éléments d'un utilisateur par type de média
     */
    public function countByMediaType($mediaType, $ownerId) {
        $query = "SELECT COUNT(*) as total FROM {$this->table} 
                 WHERE media_type = :media_type 
                 AND owner_id = :owner_id 
                 AND deleted_at IS NULL";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':media_type', $mediaType);
        $stmt->bindParam(':owner_id', $ownerId, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$result['total'];
    }

    /**
     * Vérifier les permissions d'accès à un élément
     */
    public function canAccess($elementId, $userId) {
        $query = "SELECT e.*, 
                         CASE 
                             WHEN e.owner_id = :user_id1 THEN 1
                             WHEN e.visibility = 'public' THEN 1  
                             WHEN e.visibility = 'shared' AND EXISTS (
                                 SELECT 1 FROM memory_element_relations mer 
                                 INNER JOIN memories m ON mer.memory_id = m.id 
                                 INNER JOIN memory_group_relations mgr ON m.id = mgr.memory_id
                                 INNER JOIN group_members gm ON mgr.group_id = gm.group_id 
                                 WHERE mer.element_id = e.id 
                                 AND gm.user_id = :user_id2 
                                 AND mer.deleted_at IS NULL 
                                 AND m.deleted_at IS NULL
                                 AND mgr.deleted_at IS NULL 
                                 AND gm.deleted_at IS NULL
                             ) THEN 1
                             ELSE 0
                         END as can_access
                 FROM {$this->table} e
                 WHERE e.id = :element_id 
                 AND e.deleted_at IS NULL";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':element_id', $elementId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id1', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id2', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        $element = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$element) return false;
        
        // L'utilisateur peut accéder si :
        // 1. C'est le propriétaire  
        // 2. L'élément est public
        // 3. L'élément est partagé ET l'utilisateur est membre d'un groupe qui a accès via une mémoire
        return (bool)$element['can_access'];
    }

    public function associateFile($elementId, $fileId) {
        $query = "UPDATE {$this->table} SET file_id = :file_id WHERE id = :element_id AND deleted_at IS NULL";       
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':file_id', $fileId, PDO::PARAM_INT);
        $stmt->bindParam(':element_id', $elementId, PDO::PARAM_INT);     
        return $stmt->execute();
    }

    public function disassociateFile($elementId) {
        $query = "UPDATE {$this->table} SET file_id = NULL WHERE id = :element_id AND deleted_at IS NULL";       
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':element_id', $elementId, PDO::PARAM_INT);     
        return $stmt->execute();
    }
    public function toArray() {
        return get_object_vars($this);
    }
    public function hydrate(array $data) {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

}
