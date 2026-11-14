<?php

namespace Assistant\Models;

use Database;
use PDO;

/**
 * Table user_smtp_settings (tâche #252, docs/20261114_user_smtp_settings.sql).
 * Une ligne par usager ; password_enc n'est jamais renvoyé par format().
 */
class SmtpSettingsStore
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    public function find(int $userId): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM user_smtp_settings WHERE user_id = ?");
        $st->execute([$userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Insère ou remplace ; $resetVerified = paramètres de connexion modifiés. */
    public function save(int $userId, array $s, string $passwordEnc, bool $resetVerified): void
    {
        $this->pdo->prepare(
            "INSERT INTO user_smtp_settings (user_id, host, port, encryption, username, password_enc, from_email, from_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE host = VALUES(host), port = VALUES(port), encryption = VALUES(encryption),
               username = VALUES(username), password_enc = VALUES(password_enc), from_email = VALUES(from_email),
               from_name = VALUES(from_name)" . ($resetVerified ? ", verified_at = NULL" : "")
        )->execute([
            $userId, $s['host'], $s['port'], $s['encryption'], $s['username'], $passwordEnc, $s['from_email'], $s['from_name'],
        ]);
    }

    public function delete(int $userId): bool
    {
        $st = $this->pdo->prepare("DELETE FROM user_smtp_settings WHERE user_id = ?");
        $st->execute([$userId]);
        return $st->rowCount() > 0;
    }

    public function markVerified(int $userId): void
    {
        $this->pdo->prepare("UPDATE user_smtp_settings SET verified_at = NOW() WHERE user_id = ?")->execute([$userId]);
    }

    /** Vue publique : tout sauf le mot de passe. */
    public static function format(array $row): array
    {
        return [
            'host'         => $row['host'],
            'port'         => (int) $row['port'],
            'encryption'   => $row['encryption'],
            'username'     => $row['username'],
            'has_password' => ($row['password_enc'] ?? '') !== '',
            'from_email'   => $row['from_email'],
            'from_name'    => $row['from_name'],
            'verified_at'  => $row['verified_at'] ?? null,
            'updated_at'   => $row['updated_at'] ?? null,
        ];
    }
}
