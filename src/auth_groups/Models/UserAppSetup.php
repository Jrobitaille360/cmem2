<?php

namespace AuthGroups\Models;

use PDO;

/**
 * Modèle UserAppSetup pour la gestion des configurations d'applications utilisateur
 * Utilise Database::getInstance()
 */
class UserAppSetup extends BaseModel {
    protected $table = 'user_app_setup';

    // Propriétés basées sur le schéma
    public $id;
    public $user_id;
    public $app_id;
    public $json_data;
    public $created_at;
    public $updated_at;
    public $deleted_at;

    /**
     * Créer une nouvelle configuration d'application
     */
    public function create() {
        $query = "INSERT INTO {$this->table}
                 (user_id, app_id, json_data)
                 VALUES (:user_id, :app_id, :json_data)";

        $stmt = $this->getDb()->prepare($query);

        // Validation des données
        $this->user_id = (int) $this->user_id;
        $this->app_id = htmlspecialchars(strip_tags($this->app_id));
        // json_data est déjà du JSON ou null

        // Liaison des paramètres
        $stmt->bindParam(':user_id', $this->user_id);
        $stmt->bindParam(':app_id', $this->app_id);
        $stmt->bindParam(':json_data', $this->json_data);

        if ($stmt->execute()) {
            $this->id = $this->getDb()->lastInsertId();
            return true;
        }
        return false;
    }

    /**
     * Mettre à jour la configuration d'application
     */
    public function update() {
        $query = "UPDATE {$this->table} SET
                 app_id = :app_id,
                 json_data = :json_data,
                 updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);

        // Validation des données
        $this->app_id = htmlspecialchars(strip_tags($this->app_id));

        // Liaison des paramètres
        $stmt->bindParam(':id', $this->id);
        $stmt->bindParam(':app_id', $this->app_id);
        $stmt->bindParam(':json_data', $this->json_data);

        return $stmt->execute();
    }

    /**
     * Trouver par user_id et app_id
     */
    public function findByUserAndApp($userId, $appId, $withTrashed = false) {
        $query = "SELECT * FROM {$this->table} WHERE user_id = :user_id AND app_id = :app_id";

        if (!$withTrashed) {
            $query .= " AND deleted_at IS NULL";
        }

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':app_id', $appId);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $this->mapFromArray($result);
            return $result;
        }

        return false;
    }

    /**
     * Récupérer toutes les configurations d'un utilisateur
     */
    public function findByUserId($userId, $withTrashed = false) {
        $query = "SELECT * FROM {$this->table} WHERE user_id = :user_id";

        if (!$withTrashed) {
            $query .= " AND deleted_at IS NULL";
        }

        $query .= " ORDER BY created_at DESC";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Supprimer (soft delete) par user_id et app_id
     */
    public function softDeleteByUserAndApp($userId, $appId) {
        $query = "UPDATE {$this->table} SET
                 deleted_at = CURRENT_TIMESTAMP
                 WHERE user_id = :user_id AND app_id = :app_id AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':app_id', $appId);

        return $stmt->execute();
    }

    /**
     * Restaurer une configuration supprimée
     */
    public function restoreByUserAndApp($userId, $appId) {
        $query = "UPDATE {$this->table} SET
                 deleted_at = NULL
                 WHERE user_id = :user_id AND app_id = :app_id AND deleted_at IS NOT NULL";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':app_id', $appId);

        return $stmt->execute();
    }
}