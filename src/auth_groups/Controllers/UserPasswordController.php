<?php

namespace AuthGroups\Controllers;

use AuthGroups\Models\User;
use AuthGroups\Services\EmailService;
use AuthGroups\Services\RateLimitService;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\RoleHelper;
use AuthGroups\Utils\Validator;
use AuthGroups\Utils\Database;
use AuthGroups\Services\LogService;
use AuthGroups\Middleware\LoggingMiddleware;
use Exception;

/**
 * Contrôleur User simplifié utilisant UserSimplified
 * Version simplifiée sans injection de dépendance PDO
 */
class UserPasswordController {

    /** Message unique renvoyé par request-password-reset (anti-énumération de comptes). */
    const GENERIC_RESET_MESSAGE = 'Si ce courriel existe, un code de réinitialisation a été envoyé.';

    /** Longueur du code de réinitialisation envoyé par courriel. */
    const RESET_CODE_LENGTH = 6;

    /**
     * Politique de mot de passe unique — il n'en existe plus qu'une.
     * Les règles vivent dans `Validator::passwordErrors()` : min 8 caractères,
     * une minuscule, une majuscule, un chiffre, un caractère spécial.
     *
     * `password_policy` n'a plus d'effet : seule la valeur 'strong' reste tolérée
     * pour ne pas casser les clients existants ; toute autre valeur est rejetée.
     */
    const ACCEPTED_PASSWORD_POLICY = 'strong';

    /**
     * Demander un code de réinitialisation de mot de passe.
     *
     * Le code (6 chiffres) part uniquement par courriel : il n'apparaît jamais
     * dans la réponse HTTP. La réponse est identique que le courriel existe ou non.
     */
    public function requestPasswordChange(){
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        $validation = Validator::validate(
            $input,
            ['email' => 'required|email']
        );
        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données de validation invalides', $validation['errors'], 400);
            return false;
        }

        $email = strtolower(trim($input['email']));

        // Variable de dev laissée en production : configuration à corriger
        if (defined('PASSWORD_RESET_TEST_CODE_IGNORED') && PASSWORD_RESET_TEST_CODE_IGNORED) {
            LogService::warning('PASSWORD_RESET_TEST_CODE défini en production — variable ignorée');
        }

        // Code fixe de développement : aucun courriel envoyé, exempt du rate limit.
        // Inactif en production (la constante y est forcée à '').
        $fixedCode = defined('PASSWORD_RESET_TEST_CODE') ? PASSWORD_RESET_TEST_CODE : '';
        $useFixedCode = $fixedCode !== '';

        // Rate limit par (email + IP) — même politique que /auth/send-code
        if (!$useFixedCode && !RateLimitService::check($email, 'pwd-reset-req')) {
            LogService::warning('Rate limit request-password-reset dépassé', ['email' => $email]);
            LoggingMiddleware::logExit(429);
            Response::error('Trop de demandes de réinitialisation', [
                'error'   => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Trop de demandes consécutives. Réessayez dans ' . RATE_LIMIT_AUTH_WINDOW_MINUTES . ' minutes.',
            ], 429);
            return false;
        }
        if (!$useFixedCode) {
            RateLimitService::record($email, 'pwd-reset-req');
        }

        $user = new User();
        $userData = $user->findByEmail($email);
        if (!$userData) {
            LogService::warning("Utilisateur non trouvé pour demande de changement de mot de passe", ['email' => $email]);
            LoggingMiddleware::logExit(200);
            Response::success(self::GENERIC_RESET_MESSAGE);
            return true;
        }

        $pdo = \Database::getInstance()->getConnection();

        // Un seul code actif par usager : les codes précédents sont supprimés.
        // En mode code fixe (dev), le même code peut appartenir à un autre usager :
        // on le libère aussi, sinon la contrainte UNIQUE sur `token` bloque l'insertion.
        if ($useFixedCode) {
            $pdo->prepare("DELETE FROM password_resets WHERE user_id = :user_id OR token = :token")
                ->execute(['user_id' => $userData['id'], 'token' => $fixedCode]);
        } else {
            $pdo->prepare("DELETE FROM password_resets WHERE user_id = :user_id")
                ->execute(['user_id' => $userData['id']]);
        }

        $expiryMinutes = defined('PASSWORD_RESET_EXPIRY_MINUTES') ? (int) PASSWORD_RESET_EXPIRY_MINUTES : 60;
        $maxAttempts   = defined('PASSWORD_RESET_MAX_ATTEMPTS')   ? (int) PASSWORD_RESET_MAX_ATTEMPTS   : 5;

        // `token` porte une contrainte UNIQUE : on retente en cas de collision
        $token    = null;
        $inserted = false;
        for ($try = 0; $try < 5 && !$inserted; $try++) {
            $token = $useFixedCode ? $fixedCode : $this->generateResetCode();
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO password_resets (user_id, token, attempts, max_attempts, expires_at)
                     VALUES (:user_id, :token, 0, :max_attempts, DATE_ADD(NOW(), INTERVAL :minutes MINUTE))"
                );
                $stmt->execute([
                    'user_id'      => $userData['id'],
                    'token'        => $token,
                    'max_attempts' => $maxAttempts,
                    'minutes'      => $expiryMinutes,
                ]);
                $inserted = true;
            } catch (\PDOException $e) {
                // 23000 = violation de contrainte d'unicité → régénérer un code
                if ($e->getCode() !== '23000' || $useFixedCode) {
                    throw $e;
                }
                // Un code identique existe pour un autre usager : purge des codes expirés puis retry
                $pdo->query("DELETE FROM password_resets WHERE expires_at < NOW()");
            }
        }

        if (!$inserted) {
            LogService::error('Impossible de générer un code de réinitialisation unique', ['email' => $email]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la demande de réinitialisation', null, 500);
            return false;
        }

        if ($useFixedCode) {
            LogService::info('Code de réinitialisation fixe stocké (dev) — aucun courriel envoyé', ['email' => $email]);
        } else {
            $emailService = new EmailService();
            $emailService->sendPasswordReset($userData['email'], $token);
            LogService::info("Demande de changement de mot de passe envoyée", ['email' => $email]);
        }

        LoggingMiddleware::logExit(200);
        Response::success(self::GENERIC_RESET_MESSAGE);
        return true;
    }

    /**
     * Génère un code numérique de RESET_CODE_LENGTH chiffres, premier chiffre 1-9.
     */
    private function generateResetCode(): string
    {
        $code = (string) random_int(1, 9);
        for ($i = 1; $i < self::RESET_CODE_LENGTH; $i++) {
            $code .= random_int(0, 9);
        }
        return $code;
    }

    /**
     * Changer le mot de passe (via code reçu par courriel).
     *
     * Body : { token, new_password, password_policy?, email? }
     *   - password_policy : sans effet. Une seule politique existe (Validator::passwordErrors()).
     *                       Seule la valeur 'strong' est tolérée (compatibilité des clients) ;
     *                       toute autre valeur, dont 'any', est rejetée en 400.
     *   - email           : facultatif ; permet de compter les tentatives sur le code de l'usager
     */
    public function changePasswordToken() {
        try {
            LoggingMiddleware::logEntry();
            $input = Response::getRequestParams();

            // `password_policy` n'a plus d'effet : seul 'strong' reste toléré (compatibilité clients)
            if (isset($input['password_policy']) && trim((string) $input['password_policy']) !== '') {
                $policy = strtolower(trim((string) $input['password_policy']));
                if ($policy !== self::ACCEPTED_PASSWORD_POLICY) {
                    LogService::warning('password_policy refusé — politique unique en vigueur', [
                        'endpoint'   => '/users/reset-password',
                        'policy'     => $policy,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    ]);
                    LoggingMiddleware::logExit(400);
                    Response::error('Données de validation invalides', [
                        'password_policy' => "Politique supprimée : tous les mots de passe suivent désormais la même règle. Seule la valeur '" . self::ACCEPTED_PASSWORD_POLICY . "' est tolérée, et elle est sans effet.",
                    ], 400);
                    return false;
                }
            }

            $validation = Validator::validate(
                $input,
                [
                    'new_password' => 'required|string|password',
                    'token' => 'required|string'
                ]
            );
            if (!$validation['valid']) {
                LoggingMiddleware::logExit(400);
                Response::error('Données de validation invalides', $validation['errors'], 400);
                return false;
            }

            // Rate limit anti-brute-force sur le code (par IP, et par email s'il est fourni)
            $rateKey = isset($input['email']) && is_string($input['email']) && trim($input['email']) !== ''
                ? strtolower(trim($input['email']))
                : 'anonymous';

            if (!RateLimitService::check($rateKey, 'pwd-reset')) {
                LogService::warning('Rate limit reset-password dépassé', ['key' => $rateKey]);
                LoggingMiddleware::logExit(429);
                Response::error('Trop de tentatives — demandez un nouveau code', [
                    'error'   => 'RATE_LIMIT_EXCEEDED',
                    'message' => 'Trop de tentatives consécutives. Réessayez dans ' . RATE_LIMIT_AUTH_WINDOW_MINUTES . ' minutes.',
                ], 429);
                return false;
            }

            $userModel = new User();
            // Vérifier le token en base (token non expiré et non supprimé)
            $pdo = \Database::getInstance()->getConnection();
            $stmt = $pdo->prepare("SELECT id, user_id, attempts, max_attempts FROM password_resets WHERE token = :token AND expires_at > NOW() AND deleted_at IS NULL");
            $stmt->execute(['token' => $input['token']]);
            $row = $stmt->fetch();
            if (!$row) {
                RateLimitService::record($rateKey, 'pwd-reset');
                // Compteur par code : si l'email est connu, on incrémente son code en cours
                if ($rateKey !== 'anonymous' && $this->registerFailedAttempt($pdo, $rateKey)) {
                    LoggingMiddleware::logExit(429);
                    Response::error('Trop de tentatives — demandez un nouveau code', [
                        'error' => 'TOO_MANY_ATTEMPTS',
                    ], 429);
                    return false;
                }
                LoggingMiddleware::logExit(404);
                Response::error('Token non trouvé ou expiré', null, 404);
                return false;
            }

            // Code valide mais déjà saturé de tentatives → invalidation
            if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
                $pdo->prepare("DELETE FROM password_resets WHERE id = :id")->execute(['id' => $row['id']]);
                LogService::warning('Code de réinitialisation saturé — invalidé', ['user_id' => $row['user_id']]);
                LoggingMiddleware::logExit(429);
                Response::error('Trop de tentatives — demandez un nouveau code', [
                    'error' => 'TOO_MANY_ATTEMPTS',
                ], 429);
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
                // Le code est consommé : suppression définitive
                $pdo->prepare("DELETE FROM password_resets WHERE id = :id")->execute(['id' => $row['id']]);
                RateLimitService::clear($rateKey, 'pwd-reset');
                LogService::info('Mot de passe réinitialisé par code', ['user_id' => $userId]);
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
     * Incrémente le compteur de tentatives du code actif d'un usager.
     * Supprime le code si le maximum est atteint.
     *
     * @return bool true si le code vient d'être invalidé (→ répondre 429)
     */
    private function registerFailedAttempt(\PDO $pdo, string $email): bool
    {
        $stmt = $pdo->prepare(
            "SELECT pr.id, pr.attempts, pr.max_attempts
               FROM password_resets pr
               JOIN users u ON u.id = pr.user_id
              WHERE u.email = :email AND pr.expires_at > NOW() AND pr.deleted_at IS NULL
              ORDER BY pr.id DESC LIMIT 1"
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }

        $attempts = (int) $row['attempts'] + 1;
        if ($attempts >= (int) $row['max_attempts']) {
            $pdo->prepare("DELETE FROM password_resets WHERE id = :id")->execute(['id' => $row['id']]);
            LogService::warning('Code de réinitialisation invalidé après trop de tentatives', ['email' => $email]);
            return true;
        }

        $pdo->prepare("UPDATE password_resets SET attempts = :attempts WHERE id = :id")
            ->execute(['attempts' => $attempts, 'id' => $row['id']]);
        return false;
    }

    /**
     * Changer le mot de passe (authentifié)
     */
    public function changePassword($userId,$currentUserId, $currentUserRole) {
        try {                        
            LoggingMiddleware::logEntry();
            $input = Response::getRequestParams();             
            // Vérifier l'authentification
            if ( !RoleHelper::isAtLeast($currentUserRole, 'ADMINISTRATEUR') && $userId !== $currentUserId) {
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
            if ( $userId !== $currentUserId) {
                $validation = Validator::validate(
                $input,
                [
                    'new_password' => 'required|string|password',
                ]
                );
            } else {
                $validation = Validator::validate(
                    $input,
                    [
                        'current_password' => 'required|string',
                        'new_password' => 'required|string|password',
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
