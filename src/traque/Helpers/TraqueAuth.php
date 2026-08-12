<?php

namespace Traque\Helpers;

use AuthGroups\Utils\Response;
use PDO;

/**
 * TraqueAuth — rôles de jeu Traque (gm / traque_admin)
 *
 * Rôles orthogonaux aux rôles CMEM2 : un joueur peut être UTILISATEUR côté API
 * et Maître de Jeu côté Traque. Un rôle est actif tant que revoked_at IS NULL.
 *
 * Directive : 20260605_161757_traque_vers_cmem2_API__table-traque-roles-et-endpoints-admin-gm.md
 */
class TraqueAuth
{
    /** Rôles reconnus */
    public const ROLES = ['gm', 'traque_admin'];

    private static function db(): PDO
    {
        return \Database::getInstance()->getConnection();
    }

    /** Vrai si $role fait partie des rôles reconnus */
    public static function isValidRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    /** Rôles actifs du joueur (tableau de chaînes) */
    public static function userRoles(int $userId): array
    {
        $stmt = self::db()->prepare(
            'SELECT role FROM traque_roles WHERE user_id = :uid AND revoked_at IS NULL'
        );
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /** Vrai si le joueur détient ce rôle, non révoqué */
    public static function hasRole(int $userId, string $role): bool
    {
        $stmt = self::db()->prepare(
            'SELECT 1 FROM traque_roles
              WHERE user_id = :uid AND role = :role AND revoked_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':role' => $role]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Exige un rôle : répond 403 et retourne false si absent.
     * L'appelant doit interrompre son traitement quand false est retourné.
     */
    public static function requireRole(int $userId, string $role): bool
    {
        if (self::hasRole($userId, $role)) {
            return true;
        }

        Response::error('Rôle Traque requis : ' . $role, null, 403);

        return false;
    }

    /**
     * Accorde un rôle.
     *
     * L'unicité porte sur (user_id, role) : un rôle révoqué puis réaccordé
     * réutilise la ligne existante plutôt que d'en créer une seconde.
     *
     * @return array{status: string, row: array|null}
     *         status = 'granted' | 'already_active'
     */
    public static function grant(int $userId, string $role, int $grantedBy): array
    {
        $db = self::db();

        $stmt = $db->prepare('SELECT * FROM traque_roles WHERE user_id = :uid AND role = :role');
        $stmt->execute([':uid' => $userId, ':role' => $role]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && $existing['revoked_at'] === null) {
            return ['status' => 'already_active', 'row' => $existing];
        }

        if ($existing) {
            $upd = $db->prepare(
                'UPDATE traque_roles
                    SET revoked_at = NULL, granted_at = CURRENT_TIMESTAMP, granted_by = :by
                  WHERE id = :id'
            );
            $upd->execute([':by' => $grantedBy, ':id' => $existing['id']]);
        } else {
            $ins = $db->prepare(
                'INSERT INTO traque_roles (user_id, role, granted_by) VALUES (:uid, :role, :by)'
            );
            $ins->execute([':uid' => $userId, ':role' => $role, ':by' => $grantedBy]);
        }

        return ['status' => 'granted', 'row' => self::find($userId, $role)];
    }

    /**
     * Accorde un rôle uniquement s'il n'est pas déjà actif (attribution automatique).
     *
     * @return bool true si le rôle vient d'être accordé
     */
    public static function grantIfAbsent(int $userId, string $role, int $grantedBy): bool
    {
        return self::grant($userId, $role, $grantedBy)['status'] === 'granted';
    }

    /**
     * Révoque un rôle (revoked_at = NOW()) — la ligne est conservée pour l'audit.
     *
     * @return array|null La ligne révoquée, ou null si rôle absent / déjà révoqué
     */
    public static function revoke(int $userId, string $role): ?array
    {
        $db = self::db();

        $stmt = $db->prepare(
            'UPDATE traque_roles
                SET revoked_at = CURRENT_TIMESTAMP
              WHERE user_id = :uid AND role = :role AND revoked_at IS NULL'
        );
        $stmt->execute([':uid' => $userId, ':role' => $role]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return self::find($userId, $role);
    }

    /** Ligne brute (révoquée ou non) pour un couple (user_id, role) */
    public static function find(int $userId, string $role): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT * FROM traque_roles WHERE user_id = :uid AND role = :role'
        );
        $stmt->execute([':uid' => $userId, ':role' => $role]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Journal d'attribution — toutes les lignes, révoquées comprises,
     * enrichies des courriels du bénéficiaire et de l'émetteur.
     */
    public static function log(int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));

        $sql = "SELECT r.id, r.user_id, u.email AS user_email, r.role,
                       r.granted_at, g.email AS granted_by_email, r.revoked_at
                  FROM traque_roles r
                  LEFT JOIN users u ON u.id = r.user_id
                  LEFT JOIN users g ON g.id = r.granted_by
                 ORDER BY r.granted_at DESC
                 LIMIT {$limit}";

        return self::db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
