<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\Group;
use AuthGroups\Models\User;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Services\LogService;
use AuthGroups\Services\AuthService;
use AuthGroups\Middleware\LoggingMiddleware;
use Exception;

class GroupManagerController
{
    /**
     * Créer un nouveau groupe
     */
    public function create($currentUserId) {
        try {
            LoggingMiddleware::logEntry();
            
            $input = Response::getRequestParams();
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'name' => 'required|string|max:100',
                'description' => 'optionnal|string|max:1000',
                'visibility' => 'optionnal|string|in:public,private',
                'max_members' => 'optionnal|integer|min:1|max:1000'
            ]);

            if (!$validation['valid']) {
                LogService::warning("Données de création invalides", [
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Données invalides', $validation['errors'], 400);
                return false;
            }
            // default pour visibility
            $input['visibility'] = $input['visibility'] ?? 'private';
            
            $group = new Group();
            $groupId = $group->create2($input, $currentUserId); // Utiliser create2() qui accepte les paramètres

            if (!$groupId) {
                LogService::error("Échec de la création du groupe", [
                    'user_id' => $currentUserId,
                    'data' => $input
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la création du groupe');
                return false;
            }

            LogService::info("Groupe créé", [
                'group_id' => $groupId,
                'user_id' => $currentUserId
            ]);

            LoggingMiddleware::logExit(201);
            Response::success('Groupe créé avec succès', ['id' => $groupId], 201);
            return true;
        } catch (Exception $e) {
            LogService::error("Erreur lors de la création du groupe", [
                'user_id' => $currentUserId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la création du groupe');
            return false;
        }
    }

    /**
     * Mettre à jour un groupe
     */
    public function update($id, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $group = new Group();
            $groupData = $group->findById($id);

            if (!$groupData) {
                LogService::warning("Groupe non trouvé", [
                    'group_id' => $id
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Groupe non trouvé', null, 404);
                return false;
            }

            // Vérifier les permissions (propriétaire ou admin)
            if (!$group->isGroupAdmin($id, $currentUserId) && $currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Tentative de modification non autorisée", [
                    'group_id' => $id,
                    'current_user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès non autorisé', null, 403);
                return false;
            }

            $input = Response::getRequestParams();
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'name' => 'optionnal|string|max:100',
                'description' => 'optionnal|string|max:1000',
                'visibility' => 'optionnal|string|in:public,private',
                'max_members' => 'optionnal|integer|min:1|max:1000'
            ]);

            if (!$validation['valid']) {
                LogService::warning("Données de mise à jour invalides", [
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Données invalides', $validation['errors'], 400);
                return false;
            }

            $success = $group->updateGroup($id, $input); // Changé de update() à updateGroup()

            if (!$success) {
                LogService::error("Échec de la mise à jour du groupe", [
                    'group_id' => $id,
                    'user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la mise à jour du groupe');
                return false;
            }

            LogService::info("Groupe mis à jour", [
                'group_id' => $id,
                'user_id' => $currentUserId
            ]);

            LoggingMiddleware::logExit(200);
            Response::success('Groupe mis à jour avec succès');
            return true;
        } catch (Exception $e) {
            LogService::error("Erreur lors de la mise à jour du groupe", [
                'group_id' => $id,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la mise à jour du groupe');
            return false;
        }
    }

    /**
     * Supprimer un groupe (soft ou forced delete)
     */
    public function delete($id, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();           
            $group = new Group();
            $groupData = $group->findById($id);
            if (!$groupData) {
                LogService::warning("Groupe non trouvé", [
                    'group_id' => $id
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Groupe non trouvé', null, 404);
                return false;
            }
            // Vérifier les permissions (propriétaire ou admin)
            if (!$group->isGroupAdmin($id, $currentUserId) && $currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Tentative de suppression non autorisée", [
                    'group_id' => $id,
                    'current_user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès non autorisé', null, 403);
                return false;
            }
            $input = Response::getRequestParams();
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'force_delete' => 'optionnal|boolean'
            ]);
            if (!$validation['valid']) {
                LogService::warning("Données invalides", [
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Données invalides', $validation['errors'], 400);
                return false;
            }
            $forceDelete = $input['force_delete'] ?? false;
            $success = $group->delete($forceDelete); // Suppression soft ou forcée
            if (!$success) {
                LogService::error("Échec de la suppression du groupe", [
                    'group_id' => $id,
                    'user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la suppression du groupe');
                return false;
            }
            LogService::info("Groupe supprimé", [
                'group_id' => $id,
                'user_id' => $currentUserId,
                'force_delete' => $forceDelete
            ]);
            LoggingMiddleware::logExit(200);
            Response::success('Groupe supprimé avec succès');
            return true;
        } catch (Exception $e) {
            LogService::error("Erreur lors de la suppression du groupe", [
                'group_id' => $id,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la suppression du groupe');
            return false;
        }
    }

    /**
     * Restaurer un groupe supprimé
     */
    public function restore($id, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            LoggingMiddleware::logEntry();           
            $group = new Group();
            $groupData = $group->findById($id,true);
            if (!$groupData) {
                LogService::warning("Groupe non trouvé", [
                    'group_id' => $id
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Groupe non trouvé', null, 404);
                return false;
            }
            if ($groupData['deleted_at'] === null) {
                LogService::warning("Tentative de restauration d'un groupe non supprimé", [
                    'group_id' => $id,
                    'current_user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Groupe non supprimé', null, 400);
                return false;
            }
            // Vérifier les permissions (propriétaire ou admin)
            if (!$group->isGroupAdmin($id, $currentUserId) && $currentUserRole !== 'ADMINISTRATEUR') {
                LogService::warning("Tentative de restauration non autorisée", [
                    'group_id' => $id,
                    'current_user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès non autorisé', null, 403);
                return false;
            }
            
            // L'objet est maintenant chargé avec les données, on peut restaurer
            $success = $group->restore();

            if (!$success) {
                LogService::error("Échec de la restauration du groupe", [
                    'group_id' => $id,
                    'user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la restauration du groupe');
                return false;
            }

            LogService::info("Groupe restauré", [
                'group_id' => $id,
                'user_id' => $currentUserId
            ]);

            LoggingMiddleware::logExit(200);
            Response::success('Groupe restauré avec succès');
            return true;
        } catch (Exception $e) {
            LogService::error("Erreur lors de la restauration du groupe", [
                'group_id' => $id,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la restauration du groupe');
            return false;
        }
    }


}
