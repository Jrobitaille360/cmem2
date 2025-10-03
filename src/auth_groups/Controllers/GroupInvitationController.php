<?php

namespace Memories\Controllers;

use Memories\Models\Group;
use Memories\Models\User;
use Memories\Utils\Response;
use Memories\Utils\Validator;
use Memories\Services\LogService;
use Memories\Services\AuthService;
use Memories\Middleware\LoggingMiddleware;
use Exception;

class GroupInvitationController
{
    /**
     * Inviter un utilisateur à rejoindre un groupe
     */
    public function inviteUser(int $groupId, ?int $currentUserId = null, ?string $currentUserRole = null): bool {
        try {
            // Si les paramètres d'utilisateur ne sont pas fournis, utiliser l'authentification depuis les headers
            if ($currentUserId === null || $currentUserRole === null) {
                $token = AuthService::extractTokenFromHeader();
                if (!$token) {
                    Response::error('Token d\'authentification requis', null, 401);
                    return false;
                }

                $userData = AuthService::validateToken($token);
                if (!$userData) {
                    Response::error('Token invalide', null, 401);
                    return false;
                }
                
                $currentUserId = $userData['user_id'];
                $currentUserRole = $userData['role'];
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $validator = new Validator();
            $validation =$validator->validate($input,[
                'email' => 'required|email'
            ]);
            if (!$validation['valid']) {
                Response::error('email invalide ou requis',$validation['errors'],  400);
                return false;
            }

            $email = $input['email'];
            $role = $input['role'] ?? 'member';

            $group = new Group();
            // Vérifier que l'utilisateur a les permissions pour inviter
            if($currentUserRole !== 'ADMINISTRATEUR'){
                $userRole = $group->getMemberRole($groupId, $currentUserId);
                if ((!$userRole || ($userRole !== 'admin' && $userRole !== 'moderator'))) {
                    Response::error('Permissions insuffisantes pour inviter des utilisateurs', null, 403);
                    return false;
                }
            }
            
            // Vérifier si l'email existe dans la base
            $user = new User();
            $targetUser = $user->findByEmail($email);
            if (!$targetUser) {
                Response::error('Utilisateur non trouvé', null, 404);
                return false;
            }

            // Vérifier si l'utilisateur est déjà membre
            if ($group->isMember($groupId, $targetUser['id'])) {
                Response::error('L\'utilisateur est déjà membre de ce groupe', null, 400);
                return false;
            }

            // Utiliser la méthode existante inviteUser du modèle Group
            $inviteResult = $group->inviteUser($groupId, $email, $currentUserId, $role);
            
            if ($inviteResult['success']) {
                LogService::info('Invitation envoyée', [
                    'group_id' => $groupId,
                    'inviter_id' => $currentUserId,
                    'invited_email' => $email,
                    'role' => $role
                ]);

                Response::success('Invitation envoyée avec succès', [
                    'invite_url' => $inviteResult['invite_url'],
                    'invitation_token' => $inviteResult['invitation_token']
                ]);
                return true;
            } else {
                Response::error('Erreur lors de l\'envoi de l\'invitation', null, 500);
                return false;
            }

        } catch (Exception $e) {
            LogService::error('Erreur lors de l\'invitation', [
                'group_id' => $groupId,
                'error' => $e->getMessage()
            ]);
            Response::error('Erreur lors de l\'invitation', null, 500);
            return false;
        }
    }

    /**
     * Rejoindre un groupe via un token d'invitation
     */
    public function joinGroup(): bool {
        try {
            $email = $_GET['email'] ?? null;
            $role = $_GET['role'] ?? null;
            $code = $_GET['code'] ?? null;
            if (!$email || !$role || !$code) {
                Response::error('Paramètres manquants (email, role, code)', null, 400);
                return false;
            }
            $group = new Group();
            
            // Utiliser la méthode existante findByInviteCode
            $invitationData = $group->findByInviteCode($code);
            if (!$invitationData) {
                Response::error('Token d\'invitation invalide ou expiré', null, 400);
                return false;
            }

            // Vérifier que l'email correspond
            if ($invitationData['invited_email'] !== $email) {
                Response::error('Email ne correspond pas à l\'invitation', null, 400);
                return false;
            }

            $user = new User();
            $userData = $user->findByEmail($email);
            if (!$userData) {
                Response::error('Utilisateur non trouvé. Veuillez d\'abord créer un compte.', null, 404);
                return false;
            }

            // Vérifier si l'utilisateur est déjà membre
            if ($group->isMember($invitationData['group_id'], $userData['id'])) {
                Response::error('Vous êtes déjà membre de ce groupe', null, 400);
                return false;
            }

            // Utiliser la méthode existante addMember
            $addResult = $group->addMember($invitationData, $userData['id']);
            
            if (!$addResult['success']) {
                Response::error('Erreur lors de l\'ajout au groupe', null, 500);
                return false;
            }

            LogService::info('Utilisateur a rejoint le groupe', [
                'group_id' => $invitationData['group_id'],
                'user_id' => $userData['id'],
                'role' => $role
            ]);

            Response::success('Groupe rejoint avec succès', [
                'group_id' => $invitationData['group_id'],
                'role' => $invitationData['invited_role']
            ]);
            return true;

        } catch (Exception $e) {
            LogService::error('Erreur lors de l\'adhésion au groupe', [
                'error' => $e->getMessage()
            ]);
            Response::error('Erreur lors de l\'adhésion au groupe', null, 500);
            return false;
        }
    }

    public function myInvitations($email){
        try {
            LoggingMiddleware::logEntry();

            $group = new Group();
            $invitations = $group->getUserInvitations($email);

            LogService::info("Invitations récupérées", ['email' => $email]);
            LoggingMiddleware::logExit(200);
            Response::success("Invitations récupérées", $invitations);
            return true;
        } catch (Exception $e) {
            LogService::error("Erreur lors de la récupération des invitations", [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la récupération des invitations');
            return false;
        }
    }

    /**
     * Rejoindre un groupe via code d'invitation
     */
    public function joinByCode() {
        try {
            LoggingMiddleware::logEntry();            
            $input = Response::getRequestParams();
            
            // Validation des paramètres
            $validator = new Validator();
            $validation = $validator->validate($input, [
                "code" => "required|string|max:255",
                "email" => "required|email",
                "role" => "required|in:member,moderator,admin"
            ]);
            
            if (!$validation['valid']) {
                LogService::warning("Données invalides pour rejoindre le groupe", [
                    'errors' => $validation['errors'],
                    'input' => array_keys($input)
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Données invalides', $validation['errors'], 400);
                return false;
            }
            
            $group = new Group();
            $invitationData = $group->findByInviteCode($input['code']);
            
            if (!$invitationData) {
                LogService::warning("Code d'invitation non trouvé", [
                    'invite_code' => $input['code'],
                    'email' => $input['email']
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Code d\'invitation invalide ou expiré', null, 404);
                return false;
            }

            // Vérifier que l'email correspond à l'invitation
            if ($invitationData['invited_email'] !== $input['email']) {
                LogService::warning("Email ne correspond pas à l'invitation", [
                    'invite_code' => $input['code'],
                    'expected_email' => $invitationData['invited_email'],
                    'provided_email' => $input['email']
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Cette invitation n\'est pas pour cette adresse email', null, 403);
                return false;
            }

            $user = new User();
            $userData = $user->findByEmail($input['email']);
            
            if (!$userData) {
                LogService::warning("Utilisateur non trouvé pour rejoindre le groupe", [
                    'email' => $input['email']
                ]);
                LoggingMiddleware::logExit(404);
                Response::error('Utilisateur non trouvé. Veuillez d\'abord créer un compte.', null, 404);
                return false;
            }
            
            $currentUserId = $userData['id'];
            
            // Vérifier si l'utilisateur est déjà membre
            if ($group->isMember($invitationData['group_id'], $currentUserId)) {
                LogService::info("Utilisateur déjà membre du groupe", [
                    'group_id' => $invitationData['group_id'],
                    'email' => $input['email']
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Vous êtes déjà membre de ce groupe', null, 400);
                return false;
            }

            $groupData = $group->findById($invitationData['group_id']);
            
            // Vérifier la limite de membres
            $memberCount = $group->countMembers($invitationData['group_id']);
            if ($memberCount >= $groupData['max_members']) {
                LogService::warning("Groupe complet", [
                    'group_id' => $invitationData['group_id'],
                    'max_members' => $groupData['max_members'],
                    'current_count' => $memberCount
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Ce groupe a atteint sa limite de membres', null, 400);
                return false;
            }
            
            $addResult = $group->addMember($invitationData, $currentUserId);
            
            if (!$addResult['success']) {             
                LogService::error("Échec de l'ajout au groupe", [
                    'group_id' => $invitationData['group_id'],
                    'user_id' => $currentUserId,
                    'error' => $addResult['error'] ?? 'Erreur inconnue'
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de l\'ajout au groupe', $addResult);
                return false;
            }
            
            LogService::info("Utilisateur ajouté au groupe via invitation", [
                'group_id' => $invitationData['group_id'],
                'email' => $input['email'],
                'role' => $invitationData['invited_role'],
                'group_name' => $groupData['name']
            ]);
            
            LoggingMiddleware::logExit(200);
            Response::success('Vous avez rejoint le groupe avec succès', [
                'group_id' => $invitationData['group_id'],
                'group_name' => $groupData['name'],
                'role' => $invitationData['invited_role']               
            ]);
            return true;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de l'adhésion au groupe", [
                'error' => $e->getMessage(),
                'input' => $input ?? []
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de l\'adhésion au groupe');
            return false;
        }
    }


}
