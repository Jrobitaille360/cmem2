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
use AuthGroups\Services\RateLimitService;
use AuthGroups\Services\UserSessionService;
use AuthGroups\Middleware\LoggingMiddleware;
use Stripe\Config\CmemPlans;
use Exception;

/**
 * Contrôleur User simplifié utilisant UserSimplified
 * Version simplifiée sans injection de dépendance PDO
 */
class UserManagerController {

    /** Longueur du token de vérification de courriel (confirmée à jdb : 8 chiffres). */
    const VERIFY_CODE_LENGTH = 8;

    /** Message unique renvoyé par resend-verification-email (anti-énumération de comptes). */
    const GENERIC_RESEND_MESSAGE = 'Si cette adresse est associée à un compte non vérifié, un email de vérification sera envoyé.';

    /** Message unique renvoyé sur token de vérification refusé (ne révèle rien sur le compte). */
    const GENERIC_VERIFY_ERROR = 'Token invalide ou expiré';

    /**
     * Indique si le token de vérification fixe de développement est actif.
     * Toujours faux en production : la constante y est forcée à ''.
     */
    private function verificationFixedCode(): string
    {
        if (defined('EMAIL_VERIFICATION_TEST_CODE_IGNORED') && EMAIL_VERIFICATION_TEST_CODE_IGNORED) {
            LogService::warning('EMAIL_VERIFICATION_TEST_CODE défini en production — variable ignorée');
        }
        return defined('EMAIL_VERIFICATION_TEST_CODE') ? EMAIL_VERIFICATION_TEST_CODE : '';
    }

    /**
     * Génère un token numérique de VERIFY_CODE_LENGTH chiffres, premier chiffre 1-9.
     */
    private function generateVerificationToken(): string
    {
        $token = (string) random_int(1, 9);
        for ($i = 1; $i < self::VERIFY_CODE_LENGTH; $i++) {
            $token .= random_int(0, 9);
        }
        return $token;
    }

    /**
     * Crée un token de vérification pour un usager et l'insère en base.
     *
     * Le token n'est jamais renvoyé au client : il ne sort que par courriel.
     * En développement, EMAIL_VERIFICATION_TEST_CODE impose un token fixe
     * (les lignes portant déjà ce token sont libérées — contrainte UNIQUE).
     *
     * @return array{token: string, expires_at: string, fixed: bool}
     */
    private function issueVerificationToken(\PDO $pdo, int $userId): array
    {
        $fixedCode  = $this->verificationFixedCode();
        $useFixed   = $fixedCode !== '';
        $expiryH    = defined('EMAIL_VERIFICATION_EXPIRY_HOURS') ? (int) EMAIL_VERIFICATION_EXPIRY_HOURS : 24;
        $maxAttempts = defined('EMAIL_VERIFICATION_MAX_ATTEMPTS') ? (int) EMAIL_VERIFICATION_MAX_ATTEMPTS : 5;
        $expiresAt  = date('Y-m-d H:i:s', time() + ($expiryH * 3600));

        // Un seul token actif par usager
        $pdo->prepare("DELETE FROM email_verifications WHERE user_id = :user_id")
            ->execute(['user_id' => $userId]);
        if ($useFixed) {
            // `token` porte une contrainte UNIQUE : libérer le code fixe détenu par un autre usager
            $pdo->prepare("DELETE FROM email_verifications WHERE token = :token")
                ->execute(['token' => $fixedCode]);
        }

        $token    = null;
        $inserted = false;
        for ($try = 0; $try < 5 && !$inserted; $try++) {
            $token = $useFixed ? $fixedCode : $this->generateVerificationToken();
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO email_verifications (user_id, token, attempts, max_attempts, expires_at)
                     VALUES (:user_id, :token, 0, :max_attempts, :expires_at)"
                );
                $stmt->execute([
                    'user_id'      => $userId,
                    'token'        => $token,
                    'max_attempts' => $maxAttempts,
                    'expires_at'   => $expiresAt,
                ]);
                $inserted = true;
            } catch (\PDOException $e) {
                // 23000 = violation d'unicité → régénérer un token
                if ($e->getCode() !== '23000' || $useFixed) {
                    throw $e;
                }
                $pdo->query("DELETE FROM email_verifications WHERE expires_at < NOW()");
            }
        }

        if (!$inserted) {
            throw new Exception('Impossible de générer un token de vérification unique');
        }

        return ['token' => $token, 'expires_at' => $expiresAt, 'fixed' => $useFixed];
    }

    /**
     * Incrémente le compteur de tentatives du token actif d'un usager.
     * Supprime le token si le maximum est atteint.
     *
     * @return bool true si le token vient d'être invalidé (→ répondre 429)
     */
    private function registerFailedVerification(\PDO $pdo, string $email): bool
    {
        $stmt = $pdo->prepare(
            "SELECT ev.id, ev.attempts, ev.max_attempts
               FROM email_verifications ev
               JOIN users u ON u.id = ev.user_id
              WHERE u.email = :email AND ev.expires_at > NOW() AND ev.deleted_at IS NULL
              ORDER BY ev.id DESC LIMIT 1"
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }

        $attempts = (int) $row['attempts'] + 1;
        if ($attempts >= (int) $row['max_attempts']) {
            $pdo->prepare("DELETE FROM email_verifications WHERE id = :id")->execute(['id' => $row['id']]);
            LogService::warning('Token de vérification invalidé après trop de tentatives', ['email' => $email]);
            return true;
        }

        $pdo->prepare("UPDATE email_verifications SET attempts = :attempts WHERE id = :id")
            ->execute(['attempts' => $attempts, 'id' => $row['id']]);
        return false;
    }

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
                'password' => 'required|string|password',  // Politique unique : Validator::passwordErrors()
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
                
                // 2. Générer et stocker le token de vérification d'email (8 chiffres)
                //    Le token ne sort que par courriel — jamais dans la réponse HTTP.
                $issued            = $this->issueVerificationToken($pdo, (int) $createdUser['id']);
                $verificationToken = $issued['token'];

                // 3. Envoyer l'email de vérification
                //    En mode token fixe (dev), aucun courriel n'est envoyé.
                if ($issued['fixed']) {
                    LogService::info('Token de vérification fixe stocké (dev) — aucun courriel envoyé', [
                        'user_id' => $createdUser['id'],
                    ]);
                } else {
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

                // Le token de vérification n'apparaît dans AUCUNE réponse HTTP.
                // En développement, EMAIL_VERIFICATION_TEST_CODE fournit un token fixe
                // (voir issueVerificationToken) — inopérant en production.

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
     * Date d'effacement physique prévue pour un compte supprimé (ISO 8601 UTC).
     * Retourne null si le compte n'est pas en attente de purge.
     */
    public static function purgeScheduledAt($userId): ?string
    {
        try {
            $db   = \Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                'SELECT DATE_FORMAT(
                            CONVERT_TZ(deleted_at + INTERVAL ? DAY, @@session.time_zone, "+00:00"),
                            "%Y-%m-%dT%H:%i:%sZ")
                   FROM users WHERE id = ? AND deleted_at IS NOT NULL'
            );
            $stmt->execute([\AuthGroups\Services\AccountPurgeService::graceDays(), $userId]);
            $value = $stmt->fetchColumn();

            if ($value) {
                return (string) $value;
            }

            // CONVERT_TZ renvoie NULL si les tables de fuseaux MySQL ne sont pas chargées.
            $stmt = $db->prepare(
                'SELECT DATE_FORMAT(deleted_at + INTERVAL ? DAY, "%Y-%m-%dT%H:%i:%sZ")
                   FROM users WHERE id = ? AND deleted_at IS NOT NULL'
            );
            $stmt->execute([\AuthGroups\Services\AccountPurgeService::graceDays(), $userId]);
            $fallback = $stmt->fetchColumn();

            return $fallback ? (string) $fallback : null;
        } catch (\Throwable $e) {
            LogService::error('purgeScheduledAt: échec du calcul', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Filet Stripe avant suppression de compte.
     *
     * Annule un abonnement encore actif ; si l'appel Stripe échoue, refuse la
     * suppression (409) plutôt que de laisser un compte supprimé être facturé.
     *
     * @return bool true si la suppression peut se poursuivre.
     */
    private function cancelStripeSafetyNet($userId): bool
    {
        if (!class_exists('\Stripe\Services\StripeSubscriptionService')) {
            return true; // Module Stripe non activé sur cette installation
        }

        try {
            $db   = \Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "SELECT app_id FROM stripe_subscriptions
                  WHERE user_id = ?
                    AND status IN ('active', 'trialing', 'past_due')
                    AND cancel_at_period_end = 0"
            );
            $stmt->execute([$userId]);
            $appIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            // Table absente : rien à annuler.
            return true;
        }

        foreach ($appIds as $appId) {
            try {
                \Stripe\Services\StripeSubscriptionService::cancel((int) $userId, (string) $appId);
                LogService::info('Abonnement Stripe annulé avant suppression de compte', [
                    'user_id' => $userId,
                    'app_id'  => $appId,
                ]);
            } catch (\Throwable $e) {
                LogService::error('Annulation Stripe impossible — suppression refusée', [
                    'user_id' => $userId,
                    'app_id'  => $appId,
                    'error'   => $e->getMessage(),
                ]);
                LoggingMiddleware::logExit(409);
                Response::error(
                    'Impossible d\'annuler l\'abonnement Stripe — suppression annulée',
                    [
                        'error_code' => 'STRIPE_CANCEL_FAILED',
                        'message'    => 'L\'abonnement n\'a pas pu être annulé. Réessayez plus tard '
                                      . 'ou annulez-le avant de supprimer le compte.',
                        'app_id'     => $appId,
                    ],
                    409
                );
                return false;
            }
        }

        return true;
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
            // Filet Stripe — un compte supprimé qui reste facturé est le pire scénario.
            // Le client annule normalement avant d'appeler cette route ; le serveur ne
            // peut pas en dépendre (autre client, coupure réseau, appel direct à l'API).
            if (!$force_delete && !$this->cancelStripeSafetyNet($userId)) {
                return false; // Réponse 409 déjà envoyée
            }

            if( $user->delete($force_delete)){
                LogService::info("Utilisateur supprimé (force delete = $force_delete)", [
                    'deleted_user_id' => $userId,
                    'deleted_by' => $currentUserId,
                    'deleted_user_name' => $userData['name']
                ]);
                LoggingMiddleware::logExit(200);

                $data = ['deleted' => true];
                if (!$force_delete) {
                    $data['purge_scheduled_at'] = self::purgeScheduledAt($userId);
                }

                Response::success('Utilisateur supprimé avec succès', $data);
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
            // Rate limit anti-brute-force sur le token (par IP, et par email s'il est fourni)
            $rateKey = isset($input['email']) && is_string($input['email']) && trim($input['email']) !== ''
                ? strtolower(trim($input['email']))
                : 'anonymous';

            if (!RateLimitService::check($rateKey, 'verify-email')) {
                LogService::warning('Rate limit verify-email dépassé', ['key' => $rateKey]);
                LoggingMiddleware::logExit(429);
                Response::error('Trop de tentatives. Réessayez plus tard.', [
                    'error'   => 'RATE_LIMIT_EXCEEDED',
                    'message' => 'Trop de tentatives consécutives. Réessayez dans ' . RATE_LIMIT_AUTH_WINDOW_MINUTES . ' minutes.',
                ], 429);
                return false;
            }

            $userModel = new User();
            $userData = null;
            // Vérifier le token en base (token non expiré et non supprimé)
            $pdo = \Database::getInstance()->getConnection();
            $stmt = $pdo->prepare("SELECT id, user_id, attempts, max_attempts FROM email_verifications WHERE token = :token AND expires_at > NOW() AND deleted_at IS NULL");
            $stmt->execute(['token' => $input['token']]);
            $row = $stmt->fetch();
            if (!$row) {
                RateLimitService::record($rateKey, 'verify-email');
                // Compteur par token : si l'email est connu, on incrémente son token en cours
                if ($rateKey !== 'anonymous' && $this->registerFailedVerification($pdo, $rateKey)) {
                    LoggingMiddleware::logExit(429);
                    Response::error('Trop de tentatives. Réessayez plus tard.', [
                        'error' => 'TOO_MANY_ATTEMPTS',
                    ], 429);
                    return false;
                }
                LoggingMiddleware::logExit(404);
                Response::error(self::GENERIC_VERIFY_ERROR, null, 404);
                return false;
            }

            // Token valide mais déjà saturé de tentatives → invalidation
            if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
                $pdo->prepare("DELETE FROM email_verifications WHERE id = :id")->execute(['id' => $row['id']]);
                LogService::warning('Token de vérification saturé — invalidé', ['user_id' => $row['user_id']]);
                LoggingMiddleware::logExit(429);
                Response::error('Trop de tentatives. Réessayez plus tard.', [
                    'error' => 'TOO_MANY_ATTEMPTS',
                ], 429);
                return false;
            }

            $userId = $row['user_id'];
            $userData = $userModel->findById($userId);
            if (!$userData) {
                LoggingMiddleware::logExit(404);
                Response::error(self::GENERIC_VERIFY_ERROR, null, 404);
                return false;
            }
            RateLimitService::clear($rateKey, 'verify-email');
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
            
            $email = strtolower(trim($input['email']));

            // Token fixe de dev : aucun courriel envoyé, exempt du rate limit
            $useFixedCode = $this->verificationFixedCode() !== '';

            // Rate limit par (email + IP) — même politique que /auth/send-code
            if (!$useFixedCode && !RateLimitService::check($email, 'verify-email-req')) {
                LogService::warning('Rate limit resend-verification-email dépassé', ['email' => $email]);
                LoggingMiddleware::logExit(429);
                Response::error('Trop de demandes de vérification', [
                    'error'   => 'RATE_LIMIT_EXCEEDED',
                    'message' => 'Trop de demandes consécutives. Réessayez dans ' . RATE_LIMIT_AUTH_WINDOW_MINUTES . ' minutes.',
                ], 429);
                return false;
            }
            if (!$useFixedCode) {
                RateLimitService::record($email, 'verify-email-req');
            }

            $userModel = new User();
            $userData = $userModel->findByEmail($email);

            if (!$userData) {
                LoggingMiddleware::logExit(200);
                Response::success(self::GENERIC_RESEND_MESSAGE);
                return true;
            }

            // Vérifier si l'email est déjà vérifié
            if ($userData['email_verified']) {
                LoggingMiddleware::logExit(200);
                Response::success(self::GENERIC_RESEND_MESSAGE);
                return true;
            }

            // Générer un nouveau token de vérification (jamais renvoyé au client)
            $pdo = \Database::getInstance()->getConnection();
            $issued            = $this->issueVerificationToken($pdo, (int) $userData['id']);
            $verificationToken = $issued['token'];
            $expiresAt         = $issued['expires_at'];

            if ($issued['fixed']) {
                LogService::info('Token de vérification fixe stocké (dev) — aucun courriel envoyé', [
                    'user_id' => $userData['id'],
                ]);
                LoggingMiddleware::logExit(200);
                Response::success(self::GENERIC_RESEND_MESSAGE);
                return true;
            }

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
                    // Réponse générique : ni token, ni indice sur l'existence du compte
                    Response::success(self::GENERIC_RESEND_MESSAGE);
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
