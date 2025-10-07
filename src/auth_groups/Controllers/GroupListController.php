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

class GroupListController
{
/**
     * Obtenir tous les groupes d'un utilisateur
     */
    public function getUserGroups($userId, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            // Vérifier les permissions (admin ou utilisateur lui-même)
            if ($currentUserRole !== 'ADMINISTRATEUR' && $currentUserId != $userId) {
                LogService::warning("Tentative d'accès non autorisé aux groupes", [
                    'requested_user_id' => $userId,
                    'current_user_id' => $currentUserId,
                    'role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès non autorisé', null, 403);
                return false;
            }
            
            $pagination = Response::getPaginationParams();
            
            $group = new Group(); // Instantiation simplifiée !
            $groups = $group->getByUserId($userId, $pagination['limit'], ($pagination['page'] - 1) * $pagination['limit']);
            
            LogService::info("Groupes utilisateur récupérés", [
                'user_id' => $userId,
                'groups_count' => count($groups),
                'page' => $pagination['page']
            ]);
            $data = [
                'groups' => $groups,
                'page' => $pagination['page'],
                'limit' => $pagination['limit'],
                'user_id' => $userId
            ];

            LoggingMiddleware::logExit(200);
            Response::success("Liste des groupes récupérés", $data);  
            return true;
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des groupes utilisateur", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la récupération des groupes');
                return false;
        }
    }
    
    /**
     * Obtenir les groupes publics
     */
    public function getPublicGroups() {
        try {
            LoggingMiddleware::logEntry();          
            $pagination = Response::getPaginationParams();
            $group = new Group();
            $params = Response::getRequestParams();
            $searchString = $params['q'] ?? '';
            $groups = $group->getPublicGroups($searchString,$pagination['limit'], ($pagination['page'] - 1) * $pagination['limit']);
            LogService::info("Groupes publics récupérés", [
                'groups_count' => count($groups),
                'page' => $pagination['page']
            ]);            
            LoggingMiddleware::logExit(200);
            $data = ['groups' => $groups,
                     'page' => $pagination['page'],
                     'limit' => $pagination['limit']];
            Response::success("Groupes publics récupérés",$data);
            return true;
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des groupes publics", [
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la récupération des groupes publics');
            return false;
        }
    }

    /**
     * Obtenir un groupe par ID
     */
    public function getById($id, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $group = new Group();
            $groupData = $group->findById($id);

            if (!$groupData) {
                LogService::info("Groupe non trouvé", ['id' => $id]);
                LoggingMiddleware::logExit(404);
                Response::error('Groupe non trouvé', null, 404);
            }

            // Vérifier les permissions pour les groupes privés
            if ($groupData['visibility'] === 'private') {
                if (!$group->isMember($id, $currentUserId) && $currentUserRole !== 'ADMINISTRATEUR') {
                    LogService::warning("Tentative d'accès à un groupe privé", [
                        'group_id' => $id,
                        'user_id' => $currentUserId
                    ]);
                    LoggingMiddleware::logExit(403);
                    Response::error('Accès non autorisé', null, 403);
                }
            }

            LogService::info("Groupe récupéré", [
                'group_id' => $id,
                'user_id' => $currentUserId
            ]);

            LoggingMiddleware::logExit(200);
            Response::success("Groupe récupéré", $groupData);
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération du groupe", [
                'group_id' => $id,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la récupération du groupe');
        }
    }

/**
     * Rechercher des groupes
     */
    public function search($currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            
            $input = Response::getRequestParams();
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'q' => 'required|string|min:1',
                'visibility' => 'optionnal|string|in:public,private,all'
            ]);

            if (!$validation['valid']) {
                LogService::warning("Paramètres de recherche invalides", [
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Paramètres invalides', $validation['errors'], 400);
                return false;
            }

            $pagination = Response::getPaginationParams();
            $visibility = $input['visibility'] ?? 'all';
            

            $group = new Group();
            $groups = $group->search($input['q'], $visibility, $pagination['limit'], ($pagination['page'] - 1) * $pagination['limit']);

            LogService::info("Recherche de groupes effectuée", [
                'query' => $input['q'],
                'results_count' => count($groups),
                'user_id' => $currentUserId
            ]);

            LoggingMiddleware::logExit(200);
            Response::success("Résultats de recherche", [
                'groups' => $groups,
                'query' => $input['q'],
                'page' => $pagination['page'],
                'limit' => $pagination['limit']
            ]);
            return true;

        } catch (Exception $e) {
            LogService::error("Erreur lors de la recherche de groupes", [
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la recherche');
            return false;
        }
    }

}
