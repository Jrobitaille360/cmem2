<?php

namespace Memories\Models;

/**
 * Modèle Memory simplifié pour l'API v2
 * Utilise BaseModelSimplified avec Database::getInstance()
 * Architecture simplifiée sans injection PDO
 */
class Memory extends BaseModel {
    
    protected $table = 'memories';
    
    // Propriétés spécifiques aux mémoires (selon schéma DB)
    public $user_id;
    public $title;
    public $content;
    public $visibility;
    public $time_start;
    public $time_end;
    public $location;
    public $latitude;
    public $longitude;
    
    /**
     * Récupérer toutes les mémoires avec filtres
     */
    public function getAll($filters = []) {
        $conditions = ['1=1'];
        $params = [];
        
        // Filtres de base
        if (isset($filters['user_id']) && $filters['user_id']) {
            $conditions[] = 'user_id = :user_id';
            $params[':user_id'] = $filters['user_id'];
        }
        
        // Gestion des permissions pour utilisateurs authentifiés
        if (isset($filters['current_user_id']) && $filters['current_user_id'] && !isset($filters['user_id'])) {
            // Pour un utilisateur authentifié sans filtrage user_id : mémoires publiques + ses mémoires privées
            $conditions[] = '(visibility = :public_visibility OR user_id = :current_user_id)';
            $params[':public_visibility'] = 'public';
            $params[':current_user_id'] = $filters['current_user_id'];
        } elseif (isset($filters['visibility']) && $filters['visibility']) {
            $conditions[] = 'visibility = :visibility';
            $params[':visibility'] = $filters['visibility'];
        }

        if (isset($filters['is_public'])) {
            if ($filters['is_public']) {
                $conditions[] = 'visibility = :visibility';
                $params[':visibility'] = 'public';
            } else {
                $conditions[] = 'visibility != :visibility';
                $params[':visibility'] = 'public';
            }
        }
        
        // Recherche textuelle
        if (!empty($filters['search'])) {
            $conditions[] = '(title LIKE :search1 OR content LIKE :search2)';
            $params[':search1'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
        }
        
        // Soft delete
        $conditions[] = 'deleted_at IS NULL';
        
        // Ordre
        $orderBy = $filters['order_by'] ?? 'created_at';
        $orderDir = $filters['order_dir'] ?? 'DESC';
        
        // Pagination
        $limit = $filters['limit'] ?? 20;
        $offset = $filters['offset'] ?? 0;
        
        // Requête simplifiée sans jointure complexe
        $query = "SELECT m.*, 
                         (SELECT COUNT(*) FROM memory_element_relations me WHERE me.memory_id = m.id) as elements_count
                  FROM {$this->table} m
                  WHERE " . implode(' AND ', $conditions) . "
                  ORDER BY {$orderBy} {$orderDir}
                  LIMIT {$limit} OFFSET {$offset}";
        
        try {
            $stmt = $this->getDb()->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Fallback : requête encore plus simple
            $simpleQuery = "SELECT * FROM {$this->table} 
                           WHERE " . implode(' AND ', $conditions) . "
                           ORDER BY {$orderBy} {$orderDir}
                           LIMIT {$limit} OFFSET {$offset}";
            
            $stmt = $this->getDb()->prepare($simpleQuery);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
    }
    
    /**
     * Compter les mémoires avec filtres
     */
    public function count($filters = []) {
        $conditions = ['1=1'];
        $params = [];
        
        if (isset($filters['user_id']) && $filters['user_id']) {
            $conditions[] = 'user_id = :user_id';
            $params[':user_id'] = $filters['user_id'];
        }
        
        if (isset($filters['visibility']) && $filters['visibility']) {
            $conditions[] = 'visibility = :visibility';
            $params[':visibility'] = $filters['visibility'];
        }

        if (isset($filters['is_public']) && $filters['is_public'] !== null) {
            if ($filters['is_public']) {
                $conditions[] = 'visibility = :visibility';
                $params[':visibility'] = 'public';
            } else {
                $conditions[] = 'visibility != :visibility';
                $params[':visibility'] = 'public';
            }
        }
        
        if (!empty($filters['search'])) {
            $conditions[] = '(title LIKE :search1 OR content LIKE :search2)';
            $params[':search1'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
        }
        
        $conditions[] = 'deleted_at IS NULL';
        
        $query = "SELECT COUNT(*) FROM {$this->table} 
                 WHERE " . implode(' AND ', $conditions);
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchColumn();
    }
    
    /**
     * Trouver une mémoire par ID (compatible avec BaseModelSimplified)
     */
    public function findById($id, $withTrashed = false) {
        $query = "SELECT m.*, u.name as user_name, u.email as user_email
                  FROM {$this->table} m
                  LEFT JOIN users u ON m.user_id = u.id
                  WHERE m.id = :id";
        
        if (!$withTrashed) {
            $query .= " AND m.deleted_at IS NULL";
        }
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->execute([':id' => $id]);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            $this->mapFromArray($result);
            return $result;
        }
        
        return false;
    }
    
    /**
     * Créer une nouvelle mémoire (compatible avec BaseModelSimplified)
     */
    public function create() {
        $query = "INSERT INTO {$this->table} 
                 (user_id, title, content, visibility, location, latitude, longitude, time_start, time_end, created_at, updated_at) 
                 VALUES (:user_id, :title, :content, :visibility, :location, :latitude, :longitude, :time_start, :time_end, NOW(), NOW())";
        
        $stmt = $this->getDb()->prepare($query);
        $success = $stmt->execute([
            ':user_id' => $this->user_id,
            ':title' => $this->title,
            ':content' => $this->content ?? '',
            ':visibility' => $this->visibility ?? 'private',
            ':location' => $this->location,
            ':latitude' => $this->latitude,
            ':longitude' => $this->longitude,
            ':time_start' => $this->time_start,
            ':time_end' => $this->time_end
        ]);
        
        if ($success) {
            $this->id = $this->getDb()->lastInsertId();
            return $this->id;
        }
        
        return false;
    }
    
    /**
     * Créer une mémoire avec données (méthode helper)
     */
    public function createWithData($data) {
        $this->user_id = $data['user_id'];
        $this->title = $data['title'];
        $this->content = $data['content'] ?? '';
        $this->visibility = $data['visibility'] ?? 'private';
        $this->location = $data['location'] ?? null;
        $this->latitude = $data['latitude'] ?? null;
        $this->longitude = $data['longitude'] ?? null;
        $this->time_start = $data['time_start'] ?? null;
        $this->time_end = $data['time_end'] ?? null;
        
        return $this->create();
    }
    
    /**
     * Mettre à jour une mémoire (compatible avec BaseModelSimplified)
     */
    public function update() {
        if (!$this->id) {
            return false;
        }
        
        $query = "UPDATE {$this->table} 
                 SET title = :title, content = :content, 
                     visibility = :visibility, location = :location, 
                     latitude = :latitude, longitude = :longitude,
                     time_start = :time_start, time_end = :time_end,
                     updated_at = NOW()
                 WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->getDb()->prepare($query);
        return $stmt->execute([
            ':id' => $this->id,
            ':title' => $this->title,
            ':content' => $this->content ?? '',
            ':visibility' => $this->visibility ?? 'private',
            ':location' => $this->location,
            ':latitude' => $this->latitude,
            ':longitude' => $this->longitude,
            ':time_start' => $this->time_start,
            ':time_end' => $this->time_end
        ]);
    }
    
    /**
     * Mettre à jour avec données (méthode helper)
     */
    public function updateWithData($id, $data) {
        if (!$this->findById($id)) {
            return false;
        }
        
        foreach ($data as $field => $value) {
            if (in_array($field, ['title', 'content', 'visibility', 'location', 'latitude', 'longitude', 'time_start', 'time_end']) && property_exists($this, $field)) {
                $this->$field = $value;
            }
        }
        
        return $this->update();
    }
    
    /**
     * Supprimer une mémoire (compatible avec BaseModelSimplified)
     */
    public function delete($hard = false) {
        if (!$this->id) {
            return false;
        }
        
        if ($hard) {
            return $this->forceDelete();
        } else {
            return $this->softDelete();
        }
    }
    
    /**
     * Supprimer par ID (méthode helper)
     */
    public function deleteById($id) {
        if ($this->findById($id)) {
            return $this->delete();
        }
        return false;
    }
    
    /**
     * Associer un élément à une mémoire
     */
    public function associateElement($memoryId, $elementId) {
        $query = "INSERT IGNORE INTO memory_element_relations (memory_id, element_id, created_at) 
                 VALUES (:memory_id, :element_id, NOW())";
        
        $stmt = $this->getDb()->prepare($query);
        return $stmt->execute([
            ':memory_id' => $memoryId,
            ':element_id' => $elementId
        ]);
    }
    
    /**
     * Dissocier un élément d'une mémoire
     */
    public function dissociateElement($memoryId, $elementId) {
        $query = "DELETE FROM memory_element_relations 
                 WHERE memory_id = :memory_id AND element_id = :element_id";
        
        $stmt = $this->getDb()->prepare($query);
        return $stmt->execute([
            ':memory_id' => $memoryId,
            ':element_id' => $elementId
        ]);
    }
    
    /**
     * Récupérer les éléments d'une mémoire
     */
    public function getElements($memoryId) {
    $query = "SELECT e.*, me.created_at as association_date
          FROM elements e
          INNER JOIN memory_element_relations me ON e.id = me.element_id
          WHERE me.memory_id = :memory_id 
          AND e.deleted_at IS NULL
          ORDER BY me.created_at DESC";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->execute([':memory_id' => $memoryId]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Récupérer les mémoires publiques
     */
    public function getPublicMemories($filters = []) {
        $filters['visibility'] = 'public';
        return $this->getAll($filters);
    }
    
    /**
     * Rechercher des mémoires
     */
    public function search($query, $userId = null, $filters = []) {
        $filters['search'] = $query;
        if ($userId) {
            $filters['user_id'] = $userId;
        }
        return $this->getAll($filters);
    }
    
    /**
     * Récupérer les statistiques des mémoires
     */
    public function getStatistics($userId = null) {
        $conditions = ['deleted_at IS NULL'];
        $params = [];
        
        if ($userId) {
            $conditions[] = 'user_id = :user_id';
            $params[':user_id'] = $userId;
        }
        
        $whereClause = implode(' AND ', $conditions);
        
        $queries = [
            'total_memories' => "SELECT COUNT(*) FROM {$this->table} WHERE {$whereClause}",
            'public_memories' => "SELECT COUNT(*) FROM {$this->table} WHERE {$whereClause} AND visibility = 'public'",
            'private_memories' => "SELECT COUNT(*) FROM {$this->table} WHERE {$whereClause} AND visibility IN ('private', 'shared')",
            'memories_this_month' => "SELECT COUNT(*) FROM {$this->table} WHERE {$whereClause} AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())"
        ];
        
        $stats = [];
        foreach ($queries as $key => $query) {
            $stmt = $this->getDb()->prepare($query);
            $stmt->execute($params);
            $stats[$key] = (int)$stmt->fetchColumn();
        }
        
        return $stats;
    }
}