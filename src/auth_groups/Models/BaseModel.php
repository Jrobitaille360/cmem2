<?php

namespace AuthGroups\Models;

use PDO;

/**
 * Classe de base simplifiée pour les modèles avec support des soft deletes
 * Utilise Database::getInstance() - pas besoin d'injection de dépendance
 */
abstract class BaseModel {
    use SoftDeleteTrait;
    
    protected $table;
    protected static $db = null;
    
    public $id;
    public $created_at;
    public $updated_at;
    public $deleted_at;
    
    /**
     * Constructeur simplifié - pas d'injection de dépendance
     */
    public function __construct() {
        // Connexion automatique via le singleton
        if (self::$db === null) {
            require_once __DIR__ . '/../../../config/auth_groups/database.php';
            self::$db = \Database::getInstance()->getConnection();
        }
    }
    
    /**
     * Getter pour la connexion DB (pour compatibilité avec le code existant)
     */
    protected function getDb(): PDO {
        if (self::$db === null) {
            require_once __DIR__ . '/../../../../config/auth_groups/shared/database.php';
            self::$db = \Database::getInstance()->getConnection();
        }
        return self::$db;
    }
    
    /**
     * Trouver un enregistrement par ID (en excluant les supprimés par défaut)
     */
    public function findById($id, $withTrashed = false) {
        $query = "SELECT * FROM {$this->table} WHERE id = :id";
        
        if (!$withTrashed) {
            $query .= " AND deleted_at IS NULL";
        }
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $this->mapFromArray($result);
            return $result;
        }
        
        return false;
    }
    
    /**
     * Récupérer tous les enregistrements
     */
    public function all($withTrashed = false) {
        $query = "SELECT * FROM {$this->table}";
        
        if (!$withTrashed) {
            $query .= " WHERE deleted_at IS NULL";
        }
        
        $query .= " ORDER BY created_at DESC";
        
        $stmt = $this->getDb()->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Sauvegarder (créer ou mettre à jour)
     */
    public function save() {
        if ($this->id) {
            return $this->update();
        } else {
            return $this->create();
        }
    }
    
    /**
     * Créer un nouvel enregistrement
     */
    abstract public function create();
    
    /**
     * Mettre à jour l'enregistrement
     */
    abstract public function update();
    
    /**
     * Mapper un tableau vers les propriétés de l'objet
     */
    protected function mapFromArray($data) {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
    
    /**
     * Convertir l'objet en tableau
     */
    public function toArray() {
        $array = [];
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        
        foreach ($properties as $property) {
            $name = $property->getName();
            $array[$name] = $this->$name;
        }
        
        return $array;
    }
    
    /**
     * Préparer les données pour insertion/mise à jour
     */
    protected function prepareData() {
        $data = $this->toArray();
        unset($data['id']); // On ne met pas à jour l'ID
        return $data;
    }
    
    /**
     * Construire la clause WHERE pour les requêtes
     */
    protected function buildWhereClause($conditions, $includeTrashed = false) {
        $where = [];
        $params = [];
        
        foreach ($conditions as $field => $value) {
            $where[] = "$field = :$field";
            $params[$field] = $value;
        }
        
        if (!$includeTrashed) {
            $where[] = "deleted_at IS NULL";
        }
        
        return [
            'clause' => $where ? 'WHERE ' . implode(' AND ', $where) : '',
            'params' => $params
        ];
    }
    
    /**
     * Exécuter une requête avec gestion des erreurs
     */
    protected function executeQuery($query, $params = []) {
        try {
            $stmt = $this->getDb()->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
            
            return $stmt->execute();
        } catch (\PDOException $e) {
            error_log("Erreur base de données: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtenir le dernier ID inséré
     */
    protected function getLastInsertId() {
        return $this->getDb()->lastInsertId();
    }
    
    /**
     * Exécuter une procédure stockée
     */
    public function executeProcedure($procedureName, $parameters = []) {
        try {
            $placeholders = str_repeat('?,', count($parameters));
            $placeholders = rtrim($placeholders, ',');
            
            $query = "CALL $procedureName($placeholders)";
            $stmt = $this->getDb()->prepare($query);
            
            // Bind parameters
            foreach ($parameters as $index => $value) {
                $stmt->bindValue($index + 1, $value);
            }
            
            $stmt->execute();
            
            // Récupérer tous les résultats si disponibles
            $results = [];
            do {
                try {
                    $resultSet = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if ($resultSet) {
                        $results[] = $resultSet;
                    }
                } catch (\PDOException $e) {
                    // Pas de résultats à récupérer
                    break;
                }
            } while ($stmt->nextRowset());
            
            return [
                'success' => true,
                'results' => $results,
                'affected_rows' => $stmt->rowCount()
            ];
            
        } catch (\PDOException $e) {
            error_log("Erreur lors de l'exécution de la procédure $procedureName: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
