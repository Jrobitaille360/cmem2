<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\Group;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Services\LogService;
use AuthGroups\Middleware\LoggingMiddleware;
use Exception;

class GroupMemberController
{
    public function getMembers($id, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $group = new Group();
            $groupData = $group->findById($id);
            
            if (!$groupData) {
                LogService::info("Groupe non trouvé pour voir les membres", ['id' => $id]);
                LoggingMiddleware::logExit(404);
                Response::error('Groupe non trouvé', null, 404);
            }

            // Vérifier les permissions d'accès aux membres
            $canViewMembers = (
                $groupData['visibility'] === 'public' ||
                $group->isMember($id, $currentUserId) ||
                $currentUserRole === 'ADMINISTRATEUR'
            );

            if (!$canViewMembers) {
                LogService::warning("Accès non autorisé aux membres du groupe", [
                    'group_id' => $id,
                    'user_id' => $currentUserId,
                    'group_visibility' => $groupData['visibility']
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès non autorisé pour voir les membres de ce groupe', null, 403);
            }

            $pagination = Response::getPaginationParams();
            $members = $group->getMembers($id, $pagination['limit'], ($pagination['page'] - 1) * $pagination['limit']);

            LogService::info("Membres du groupe récupérés", [
                'group_id' => $id,
                'members_count' => count($members),
                'user_id' => $currentUserId
            ]);

            LoggingMiddleware::logExit(200);
            Response::success("Membres du groupe récupérés", [
                'members' => $members,
                'group_id' => $id,
                'page' => $pagination['page'],
                'limit' => $pagination['limit']
            ]);
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des membres", [
                'group_id' => $id,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la récupération des membres');
        }
    }
    
/**
     * Quitter un groupe
     */
    public function leave($groupId, $currentUserId) {
        try {
            LoggingMiddleware::logEntry();
            
            $group = new Group();
            $groupData = $group->findById($groupId);

            if (!$groupData) {
                LogService::warning("Groupe non trouvé", [
                    'group_id' => $groupId
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Groupe non trouvé', null, 404);
            }

            // Vérifier si l'utilisateur est membre
            if (!$group->isMember($groupId, $currentUserId)) {
                LogService::info("Utilisateur n'est pas membre du groupe", [
                    'group_id' => $groupId,
                    'user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Vous n\'êtes pas membre de ce groupe', null, 400);
            }

            // Vérifier si c'est le seul admin (on ne peut pas laisser un groupe sans admin)
            $userRole = $group->getMemberRole($groupId, $currentUserId);
            if ($userRole === 'admin') {
                $adminCount = $group->countAdmins($groupId);
                if ($adminCount <= 1) {
                    LogService::warning("Tentative de quitter le groupe par le seul admin", [
                        'group_id' => $groupId,
                        'user_id' => $currentUserId
                    ]);
                    LoggingMiddleware::logExit(400);
                    Response::error('Vous ne pouvez pas quitter ce groupe car vous êtes le seul administrateur. Désignez d\'abord un autre administrateur.', null, 400);
                }
            }

            $success = $group->removeMember($groupId, $currentUserId);

            if (!$success) {
                LogService::error("Échec du départ du groupe", [
                    'group_id' => $groupId,
                    'user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors du départ du groupe');
            }

            LogService::info("Utilisateur a quitté le groupe", [
                'group_id' => $groupId,
                'user_id' => $currentUserId
            ]);

            LoggingMiddleware::logExit(200);
            Response::success('Vous avez quitté le groupe avec succès');
        } catch (Exception $e) {
            LogService::error("Erreur lors du départ du groupe", [
                'group_id' => $groupId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors du départ du groupe');
        }
    }

    /**
     * Mettre à jour le rôle d'un utilisateur
     */
    public function updateUserRole($currentUserId, $currentUserRole,$userId, $groupId) {
        try {
            LoggingMiddleware::logEntry();
            
            $input = Response::getRequestParams();
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'role' => 'required|string|in:admin,moderator,member'
            ]);

            if (!$validation['valid']) {
                LogService::warning("Données invalides pour la mise à jour du rôle", [
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Données invalides', $validation['errors'], 400);
            }


            $newRole = $input['role'];

            $group = new Group();
            $groupData = $group->findById($groupId);

            if (!$groupData) {
                LogService::warning("Groupe non trouvé", [
                    'group_id' => $groupId
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Groupe non trouvé', null, 404);
            }

            // Vérifier les permissions
            $currentUserGroupRole = $group->getMemberRole($groupId, $currentUserId);
            $canManageRoles = (
                $currentUserRole === 'ADMINISTRATEUR' ||
                $currentUserGroupRole === 'admin'
            );

            if (!$canManageRoles) {
                LogService::warning("Tentative de modification de rôle non autorisée", [
                    'group_id' => $groupId,
                    'current_user_id' => $currentUserId,
                    'target_user_id' => $userId,
                    'current_user_role_in_group' => $currentUserGroupRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Vous n\'avez pas les permissions pour modifier les rôles dans ce groupe', null, 403);
            }

            // Vérifier que l'utilisateur cible est membre du groupe
            if (!$group->isMember($groupId, $userId)) {
                LogService::warning("Utilisateur cible n'est pas membre du groupe", [
                    'group_id' => $groupId,
                    'target_user_id' => $userId
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('L\'utilisateur n\'est pas membre de ce groupe', null, 400);
            }

            $success = $group->updateUserRole($groupId, $userId, $newRole, $currentUserId);

            if (!$success) {
                LogService::error("Échec de la mise à jour du rôle", [
                    'group_id' => $groupId,
                    'user_id' => $userId,
                    'new_role' => $newRole,
                    'updated_by' => $currentUserId
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la mise à jour du rôle');
            }

            LogService::info("Rôle utilisateur mis à jour", [
                'group_id' => $groupId,
                'user_id' => $userId,
                'new_role' => $newRole,
                'updated_by' => $currentUserId
            ]);

            LoggingMiddleware::logExit(200);
            Response::success('Rôle mis à jour avec succès');
        } catch (Exception $e) {
            LogService::error("Erreur lors de la mise à jour du rôle", [
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la mise à jour du rôle');
        }
    }


}
