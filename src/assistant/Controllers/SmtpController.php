<?php

namespace Assistant\Controllers;

use Assistant\Models\SmtpSettingsStore;
use Assistant\Services\SmtpCrypto;
use Assistant\Services\UserSmtpService;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Services\RateLimitService;
use AuthGroups\Utils\Response;
use Database;

/**
 * SMTP par usager (tâches #252, #253) — routes /assistant/smtp, garde module assistant
 * appliquée en amont par AssistantRouteHandler.
 *
 *   GET    /assistant/smtp        paramètres (sans mot de passe) ; 404 SMTP_NOT_CONFIGURED
 *   PUT    /assistant/smtp        crée / remplace ; mot de passe omis = conservé
 *   DELETE /assistant/smtp        supprime
 *   POST   /assistant/smtp/test   sonde sans envoi (paramètres fournis ou enregistrés) ;
 *                                 {send_to_self: true} → courriel réel à l'adresse du compte
 */
class SmtpController
{
    private const RATE_MAX         = 5;
    private const RATE_WINDOW_MIN  = 10;
    private const RATE_ENDPOINT    = 'smtp-test';
    private const ENCRYPTIONS      = ['tls', 'ssl', 'none'];

    private SmtpSettingsStore $store;

    public function __construct()
    {
        $this->store = new SmtpSettingsStore();
    }

    public function get(int $userId): void
    {
        $row = $this->store->find($userId);
        if ($row === null) {
            $this->notConfigured();
            return;
        }
        Response::success('Paramètres SMTP', ['smtp' => SmtpSettingsStore::format($row)]);
    }

    public function put(int $userId): void
    {
        if (!$this->requireKey()) {
            return;
        }
        $existing = $this->store->find($userId);
        [$s, $errors] = $this->validate(Response::getRequestParams(), $existing);
        if ($errors) {
            $this->unprocessable($errors);
            return;
        }

        if ($s['password'] !== null) {
            $passwordEnc = $s['password'] === '' ? '' : SmtpCrypto::encrypt($s['password']);
        } else {
            $passwordEnc = $existing['password_enc'];
        }
        $reset = $existing === null || $s['password'] !== null
            || $existing['host'] !== $s['host'] || (int) $existing['port'] !== $s['port']
            || $existing['encryption'] !== $s['encryption'] || $existing['username'] !== $s['username'];

        $this->store->save($userId, $s, $passwordEnc, $reset);
        Response::success('Paramètres SMTP enregistrés', ['smtp' => SmtpSettingsStore::format($this->store->find($userId))]);
    }

    public function delete(int $userId): void
    {
        if (!$this->store->delete($userId)) {
            $this->notConfigured();
            return;
        }
        Response::success('Paramètres SMTP supprimés');
    }

    public function test(int $userId): void
    {
        $input     = Response::getRequestParams();
        $existing  = $this->store->find($userId);
        $useStored = !isset($input['host']);
        $sendSelf  = filter_var($input['send_to_self'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($useStored) {
            if ($existing === null) {
                $this->notConfigured();
                return;
            }
            $cfg = $existing;
        } else {
            [$cfg, $errors] = $this->validate($input, $existing);
            if ($errors) {
                $this->unprocessable($errors);
                return;
            }
        }

        // Mot de passe : fourni, sinon celui enregistré (déchiffré)
        if (!isset($cfg['password']) || $cfg['password'] === null) {
            if (($existing['password_enc'] ?? '') !== '') {
                if (!$this->requireKey()) {
                    return;
                }
                $cfg['password'] = SmtpCrypto::decrypt($existing['password_enc']);
            } else {
                $cfg['password'] = '';
            }
        }

        if (!$this->rateLimitOk($userId)) {
            LoggingMiddleware::logExit(429);
            Response::error(
                'Trop d\'essais de connexion SMTP : réessayez dans ' . self::RATE_WINDOW_MIN . ' minutes.',
                ['code' => 'SMTP_TEST_RATE_LIMITED', 'max' => self::RATE_MAX, 'window_minutes' => self::RATE_WINDOW_MIN],
                429
            );
            return;
        }

        $service = new UserSmtpService();
        $result  = $service->probe($cfg, $userId);
        if (!$result['ok']) {
            LoggingMiddleware::logExit(422);
            Response::error($result['message'], ['code' => $result['code']], 422);
            return;
        }
        if ($useStored) {
            $this->store->markVerified($userId);
        }

        $sent = null;
        if ($sendSelf) {
            $to      = $this->accountEmail($userId);
            $subject = 'Test SMTP — Journaux de Bord';
            $text    = "Ce courriel confirme que votre serveur SMTP {$cfg['host']} fonctionne avec Journaux de Bord.\n"
                     . "Vos rappels par courriel seront envoyés par ce serveur.";
            $sent = $to !== null && $service->send($cfg, $userId, $to, $subject, UserSmtpService::htmlFromText($subject, $text), $text);
            if (!$sent) {
                LoggingMiddleware::logExit(422);
                Response::error("Connexion réussie, mais l'envoi du courriel de test a échoué.", ['code' => 'SMTP_SEND_FAILED'], 422);
                return;
            }
        }

        Response::success($result['message'], [
            'ok'       => true,
            'verified' => $useStored,
            'sent'     => $sent,
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * Valide un corps PUT / test. password : null = omis (conserver l'existant).
     * @return array{0: array, 1: array} [paramètres normalisés, erreurs]
     */
    private function validate(array $in, ?array $existing): array
    {
        $errors = [];
        $host   = is_string($in['host'] ?? null) ? strtolower(trim($in['host'])) : '';
        $port   = $in['port'] ?? null;
        $enc    = is_string($in['encryption'] ?? null) ? strtolower(trim($in['encryption'])) : 'tls';
        $user   = is_string($in['username'] ?? null) ? trim($in['username']) : '';
        $pass   = array_key_exists('password', $in) && $in['password'] !== null ? (string) $in['password'] : null;
        $from   = is_string($in['from_email'] ?? null) ? trim($in['from_email']) : '';
        $name   = is_string($in['from_name'] ?? null) ? trim($in['from_name']) : '';

        if ($host === '' || strlen($host) > 255 || !preg_match('/^[a-z0-9.\-]+$/', $host)) {
            $errors['host'] = 'Nom d\'hôte ou adresse IPv4 requis';
        } elseif (UserSmtpService::resolvePublicHost($host)['status'] === 'forbidden') {
            $errors['host'] = 'Hôte refusé : adresse privée, locale ou réservée';
            $errors['code'] = 'SMTP_HOST_FORBIDDEN';
        }
        if (filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            $errors['port'] = 'Port entre 1 et 65535';
        }
        if (!in_array($enc, self::ENCRYPTIONS, true)) {
            $errors['encryption'] = 'tls, ssl ou none';
        }
        if (strlen($user) > 255) {
            $errors['username'] = '255 caractères au plus';
        }
        if ($pass !== null && strlen($pass) > 1024) {
            $errors['password'] = '1024 caractères au plus';
        }
        if ($pass === null && $user !== '' && ($existing === null || $existing['password_enc'] === '')) {
            $errors['password'] = 'Mot de passe requis';
        }
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || strlen($from) > 255) {
            $errors['from_email'] = 'Adresse d\'expédition valide requise';
        }
        if (mb_strlen($name) > 255) {
            $errors['from_name'] = '255 caractères au plus';
        }

        return [[
            'host'       => $host,
            'port'       => (int) $port,
            'encryption' => $enc,
            'username'   => $user,
            'password'   => $pass,
            'from_email' => $from,
            'from_name'  => $name,
        ], $errors];
    }

    /** Limite par usager (indépendante de l'IP et de ENABLE_RATE_LIMITING) : 5 essais / 10 min. */
    private function rateLimitOk(int $userId): bool
    {
        $pdo = Database::getInstance()->getConnection();
        $key = 'smtp-test#' . $userId;
        $st  = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE email = ? AND endpoint = ? AND created_at > DATE_SUB(NOW(), INTERVAL ' . self::RATE_WINDOW_MIN . ' MINUTE)'
        );
        $st->execute([$key, self::RATE_ENDPOINT]);
        if ((int) $st->fetchColumn() >= self::RATE_MAX) {
            return false;
        }
        $pdo->prepare('INSERT INTO login_attempts (email, ip_address, endpoint) VALUES (?, ?, ?)')
            ->execute([$key, RateLimitService::getClientIp(), self::RATE_ENDPOINT]);
        return true;
    }

    private function accountEmail(int $userId): ?string
    {
        $st = Database::getInstance()->getConnection()->prepare('SELECT email FROM users WHERE id = ?');
        $st->execute([$userId]);
        $email = $st->fetchColumn();
        return is_string($email) && $email !== '' ? $email : null;
    }

    private function requireKey(): bool
    {
        if (SmtpCrypto::isConfigured()) {
            return true;
        }
        LoggingMiddleware::logExit(503);
        Response::error('Chiffrement SMTP non configuré sur le serveur.', ['code' => 'SMTP_KEY_NOT_CONFIGURED'], 503);
        return false;
    }

    private function notConfigured(): void
    {
        LoggingMiddleware::logExit(404);
        Response::error('Aucun serveur SMTP personnel configuré', ['code' => 'SMTP_NOT_CONFIGURED'], 404);
    }

    private function unprocessable(array $errors): void
    {
        LoggingMiddleware::logExit(422);
        Response::error('Données invalides', $errors, 422);
    }
}
