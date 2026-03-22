<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\User;
use AuthGroups\Services\JwtService;
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

        $userModel = new User();
        $userData  = $userModel->authenticate($input['email'], $input['password']);

        if (!$userData) {
            LogService::warning('Connexion échouée (email/password)', ['email' => $input['email']]);
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

        $email     = strtolower(trim($input['email']));
        $userModel = new User();
        $userData  = $userModel->findByEmail($email);

        // Réponse générique pour éviter l'énumération d'emails
        $genericOk = ['message' => 'Si cet email est enregistré, un code de connexion vous a été envoyé.'];

        if (!$userData || !empty($userData['deleted_at'])) {
            LogService::warning('Code OTP demandé pour email inexistant', ['email' => $email]);
            LoggingMiddleware::logExit(200);
            Response::success('Code envoyé', $genericOk);
            return;
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
            $code         = OtpService::generateAndStore($email);
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

        $record = DeviceTokenService::validate(
            trim($input['device_token']),
            trim($input['device_id'])
        );

        if (!$record) {
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

        LogService::info('JWT renouvelé via device token', [
            'user_id'   => $userData['id'],
            'device_id' => $input['device_id'],
        ]);

        LoggingMiddleware::logExit(200);
        Response::success('Token renouvelé', [
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'user'       => [
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
    // POST /auth/logout  (JWT requis, appelé depuis AuthRouteHandler)
    // -----------------------------------------------------------------------

    public function logout(int $userId): void
    {
        LoggingMiddleware::logEntry();

        UserSessionService::endAllUserSessions($userId);

        LogService::info('Déconnexion', ['user_id' => $userId]);
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

        // Enregistrer la session (traçabilité ; api_key_id = null)
        UserSessionService::createSession((int) $userData['id'], null);

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
