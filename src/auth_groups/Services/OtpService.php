<?php

namespace AuthGroups\Services;

/**
 * Service OTP - génère et vérifie des codes à usage unique envoyés par email.
 * Les codes sont stockés dans la table `otp_codes` (voir migration SQL).
 */
class OtpService
{
    const CODE_LENGTH    = 6;
    const DEFAULT_EXPIRY = 15;   // minutes (surchargeable via OTP_EXPIRY_MINUTES)
    const MAX_ATTEMPTS   = 5;    // tentatives max avant invalidation

    // -----------------------------------------------------------------------
    // API publique
    // -----------------------------------------------------------------------

    /**
     * Génère un code OTP à 6 chiffres, le stocke hashé en base et retourne
     * le code en clair (à envoyer par email).
     */
    public static function generateAndStore(string $email): string
    {
        $expiryMinutes = defined('OTP_EXPIRY_MINUTES') ? (int) OTP_EXPIRY_MINUTES : self::DEFAULT_EXPIRY;
        $maxAttempts   = defined('OTP_MAX_ATTEMPTS')   ? (int) OTP_MAX_ATTEMPTS   : self::MAX_ATTEMPTS;

        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_BCRYPT);

        $pdo = \Database::getInstance()->getConnection();

        // Supprimer les anciens codes non utilisés pour cet email
        $pdo->prepare("DELETE FROM otp_codes WHERE email = ?")->execute([$email]);

        // Insérer le nouveau code — expires_at calculé par MySQL pour éviter tout décalage de timezone
        $pdo->prepare(
            "INSERT INTO otp_codes (email, code_hash, expires_at, attempts, max_attempts)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 0, ?)"
        )->execute([$email, $hash, $expiryMinutes, $maxAttempts]);

        return $code;
    }

    /**
     * Vérifie un code OTP.  Retourne true si valide, false sinon.
     * Incrémente le compteur de tentatives ; invalide le code si utilisé.
     */
    public static function verify(string $email, string $code): bool
    {
        $pdo = \Database::getInstance()->getConnection();

        $stmt = $pdo->prepare(
            "SELECT id, code_hash, attempts, max_attempts
             FROM otp_codes
             WHERE email = ?
               AND expires_at > NOW()
               AND used_at IS NULL
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $stmt->execute([$email]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$record) {
            LogService::warning('OTP introuvable ou expiré', ['email' => $email]);
            return false;
        }

        // Trop de tentatives ?
        if ((int) $record['attempts'] >= (int) $record['max_attempts']) {
            LogService::warning('OTP : trop de tentatives', ['email' => $email]);
            return false;
        }

        // Incrémenter les tentatives
        $pdo->prepare("UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?")
            ->execute([$record['id']]);

        if (!password_verify($code, $record['code_hash'])) {
            LogService::warning('OTP : code incorrect', ['email' => $email]);
            return false;
        }

        // Marquer comme utilisé
        $pdo->prepare("UPDATE otp_codes SET used_at = NOW() WHERE id = ?")
            ->execute([$record['id']]);

        return true;
    }

    /**
     * Supprime les codes expirés ou déjà utilisés (maintenance).
     */
    public static function cleanup(): void
    {
        $pdo = \Database::getInstance()->getConnection();
        $pdo->query("DELETE FROM otp_codes WHERE expires_at < NOW() OR used_at IS NOT NULL");
    }
}
