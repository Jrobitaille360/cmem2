<?php

namespace Assistant\Services;

use Access\Services\ModuleGrantService;
use Assistant\Models\SmtpSettingsStore;
use AuthGroups\Services\LogService;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Throwable;

/**
 * SMTP par usager (tâches #253, #254).
 *
 * - probe()  : connexion + EHLO + STARTTLS + AUTH + QUIT, sans envoyer de courriel ;
 *              erreur lisible SMTP_HOST_UNREACHABLE / SMTP_TLS_FAILED / SMTP_AUTH_FAILED.
 * - send()   : courriel multipart HTML + texte via le SMTP de l'usager (PHPMailer).
 * - configFor() : paramètres déchiffrés pour le cron des rappels (null = SMTP système).
 *
 * Les hôtes privés, loopback ou réservés sont refusés (SMTP_HOST_FORBIDDEN) : la sonde
 * ne doit pas servir à explorer le réseau interne du serveur. La connexion se fait sur
 * l'IP vérifiée (pas de seconde résolution DNS), le certificat est validé sur le nom.
 * Le mot de passe n'apparaît jamais dans un journal ni dans une réponse.
 */
class UserSmtpService
{
    private const TIMEOUT = 10;

    /**
     * Résout l'hôte et vérifie qu'il est public.
     * @return array{status: 'ok'|'forbidden'|'unresolved', ip: ?string}
     */
    public static function resolvePublicHost(string $host): array
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return ['status' => 'unresolved', 'ip' => null];
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return ['status' => 'forbidden', 'ip' => $ip];
            }
        }
        return ['status' => 'ok', 'ip' => $ips[0]];
    }

    /**
     * Teste la connexion et l'authentification sans envoyer de courriel.
     * @param array $cfg host, port, encryption, username, password
     * @return array{ok: bool, code: ?string, message: string}
     */
    public function probe(array $cfg, int $userId): array
    {
        $resolved = self::resolvePublicHost($cfg['host']);
        if ($resolved['status'] === 'forbidden') {
            return $this->fail($cfg, $userId, 'SMTP_HOST_FORBIDDEN', "Hôte refusé : adresse privée, locale ou réservée.");
        }
        if ($resolved['status'] === 'unresolved') {
            return $this->fail($cfg, $userId, 'SMTP_HOST_UNREACHABLE', "Hôte « {$cfg['host']} » introuvable (DNS).");
        }

        $ip      = $resolved['ip'];
        $port    = (int) $cfg['port'];
        $options = ['ssl' => ['peer_name' => $cfg['host'], 'verify_peer' => true, 'verify_peer_name' => true]];
        $smtp    = new SMTP();
        $smtp->Timeout = self::TIMEOUT;

        try {
            $prefix = $cfg['encryption'] === 'ssl' ? 'ssl://' : '';
            if (!$smtp->connect($prefix . $ip, $port, self::TIMEOUT, $options)) {
                if ($cfg['encryption'] === 'ssl' && $this->tcpReachable($ip, $port)) {
                    return $this->fail($cfg, $userId, 'SMTP_TLS_FAILED',
                        "Connexion TLS impossible sur {$cfg['host']}:{$port} (port STARTTLS ? certificat invalide ?).",
                        $smtp->getError()['error'] ?? '');
                }
                return $this->fail($cfg, $userId, 'SMTP_HOST_UNREACHABLE',
                    "Hôte {$cfg['host']}:{$port} injoignable.", $smtp->getError()['error'] ?? '');
            }
            if (!$smtp->hello(gethostname() ?: 'localhost')) {
                return $this->fail($cfg, $userId, 'SMTP_HOST_UNREACHABLE',
                    "Le serveur {$cfg['host']} ne répond pas comme un serveur SMTP.", $this->lastError($smtp));
            }
            if ($cfg['encryption'] === 'tls') {
                if (!$smtp->startTLS()) {
                    return $this->fail($cfg, $userId, 'SMTP_TLS_FAILED',
                        "STARTTLS refusé ou certificat invalide sur {$cfg['host']}:{$port}.", $this->lastError($smtp));
                }
                $smtp->hello(gethostname() ?: 'localhost');
            }
            if ($cfg['username'] !== '' && !$smtp->authenticate($cfg['username'], (string) $cfg['password'])) {
                return $this->fail($cfg, $userId, 'SMTP_AUTH_FAILED',
                    "Authentification refusée par {$cfg['host']} (utilisateur ou mot de passe).", $this->lastError($smtp));
            }
            $smtp->quit();
            return ['ok' => true, 'code' => null, 'message' => 'Connexion SMTP réussie'];
        } catch (Throwable $e) {
            return $this->fail($cfg, $userId, 'SMTP_HOST_UNREACHABLE', "Erreur de connexion à {$cfg['host']}.", $e->getMessage());
        } finally {
            $smtp->close();
        }
    }

    /**
     * Envoie un courriel HTML + texte via le SMTP de l'usager.
     * EMAIL_SIMULATION ne s'applique pas : serveur et quota sont ceux de l'usager.
     */
    public function send(array $cfg, int $userId, string $to, string $subject, string $html, string $text): bool
    {
        $resolved = self::resolvePublicHost($cfg['host']);
        if ($resolved['status'] !== 'ok') {
            $this->log($cfg, $userId, 'envoi échoué', $resolved['status'] === 'forbidden' ? 'SMTP_HOST_FORBIDDEN' : 'SMTP_HOST_UNREACHABLE', '');
            return false;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host        = $resolved['ip'];
            $mail->Port        = (int) $cfg['port'];
            $mail->Timeout     = self::TIMEOUT;
            $mail->SMTPOptions = ['ssl' => ['peer_name' => $cfg['host'], 'verify_peer' => true, 'verify_peer_name' => true]];
            $mail->SMTPAuth    = $cfg['username'] !== '';
            $mail->Username    = $cfg['username'];
            $mail->Password    = (string) $cfg['password'];
            if ($cfg['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($cfg['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure  = '';
                $mail->SMTPAutoTLS = false;
            }
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($cfg['from_email'], $cfg['from_name'] ?? '');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text;
            $mail->send();
            LogService::info('SMTP usager : courriel envoyé', ['user_id' => $userId, 'host' => $cfg['host']]);
            return true;
        } catch (PHPMailerException | Throwable $e) {
            $this->log($cfg, $userId, 'envoi échoué', 'SMTP_SEND_FAILED', $e->getMessage());
            return false;
        }
    }

    /**
     * Paramètres déchiffrés de l'usager pour le cron, ou null (pas de SMTP perso, module retiré,
     * clé absente / mot de passe indéchiffrable → repli sur le SMTP système).
     */
    public function configFor(int $userId): ?array
    {
        try {
            $row = (new SmtpSettingsStore())->find($userId);
            if ($row === null || !(new ModuleGrantService())->isAvailable($userId, 'assistant')) {
                return null;
            }
            $row['password'] = $row['password_enc'] !== '' ? SmtpCrypto::decrypt($row['password_enc']) : '';
            return $row;
        } catch (Throwable $e) {
            LogService::warning('SMTP usager : paramètres inutilisables', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** Gabarit HTML minimal à partir d'un texte brut. */
    public static function htmlFromText(string $subject, string $text): string
    {
        $body = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        $title = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>' . $title . '</title></head>'
             . '<body style="font-family:Arial,sans-serif;line-height:1.5;color:#222;max-width:600px;margin:0 auto;padding:16px">'
             . '<h2 style="font-size:18px;margin:0 0 12px">' . $title . '</h2><p>' . $body . '</p></body></html>';
    }

    // ------------------------------------------------------------------

    private function tcpReachable(string $ip, int $port): bool
    {
        $fp = @stream_socket_client("tcp://{$ip}:{$port}", $errno, $errstr, self::TIMEOUT);
        if ($fp === false) {
            return false;
        }
        fclose($fp);
        return true;
    }

    private function lastError(SMTP $smtp): string
    {
        $e = $smtp->getError();
        return trim(($e['error'] ?? '') . ' ' . ($e['smtp_code'] ?? '') . ' ' . ($e['detail'] ?? ''));
    }

    private function fail(array $cfg, int $userId, string $code, string $message, string $detail = ''): array
    {
        $this->log($cfg, $userId, 'test échoué', $code, $detail);
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }

    /** Journalise un échec — host/port/code/détail, jamais le mot de passe. */
    private function log(array $cfg, int $userId, string $what, string $code, string $detail): void
    {
        $password = (string) ($cfg['password'] ?? '');
        if ($password !== '') {
            $detail = str_replace($password, '***', $detail);
        }
        LogService::warning("SMTP usager : {$what}", [
            'user_id'    => $userId,
            'host'       => $cfg['host'] ?? null,
            'port'       => isset($cfg['port']) ? (int) $cfg['port'] : null,
            'encryption' => $cfg['encryption'] ?? null,
            'code'       => $code,
            'error'      => mb_substr($detail, 0, 500),
        ]);
    }
}
