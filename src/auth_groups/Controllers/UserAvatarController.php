<?php

namespace Memories\Controllers;

use Memories\Models\User;
use Memories\Services\EmailService;
use Memories\Utils\Response;
use Memories\Utils\Validator;
use Memories\Utils\FileValidator;
use Memories\Utils\Database;
use Firebase\JWT\JWT;
use Memories\Services\LogService;
use Memories\Middleware\LoggingMiddleware;
use Exception;

/**
 * Contrôleur User simplifié utilisant UserSimplified
 * Version simplifiée sans injection de dépendance PDO
 */
class UserAvatarController {
 
    /**
     * Upload d'un avatar utilisateur
     * POST /users/avatar
     * Nécessite authentification (user ou admin)
     */
    public function uploadAvatar($userId,$currentUserId, $currentUserRole) {
        try {                        
            LoggingMiddleware::logEntry();
            $input = Response::getRequestParams();      
            
            // Vérifier l'authentification
            if ( $currentUserRole !== 'ADMINISTRATEUR' && $userId !== $currentUserId) {
                LogService::warning("Tentative de modification de mot de passe par un non-admin", [
                    'current_user_id' => $currentUserId,
                    'target_user_id' => $userId,
                    'role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès non autorisé', null, 403);
                return false;
            }         
            $user = new User();
            $userData = $user->findById($userId);
            if (!$userData) {
                LogService::warning("Utilisateur non trouvé pour changement de mot de passe", ['input' => $input]);
                LoggingMiddleware::logExit(404);
                Response::error('Utilisateur non trouvé', null, 404);
            }
            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] === UPLOAD_ERR_NO_FILE) {
                LogService::warning("Aucun fichier avatar uploadé", [
                    'user_id' => $userId,
                    'current_user_id' => $currentUserId
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Aucun fichier avatar uploadé', null, 400);
                return false;
            }          
           // $root = dirname(__DIR__, 2);
           // require_once $root . '/src/utils/FileValidator.php';
            $validation = FileValidator::validateUploadedFile($_FILES['avatar'], 'image');
            if (!$validation['valid']) {
                LogService::warning("Fichier avatar invalide", [
                    'user_id' => $userId,
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Fichier avatar invalide', $validation['errors'], 400);
                return false;
            }
            // Générer un nom de fichier sécurisé
            $secureName = FileValidator::generateSecureFileName($_FILES['avatar']['name'], 'avatar_' . $userId . '_');
            $avatarDir = UPLOAD_DIR . '/avatars/';
            if (!is_dir($avatarDir)) {
                mkdir($avatarDir, 0775, true);
            }
            $targetPath = $avatarDir . $secureName;
            // Déplacer le fichier uploadé
            if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
                LogService::error("Erreur lors du déplacement du fichier avatar", [
                    'user_id' => $userId,
                    'target' => $targetPath
                ]);
                LoggingMiddleware::logExit(500);
                Response::error("Erreur lors de l'enregistrement du fichier avatar", null, 500);
                return false;
            }                    
            // Supprimer l'ancien avatar si présent
            if (!empty($userData['profile_image']) && file_exists($avatarDir . $userData['profile_image'])) {
                @unlink($avatarDir . $userData['profile_image']);
            }
            $user->id = $userId;
            $user->name = $userData['name'];
            $user->email = $userData['email'];
            $user->role = $userData['role'];
            $user->profile_image = $secureName;
            $user->bio = $userData['bio'];
            $user->phone = $userData['phone'];
            $user->date_of_birth = $userData['date_of_birth'];
            $user->location = $userData['location'];
            $user->email_verified = $userData['email_verified'];
            if ($user->update()) {
                LogService::info("Avatar utilisateur mis à jour", [
                    'user_id' => $userId,
                    'updated_by' => $currentUserId,
                    'is_admin_action' => $userId !== $currentUserId,
                    'file' => $secureName
                ]);
                LoggingMiddleware::logExit(200);
                Response::success([
                    'message' => 'Avatar mis à jour avec succès',
                    'data' => [
                        'avatar_url' => '/uploads/avatars/' . $secureName
                    ]
                ]);
                return true;
            } else {
                LogService::error("Échec de la mise à jour du profil avec avatar", [
                    'user_id' => $userId
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la mise à jour du profil utilisateur', null, 500);
                return false;
            }

        } catch (Exception $e) {
            LogService::error("Erreur lors de l'upload avatar", [
                'user_id' => $userId,
                'current_user_id' => $currentUserId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error("Erreur serveur lors de l'upload de l'avatar");
            return false;
        }
    }
   
}
