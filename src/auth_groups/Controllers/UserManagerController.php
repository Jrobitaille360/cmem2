<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\User;
use AuthGroups\Models\Plan;
use AuthGroups\Services\EmailService;
use AuthGroups\Services\AuthService;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\RoleHelper;
use AuthGroups\Utils\Validator;
use AuthGroups\Utils\Database;
use AuthGroups\Services\LogService;
use AuthGroups\Services\UserSessionService;
use AuthGroups\Middleware\LoggingMiddleware;
use Stripe\Config\CmemPlans;
use Exception;

/**
 * Contrôleur User simplifié utilisant UserSimplified
 * Version simplifiée sans injection de dépendance PDO
 */
class UserManagerController {
    
     /**
     * Créer un nouvel utilisateur
     */
    public function create() {
        try {
            LoggingMiddleware::logEntry();         
            $input = Response::getRequestParams();            
            // Validation selon la documentation API
            $validation = Validator::validate($input, [
                'name' => 'required|string|min:2|max:255',
                'email' => 'required|email|max:255',
                'password' => 'required|string|min:6',  // Changé de min:8 à min:6
                'bio' => 'string',
                'phone' => 'string',
                'date_of_birth' => 'date',
                'location' => 'string'
            ]);
            if (!$validation['valid']) {
                LogService::warning("Données de création utilisateur invalides", [
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Données invalides', $validation['errors'], 400);
                return false;
            }

            // Validation âge ≥ 16 (GDPR) via champ birthdate
            $birthdate = $input['birthdate'] ?? null;
            if ($birthdate !== null) {
                $bd = \DateTime::createFromFormat('Y-m-d', $birthdate);
                if (!$bd) {
                    LoggingMiddleware::logExit(422);
                    Response::error('Format birthdate invalide (YYYY-MM-DD)', null, 422);
                    return false;
                }
                $bd->setTime(0, 0, 0);
                $minBirthdate = new \DateTime('today midnight -16 years');
                if ($bd > $minBirthdate) {
                    LogService::warning("Inscription refusée — âge insuffisant", ['birthdate' => $birthdate]);
                    LoggingMiddleware::logExit(422);
                    Response::error('Vous devez avoir 16 ans ou plus.', ['error' => 'age_restriction'], 422);
                    return false;
                }
                // Stocker birthdate dans date_of_birth si non déjà fourni
                if (!isset($input['date_of_birth'])) {
                    $input['date_of_birth'] = $birthdate;
                }
            }

            $user = new User();

            // Vérifier si l'email existe déjà (incluant les comptes supprimés)
            if ($user->emailExists($input['email'], null, false)) {
                LogService::warning("Tentative de création avec email existant", [
                    'email' => $input['email']
                ]);
                LoggingMiddleware::logExit(409);
                Response::error('Cet email est déjà utilisé, peut-être désactivé. Vous devez vous connecter ou le réactiver', null, 409);
                return false;
            }            
            // Préparer les données
            $user->name = $input['name'];
            $user->email = $input['email'];
            $user->password_hash = password_hash($input['password'], PASSWORD_DEFAULT);
            $user->role = 'UTILISATEUR';
            $user->bio = $input['bio'] ?? null;
            $user->phone = $input['phone'] ?? null;
            $user->date_of_birth = $input['date_of_birth'] ?? null;
            $user->location = $input['location'] ?? null;
            $user->email_verified = 0;
            $user->profile_image = 'default.jpg'; // Avatar par défaut            
            if ($user->create()) {
                // Récupérer l'utilisateur créé avec toutes ses données
                $createdUser = $user->findById($user->id);
                
                // 1. Assigner le plan gratuit par défaut
                $pdo = \Database::getInstance()->getConnection();
                $freePlan = Plan::findByName(Plan::PLAN_FREE);
                if ($freePlan) {
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET plan_id = :plan_id, plan_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY)
                        WHERE id = :user_id
                    ");
                    $stmt->execute([
                        'plan_id' => $freePlan['id'],
                        'user_id' => $createdUser['id']
                    ]);
                    
                    LogService::info("Plan gratuit assigné", [
                        'user_id' => $createdUser['id'],
                        'plan_id' => $freePlan['id']
                    ]);
                }
                
                // 2. Générer un token de vérification d'email 1 chiffre de 1 à 9 et 7 chiffres de 0 à 9
                
                $verificationToken = mt_rand(1, 9) . str_pad(mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT);
                $expiresAt = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // Expire dans 24h
                
                // 4. Créer un token d'invitation pour choisir un plan
               // $planInvitationToken = bin2hex(random_bytes(32));
               // $planInvitationExpires = date('Y-m-d H:i:s', time() + (7 * 24 * 60 * 60)); // 7 jours
                
                // Insérer le token de vérification dans la base de données
                $stmt = $pdo->prepare("
                    INSERT INTO email_verifications (user_id, token, expires_at) 
                    VALUES (:user_id, :token, :expires_at)
                ");
                $stmt->execute([
                    'user_id' => $createdUser['id'],
                    'token' => $verificationToken,
                    'expires_at' => $expiresAt
                ]);
                
                // Insérer l'invitation au choix de plan
                //$stmt = $pdo->prepare(" INSERT INTO plan_invitations (user_id, invitation_token, expires_at) VALUES (:user_id, :token, :expires_at)");
                //$stmt->execute([ 'user_id' => $createdUser['id'], 'expires_at' => $planInvitationExpires ]);
                
                // 3. Envoyer l'email de vérification
                try {
                    $emailService = new EmailService();
                    $emailSent = $emailService->sendRegistrationVerification(
                        $createdUser['email'],
                        $createdUser['name'],
                        $verificationToken
                    );

                    if ($emailSent) {
                        LogService::info("Email de vérification d'inscription envoyé", [
                            'user_id' => $createdUser['id'],
                            'email'   => $createdUser['email']
                        ]);
                    } else {
                        LogService::warning("Échec envoi email d'inscription", [
                            'user_id' => $createdUser['id'],
                            'email'   => $createdUser['email']
                        ]);
                        Response::error("Échec de l'envoi de l'email d'inscription", null, 500);
                    }
                } catch (Exception $emailError) {
                    LogService::error("Erreur lors de l'envoi de l'email d'inscription", [
                        'user_id' => $createdUser['id'],
                        'email'   => $createdUser['email'],
                        'error'   => $emailError->getMessage()
                    ]);
                    Response::error("Échec de l'envoi de l'email d'inscription", null, 500);
                    return false;
                }               
                
                
                LogService::info("Nouvel utilisateur créé", [
                    'user_id' => $createdUser['id'],
                    'name' => $createdUser['name'],
                    'email' => $createdUser['email'],
                    'role' => $createdUser['role']
                ]);                
                // Format de réponse conforme à la documentation
                $responseData = [
                    'user' => [
                        'id'             => $createdUser['id'],
                        'name'           => $createdUser['name'],
                        'email'          => $createdUser['email'],
                        'role'           => $createdUser['role'],
                        'profile_image'  => $createdUser['profile_image'],
                        'bio'            => $createdUser['bio'],
                        'phone'          => $createdUser['phone'],
                        'date_of_birth'  => $createdUser['date_of_birth'],
                        'location'       => $createdUser['location'],
                        'email_verified' => $createdUser['email_verified'],
                        'last_login'     => $createdUser['last_login'],
                        'created_at'     => $createdUser['created_at'],
                        'updated_at'     => $createdUser['updated_at'],
                        'plan'           => 'free'
                    ],
                    'next_steps' => [
                        'verify_email' => 'Vérifiez votre email avec le token reçu : POST /users/verify-email',
                        'login'        => 'Connectez-vous ensuite : POST /auth/login',
                    ],
                    'auth_method' => 'jwt',
                ];

                // En développement, inclure le token de vérification pour les tests
                if (defined('APP_ENV') && APP_ENV === 'development') {
                    $responseData['verification_token'] = $verificationToken;
                }

                LoggingMiddleware::logExit(201);
                Response::success('Nouvel utilisateur créée ', $responseData, 201);
                return true;
            } else {
                LogService::error("Échec de la création utilisateur", [
                    'name' => $user->name,
                    'email' => $user->email
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la création de l\'utilisateur', null, 500);
                return false;
            }
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la création utilisateur", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la création de l\'utilisateur', null, 500);
            return false;
        }
    }
    
    /**
     * Supprimer un utilisateur (soft delete)
     */
    public function delete($userId, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            $input = Response::getRequestParams();
            $user = new User();
            $userData = $user->findById($userId);
            if (!$userData) {
                LogService::warning("Utilisateur non trouvé pour changement de mot de passe", ['input' => $input]);
                LoggingMiddleware::logExit(404);
                Response::error('Utilisateur non trouvé', null, 404);
                return false;
            }
            if($currentUserId==$userId){
                // JWT déjà validé par le middleware d'auth = premier facteur suffisant
                // (aucun second facteur système pour les comptes OTP, mot de passe aléatoire jamais connu de l'utilisateur)
                $force_delete = false;
            } else {
                // Matrice d'autorité de révocation (directive 20260716_113000) :
                // - un SUPERADMINISTRATEUR ne peut être révoqué par personne via l'API ;
                // - un ADMINISTRATEUR ne peut être révoqué que par un SUPERADMINISTRATEUR ;
                // - un UTILISATEUR peut être révoqué par ADMINISTRATEUR ou plus.
                $targetRole = $userData['role'];
                $callerIsSuperadmin = $currentUserRole === 'SUPERADMINISTRATEUR';
                $authorized = $targetRole === 'SUPERADMINISTRATEUR'
                    ? false
                    : ($targetRole === 'ADMINISTRATEUR' ? $callerIsSuperadmin : RoleHelper::isAtLeast($currentUserRole, 'ADMINISTRATEUR'));
                if (!$authorized) {
                    LogService::warning("Tentative de suppression non autorisée", [
                        'current_user_id' => $currentUserId,
                        'target_user_id' => $userId,
                        'role' => $currentUserRole,
                        'target_role' => $targetRole
                    ]);
                    LoggingMiddleware::logExit(403);
                    Response::error('Accès non autorisé', null, 403);
                    return false;
                }

                $validation = Validator::validate($input, [
                    "force_delete" => 'optional|boolean'
                ]);
                if (!$validation['valid']) {
                    LoggingMiddleware::logExit(400);
                    Response::error('Validation échouée', $validation['errors'], 400);
                    return false;
                }

                $force_delete = $input['force_delete']?? false; // Par défaut, on fait un soft delete
            }
            if( $user->delete($force_delete)){
                LogService::info("Utilisateur supprimé (force delete = $force_delete)", [
                    'deleted_user_id' => $userId,
                    'deleted_by' => $currentUserId,
                    'deleted_user_name' => $userData['name']
                ]);                
                LoggingMiddleware::logExit(200);
                Response::success('Utilisateur supprimé avec succès', ['deleted' => true]);
                return true;
            } else {
                LogService::error("Échec de la suppression utilisateur", [
                    'user_id' => $userId
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la suppression de l\'utilisateur');
                return false;
            }
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la suppression utilisateur", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la suppression de l\'utilisateur');
            return false;
        }
    }
  /**
     * Supprimer un utilisateur (soft delete)
     */
    public function restore($userId, $currentUserId, $currentUserRole) {
        try {
            LoggingMiddleware::logEntry();
            // Vérifier l'authentification
            if (!RoleHelper::isAtLeast($currentUserRole, 'ADMINISTRATEUR') ) {
                LogService::warning("Tentative de suppression non autorisée", [
                    'current_user_id' => $currentUserId,
                    'target_user_id' => $userId,
                    'role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès non autorisé', null, 403);
                return false;
            }
            $user = new User();
            $userData = $user->findById($userId, true);
            if (!$userData) {
                LogService::warning("Utilisateur non trouvé pour undelete", ['userId' => $userId]);
                LoggingMiddleware::logExit(404);
                Response::error('Utilisateur non trouvé', null, 404);
                return false;
            }
            if( $user->restore()){
                LogService::info("Utilisateur restauré ", [
                    'restored_user_id' => $userId,
                    'restored_by' => $currentUserId,
                    'restored_user_name' => $userData['name']
                ]);
                LoggingMiddleware::logExit(200);
                Response::success('Utilisateur restauré avec succès', ['restored' => true]);
                return true;
            } else {
                LogService::error("Échec de la restauration utilisateur", [
                    'user_id' => $userId
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la restauration de l\'utilisateur');
                return false;
            }
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la restauration utilisateur", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la restauration de l\'utilisateur');
            return false;
        }
    }
   
    public function updateProfile($userId,$currentUserId, $currentUserRole){
        try {
            LoggingMiddleware::logEntry();
            $input = Response::getRequestParams();
            $validation = Validator::validate($input, [
                'name' => 'string|max:100',
                'email' => 'email|max:100',
                'bio' => 'nullable|string|max:500',
                'phone' => 'nullable|string|max:20',
                'date_of_birth' => 'nullable|date_format:Y-m-d',
                'location' => 'nullable|string|max:100',
            ]);
            if (!$validation['valid']) {
                LoggingMiddleware::logExit(400);
                Response::error('Données de validation invalides', $validation['errors'], 400);
                return false;
            }
            if (array_key_exists('role', $input) && !Validator::validateUserRole($input['role'])) {
                LoggingMiddleware::logExit(422);
                Response::error('Données de validation invalides', ['role' => ['valeur non reconnue']], 422);
                return false;
            }

            // timezone : identifiant IANA (ex. Europe/Paris) ou null pour revenir au repli
            // (fuseau du premier calendrier, sinon America/Montreal). Champ absent = inchangé.
            if (array_key_exists('timezone', $input) && $input['timezone'] !== null) {
                if (!is_string($input['timezone'])
                    || !in_array($input['timezone'], timezone_identifiers_list(), true)) {
                    LoggingMiddleware::logExit(422);
                    Response::error('Données de validation invalides',
                        ['timezone' => ['identifiant de fuseau IANA non reconnu']], 422);
                    return false;
                }
            }

            // Vérifier l'authentification
            if ( !RoleHelper::isAtLeast($currentUserRole, 'ADMINISTRATEUR') && $userId !== $currentUserId) {
                LogService::warning("Tentative de modification de profil par un non-admin", [
                    'current_user_id' => $currentUserId,
                    'target_user_id' => $userId,
                    'role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès non autorisé', null, 403);
                return false;
            }
            // Mettre à jour le profil utilisateur
            $user = new User();
            $userData = $user->findById($userId);
            if (!$userData) {
                LogService::warning("Utilisateur pour modification de profil non trouvé", ['user_id' => $userId]);
                LoggingMiddleware::logExit(404);
                Response::error('Utilisateur non trouvé', null, 404);
                return false;
            }
            // Changement de rôle (matrice d'autorité — directive 20260716_113000)
            if (array_key_exists('role', $input) && $input['role'] !== $userData['role']) {
                $requestedRole = $input['role'];
                if ($userData['role'] === 'SUPERADMINISTRATEUR' || $requestedRole === 'SUPERADMINISTRATEUR') {
                    // Un superadmin ne peut être ni modifié, ni créé via l'API (DB-only).
                    LogService::warning("Tentative de changement de rôle impliquant SUPERADMINISTRATEUR refusée", [
                        'current_user_id' => $currentUserId,
                        'target_user_id' => $userId,
                        'target_current_role' => $userData['role'],
                        'requested_role' => $requestedRole
                    ]);
                    LoggingMiddleware::logExit(403);
                    Response::error('Accès non autorisé', null, 403);
                    return false;
                }
                $callerIsSuperadmin = $currentUserRole === 'SUPERADMINISTRATEUR';
                $isAdminPromotion = $userData['role'] === 'UTILISATEUR' && $requestedRole === 'ADMINISTRATEUR';
                if (!$callerIsSuperadmin && !$isAdminPromotion) {
                    LogService::warning("Tentative de changement de rôle non autorisée", [
                        'current_user_id' => $currentUserId,
                        'target_user_id' => $userId,
                        'role' => $currentUserRole,
                        'target_current_role' => $userData['role'],
                        'requested_role' => $requestedRole
                    ]);
                    LoggingMiddleware::logExit(403);
                    Response::error('Accès non autorisé', null, 403);
                    return false;
                }
                $newRole = $requestedRole;
            } else {
                $newRole = $userData['role'];
            }

            $user->id = $userId;
            $user->name = $input['name'] ?? $userData['name'];
            $user->email = $input['email'] ?? $userData['email'];
            $user->role =  $newRole;
            $user->profile_image =  $userData['profile_image'];
            $user->bio = $input['bio'] ?? $userData['bio'];
            $user->phone = $input['phone'] ?? $userData['phone'];
            $user->date_of_birth = $input['date_of_birth'] ?? $userData['date_of_birth'];
            $user->location = $input['location'] ?? $userData['location'];
            $user->timezone = array_key_exists('timezone', $input)
                ? $input['timezone']                 // null explicite = retour au repli
                : ($userData['timezone'] ?? null);   // champ absent = valeur conservée
            $user->email_verified = $userData['email_verified'];
            if ($user->update()) {
                LogService::info("Profil utilisateur mis à jour", [
                    'user_id' => $userId,
                    'updated_by' => $currentUserId,
                    'is_admin_action' => $userId !== $currentUserId,
                    'input' => $input
                ]);            
                // Récupérer les données mises à jour
                $updatedUser = $user->findById($userId);
                unset($updatedUser['password_hash']); // Ne pas retourner le hash du mot de passe               
                LoggingMiddleware::logExit(200);
                Response::success('Profil mis à jour avec succès', $updatedUser);
                return true;
            } else {
                LogService::error("Échec de la mise à jour du profil", [
                    'user_id' => $userId,
                    'updated_by' => $currentUserId,
                    'is_admin_action' => $userId !== $currentUserId,
                    'input' => $input
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la mise à jour du profil utilisateur', null, 500);
                return false;
            }

        } catch (Exception $e) {
            LogService::error("Erreur lors de la mise à jour du profil", [
                'user_id' => $userId,
                'current_user_id' => $currentUserId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error("Erreur serveur lors de la mise à jour du profil utilisateur");
            return false;
        }
    }

    /**
     * Poser/retirer l'assignation manuelle du plan cmem (users.cmem_plan_override)
     * PUT /users/{id}/plan-override — SUPERADMINISTRATEUR seul (décision de facturation)
     * Directive 20260716_090000_cmem_web_vers_cmem2_API__admin-assignation-plan-ami
     * Resserré depuis ADMINISTRATEUR par la directive 20260716_113000_cmem_web_vers_cmem2_API__role-superadministrateur
     */
    public function updatePlanOverride($userId, $currentUserId, $currentUserRole){
        try {
            LoggingMiddleware::logEntry();

            if ($currentUserRole !== 'SUPERADMINISTRATEUR') {
                LogService::warning("Tentative d'assignation de plan override par un non-superadmin", [
                    'current_user_id' => $currentUserId,
                    'target_user_id' => $userId,
                    'role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès non autorisé', null, 403);
                return false;
            }

            $input = Response::getRequestParams();
            if (!array_key_exists('cmem_plan_override', $input)) {
                LoggingMiddleware::logExit(400);
                Response::error('Données de validation invalides', ['cmem_plan_override' => ['champ requis']], 400);
                return false;
            }

            $planOverride = $input['cmem_plan_override'];
            if ($planOverride !== null && !in_array($planOverride, CmemPlans::overridableCodes(), true)) {
                LoggingMiddleware::logExit(422);
                Response::error('Données de validation invalides', ['cmem_plan_override' => ['valeur non reconnue']], 422);
                return false;
            }

            $user = new User();
            $userData = $user->findById($userId);
            if (!$userData) {
                LogService::warning("Utilisateur pour assignation de plan override non trouvé", ['user_id' => $userId]);
                LoggingMiddleware::logExit(404);
                Response::error('Utilisateur non trouvé', null, 404);
                return false;
            }

            if ($user->updatePlanOverride($userId, $planOverride)) {
                LogService::info("Plan override assigné", [
                    'user_id' => $userId,
                    'updated_by' => $currentUserId,
                    'cmem_plan_override' => $planOverride
                ]);
                $updatedUser = $user->findById($userId);
                unset($updatedUser['password_hash']);
                LoggingMiddleware::logExit(200);
                Response::success('Plan override mis à jour avec succès', $updatedUser);
                return true;
            } else {
                LogService::error("Échec de l'assignation du plan override", [
                    'user_id' => $userId,
                    'updated_by' => $currentUserId
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la mise à jour du plan override', null, 500);
                return false;
            }
        } catch (Exception $e) {
            LogService::error("Erreur lors de l'assignation du plan override", [
                'user_id' => $userId,
                'current_user_id' => $currentUserId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error("Erreur serveur lors de la mise à jour du plan override");
            return false;
        }
    }

    public function confirmEmail(){
        try {  
            LoggingMiddleware::logEntry();
            $input = Response::getRequestParams();      
            $validation = Validator::validate(
                $input,
                [
                    'token' => 'required|string'
                ]
            );
            if (!$validation['valid']) {
                LoggingMiddleware::logExit(400);
                Response::error('Données de validation invalides', $validation['errors'], 400);
                return false;
            }                      
            $userModel = new User();
            $userData = null;            
            // Vérifier le token en base (token non expiré et non supprimé)
            $pdo = \Database::getInstance()->getConnection();
            $stmt = $pdo->prepare("SELECT user_id FROM email_verifications WHERE token = :token AND expires_at > NOW() AND deleted_at IS NULL");
            $stmt->execute(['token' => $input['token']]);
            $row = $stmt->fetch();
            if (!$row) {
                LoggingMiddleware::logExit(404);
                Response::error('token non trouvé', null, 404);
                return false;
            }
            $userId = $row['user_id'];
            $userData = $userModel->findById($userId);
            if (!$userData) {
                LoggingMiddleware::logExit(404);
                Response::error('Token invalide...', null, 404);
                return false;
            }
            // Vérifier si l'email est déjà vérifié
            if ($userData['email_verified']) {
                LoggingMiddleware::logExit(400);
                Response::error('Email déjà vérifié', null, 400);
                return false;
            }
            // Update email_verified
            $userModel->markEmailAsVerified($userId);
            
            // 1. Étendre les limites du plan free après confirmation d'email
            try {
                // Étendre l'expiration du plan gratuit à 30 jours au lieu de 7
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET plan_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
                    WHERE id = :user_id AND plan_id = (SELECT id FROM plans WHERE name = 'free' LIMIT 1)
                ");
                $stmt->execute(['user_id' => $userId]);
                
                // Étendre l'expiration des API keys liées au plan gratuit
                $stmt = $pdo->prepare("
                    UPDATE api_keys 
                    SET expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY),
                        rate_limit_per_minute = 30,
                        rate_limit_per_hour = 1800
                    WHERE user_id = :user_id 
                    AND plan_limited = 1 
                    AND plan_id = (SELECT id FROM plans WHERE name = 'free' LIMIT 1)
                ");
                $stmt->execute(['user_id' => $userId]);
                
                LogService::info("Plan gratuit étendu après confirmation email", [
                    'user_id' => $userId,
                    'new_expiry' => '30 jours',
                    'new_rate_limit' => '30/minute'
                ]);
                
            } catch (Exception $planError) {
                LogService::error("Erreur lors de l'extension du plan gratuit", [
                    'user_id' => $userId,
                    'error' => $planError->getMessage()
                ]);
                // Ne pas faire échouer la confirmation d'email pour cette erreur
            }
            
            // 2. Créer une nouvelle invitation aux plans payants avec délai étendu
            try {
                $planInvitationToken = bin2hex(random_bytes(32));
                $planInvitationExpires = date('Y-m-d H:i:s', time() + (15 * 24 * 60 * 60)); // 15 jours
                
                // Invalider les anciennes invitations
                $stmt = $pdo->prepare("
                    UPDATE plan_invitations 
                    SET status = 'expired' 
                    WHERE user_id = :user_id AND status = 'pending'
                ");
                $stmt->execute(['user_id' => $userId]);
                
                // Créer nouvelle invitation
                $stmt = $pdo->prepare("
                    INSERT INTO plan_invitations (user_id, invitation_token, expires_at) 
                    VALUES (:user_id, :token, :expires_at)
                ");
                $stmt->execute([
                    'user_id' => $userId,
                    'token' => $planInvitationToken,
                    'expires_at' => $planInvitationExpires
                ]);
                
                // 3. Envoyer email de félicitations avec nouvelle invitation aux plans
                $emailService = new EmailService();
                $emailSent = $emailService->sendEmailConfirmedWithPlanReminder(
                    $userData['email'],
                    $userData['name'],
                    $planInvitationToken,
                    30 // jours d'extension
                );
                
                if ($emailSent) {
                    LogService::info("Email de félicitations avec invitation plan envoyé", [
                        'user_id' => $userId,
                        'email' => $userData['email']
                    ]);
                } else {
                    LogService::warning("Échec envoi email félicitations", [
                        'user_id' => $userId,
                        'email' => $userData['email']
                    ]);
                }
                
            } catch (Exception $invitationError) {
                LogService::error("Erreur lors de la création d'invitation plan", [
                    'user_id' => $userId,
                    'error' => $invitationError->getMessage()
                ]);
                // Ne pas faire échouer la confirmation d'email pour cette erreur
            }
            
            // Soft delete du token de reset s'il a été utilisé
            $stmt = $pdo->prepare("UPDATE email_verifications SET deleted_at = NOW() WHERE token = :token");
            $stmt->execute(['token' => $input['token']]);
            
            LoggingMiddleware::logExit(200);
            Response::success('Email confirmé avec succès. Votre plan gratuit a été étendu à 30 jours avec des limites améliorées !', [
                'email_verified' => true,
                'plan_extended' => true,
                'new_plan_expiry_days' => 30,
                'improved_limits' => [
                    'rate_limit_per_minute' => 30,
                    'rate_limit_per_hour' => 1800
                ]
            ]);
            return true;
        } catch (Exception $e) {
            LogService::error("Erreur lors de la confirmation de l'email", [
                'input' => $input,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la confirmation de l\'email', null, 500);
            return false;
        }
    }

    /**
     * Renvoyer l'email de vérification pour un utilisateur
     */
        public function resendVerificationEmail() {
        try {
            LoggingMiddleware::logEntry();
            $input = Response::getRequestParams();
            
            $validation = Validator::validate($input, [
                'email' => 'required|email'
            ]);
            
            if (!$validation['valid']) {
                LoggingMiddleware::logExit(400);
                Response::error('Données de validation invalides', $validation['errors'], 400);
                return false;
            }
            
            $userModel = new User();
            $userData = $userModel->findByEmail($input['email']);
            
            if (!$userData) {
                LoggingMiddleware::logExit(200);
                Response::success('Si cette adresse est associée à un compte non vérifié, un email de vérification sera envoyé.');
                return true;
            }

            // Vérifier si l'email est déjà vérifié
            if ($userData['email_verified']) {
                LoggingMiddleware::logExit(200);
                Response::success('Si cette adresse est associée à un compte non vérifié, un email de vérification sera envoyé.');
                return true;
            }
            
            // Générer un nouveau token de vérification
            $verificationToken = mt_rand(1, 9) . str_pad(mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT);          
            $expiresAt = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // Expire dans 24h
            
            // Invalider les anciens tokens de vérification pour cet utilisateur
            $pdo = \Database::getInstance()->getConnection();
            $stmt = $pdo->prepare("UPDATE email_verifications SET deleted_at = NOW() WHERE user_id = :user_id AND deleted_at IS NULL");
            $stmt->execute(['user_id' => $userData['id']]);
            
            // Insérer le nouveau token de vérification
            $stmt = $pdo->prepare("
                INSERT INTO email_verifications (user_id, token, expires_at) 
                VALUES (:user_id, :token, :expires_at)
            ");
            $stmt->execute([
                'user_id' => $userData['id'],
                'token' => $verificationToken,
                'expires_at' => $expiresAt
            ]);
            
            // Envoyer l'email de vérification
            try {
                $emailService = new EmailService();
                $emailSent = $emailService->sendEmailVerification(
                    $userData['email'],
                    $userData['name'],
                    $verificationToken
                );
                
                if ($emailSent) {
                    LogService::info("Email de vérification renvoyé avec succès", [
                        'user_id' => $userData['id'],
                        'email' => $userData['email']
                    ]);
                    LoggingMiddleware::logExit(200);
                    if($_ENV['APP_ENV'] === 'development') {
                        Response::success('Un nouvel email de vérification a été envoyé à votre adresse', [
                            'email' => $userData['email'],
                            'token_expires_at' => $expiresAt,
                            'verification_token' => $verificationToken,
                        ]);
                        return true;
                    } else {   
                        Response::success('Un nouvel email de vérification a été envoyé à votre adresse', [
                            'email' => $userData['email'],
                            'token_expires_at' => $expiresAt,                      
                        ]);
                    }
                    return true;
                } else {
                    LogService::warning("Échec renvoi email de vérification", [
                        'user_id' => $userData['id'],
                        'email' => $userData['email']
                    ]);
                    LoggingMiddleware::logExit(500);
                    Response::error("Échec de l'envoi de l'email de vérification", null, 500);
                    return false;
                }
            } catch (Exception $emailError) {
                LogService::error("Erreur lors du renvoi de l'email de vérification", [
                    'user_id' => $userData['id'],
                    'email' => $userData['email'],
                    'error' => $emailError->getMessage()
                ]);
                LoggingMiddleware::logExit(500);
                Response::error("Erreur lors de l'envoi de l'email de vérification", null, 500);
                return false;
            }
            
        } catch (Exception $e) {
            LogService::error("Erreur lors du renvoi de l'email de vérification", [
                'input' => $input,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors du renvoi de l\'email de vérification', null, 500);
            return false;
        }
    }
     

}
