<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\User;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\RoleHelper;
use AuthGroups\Utils\Validator;
use AuthGroups\Services\LogService;
use AuthGroups\Middleware\LoggingMiddleware;
use Exception;

/**
 * Contrôleur User simplifié utilisant UserSimplified
 * Version simplifiée sans injection de dépendance PDO
 */
class UserListController {
 
    /**
     * Obtenir tous les utilisateurs (pour les admins)
     */
    public function getAll($currentUserRole) {
        try {
            LoggingMiddleware::logEntry();            
            // Vérifier les permissions admin
            if (!RoleHelper::isAtLeast($currentUserRole, 'ADMINISTRATEUR')) {
                LogService::warning("Tentative d'accès non autorisé à la liste des utilisateurs", [
                    'role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès non autorisé', null, 403);
                return false;
            }            
            LogService::info("Récupération de la liste des utilisateurs par un administrateur", [
                'admin_role' => $currentUserRole
            ]);
            $input = Response::getRequestParams();
            $validate = Validator::validate($input, [
                'email' => 'optional|email',
                'page' => 'optional|integer|min:1',
                'limit' => 'optional|integer|min:1|max:100'
            ]);
            $pagination = Response::getPaginationParams();            
            $email  = $input['email'] ?? null;
            $user   = new User();
            $users  = $user->getAll($pagination['limit'], ($pagination['page'] - 1) * $pagination['limit'], $email);
            $total  = $user->countFiltered($email);
            $perPage = $pagination['limit'];

            LogService::info("Liste des utilisateurs récupérée avec succès", [
                'total_users' => $total,
                'page' => $pagination['page'],
                'per_page' => $perPage
            ]);
            LoggingMiddleware::logExit(200);
            Response::success('Liste des utilisateurs', [
                'users'       => $users,
                'total'       => $total,
                'page'        => $pagination['page'],
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage)
            ]);
            return true;            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des utilisateurs", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la récupération des utilisateurs');
            return false;
        }
    }
    
    /**
     * Obtenir un utilisateur par ID
     */
    public function getById($id, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();          
            // Vérifier les permissions (admin ou utilisateur lui-même)
            if (!RoleHelper::isAtLeast($currentUserRole, 'ADMINISTRATEUR') && $currentUserId != $id) {
                LogService::warning("Tentative d'accès non autorisé aux données utilisateur", [
                    'requested_id' => $id,
                    'current_user_id' => $currentUserId,
                    'role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès non autorisé', null, 403);
                return false;
            }            
            $user = new User();
            $userData = $user->findById($id);           
            if (!$userData) {
                LogService::info("Utilisateur non trouvé", ['id' => $id]);
                LoggingMiddleware::logExit(404);
                Response::error('Utilisateur non trouvé', null, 404);
                return false;
            }
            // Masquer le mot de passe
            unset($userData['password_hash']);
            LogService::info("Données utilisateur récupérées", [
                'user_id' => $id,
                'accessed_by' => $currentUserId
            ]);
            LoggingMiddleware::logExit(200);
            Response::success("Données utilisateur récupérées", [
                'user' => $userData,
            ]);
            return true;
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération de l'utilisateur", [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la récupération de l\'utilisateur');
            return false;
        }
    }
    
      
 
}
