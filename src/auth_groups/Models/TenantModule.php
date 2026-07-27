<?php

namespace AuthGroups\Models;

use PDO;

/**
 * Registre d'activation des modules par usager (table tenant_modules).
 * Directive cmem_web 20260727_144926.
 *
 * L'absence de ligne n'est pas une erreur : elle signifie « état par défaut »
 * (voir Stripe\Config\CmemModules::isEnabledByDefault). Une ligne n'est créée
 * qu'au premier PATCH — GET /modules ne backfille rien.
 *
 * `group_id` existe en base pour préparer le plan équipe, mais n'est pas servi en v1.
 */
class TenantModule extends BaseModel
{
    protected $table = 'tenant_modules';

    public function create() { throw new \LogicException('Utiliser setEnabled()'); }
    public function update() { throw new \LogicException('Utiliser setEnabled()'); }

    /** Lignes de l'usager, indexées par module_key. */
    public function findAllByOwner(int $ownerId): array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table} WHERE owner_id = ?"
        );
        $stmt->execute([$ownerId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['module_key']] = $row;
        }
        return $out;
    }

    public function findByOwnerAndKey(int $ownerId, string $moduleKey): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table} WHERE owner_id = ? AND module_key = ?"
        );
        $stmt->execute([$ownerId, $moduleKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Pose l'état d'activation. UPSERT sur (owner_id, module_key) : deux PATCH
     * successifs ne créent jamais deux lignes.
     */
    public function setEnabled(int $ownerId, string $appId, string $moduleKey, bool $enabled): array
    {
        $stmt = $this->getDb()->prepare(
            "INSERT INTO {$this->table} (app_id, owner_id, module_key, enabled)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 enabled = VALUES(enabled),
                 app_id  = VALUES(app_id)"
        );
        $stmt->execute([$appId, $ownerId, $moduleKey, $enabled ? 1 : 0]);

        return $this->findByOwnerAndKey($ownerId, $moduleKey) ?? [];
    }

    /**
     * Incrémente le compteur d'usage de la période et retourne sa nouvelle valeur.
     * Réinitialise d'abord si la période est échue. Appelé par les endpoints
     * consommateurs (module IA — directive ai-proxy), jamais par le client.
     */
    public function incrementQuota(int $ownerId, string $appId, string $moduleKey, string $nextResetAt): int
    {
        $db = $this->getDb();

        $stmt = $db->prepare(
            "INSERT INTO {$this->table} (app_id, owner_id, module_key, enabled, quota_used, quota_reset_at)
             VALUES (?, ?, ?, 0, 1, ?)
             ON DUPLICATE KEY UPDATE
                 quota_used     = IF(quota_reset_at IS NULL OR quota_reset_at <= NOW(), 1, quota_used + 1),
                 quota_reset_at = IF(quota_reset_at IS NULL OR quota_reset_at <= NOW(), VALUES(quota_reset_at), quota_reset_at)"
        );
        $stmt->execute([$appId, $ownerId, $moduleKey, $nextResetAt]);

        $row = $this->findByOwnerAndKey($ownerId, $moduleKey);
        return (int) ($row['quota_used'] ?? 0);
    }
}
