<?php

namespace Push\Models;

use AuthGroups\Models\BaseModel;
use PDO;

/**
 * Préférences de notification — portée COMPTE (owner_id, app_id, kind).
 *
 * Décision d'architecture (directive cmem_web 20260726_140426, point à trancher) :
 * les préférences ne sont pas rattachées à un appareil. Un compte = un jeu de
 * préférences ; toutes les subscriptions du compte reçoivent le même envoi. C'est la
 * seule portée compatible avec l'exigence « une échéance = un envoi logique ».
 */
class NotificationPref extends BaseModel
{
    protected $table = 'notification_prefs';

    public const KINDS         = ['event', 'task_due', 'recurring', 'contact_followup'];
    public const LEAD_MINUTES  = [5, 15, 60, 1440];
    public const DEFAULT_LEAD  = 15;

    public function create() { throw new \LogicException('Utiliser upsert()'); }
    public function update() { throw new \LogicException('Utiliser upsert()'); }

    /** Lignes persistées, indexées par kind. */
    public function findByOwner(int $ownerId, string $appId): array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table} WHERE owner_id = ? AND app_id = ?"
        );
        $stmt->execute([$ownerId, $appId]);

        $byKind = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byKind[$row['kind']] = $row;
        }
        return $byKind;
    }

    /**
     * Les 4 kinds, complétés par les défauts (opt-in : enabled = false).
     *
     * @return array<int, array>
     */
    public function findByOwnerWithDefaults(int $ownerId, string $appId): array
    {
        $persisted = $this->findByOwner($ownerId, $appId);

        $out = [];
        foreach (self::KINDS as $kind) {
            $out[] = isset($persisted[$kind])
                ? self::toContract($persisted[$kind])
                : [
                    'kind'         => $kind,
                    'lead_minutes' => self::DEFAULT_LEAD,
                    'quiet_from'   => null,
                    'quiet_to'     => null,
                    'enabled'      => false,
                ];
        }
        return $out;
    }

    public function upsert(
        int     $ownerId,
        string  $appId,
        string  $kind,
        int     $leadMinutes,
        ?string $quietFrom,
        ?string $quietTo,
        bool    $enabled
    ): void {
        $stmt = $this->getDb()->prepare(
            "INSERT INTO {$this->table}
                 (app_id, owner_id, kind, lead_minutes, quiet_from, quiet_to, enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 lead_minutes = VALUES(lead_minutes),
                 quiet_from   = VALUES(quiet_from),
                 quiet_to     = VALUES(quiet_to),
                 enabled      = VALUES(enabled)"
        );
        $stmt->execute([$appId, $ownerId, $kind, $leadMinutes, $quietFrom, $quietTo, $enabled ? 1 : 0]);
    }

    /** Préférences actives d'un usager, tous app_id confondus, indexées par kind. */
    public function enabledByOwner(int $ownerId): array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM {$this->table} WHERE owner_id = ? AND enabled = 1"
        );
        $stmt->execute([$ownerId]);

        $byKind = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byKind[$row['kind']] = $row;
        }
        return $byKind;
    }

    public static function toContract(array $row): array
    {
        return [
            'kind'         => $row['kind'],
            'lead_minutes' => (int) $row['lead_minutes'],
            'quiet_from'   => $row['quiet_from'] !== null ? substr($row['quiet_from'], 0, 5) : null,
            'quiet_to'     => $row['quiet_to'] !== null ? substr($row['quiet_to'], 0, 5) : null,
            'enabled'      => (bool) $row['enabled'],
        ];
    }
}
