<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\JwtBlacklist;
use AuthGroups\Models\User;
use AuthGroups\Services\JwtService;
use AuthGroups\Services\RateLimitService;
use AuthGroups\Services\OtpService;
use AuthGroups\Services\DeviceTokenService;
use AuthGroups\Services\EmailService;
use AuthGroups\Services\LogService;
use AuthGroups\Services\UserSessionService;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Middleware\LoggingMiddleware;
use Exception;

/**
 * Contrôleur d'authentification JWT.
 *
 * Routes :
 *   POST /auth/login          – email + password   → JWT
 *   POST /auth/send-code      – email              → envoie un code OTP
 *   POST /auth/verify-code    – email + code       → JWT
 *   POST /auth/logout         – (JWT requis)       → déconnexion
 */
class AuthController
{
    // -----------------------------------------------------------------------
    // POST /auth/login
    // -----------------------------------------------------------------------

    public function login(): void
    {
        LoggingMiddleware::logEntry();

        $input = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        $email = strtolower(trim($input['email']));

        if (!RateLimitService::check($email, 'login')) {
            LogService::warning('Rate limit login dépassé', ['email' => $email]);
            LoggingMiddleware::logExit(429);
            Response::error('Trop de tentatives de connexion', [
                'error'   => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Trop d\'échecs consécutifs. Réessayez dans ' . RATE_LIMIT_AUTH_WINDOW_MINUTES . ' minutes.',
            ], 429);
            return;
        }

        $userModel = new User();
        $userData  = $userModel->authenticate($email, $input['password']);

        if (!$userData) {
            RateLimitService::record($email, 'login');
            LogService::warning('Connexion échouée (email/password)', ['email' => $email]);
            LoggingMiddleware::logExit(401);
            Response::error('Email ou mot de passe incorrect', null, 401);
            return;
        }

        // Email non vérifié
        if (is_array($userData) && isset($userData['status']) && $userData['status'] === 'email_not_verified') {
            LoggingMiddleware::logExit(403);
            Response::error('Email non vérifié', [
                'code'    => 'EMAIL_NOT_VERIFIED',
                'message' => $userData['message'],
                'actions' => [
                    'resend_verification' => ['endpoint' => '/users/resend-verification-email', 'method' => 'POST'],
                    'verify_email'        => ['endpoint' => '/users/verify-email',               'method' => 'POST'],
                ],
            ], 403);
            return;
        }

        RateLimitService::clear($email, 'login');
        $this->issueToken($userData, 'email/password');
    }

    // -----------------------------------------------------------------------
    // POST /auth/send-code
    // -----------------------------------------------------------------------

    public function sendCode(): void
    {
        LoggingMiddleware::logEntry();

        $input = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'email' => 'required|email',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        $email = strtolower(trim($input['email']));

        // Compte de test E2E (dev seulement) : code fixe, aucun email envoyé,
        // exempt du rate limit. Inactif si les vars d'env sont absentes (prod).
        $isTestAccount = OTP_TEST_ACCOUNT_EMAIL !== ''
            && OTP_TEST_ACCOUNT_CODE !== ''
            && $email === OTP_TEST_ACCOUNT_EMAIL;

        if (!$isTestAccount && !RateLimitService::check($email, 'send-code')) {
            LogService::warning('Rate limit send-code dépassé', ['email' => $email]);
            LoggingMiddleware::logExit(429);
            Response::error('Trop de demandes de code', [
                'error'   => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Trop de demandes consécutives. Réessayez dans ' . RATE_LIMIT_AUTH_WINDOW_MINUTES . ' minutes.',
            ], 429);
            return;
        }

        if (!$isTestAccount) {
            RateLimitService::record($email, 'send-code');
        }

        $userModel = new User();
        $userData  = $userModel->findByEmail($email);

        // Réponse générique pour éviter l'énumération d'emails
        $genericOk = ['message' => 'Si cet email est enregistré, un code de connexion vous a été envoyé.'];

        if (!$userData || !empty($userData['deleted_at'])) {
            if (!$userData) {
                // Option A : auto-register silencieux — crée le compte et continue le flux OTP
                $newUser                 = new User();
                $newUser->name           = strstr($email, '@', true);
                $newUser->email          = $email;
                $newUser->password_hash  = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
                $newUser->email_verified = 1;

                if (!$newUser->create()) {
                    LogService::error('Échec auto-register send-code', ['email' => $email]);
                    LoggingMiddleware::logExit(200);
                    Response::success('Code envoyé', $genericOk);
                    return;
                }

                $userData = $userModel->findByEmail($email);
                LogService::info('Compte auto-créé via send-code (Option A)', ['email' => $email]);
            } else {
                // Compte supprimé : retour générique, pas de recréation
                LogService::warning('Code OTP demandé pour compte supprimé', ['email' => $email]);
                LoggingMiddleware::logExit(200);
                Response::success('Code envoyé', $genericOk);
                return;
            }
        }

        if (empty($userData['email_verified'])) {
            LoggingMiddleware::logExit(403);
            Response::error('Email non vérifié', [
                'code'    => 'EMAIL_NOT_VERIFIED',
                'message' => 'Vérifiez votre adresse email avant de vous connecter.',
            ], 403);
            return;
        }

        try {
            if ($isTestAccount) {
                // Code fixe, aucun email envoyé — flux E2E déterministe
                OtpService::generateAndStore($email, OTP_TEST_ACCOUNT_CODE);
                LogService::info('Code OTP fixe stocké pour compte de test E2E', ['email' => $email]);
                LoggingMiddleware::logExit(200);
                Response::success('Code envoyé', $genericOk);
                return;
            }

            $forceCode    = (defined('APP_ENV') && APP_ENV === 'development' && defined('TMP_CODE') && TMP_CODE !== '') ? TMP_CODE : null;
            $code         = OtpService::generateAndStore($email, $forceCode);
            $emailService = new EmailService();
            $emailService->sendOtpCode($email, $userData['name'], $code);
        } catch (Exception $e) {
            LogService::error('Erreur envoi code OTP', ['email' => $email, 'error' => $e->getMessage()]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de l\'envoi du code', null, 500);
            return;
        }

        LogService::info('Code OTP envoyé', ['email' => $email]);
        LoggingMiddleware::logExit(200);
        Response::success('Code envoyé', $genericOk);
    }

    // -----------------------------------------------------------------------
    // POST /auth/verify-code
    // -----------------------------------------------------------------------

    public function verifyCode(): void
    {
        LoggingMiddleware::logEntry();

        $input = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'email' => 'required|email',
            'code'  => 'required|string',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        $email = strtolower(trim($input['email']));
        $code  = trim($input['code']);

        if (!OtpService::verify($email, $code)) {
            LogService::warning('Code OTP invalide ou expiré', ['email' => $email]);
            LoggingMiddleware::logExit(401);
            Response::error('Code invalide ou expiré', [
                'error'   => 'INVALID_CODE',
                'message' => 'Le code est incorrect ou a expiré. Demandez un nouveau code.',
            ], 401);
            return;
        }

        $userModel = new User();
        $userData  = $userModel->findByEmail($email);

        if (!$userData) {
            LoggingMiddleware::logExit(401);
            Response::error('Utilisateur introuvable', null, 401);
            return;
        }

        $this->issueToken($userData, 'OTP');
    }

    // -----------------------------------------------------------------------
    // POST /auth/refresh  (device token requis, pas de JWT)
    // -----------------------------------------------------------------------

    /**
     * Renouvelle un JWT à partir d'un device token longue durée.
     *
     * Body : { "device_id": "uuid", "device_token": "hex64" }
     * Réponse : nouveau JWT (mêmes champs que /auth/login)
     */
    public function refresh(): void
    {
        LoggingMiddleware::logEntry();

        $input = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'device_id'    => 'required|string',
            'device_token' => 'required|string',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        $deviceId = trim($input['device_id']);

        // Rate limiting sur le refresh (prévenir le brute-force de device tokens)
        if (!RateLimitService::check($deviceId, 'refresh')) {
            LogService::warning('Rate limit refresh dépassé', ['device_id' => $deviceId]);
            LoggingMiddleware::logExit(429);
            Response::error('Trop de tentatives. Réessayez dans ' . RATE_LIMIT_AUTH_WINDOW_MINUTES . ' minutes.', [
                'error' => 'RATE_LIMIT_EXCEEDED',
            ], 429);
            return;
        }

        $record = DeviceTokenService::validate(
            trim($input['device_token']),
            $deviceId
        );

        if (!$record) {
            RateLimitService::record($deviceId, 'refresh');
            LoggingMiddleware::logExit(401);
            Response::error('Device token invalide ou expiré', [
                'error'   => 'INVALID_DEVICE_TOKEN',
                'message' => 'Reconnectez-vous pour obtenir un nouveau device token.',
            ], 401);
            return;
        }

        $userModel = new User();
        $userData  = $userModel->findById((int) $record['user_id']);

        if (!$userData || !empty($userData['deleted_at'])) {
            LoggingMiddleware::logExit(401);
            Response::error('Utilisateur introuvable', null, 401);
            return;
        }

        $token     = JwtService::generate($userData);
        $expiresAt = JwtService::getExpiresAt();

        // A3 — Rotation du device token : invalider l'ancien, émettre le nouveau.
        // Le family_id est conservé pour permettre la détection de replay attack.
        // Le client DOIT remplacer son device_token par la nouvelle valeur retournée.
        $newDeviceToken = DeviceTokenService::generate(
            (int) $record['user_id'],
            $deviceId,
            $record['device_name'],
            $record['family_id'] ?? null
        );

        RateLimitService::clear($deviceId, 'refresh');

        LogService::info('JWT renouvelé via device token (token rotaté)', [
            'user_id'   => $userData['id'],
            'device_id' => $deviceId,
        ]);

        LoggingMiddleware::logExit(200);
        Response::success('Token renouvelé', [
            'token'        => $token,
            'token_type'   => 'Bearer',
            'expires_at'   => $expiresAt,
            'device_token' => $newDeviceToken,
            'device_id'    => $deviceId,
            'device_note'  => 'Remplacez votre device_token par cette nouvelle valeur.',
            'user'         => [
                'id'    => $userData['id'],
                'name'  => $userData['name'],
                'email' => $userData['email'],
                'role'  => $userData['role'],
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /auth/devices  (JWT requis)
    // -----------------------------------------------------------------------

    /**
     * Liste les appareils de confiance de l'utilisateur connecté.
     */
    public function listDevices(int $userId): void
    {
        LoggingMiddleware::logEntry();
        $devices = DeviceTokenService::listDevices($userId);
        LoggingMiddleware::logExit(200);
        Response::success('Appareils de confiance', ['devices' => $devices]);
    }

    // -----------------------------------------------------------------------
    // DELETE /auth/devices/{device_id}  (JWT requis)
    // -----------------------------------------------------------------------

    /**
     * Révoque le device token d'un appareil spécifique.
     */
    public function revokeDevice(int $userId, string $deviceId): void
    {
        LoggingMiddleware::logEntry();
        DeviceTokenService::revoke($userId, $deviceId);
        LoggingMiddleware::logExit(200);
        Response::success('Appareil révoqué', [
            'message' => 'Le device token a été révoqué. L\'appareil devra se reconnecter.',
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /auth/me  (JWT requis)
    // -----------------------------------------------------------------------

    /**
     * Retourne le profil de l'utilisateur authentifié (données fraîches depuis la DB).
     */
    public function me(int $userId): void
    {
        LoggingMiddleware::logEntry();

        $user = new User();
        $data = $user->findById($userId);

        if (!$data) {
            LogService::warning('GET /auth/me — utilisateur introuvable', ['user_id' => $userId]);
            LoggingMiddleware::logExit(404);
            Response::error('Utilisateur introuvable', null, 404);
            return;
        }

        unset($data['password_hash']);

        LoggingMiddleware::logExit(200);
        Response::success('Profil utilisateur', ['user' => $data]);
    }

    // -----------------------------------------------------------------------
    // GET /auth/sessions  (JWT requis)
    // -----------------------------------------------------------------------

    /**
     * Retourne la vue unifiée des sessions actives et des appareils de confiance
     * de l'utilisateur connecté.
     */
    public function listSessions(int $userId): void
    {
        LoggingMiddleware::logEntry();

        $sessions = UserSessionService::getUserActiveSessions($userId);
        $devices  = DeviceTokenService::listDevices($userId);

        LoggingMiddleware::logExit(200);
        Response::success('Sessions actives', [
            'sessions'        => $sessions,
            'sessions_count'  => count($sessions),
            'devices'         => $devices,
            'devices_count'   => count($devices),
        ]);
    }

    // -----------------------------------------------------------------------
    // DELETE /auth/sessions  (JWT requis — déconnexion globale)
    // -----------------------------------------------------------------------

    /**
     * Révoque toutes les sessions JWT et tous les appareils de confiance
     * de l'utilisateur connecté (déconnexion de tous les appareils).
     * Le token courant est également blacklisté.
     */
    public function revokeAllSessions(int $userId, ?string $jti = null, ?int $tokenExp = null): void
    {
        LoggingMiddleware::logEntry();

        // Blacklister le JWT courant
        if ($jti !== null) {
            $expiresAt = $tokenExp
                ? date('Y-m-d H:i:s', $tokenExp)
                : date('Y-m-d H:i:s', time() + 60);

            $blacklist = new JwtBlacklist();
            $blacklist->add($jti, $userId, $expiresAt);
        }

        // Terminer toutes les sessions et révoquer tous les device tokens
        UserSessionService::endAllUserSessions($userId);
        DeviceTokenService::revokeAll($userId);

        LogService::info('Déconnexion globale (toutes sessions + appareils révoqués)', [
            'user_id' => $userId,
            'jti'     => $jti,
        ]);

        LoggingMiddleware::logExit(200);
        Response::success('Déconnexion globale effectuée', [
            'message' => 'Toutes vos sessions et appareils de confiance ont été révoqués.',
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /auth/logout  (JWT requis, appelé depuis AuthRouteHandler)
    // -----------------------------------------------------------------------

    public function logout(int $userId, ?string $jti = null, ?int $tokenExp = null): void
    {
        LoggingMiddleware::logEntry();

        // Blacklister le token JWT actuel pour invalider immédiatement la session
        if ($jti !== null) {
            $expiresAt = $tokenExp
                ? date('Y-m-d H:i:s', $tokenExp)
                : date('Y-m-d H:i:s', time() + 60); // fallback : 1 min si exp absent

            $blacklist = new JwtBlacklist();
            $blacklist->add($jti, $userId, $expiresAt);
        }

        UserSessionService::endAllUserSessions($userId);

        LogService::info('Déconnexion', ['user_id' => $userId, 'jti' => $jti]);
        LoggingMiddleware::logExit(200);
        Response::success('Déconnexion réussie', [
            'message' => 'Token JWT révoqué côté serveur. Supprimez-le côté client.',
        ]);
    }

    // -----------------------------------------------------------------------
    // Helper privé
    // -----------------------------------------------------------------------

    /**
     * Génère un JWT, enregistre la session et envoie la réponse.
     *
     * Si le body contient `device_id` (et optionnellement `device_name`),
     * un device token longue durée est également généré et retourné.
     * Le client doit stocker ce token pour appeler POST /auth/refresh.
     */
    private function issueToken(array $userData, string $authMethod): void
    {
        $input     = Response::getRequestParams();
        $token     = JwtService::generate($userData);
        $expiresAt = JwtService::getExpiresAt();

        // Enregistrer la session (traçabilité)
        UserSessionService::createSession((int) $userData['id']);

        // Device token optionnel (si le client fournit un device_id)
        $deviceToken = null;
        $deviceId    = trim($input['device_id'] ?? '');
        if ($deviceId !== '') {
            $deviceName  = trim($input['device_name'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Appareil inconnu'));
            $deviceToken = DeviceTokenService::generate((int) $userData['id'], $deviceId, $deviceName);
        }

        LogService::info("Connexion réussie ({$authMethod})", [
            'user_id'        => $userData['id'],
            'email'          => $userData['email'],
            'device_trusted' => $deviceToken !== null,
        ]);

        $response = [
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'user'       => [
                'id'    => $userData['id'],
                'name'  => $userData['name'],
                'email' => $userData['email'],
                'role'  => $userData['role'],
            ],
        ];

        if ($deviceToken !== null) {
            $response['device_token'] = $deviceToken;
            $response['device_id']    = $deviceId;
            $response['device_note']  = 'Conservez device_token + device_id pour renouveler le JWT via POST /auth/refresh.';
        }

        LoggingMiddleware::logExit(200);
        Response::success('Connexion réussie', $response);
    }
}
