<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\User;
use AuthGroups\Models\Plan;
use AuthGroups\Models\ApiKey;
use AuthGroups\Services\EmailService;
use AuthGroups\Services\AuthService;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Utils\Database;
use AuthGroups\Services\LogService;
use AuthGroups\Services\UserSessionService;
use AuthGroups\Middleware\LoggingMiddleware;
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
            $validator = new Validator();
            $validation = $validator->validate($input, [
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
                $freePlan = Plan::findByName(Plan::PLAN_FREE);
                if ($freePlan) {
                    $pdo = \Database::getInstance()->getConnection();
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
                
                // 2. Créer une API key limitée (plan free)
                try {
                    $apiKeyConfig = Plan::getApiKeyConfigForPlan(Plan::PLAN_FREE);
                    $apiKeyResult = ApiKey::generate(
                        $createdUser['id'],
                        "Clé API gratuite - " . $createdUser['name'],
                        $apiKeyConfig
                    );
                    
                    // Marquer cette API key comme liée au plan gratuit
                    if ($freePlan && $apiKeyResult) {
                        $stmt = $pdo->prepare("
                            UPDATE api_keys 
                            SET plan_id = :plan_id, plan_limited = 1
                            WHERE id = :api_key_id
                        ");
                        $stmt->execute([
                            'plan_id' => $freePlan['id'],
                            'api_key_id' => $apiKeyResult['data']['id']
                        ]);
                    }
                    
                    LogService::info("API key gratuite créée", [
                        'user_id' => $createdUser['id'],
                        'api_key_id' => $apiKeyResult['data']['id'],
                        'key_prefix' => substr($apiKeyResult['key'], 0, 12) . '...'
                    ]);
                    
                } catch (Exception $apiKeyError) {
                    LogService::error("Erreur lors de la création de l'API key gratuite", [
                        'user_id' => $createdUser['id'],
                        'error' => $apiKeyError->getMessage()
                    ]);
                    // Continuer même si l'API key n'est pas créée
                    $apiKeyResult = null;
                }
                
                // 3. Générer un token de vérification d'email
                $verificationToken = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // Expire dans 24h
                
                // 4. Créer un token d'invitation pour choisir un plan
                $planInvitationToken = bin2hex(random_bytes(32));
                $planInvitationExpires = date('Y-m-d H:i:s', time() + (7 * 24 * 60 * 60)); // 7 jours
                
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
                $stmt = $pdo->prepare("
                    INSERT INTO plan_invitations (user_id, invitation_token, expires_at) 
                    VALUES (:user_id, :token, :expires_at)
                ");
                $stmt->execute([
                    'user_id' => $createdUser['id'],
                    'token' => $planInvitationToken,
                    'expires_at' => $planInvitationExpires
                ]);
                
                // 5. Envoyer l'email avec API key + invitation au choix de plan
                try {
                    $emailService = new EmailService();
                    $emailSent = $emailService->sendRegistrationWithApiKeyAndPlanInvitation(
                        $createdUser['email'],
                        $createdUser['name'],
                        $verificationToken,
                        $apiKeyResult ? $apiKeyResult['key'] : null,
                        $planInvitationToken
                    );
                    
                    if ($emailSent) {
                        LogService::info("Email d'inscription avec API key et invitation plan envoyé", [
                            'user_id' => $createdUser['id'],
                            'email' => $createdUser['email']
                        ]);
                    } else {
                        LogService::warning("Échec envoi email d'inscription", [
                            'user_id' => $createdUser['id'],
                            'email' => $createdUser['email']
                        ]);
                        Response::error("Échec de l'envoi de l'email d'inscription", null, 500);
                    }
                } catch (Exception $emailError) {
                    // Ne pas faire échouer la création si l'email ne peut pas être envoyé
                    LogService::error("Erreur lors de l'envoi de l'email d'inscription", [
                        'user_id' => $createdUser['id'],
                        'email' => $createdUser['email'],
                        'error' => $emailError->getMessage()
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
                        'id' => $createdUser['id'],
                        'name' => $createdUser['name'],
                        'email' => $createdUser['email'],
                        'role' => $createdUser['role'],
                        'profile_image' => $createdUser['profile_image'],
                        'bio' => $createdUser['bio'],
                        'phone' => $createdUser['phone'],
                        'date_of_birth' => $createdUser['date_of_birth'],
                        'location' => $createdUser['location'],
                        'email_verified' => $createdUser['email_verified'],
                        'last_login' => $createdUser['last_login'],
                        'created_at' => $createdUser['created_at'],
                        'updated_at' => $createdUser['updated_at'],
                        'plan' => 'free'
                    ],
                    'api_key' => [
                        'key' => $apiKeyResult ? $apiKeyResult['key'] : null,
                        'name' => $apiKeyResult ? $apiKeyResult['data']['name'] : null,
                        'environment' => $apiKeyResult ? $apiKeyResult['data']['environment'] : null,
                        'scopes' => $apiKeyResult ? json_decode($apiKeyResult['data']['scopes'], true) : [],
                        'rate_limit_per_minute' => $apiKeyResult ? $apiKeyResult['data']['rate_limit_per_minute'] : null,
                        'expires_at' => $apiKeyResult ? $apiKeyResult['data']['expires_at'] : null,
                        'plan_limited' => true
                    ],
                    'plan_invitation' => [
                        'token' => $planInvitationToken,
                        'expires_at' => $planInvitationExpires,
                        'available_plans' => ['bronze', 'argent', 'platine']
                    ],
                    'verification_token' => $verificationToken,
                    'auth_method' => 'api_key'
                ];
                
                // En développement, inclure les tokens pour les tests
                if(defined('APP_ENV') && APP_ENV === 'development') {
                    $responseData['verification_token'] = $verificationToken;
                    $responseData['plan_invitation_token'] = $planInvitationToken;
                }

                LoggingMiddleware::logExit(201);
                Response::success('Nouvel utilisateur créé avec API key gratuite. Un email de confirmation avec invitation aux plans payants a été envoyé.', $responseData, 201);
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
            $validator = new Validator();
            // Vérifier l'authentification
            if ($currentUserRole !== 'ADMINISTRATEUR' && $userId !== $currentUserId) {
                LogService::warning("Tentative de suppression non autorisée", [
                    'current_user_id' => $currentUserId,
                    'target_user_id' => $userId,
                    'role' => $currentUserRole
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Accès non autorisé', null, 403);
                return false;
            }
            if($currentUserId==$userId){
                $validation=$validator->validate($input, [
                    'password' => 'required|string'
                ]);
            } else{
                $validation=$validator->validate($input, [
                    "force_delete" => 'optional|boolean'
                ]);
            }
            if(!$validation['valid']) {
                LoggingMiddleware::logExit(400);
                Response::error('Validation échouée', $validation['errors'], 400);
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
            if($currentUserId==$userId){
                // test password
                if (!password_verify($input['password'], $userData['password_hash'])) {
                    LogService::warning("Mot de passe incorrect pour suppression utilisateur", [
                        'user_id' => $userId
                    ]);
                    LoggingMiddleware::logExit(403);
                    Response::error('Mot de passe incorrect', null, 403);
                    return false;
                }
                $force_delete = false;
            } else {
                $force_delete = $input['force_delete']?? false; // Par défaut, on fait un soft delete
            }
            if( $user->delete($force_delete)){
                LogService::info("Utilisateur supprimé (force delete = $force_delete)", [
                    'deleted_user_id' => $userId,
                    'deleted_by' => $currentUserId,
                    'deleted_user_name' => $userData['name']
                ]);                
                LoggingMiddleware::logExit(200);
                Response::success(['message' => 'Utilisateur supprimé avec succès']);
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
            if ($currentUserRole !== 'ADMINISTRATEUR' ) {
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
                Response::success(['message' => 'Utilisateur restauré avec succès']);
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
   
    /**
     * Authentification utilisateur pour LOGIN STRICT
     * Nécessite TOUJOURS email + password. Force le logout automatique par sécurité.
     */
    public function loginAuthenticate() {
        try {
            LoggingMiddleware::logEntry();
            
            $input = Response::getRequestParams();
            // Validation stricte des identifiants
            $validator = new Validator();
            $validation = $validator->validate($input, [
                'email' => 'required|email',
                'password' => 'required|string'
            ]);
            
            if (!$validation['valid']) {
                LogService::warning("Données d'authentification invalides", [
                    'errors' => $validation['errors']
                ]);
                LoggingMiddleware::logExit(400);
                Response::error('Données invalides', $validation['errors'], 400);
                return false;
            }
            
            // 🔥 LOGOUT AUTOMATIQUE FORCÉ pour route /login
            if (AUTH_AUTO_LOGOUT_BEFORE_LOGIN) {
                $tokensCleared = $this->silentLogout();
                if ($tokensCleared > 0 && AUTH_AUTO_LOGOUT_LOG_LEVEL !== 'none') {
                    LogService::info("Logout automatique effectué avant authentification", [
                        'tokens_cleared' => $tokensCleared
                    ]);
                }
            }
            
            // Authentifier avec email/password
            $user = new User();
            $userData = $user->authenticate($input['email'], $input['password']);
            
            if (!$userData) {
                LogService::warning("Tentative d'authentification échouée", [
                    'email' => $input['email']
                ]);
                LoggingMiddleware::logExit(401);
                Response::error('Email ou mot de passe incorrect', null, 401);
                return false;
            }
            
            // Vérifier si l'email n'est pas vérifié
            if (is_array($userData) && isset($userData['status']) && $userData['status'] === 'email_not_verified') {
                LogService::warning("Tentative de connexion avec email non vérifié", [
                    'email' => $input['email'],
                    'user_id' => $userData['user_data']['id']
                ]);
                LoggingMiddleware::logExit(403);
                Response::error('Email non vérifié', [
                    'code' => 'EMAIL_NOT_VERIFIED',
                    'message' => $userData['message'],
                    'actions' => [
                        'resend_verification' => [
                            'endpoint' => '/public/users/resend-verification',
                            'method' => 'POST',
                            'params' => ['email']
                        ],
                        'verify_email' => [
                            'endpoint' => '/public/users/verify-email',
                            'method' => 'POST',
                            'params' => ['token']
                        ]
                    ],
                    'user_email' => $input['email']
                ], 403);
                return false;
            }
            
            // Récupérer les informations de l'API Key utilisée pour ce login
            $apiKeyData = \AuthGroups\Middleware\ApiKeyAuthMiddleware::requireApiKey();
            if (!$apiKeyData) {
                LogService::error("Impossible de récupérer les données API Key lors du login");
                LoggingMiddleware::logExit(500);
                Response::error('Erreur d\'authentification API Key', null, 500);
                return false;
            }
            
            // Créer une nouvelle session utilisateur
            $sessionId = UserSessionService::createSession(
                $userData['id'], 
                $apiKeyData['id']
            );
            
            if (!$sessionId) {
                LogService::error("Impossible de créer la session utilisateur", [
                    'user_id' => $userData['id'],
                    'api_key_id' => $apiKeyData['id']
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de la création de session', null, 500);
                return false;
            }
                
            LogService::info("Authentification réussie (login)", [
                'user_id' => $userData['id'],
                'email' => $userData['email'],
                'session_id' => $sessionId,
                'api_key_id' => $apiKeyData['id']
            ]);
            
            LoggingMiddleware::logExit(200);
            Response::success("Connexion réussie", [
                'session_id' => $sessionId,
                'auth_method' => 'api_key',
                'api_key_name' => $apiKeyData['name'],
                'user' => [
                    'id' => $userData['id'],
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                    'role' => $userData['role']
                ]
            ]);
            
            return true;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de l'authentification", [
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de l\'authentification');
            return false;
        }
    }


    public function logout($userId) {
        try {
            LoggingMiddleware::logEntry();            
            
            // Vérifier si l'utilisateur existe
            $user = new User();
            $userData = $user->findById($userId);
            if (!$userData) {
                LogService::warning("Utilisateur non trouvé pour déconnexion", ['user_id' => $userId]);
                LoggingMiddleware::logExit(404);
                Response::error('Utilisateur non trouvé', null, 404);
                return false;
            }
            
            // Déterminer le mode d'authentification et gérer le logout en conséquence
            $sessionsEnded = 0;
            $authMode = 'api_key'; // Maintenant, tout est API Key obligatoire
            
            // Récupérer les informations de l'API Key utilisée
            $apiKeyData = \AuthGroups\Middleware\ApiKeyAuthMiddleware::requireApiKey();
            
            if ($apiKeyData) {
                // Terminer la session spécifique pour cette API Key
                $sessionsEnded = UserSessionService::endSession($userId, $apiKeyData['id']);
                LogService::info("Logout avec API Key - Session terminée", [
                    'user_id' => $userId,
                    'auth_mode' => $authMode,
                    'api_key_id' => $apiKeyData['id'],
                    'sessions_ended' => $sessionsEnded
                ]);
            } else {
                // Fallback : terminer toutes les sessions actives de l'utilisateur
                $sessionsEnded = UserSessionService::endAllUserSessions($userId);
                LogService::info("Logout sans API Key détectée - Toutes les sessions terminées", [
                    'user_id' => $userId,
                    'auth_mode' => $authMode,
                    'sessions_ended' => $sessionsEnded
                ]);
            }
            
            LogService::info("Déconnexion réussie", [
                'user_id' => $userId,
                'auth_mode' => $authMode,
                'sessions_ended' => $sessionsEnded
            ]);
            
            LoggingMiddleware::logExit(200);
            Response::success('Déconnexion réussie', [
                'auth_mode' => $authMode,
                'sessions_ended' => $sessionsEnded,
                'message' => 'Session(s) utilisateur terminée(s) avec succès'
            ]);
            return true;
            
        } catch (Exception $e) {
            LogService::error("Erreur lors de la déconnexion", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur serveur lors de la déconnexion', null, 500);
            return false;
        }
    }

    /**
     * Logout silencieux (pour usage interne, sans réponse HTTP)
     * Utilisé par loginAuthenticate() pour nettoyer les sessions existantes
     */
    private function silentLogout(): int {
        try {
            // Récupérer les informations de l'API Key utilisée
            $apiKeyData = \AuthGroups\Middleware\ApiKeyAuthMiddleware::requireApiKey();
            
            if ($apiKeyData) {
                if (AUTH_AUTO_LOGOUT_ALL_TOKENS) {
                    // Nettoyer toutes les sessions de l'utilisateur propriétaire de l'API Key
                    $sessionsEnded = UserSessionService::endAllUserSessions($apiKeyData['user_id']);
                    if (AUTH_AUTO_LOGOUT_LOG_LEVEL !== 'none') {
                        LogService::info("Toutes les sessions utilisateur nettoyées avant authentification", [
                            'user_id' => $apiKeyData['user_id'],
                            'api_key_id' => $apiKeyData['id'],
                            'sessions_ended' => $sessionsEnded
                        ]);
                    }
                    return $sessionsEnded;
                } else {
                    // Nettoyer seulement les sessions pour cette API Key spécifique
                    $sessionsEnded = UserSessionService::endSession($apiKeyData['user_id'], $apiKeyData['id']);
                    if (AUTH_AUTO_LOGOUT_LOG_LEVEL !== 'none') {
                        LogService::info("Sessions API Key spécifique nettoyées avant authentification", [
                            'user_id' => $apiKeyData['user_id'],
                            'api_key_id' => $apiKeyData['id'],
                            'sessions_ended' => $sessionsEnded
                        ]);
                    }
                    return $sessionsEnded;
                }
            }
            
            return 0; // Aucune session à nettoyer
            
        } catch (Exception $e) {
            LogService::warning("Erreur lors du nettoyage des sessions", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    public function updateProfile($userId,$currentUserId, $currentUserRole){
        try {
            LoggingMiddleware::logEntry();
            $input = Response::getRequestParams();
            $validator = new Validator();
            $validation = $validator->validate($input, [
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

            // Vérifier l'authentification
            if ( $currentUserRole !== 'ADMINISTRATEUR' && $userId !== $currentUserId) {
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
            $user->id = $userId;
            $user->name = $input['name'] ?? $userData['name'];
            $user->email = $input['email'] ?? $userData['email'];
            $user->role =  $userData['role'];
            $user->profile_image =  $userData['profile_image'];
            $user->bio = $input['bio'] ?? $userData['bio'];
            $user->phone = $input['phone'] ?? $userData['phone'];
            $user->date_of_birth = $input['date_of_birth'] ?? $userData['date_of_birth'];
            $user->location = $input['location'] ?? $userData['location'];
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

    public function confirmEmail(){
        try {  
            LoggingMiddleware::logEntry();
            $input = Response::getRequestParams();      
            $validator = new Validator();
            $validation = $validator->validate(
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
            
            $validator = new Validator();
            $validation = $validator->validate($input, [
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
                LoggingMiddleware::logExit(404);
                Response::error('Aucun compte associé à cette adresse email', null, 404);
                return false;
            }
            
            // Vérifier si l'email est déjà vérifié
            if ($userData['email_verified']) {
                LoggingMiddleware::logExit(400);
                Response::error('Cette adresse email est déjà vérifiée', null, 400);
                return false;
            }
            
            // Générer un nouveau token de vérification
            $verificationToken = bin2hex(random_bytes(32));
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
                    Response::success('Un nouvel email de vérification a été envoyé à votre adresse', [
                        'email' => $userData['email'],
                        'expires_in' => '24 heures'
                    ]);
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
