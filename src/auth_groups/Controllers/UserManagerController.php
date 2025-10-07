<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\User;
use AuthGroups\Services\EmailService;
use AuthGroups\Services\AuthService;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Utils\Database;
use Firebase\JWT\JWT;
use AuthGroups\Services\LogService;
use AuthGroups\Services\ValidTokenService;
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
                // Générer un token de vérification d'email
                $verificationToken = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // Expire dans 24h                
                // Insérer le token de vérification dans la base de données
                $pdo = \Database::getInstance()->getConnection();
                $stmt = $pdo->prepare("
                    INSERT INTO email_verifications (user_id, token, expires_at) 
                    VALUES (:user_id, :token, :expires_at)
                ");
                $stmt->execute([
                    'user_id' => $createdUser['id'],
                    'token' => $verificationToken,
                    'expires_at' => $expiresAt
                ]);             
                // Envoyer l'email de vérification
                try {
                    $emailService = new EmailService();
                    $emailSent = $emailService->sendEmailVerification(
                        $createdUser['email'],
                        $createdUser['name'],
                        $verificationToken
                    );                    
                    if ($emailSent) {
                        LogService::info("Email de vérification envoyé", [
                            'user_id' => $createdUser['id'],
                            'email' => $createdUser['email']
                        ]);
                    } else {
                        LogService::warning("Échec envoi email de vérification", [
                            'user_id' => $createdUser['id'],
                            'email' => $createdUser['email']
                        ]);
                        Response::error("Échec de l'envoi de l'email de vérification", null, 500);
                    }
                } catch (Exception $emailError) {
                    // Ne pas faire échouer la création si l'email ne peut pas être envoyé
                    LogService::error("Erreur lors de l'envoi de l'email de vérification", [
                        'user_id' => $createdUser['id'],
                        'email' => $createdUser['email'],
                        'error' => $emailError->getMessage()
                    ]);
                    Response::error("Échec de l'envoi de l'email de vérification", null, 500);
                    return false;
                }               
                // Générer un token JWT
                $tokenPayload = [
                    'user_id' => $createdUser['id'],
                    'email' => $createdUser['email'],
                    'role' => $createdUser['role'],
                    'iat' => time(),
                    'exp' => time() + (24 * 60 * 60) // 24 heures
                ];                
                $token = JWT::encode($tokenPayload, $_ENV['JWT_SECRET'] ?? 'default_secret', 'HS256');                
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
                        'deleted_at' => $createdUser['deleted_at']
                    ],
                    'token' => $token,
                    // TODO LIGNE À ENLEVER EN PRODUCTION
                    'verification_token' => $verificationToken
                ];                
                LoggingMiddleware::logExit(201);
                Response::success('Nouvel utilisateur créé. Un email de vérification a été envoyé.', $responseData, 201);
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
            
            // 🛡️ SÉCURITÉ: Route /login DOIT avoir des identifiants
            $hasLoginData = isset($input['email']) && isset($input['password']) 
                && !empty($input['email']) && !empty($input['password']);
            
            if (!$hasLoginData) {
                // 🚨 SÉCURITÉ: Appel login sans identifiants = suspect
                LogService::warning("Tentative d'utilisation de /login sans identifiants - logout forcé", [
                    'input_keys' => array_keys($input),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                
                // Forcer logout par sécurité
                $this->silentLogout();
                
                LoggingMiddleware::logExit(400);
                Response::error('Identifiants requis pour la connexion', [
                    'email' => ['Le champ email est requis'],
                    'password' => ['Le champ password est requis']
                ], 400);
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
            

            //TODO JE CROIS QU'ON SE RÉPÈTE ICI. cette solution est plus conforme à la doc
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
            
            // Générer le token JWT
                $payload = [
                    'user_id' => $userData['id'],
                    'email' => $userData['email'],
                    'role' => $userData['role'],
                    'iat' => time(),
                    'exp' => time() + (24 * 60 * 60) // 24 heures
                ];                
                $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'] ?? 'default_secret', 'HS256');
                
                // Enregistrer le token dans la table des tokens valides
                $tokenRegistered = ValidTokenService::registerToken($jwt, $userData['id']);
                if (!$tokenRegistered) {
                    LogService::warning("Échec de l'enregistrement du token", [
                        'user_id' => $userData['id']
                    ]);
                    // On continue même si l'enregistrement du token échoue
                    // pour ne pas bloquer la connexion
                }
                
                LogService::info("Authentification réussie (login)", [
                    'user_id' => $userData['id'],
                    'email' => $userData['email'],
                    'token_registered' => $tokenRegistered
                ]);
                
                LoggingMiddleware::logExit(200);
                Response::success("Connexion réussie", [
                    'token' => $jwt,
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
            
            // Extraire le token depuis les headers en utilisant AuthService
            $token = AuthService::extractTokenFromHeader();
            
            $tokensRemoved = 0;
            
            if ($token) {
                // Supprimer le token spécifique
                if (ValidTokenService::removeToken($token)) {
                    $tokensRemoved = 1;
                    LogService::info("Token spécifique supprimé lors du logout", [
                        'user_id' => $userId
                    ]);
                } else {
                    LogService::warning("Échec de la suppression du token spécifique", [
                        'user_id' => $userId
                    ]);
                }
            } else {
                // Si pas de token dans les headers, supprimer tous les tokens de l'utilisateur
                $tokensRemoved = ValidTokenService::removeAllUserTokens($userId);
                LogService::info("Tous les tokens utilisateur supprimés lors du logout", [
                    'user_id' => $userId,
                    'tokens_removed' => $tokensRemoved
                ]);
            }
            
            LogService::info("Déconnexion réussie", [
                'user_id' => $userId,
                'tokens_removed' => $tokensRemoved
            ]);
            
            LoggingMiddleware::logExit(200);
            Response::success('Déconnexion réussie', [
                'tokens_invalidated' => $tokensRemoved
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
     * Utilisé par loginAuthenticate() pour nettoyer les tokens existants
     */
    private function silentLogout(): int {
        try {
            // Extraire le token depuis les headers en utilisant AuthService
            $token = AuthService::extractTokenFromHeader();
            
            if ($token) {
                if (AUTH_AUTO_LOGOUT_ALL_TOKENS) {
                    // Récupérer l'ID utilisateur depuis le token pour nettoyer tous ses tokens
                    $authService = new AuthService();
                    $userData = $authService->validateToken($token);
                    if ($userData && isset($userData['user_id'])) {
                        $tokensRemoved = ValidTokenService::removeAllUserTokens($userData['user_id']);
                        if (AUTH_AUTO_LOGOUT_LOG_LEVEL !== 'none') {
                            LogService::info("Tous les tokens utilisateur nettoyés avant authentification", [
                                'user_id' => $userData['user_id'],
                                'tokens_removed' => $tokensRemoved
                            ]);
                        }
                        return $tokensRemoved;
                    }
                } else {
                    // Supprimer seulement le token spécifique
                    if (ValidTokenService::removeToken($token)) {
                        if (AUTH_AUTO_LOGOUT_LOG_LEVEL !== 'none') {
                            LogService::info("Token existant nettoyé avant authentification");
                        }
                        return 1;
                    } else {
                        if (AUTH_AUTO_LOGOUT_LOG_LEVEL !== 'none') {
                            LogService::warning("Échec du nettoyage du token existant");
                        }
                        return 0;
                    }
                }
            }
            
            return 0; // Aucun token à nettoyer
            
        } catch (Exception $e) {
            LogService::warning("Erreur lors du nettoyage des tokens", [
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
            // Soft delete du token de reset s'il a été utilisé
            $stmt = $pdo->prepare("UPDATE email_verifications SET deleted_at = NOW() WHERE token = :token");
            $stmt->execute(['token' => $input['token']]);
            LoggingMiddleware::logExit(200);
            Response::success('Email confirmé avec succès');
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
