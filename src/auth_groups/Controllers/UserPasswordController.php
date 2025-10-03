<?php

namespace Memories\Controllers;

use Memories\Models\User;
use Memories\Services\EmailService;
use Memories\Utils\Response;
use Memories\Utils\Validator;
use Memories\Utils\Database;
use Firebase\JWT\JWT;
use Memories\Services\LogService;
use Memories\Middleware\LoggingMiddleware;
use Exception;

/**
 * Contrôleur User simplifié utilisant UserSimplified
 * Version simplifiée sans injection de dépendance PDO
 */
class UserPasswordController {

    public function requestPasswordChange(){
        // Logique pour demander un changement de mot de passe
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        $validator = new Validator();
        $validation = $validator->validate(
            $input,
            ['email' => 'required|email']
        );
        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données de validation invalides', $validation['errors'], 400);
            return false;
        }
        $user = new User();
        $userData = $user->findByEmail($input['email']);
        if (!$userData) {
            LogService::warning("Utilisateur non trouvé pour demande de changement de mot de passe", ['email' => $input['email']]);
            LoggingMiddleware::logExit(404);
            Response::error('Si.. le courriel existe, un courriel de demande de changement de mot de passe a été envoyé.', null, 404);
            return false;
        }
        // Générer un token de réinitialisation
        $token = bin2hex(random_bytes(32));
        // Insérer le token dans la table
        $pdo = \Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
        $stmt->execute(['user_id' => $userData['id'], 'token' => $token]);
        // Envoyer l'e-mail de demande de changement de mot de passe
        $emailService = new EmailService();
        $emailService->sendPasswordReset(
            $userData['email'],
            $token
        );
        LogService::info("Demande de changement de mot de passe envoyée", ['user_id' => $input['email']]);
        LoggingMiddleware::logExit(200);
        Response::success('Un courriel de demande de changement de mot de passe a été envoyé.', ['token' => $token]);
        return true;
    }

    /**
     * Changer le mot de passe (via token)
     */
    public function changePasswordToken() {
        try {  
            LoggingMiddleware::logEntry();
            $input = Response::getRequestParams();      
            $validator = new Validator();
            $validation = $validator->validate(
                $input,
                [
                    'new_password' => 'required|string|min:6',
                    'token' => 'required|string'
                ]
            );
            if (!$validation['valid']) {
                LoggingMiddleware::logExit(400);
                Response::error('Données de validation invalides', $validation['errors'], 400);
                return false;
            }                      
            $userModel = new User();        
            // Vérifier le token en base (token non expiré et non supprimé)
            $pdo = \Database::getInstance()->getConnection();
            $stmt = $pdo->prepare("SELECT user_id FROM password_resets WHERE token = :token AND expires_at > NOW() AND deleted_at IS NULL");
            $stmt->execute(['token' => $input['token']]);
            $row = $stmt->fetch();
            if (!$row) {
                LoggingMiddleware::logExit(404);
                Response::error('Token non trouvé ou expiré', null, 404);
                return false;
            }            
            $userId = $row['user_id'];
            $userData = $userModel->findById($userId);
            if (!$userData) {
                LoggingMiddleware::logExit(404);
                Response::error('Utilisateur non trouvé', null, 404);
                return false;
            }            
            // Mettre à jour le mot de passe
            $userModel->id = $userId;
            if ($userModel->updatePassword(password_hash($input['new_password'], PASSWORD_DEFAULT))) {
                // Soft delete du token de reset s'il a été utilisé
                $stmt = $pdo->prepare("UPDATE password_resets SET deleted_at = NOW() WHERE token = :token");
                $stmt->execute(['token' => $input['token']]);                
                LoggingMiddleware::logExit(200);
                Response::success('Mot de passe changé avec succès');
                return true;
            } else {
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la mise à jour du mot de passe', null, 500);
                return false;
            }
        } catch (Exception $e) {
            LogService::error("Erreur lors du changement de mot de passe", [
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors du changement de mot de passe', null, 500);
            return false;
        }
    }

    /**
     * Changer le mot de passe (authentifié)
     */
    public function changePassword($userId,$currentUserId, $currentUserRole) {
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
            // Vérifier l'authentification
            $validator = new Validator();
            if ( $userId !== $currentUserId) {
                $validation = $validator->validate(
                $input,
                [
                    'new_password' => 'required|string|min:6',
                ]
                );
            } else {
                $validation = $validator->validate(
                    $input,
                    [
                        'current_password' => 'required|string',
                        'new_password' => 'required|string|min:6',
                    ]
                );
            }           
            if (!$validation['valid']) {
                LoggingMiddleware::logExit(400);
                Response::error('Données de validation invalides', $validation['errors'], 400);
                return false;
            }
            $user = new User();
            $userData = $user->findById($userId);
            if (!$userData) {
                LogService::warning("Utilisateur non trouvé pour changement de mot de passe", ['input' => $input]);
                LoggingMiddleware::logExit(404);
                Response::error('Utilisateur non trouvé', null, 404);
                return false;
            }
            // On vérifie le mot de passe actuel dans ce cas
            if ($userId == $currentUserId && !password_verify($input['current_password'], $userData['password_hash'])) {
                LoggingMiddleware::logExit(401);
                Response::error('Mot de passe actuel incorrect', null, 401);
                return false;
            }
            $user->updatePassword(password_hash($input['new_password'], PASSWORD_DEFAULT));
            LogService::info("Mot de passe changé avec succès", ['user_id' => $userId]);
            LoggingMiddleware::logExit(200);
            Response::success('Mot de passe changé avec succès');
            return true;
        } catch (Exception $e) {
            LogService::error("Erreur lors du changement de mot de passe", [
                'input' => $input,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors du changement de mot de passe', null, 500);
            return false;
        }       
    }

}
