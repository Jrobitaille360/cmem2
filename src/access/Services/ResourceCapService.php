<?php

namespace Access\Services;

use Access\Config\ResourceCaps;
use Database;
use PDO;

/**
 * Caps de ressources à trois niveaux (plan accès sur approbation #269, Phase 3 bis, tâches #293-#295).
 *
 *   1. défaut : Access\Config\ResourceCaps ;
 *   2. surcharge par groupe : group_resource_caps (clés de groupe : max_group_members) ;
 *   3. surcharge par utilisateur : user_resource_caps (toutes les autres clés).
 *
 * Présence d'une ligne = surcharge ; value NULL = illimité explicite.
 * Aucune lecture de plan (Stripe / CmemPlans).
 */
class ResourceCapService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /** @return array<string,int|null> surcharges de l'usager (clés perso) */
    public function userOverrides(int $userId): array
    {
        return $this->overrides('user_resource_caps', 'user_id', $userId);
    }

    /** @return array<string,int|null> surcharges du groupe (clés de groupe) */
    public function groupOverrides(int $groupId): array
    {
        return $this->overrides('group_resource_caps', 'group_id', $groupId);
    }

    /**
     * Caps effectifs d'un usager : défauts + ses surcharges sur les clés perso.
     * Les clés de groupe gardent leur défaut (plafond appliqué à la création d'un groupe).
     *
     * @return array<string,int|null>
     */
    public function effectiveForUser(int $userId): array
    {
        $caps = ResourceCaps::defaults();
        foreach ($this->userOverrides($userId) as $key => $value) {
            if (ResourceCaps::isUserKey($key)) {
                $caps[$key] = $value;
            }
        }
        return $caps;
    }

    /**
     * Caps effectifs d'un groupe : défauts des clés de groupe + surcharges du groupe.
     *
     * @return array<string,int|null>
     */
    public function effectiveForGroup(int $groupId): array
    {
        $caps = array_intersect_key(ResourceCaps::defaults(), array_flip(ResourceCaps::groupKeys()));
        foreach ($this->groupOverrides($groupId) as $key => $value) {
            if (ResourceCaps::isGroupKey($key)) {
                $caps[$key] = $value;
            }
        }
        return $caps;
    }

    /**
     * Valide un objet de surcharges : clés connues du bon niveau, entier ≥ 0 ou null.
     *
     * @return array<string,string> erreurs par clé (vide si valide)
     */
    public static function validate(mixed $caps, bool $groupLevel): array
    {
        if (!is_array($caps) || (count($caps) > 0 && array_keys($caps) === range(0, count($caps) - 1))) {
            return ['caps' => 'objet {cle: entier ≥ 0 | null} attendu'];
        }
        $errors = [];
        foreach ($caps as $key => $value) {
            $key = (string) $key;
            $okKey = $groupLevel ? ResourceCaps::isGroupKey($key) : ResourceCaps::isUserKey($key);
            if (!$okKey) {
                $allowed = $groupLevel ? ResourceCaps::groupKeys() : ResourceCaps::userKeys();
                $errors[$key] = 'clé non permise ; clés acceptées : ' . implode(', ', $allowed);
                continue;
            }
            if ($value !== null && (!is_int($value) || $value < 0)) {
                $errors[$key] = 'entier ≥ 0 ou null (illimité) attendu';
            }
        }
        return $errors;
    }

    /** Remplace toutes les surcharges d'un usager (PUT : liste complète). */
    public function replaceUserOverrides(int $userId, array $caps, int $setBy): void
    {
        $this->replace('user_resource_caps', 'user_id', $userId, $caps, $setBy);
    }

    /** Remplace toutes les surcharges d'un groupe (PUT : liste complète). */
    public function replaceGroupOverrides(int $groupId, array $caps, int $setBy): void
    {
        $this->replace('group_resource_caps', 'group_id', $groupId, $caps, $setBy);
    }

    /** @return array<string,int|null> */
    private function overrides(string $table, string $column, int $id): array
    {
        $st = $this->pdo->prepare("SELECT cap_key, value FROM {$table} WHERE {$column} = ? ORDER BY cap_key");
        $st->execute([$id]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['cap_key']] = $row['value'] === null ? null : (int) $row['value'];
        }
        return $out;
    }

    private function replace(string $table, string $column, int $id, array $caps, int $setBy): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM {$table} WHERE {$column} = ?")->execute([$id]);
            $ins = $this->pdo->prepare(
                "INSERT INTO {$table} ({$column}, cap_key, value, set_by) VALUES (?, ?, ?, ?)"
            );
            foreach ($caps as $key => $value) {
                $ins->execute([$id, (string) $key, $value, $setBy]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
