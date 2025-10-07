<?php

namespace AuthGroups\Models;

/**
 * Trait pour gérer les soft deletes
 * Détermine si on flag deleted_at ou on supprime l'enregistrement de la table
 */
trait SoftDeleteTrait {
    
    /**
     * Supprimer l'enregistrement (soft delete par défaut)
     * @param bool $force Si true, suppression physique (hard delete)
     * @return bool
     */
    public function delete($force = false) {
        if ($force) {
            return $this->forceDelete();
        } else {
            return $this->softDelete();
        }
    }
    
    /**
     * Soft delete - marquer comme supprimé avec deleted_at
     * @return bool
     */
    public function softDelete() {
        if (!$this->id || !$this->table) {
            return false;
        }
        
        $db = self::getDB();
        $stmt = $db->prepare("UPDATE {$this->table} SET deleted_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$this->id]);
        
        if ($result) {
            $this->deleted_at = date('Y-m-d H:i:s');
        }
        
        return $result;
    }
    
    /**
     * Hard delete - suppression physique de la table
     * @return bool
     */
    public function forceDelete() {
        if (!$this->id || !$this->table) {
            return false;
        }
        
        $db = self::getDB();
        $stmt = $db->prepare("DELETE FROM {$this->table} WHERE id = ?");
        return $stmt->execute([$this->id]);
    }
    
    /**
     * Restaurer un enregistrement soft deleted
     * @return bool
     */
    public function restore() {
        if (!$this->id || !$this->table) {
            return false;
        }
        
        $db = self::getDB();
        $stmt = $db->prepare("UPDATE {$this->table} SET deleted_at = NULL WHERE id = ?");
        $result = $stmt->execute([$this->id]);
        
        if ($result) {
            $this->deleted_at = null;
        }
        
        return $result;
    }
    
    /**
     * Vérifier si l'enregistrement est soft deleted
     * @return bool
     */
    public function isDeleted() {
        return !is_null($this->deleted_at);
    }
    
    /**
     * Scope pour exclure les enregistrements soft deleted
     * @param string $query
     * @return string
     */
    public static function withoutDeleted($query = null) {
        $condition = "deleted_at IS NULL";
        
        if ($query) {
            if (stripos($query, 'WHERE') !== false) {
                return $query . " AND " . $condition;
            } else {
                return $query . " WHERE " . $condition;
            }
        }
        
        return $condition;
    }
    
    /**
     * Scope pour inclure seulement les enregistrements soft deleted
     * @param string $query
     * @return string
     */
    public static function onlyDeleted($query = null) {
        $condition = "deleted_at IS NOT NULL";
        
        if ($query) {
            if (stripos($query, 'WHERE') !== false) {
                return $query . " AND " . $condition;
            } else {
                return $query . " WHERE " . $condition;
            }
        }
        
        return $condition;
    }
    
    /**
     * Scope pour inclure tous les enregistrements (deleted et non-deleted)
     * @param string $query
     * @return string
     */
    public static function withDeleted($query = null) {
        // Retourne la query telle quelle, sans filtrage
        return $query;
    }
}
