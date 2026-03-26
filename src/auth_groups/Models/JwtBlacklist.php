<?php

namespace AuthGroups\Models;

/**
 * Modèle de blacklist JWT.
 *
 * Stocke les JTI (JWT ID) des tokens révoqués avant leur expiration naturelle.
 * Utilisé par JwtService::validate() pour rejeter les tokens blacklistés (logout).
 */
class JwtBlacklist extends BaseModel
{
    protected $table = 'jwt_blacklist';

    public $jti;
    public $user_id;
    public $expires_at;

    // BaseModel requiert ces méthodes abstraites ; non utilisées ici.
    public function create() {}
    public function update() {}

    // -----------------------------------------------------------------------
    // API publique
    // -----------------------------------------------------------------------

    /**
     * Ajoute un JTI à la blacklist.
     *
     * @param string $jti       UUID v4 du token
     * @param int    $userId    Propriétaire du token
     * @param string $expiresAt Date d'expiration du token (format MySQL DATETIME)
     */
    public function add(string $jti, int $userId, string $expiresAt): void
    {
        $stmt = $this->getDb()->prepare(
            'INSERT INTO jwt_blacklist (jti, user_id, expires_at) VALUES (:jti, :user_id, :expires_at)'
        );
        $stmt->execute([
            'jti'        => $jti,
            'user_id'    => $userId,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Vérifie si un JTI est blacklisté (et pas encore expiré).
     *
     * On n'interroge que les tokens non expirés : les tokens expirés sont
     * rejetés par JwtService::validate() de toute façon.
     *
     * @param string $jti
     * @return bool
     */
    public function isBlacklisted(string $jti): bool
    {
        $stmt = $this->getDb()->prepare(
            'SELECT 1 FROM jwt_blacklist WHERE jti = :jti AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(['jti' => $jti]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Supprime les entrées expirées (pour le cron de nettoyage).
     *
     * @return int Nombre de lignes supprimées
     */
    public function deleteExpired(): int
    {
        $stmt = $this->getDb()->prepare(
            'DELETE FROM jwt_blacklist WHERE expires_at <= NOW()'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}
