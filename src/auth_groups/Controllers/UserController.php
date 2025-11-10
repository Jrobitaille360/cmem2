<?php

namespace AuthGroups\Controllers;


/**
 * Contrôleur User simplifié utilisant UserSimplified
 * Version simplifiée sans injection de dépendance PDO
 */
class UserController {
 
    private UserListController $userListController;
    private UserManagerController $userManagerController;
    private UserAvatarController $userAvatarController;
    private UserPasswordController $userPasswordController;
    private UserAppController $userAppController;

    public function __construct() {
        $this->userListController = new UserListController();
        $this->userManagerController = new UserManagerController();
        $this->userAvatarController = new UserAvatarController();
        $this->userPasswordController = new UserPasswordController();
        $this->userAppController = new UserAppController();
    }

    /**
     * Obtenir tous les utilisateurs (pour les admins)
     */
    public function getAll($currentUserRole) {
        return $this->userListController->getAll($currentUserRole);
    }
    
    /**
     * Obtenir un utilisateur par ID
     */
    public function getById($id, $currentUserId, $currentUserRole) {
        return $this->userListController->getById($id, $currentUserId, $currentUserRole);
    }
    
     /**
     * Créer un nouvel utilisateur
     */
    public function create() {
        return $this->userManagerController->create();
    }
    
    /**
     * Supprimer un utilisateur (soft delete)
     */
    public function delete($userId, $currentUserId, $currentUserRole) {
        return $this->userManagerController->delete($userId, $currentUserId, $currentUserRole);
    }

    /**
     * Restaurer un utilisateur (soft delete)
     */
    public function restore($userId, $currentUserId, $currentUserRole) {
        return $this->userManagerController->restore($userId, $currentUserId, $currentUserRole);
    }
   
    /**
     * Authentification utilisateur pour LOGIN
     * Nécessite: api_key, email, password
     */
    public function authenticate() {
        return $this->userManagerController->authenticate();
    }

    /**
     * Upload d'un avatar utilisateur
     * POST /users/avatar
     * Nécessite authentification (user ou admin)
     */
    public function uploadAvatar($userId,$currentUserId, $currentUserRole) {
        return $this->userAvatarController->uploadAvatar($userId, $currentUserId, $currentUserRole);
    }

    public function requestPasswordChange(){
        return $this->userPasswordController->requestPasswordChange();
    }

    /**
     * Changer le mot de passe (via token)
     */
    public function changePasswordToken() {
        return $this->userPasswordController->changePasswordToken();
    }

    /**
     * Changer le mot de passe (authentifié)
     */
    public function changePassword($userId,$currentUserId, $currentUserRole) {
        return $this->userPasswordController->changePassword($userId,$currentUserId, $currentUserRole);
    }

    public function logout($userId) {
       return $this->userManagerController->logout($userId);
    }

    public function updateProfile($userId,$currentUserId, $currentUserRole){
        return $this->userManagerController->updateProfile($userId,$currentUserId, $currentUserRole);
    }

    public function confirmEmail(){
        return $this->userManagerController->confirmEmail();
    }

    /**
     * Renvoyer l'email de vérification pour un utilisateur
     */
    public function resendVerificationEmail(){
        return $this->userManagerController->resendVerificationEmail();
    }

    /**
     * Créer une configuration d'application
     * POST /users/app/
     */
    public function createAppSetup($userId) {
        return $this->userAppController->create($userId);
    }

    /**
     * Récupérer toutes les configurations d'applications
     * GET /users/app/
     */
    public function getAppSetups($userId) {
        return $this->userAppController->getAll($userId);
    }

    /**
     * Récupérer une configuration d'application spécifique
     * GET /users/app/{app_id}
     */
    public function getAppSetup($userId, $appId) {
        return $this->userAppController->getByAppId($userId, $appId);
    }

    /**
     * Mettre à jour une configuration d'application
     * PUT /users/app/{app_id}
     */
    public function updateAppSetup($userId, $appId) {
        return $this->userAppController->updateByAppId($userId, $appId);
    }

    /**
     * Supprimer une configuration d'application (soft delete)
     * DELETE /users/app/{app_id}
     */
    public function deleteAppSetup($userId, $appId) {
        return $this->userAppController->deleteByAppId($userId, $appId);
    }
     
}
