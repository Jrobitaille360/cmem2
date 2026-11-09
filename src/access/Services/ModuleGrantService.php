<?php

namespace Access\Services;

use Database;
use PDO;
use Stripe\Config\CmemModules;

/**
 * Modules cochés par personne (plan accès sur approbation #269, Phase 3).
 *
 * « Disponible » = une ligne dans user_module_grants. Aucune lecture de plan.
 *   - hors groupe : grants de l'usager ;
 *   - dans un groupe : grants du propriétaire du groupe (groups.owner_id).
 * « Activé » reste tenant_modules.enabled (ModuleController / GroupModuleController).
 */
class ModuleGrantService
{
    /** Grants posés à l'approbation d'un compte (modifiables par l'admin ou l'invitation). */
    public const DEFAULT_GRANTS = ['projet', 'contacts', 'crm', 'ged'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /** @return string[] clés triées */
    public function grantsFor(int $userId): array
    {
        $st = $this->pdo->prepare("SELECT module_key FROM user_module_grants WHERE user_id = ?");
        $st->execute([$userId]);
        $keys = $st->fetchAll(PDO::FETCH_COLUMN);
        sort($keys, SORT_STRING); // ORDER BY sur un ENUM trierait par index, pas alphabétiquement
        return $keys;
    }

    /**
     * Modules disponibles pour l'usager : ses grants ∪ grants des propriétaires de ses
     * groupes actifs (un membre utilise un module que le propriétaire du groupe a ;
     * l'IA reste imputée au budget de l'appelant). Hors groupe : ses seuls grants.
     * @return string[] clés triées
     */
    public function effectiveGrantsFor(int $userId): array
    {
        $st = $this->pdo->prepare(
            "SELECT module_key FROM user_module_grants WHERE user_id = ?
             UNION
             SELECT umg.module_key
               FROM group_members gm
               JOIN `groups` g ON g.id = gm.group_id AND g.deleted_at IS NULL
               JOIN user_module_grants umg ON umg.user_id = g.owner_id
              WHERE gm.user_id = ? AND gm.deleted_at IS NULL"
        );
        $st->execute([$userId, $userId]);
        $keys = array_values(array_unique($st->fetchAll(PDO::FETCH_COLUMN)));
        sort($keys, SORT_STRING);
        return $keys;
    }

    public function isAvailable(int $userId, string $key): bool
    {
        return in_array($key, $this->effectiveGrantsFor($userId), true);
    }

    /** Propriétaire du groupe actif, ou null. */
    public function groupOwnerId(int $groupId): ?int
    {
        $st = $this->pdo->prepare("SELECT owner_id FROM `groups` WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$groupId]);
        $owner = $st->fetchColumn();
        return $owner !== false && $owner !== null ? (int) $owner : null;
    }

    /** @return string[] grants du propriétaire du groupe */
    public function grantsForGroup(int $groupId): array
    {
        $owner = $this->groupOwnerId($groupId);
        return $owner === null ? [] : $this->grantsFor($owner);
    }

    public function isAvailableForGroup(int $groupId, string $key): bool
    {
        return in_array($key, $this->grantsForGroup($groupId), true);
    }

    /**
     * Remplace la liste des modules cochés.
     * @param string[] $keys clés valides (CmemModules::KEYS)
     * @return string[] liste résultante triée
     */
    public function setGrants(int $userId, array $keys, ?int $grantedBy): array
    {
        $keys = array_values(array_unique(array_filter($keys, fn($k) => is_string($k) && CmemModules::isValidKey($k))));
        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        }
        try {
            if ($keys) {
                $marks = implode(',', array_fill(0, count($keys), '?'));
                $this->pdo->prepare("DELETE FROM user_module_grants WHERE user_id = ? AND module_key NOT IN ({$marks})")
                    ->execute(array_merge([$userId], $keys));
            } else {
                $this->pdo->prepare("DELETE FROM user_module_grants WHERE user_id = ?")->execute([$userId]);
            }
            $ins = $this->pdo->prepare(
                "INSERT IGNORE INTO user_module_grants (user_id, module_key, granted_by) VALUES (?, ?, ?)"
            );
            foreach ($keys as $k) {
                $ins->execute([$userId, $k, $grantedBy]);
            }
            if ($ownTx) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->grantsFor($userId);
    }

    /** Pose les grants par défaut si le compte n'en a encore aucun (première approbation). */
    public function grantDefaultsIfNone(int $userId, ?int $grantedBy): void
    {
        if ($this->grantsFor($userId) === []) {
            $this->setGrants($userId, self::DEFAULT_GRANTS, $grantedBy);
        }
    }
}
