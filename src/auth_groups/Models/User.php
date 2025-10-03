<?php

namespace Memories\Models;

use PDO;

/**
 * Modèle User simplifié utilisant Database::getInstance()
 * Version simplifiée sans injection de dépendance
 */
class User extends BaseModel {
    protected $table = 'users';
    
    // Propriétés basées sur le nouveau schéma
    public $id;
    public $name;
    public $email;
    public $password_hash;
    public $role;
    public $profile_image;
    public $bio;
    public $phone;
    public $date_of_birth;
    public $location;
    public $email_verified;
    public $last_login;
    public $created_at;
    public $updated_at;
    public $deleted_at;

    /**
     * Créer un nouvel utilisateur
     */
    public function create() {
        $query = "INSERT INTO {$this->table} 
                 (name, email, password_hash, role, profile_image, bio, phone, date_of_birth, location, email_verified) 
                 VALUES (:name, :email, :password_hash, :role, :profile_image, :bio, :phone, :date_of_birth, :location, :email_verified)";
        
        $stmt = $this->getDb()->prepare($query);
        
        // Nettoyage des données
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->email = htmlspecialchars(strip_tags($this->email));
        $this->role = $this->role ?? 'UTILISATEUR';
        $this->email_verified = $this->email_verified ?? 0;
        
        // Liaison des paramètres
        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':password_hash', $this->password_hash);
        $stmt->bindParam(':role', $this->role);
        $stmt->bindParam(':profile_image', $this->profile_image);
        $stmt->bindParam(':bio', $this->bio);
        $stmt->bindParam(':phone', $this->phone);
        $stmt->bindParam(':date_of_birth', $this->date_of_birth);
        $stmt->bindParam(':location', $this->location);
        $stmt->bindParam(':email_verified', $this->email_verified);
        
        if ($stmt->execute()) {
            $this->id = $this->getDb()->lastInsertId();
            return true;
        }
        return false;
    }

    /**
     * Trouver un utilisateur par ID
     */
    public function findById($id, $withTrashed = false) {
        $query = "SELECT * FROM {$this->table} WHERE id = :id";
        
        if (!$withTrashed) {
            $query .= " AND deleted_at IS NULL";
        }
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$data) {
            return $data;
        }
        $this->id = $data['id'] ;
        $this->name = $data['name'] ;
        $this->email = $data['email'] ;
        $this->role = $data['role'] ;
        $this->profile_image = $data['profile_image'] ;
        $this->bio = $data['bio'];
        $this->phone = $data['phone'] ;
        $this->date_of_birth = $data['date_of_birth'] ;
        $this->location = $data['location'] ;
        $this->email_verified = $data['email_verified'] ;
        $this->last_login = $data['last_login'] ;
        $this->created_at = $data['created_at'] ;
        $this->updated_at = $data['updated_at'] ;
        $this->deleted_at = $data['deleted_at'] ;
        return $data;
    }

    /**
     * Trouver un utilisateur par email
     */
    public function findByEmail($email) {
        $query = "SELECT * FROM {$this->table} WHERE email = :email AND deleted_at IS NULL";
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();    
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$data) {
            return $data;
        }
        $this->id = $data['id'] ;
        $this->name = $data['name'] ;
        $this->email = $data['email'] ;
        $this->role = $data['role'] ;
        $this->profile_image = $data['profile_image'] ;
        $this->bio = $data['bio'];
        $this->phone = $data['phone'] ;
        $this->date_of_birth = $data['date_of_birth'] ;
        $this->location = $data['location'] ;
        $this->email_verified = $data['email_verified'] ;
        $this->last_login = $data['last_login'] ;
        $this->created_at = $data['created_at'] ;
        $this->updated_at = $data['updated_at'] ;
        $this->deleted_at = $data['deleted_at'] ;
        return $data;
    }

    /**
     * Mettre à jour un utilisateur
     */
    public function update() {
        $query = "UPDATE {$this->table} SET 
                 name = :name, 
                 email = :email,
                 role = :role,
                 profile_image = :profile_image,
                 bio = :bio,
                 phone = :phone,
                 date_of_birth = :date_of_birth,
                 location = :location,
                 email_verified = :email_verified,
                 updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->getDb()->prepare($query);
        
        // Nettoyage des données
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->email = htmlspecialchars(strip_tags($this->email));
        
        // Liaison des paramètres
        $stmt->bindParam(':id', $this->id);
        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':role', $this->role);
        $stmt->bindParam(':profile_image', $this->profile_image);
        $stmt->bindParam(':bio', $this->bio);
        $stmt->bindParam(':phone', $this->phone);
        $stmt->bindParam(':date_of_birth', $this->date_of_birth);
        $stmt->bindParam(':location', $this->location);
        $stmt->bindParam(':email_verified', $this->email_verified);
        
        return $stmt->execute();
    }

    /**
     * Mettre à jour le mot de passe
     */
    public function updatePassword($newPasswordHash) {
        $query = "UPDATE {$this->table} SET 
                 password_hash = :password_hash,
                 updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':id', $this->id);
        $stmt->bindParam(':password_hash', $newPasswordHash);
        
        return $stmt->execute();
    }

    /**
     * Vérifier les identifiants de connexion
     */
    public function authenticate($email, $password) {
        $user = $this->findByEmail($email);
        if ($user && password_verify($password, $user['password_hash'])) {
            // Vérifier si l'email est vérifié
            if (!$user['email_verified']) {
                // Retourner un objet spécial pour indiquer que l'email n'est pas vérifié
                return [
                    'status' => 'email_not_verified',
                    'user_data' => $user,
                    'message' => 'Votre adresse email n\'a pas encore été vérifiée. Veuillez vérifier votre boîte de réception.'
                ];
            }
            
            // Mettre à jour last_login seulement si l'email est vérifié
            $query = "UPDATE {$this->table} SET last_login = CURRENT_TIMESTAMP WHERE id = :id";
            $stmt = $this->getDb()->prepare($query);
            $stmt->bindParam(':id', $user['id']);
            $stmt->execute();
            
            return $user;
        }
        return false;
    }

    /**
     * Obtenir tous les utilisateurs (avec pagination)
     */
    public function getAll($limit = 20, $offset = 0) {
        $query = "SELECT id, name, email, role, profile_image, bio, phone, date_of_birth, location, email_verified, last_login, created_at 
                 FROM {$this->table} 
                 WHERE deleted_at IS NULL 
                 ORDER BY created_at DESC 
                 LIMIT :limit OFFSET :offset";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Compter le nombre total d'utilisateurs
     */
    public function count() {
        $query = "SELECT COUNT(*) as total FROM {$this->table} WHERE deleted_at IS NULL";
        $stmt = $this->getDb()->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$result['total'];
    }

    /**
     * Vérifier si un email existe déjà
     */
    public function emailExists($email, $excludeId = null, $ignoreDeleted = true) {
         $query = "SELECT COUNT(*) as count FROM {$this->table} WHERE email = :email";
        
        if ($excludeId) {
            $query .= " AND id != :exclude_id";
        }
        
        if ($ignoreDeleted) {
            $query .= " AND deleted_at IS NULL";
        }
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':email', $email);
        
        if ($excludeId) {
            $stmt->bindParam(':exclude_id', $excludeId, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] > 0;
    }

    public function markEmailAsVerified($userId) : bool {
        $query = "UPDATE {$this->table} SET email_verified = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND deleted_at IS NULL";
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':id', $userId);
        return $stmt->execute();
    }

    /**
     * Supprimer un utilisateur (soft delete)
     */
    public function delete($forceDelete = false) {
        if ($forceDelete) {
            // Suppression définitive
            $query = "DELETE FROM {$this->table} WHERE id = :id";
        } else {
            // Soft delete
            $query = "UPDATE {$this->table} SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id AND deleted_at IS NULL";
        }
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':id', $this->id);
        return $stmt->execute();
    }

    /**
     * Restaurer un utilisateur supprimé
     */
    public function restore() {
        $query = "UPDATE {$this->table} SET deleted_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':id', $this->id);
        return $stmt->execute();
    }

    /**
     * Convertir l'objet en tableau
     */
    public function toArray() {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'profile_image' => $this->profile_image,
            'bio' => $this->bio,
            'phone' => $this->phone,
            'date_of_birth' => $this->date_of_birth,
            'location' => $this->location,
            'email_verified' => $this->email_verified,
            'last_login' => $this->last_login,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at
        ];
    }


    
}
