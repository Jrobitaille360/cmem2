<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\Group;
use AuthGroups\Models\User;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Services\LogService;
use AuthGroups\Services\AuthService;
use AuthGroups\Services\UserService;
use AuthGroups\Middleware\LoggingMiddleware;
use Exception;

/**
 * Contrôleur Group simplifié utilisant GroupSimplified
 * Version simplifiée sans injection de dépendance PDO
 */
class GroupController 
{
    private GroupMemberController $memberController;
    private GroupInvitationController $invitationController;
    private GroupManagerController $managerController;
    private GroupListController $listController;


    public function __construct() {
        $this->memberController = new GroupMemberController();
        $this->invitationController = new GroupInvitationController();
        $this->managerController = new GroupManagerController();
        $this->listController = new GroupListController();
    }

    /**
     * Obtenir tous les groupes d'un utilisateur
     */
    public function getUserGroups($userId, $currentUserId, $currentUserRole) {
        return $this->listController->getUserGroups($userId, $currentUserId, $currentUserRole);
    }

    /**
     * Obtenir les groupes publics
     * 
     */
    public function getPublicGroups() {
        return $this->listController->getPublicGroups();
    }
    
    /**
     * Obtenir un groupe par ID
     */
    public function getById($id, $currentUserId, $currentUserRole) {
        return $this->listController->getById($id, $currentUserId, $currentUserRole);
    }

    /**
     * Créer un nouveau groupe
     */
    public function create($currentUserId) {
        return $this->managerController->create($currentUserId);
    }

    /**
     * Mettre à jour un groupe
     */
    public function update($id, $currentUserId, $currentUserRole) {
        return $this->managerController->update($id, $currentUserId, $currentUserRole);
    }

    /**
     * Supprimer un groupe (soft ou forced delete)
     */
    public function delete($id, $currentUserId, $currentUserRole) {
        return $this->managerController->delete($id, $currentUserId, $currentUserRole);
    }

    /**
     * Restaurer un groupe supprimé
     */
    public function restore($id, $currentUserId, $currentUserRole) {
        return $this->managerController->restore($id, $currentUserId, $currentUserRole);
    }

    /**
     * Rechercher des groupes
     */
    public function search($currentUserId, $currentUserRole) {
        return $this->listController->search($currentUserId, $currentUserRole);
    }

    /**
     * Inviter un utilisateur dans un groupe
     */
    public function invite($groupId, $currentUserId, $currentUserRole) {
        return $this->invitationController->inviteUser($groupId, $currentUserId, $currentUserRole);
    }

    /**
     * Rejoindre un groupe via code d'invitation
     */
    public function joinByCode() {
        return $this->invitationController->joinByCode();
    }

    public function myInvitations($email){
        return $this->invitationController->myInvitations($email);
    }
    
    /**
     * Obtenir les membres d'un groupe (délégation)
     */
    public function getMembers($id, $currentUserId, $currentUserRole) {
        return $this->memberController->getMembers($id, $currentUserId, $currentUserRole);
    }

    /**
     * Quitter un groupe
     */
    public function leave($groupId, $currentUserId) {
        return $this->memberController->leave($groupId, $currentUserId);
    }

    /**
     * Mettre à jour le rôle d'un utilisateur
     */
    public function updateUserRole($currentUserId, $currentUserRole, $userId,$groupId) {
        return $this->memberController->updateUserRole($currentUserId, $currentUserRole, $userId, $groupId);
    }

    /**
     * Rejoindre un groupe via un token d'invitation
     */
    public function joinGroup() {
        return $this->invitationController->joinGroup();
    }
}