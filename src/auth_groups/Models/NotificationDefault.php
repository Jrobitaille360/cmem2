<?php

namespace AuthGroups\Models;

use AuthGroups\Services\ReminderList;
use PDO;

/**
 * Rappels par défaut par type (kind), par compte et par app — table notification_defaults
 * (directive cmem_web #487, contrat #236 §4).
 *
 * Un kind jamais réglé n'a pas de ligne : il est renvoyé avec notifications = [],
 * plage silencieuse nulle et show_entity_detail = false.
 */
class NotificationDefault
{
    public const KINDS = ['event', 'recurring', 'task_due', 'contact_followup'];

    private static function db(): PDO
    {
        return \Database::getInstance()->getConnection();
    }

    /** Lignes existantes, indexées par kind (forme du contrat). */
    public static function findByOwner(int $ownerId, string $appId): array
    {
        $stmt = self::db()->prepare(
            'SELECT kind, notifications, quiet_from, quiet_to, show_entity_detail
               FROM notification_defaults WHERE owner_id = ? AND app_id = ?'
        );
        $stmt->execute([$ownerId, $appId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['kind']] = self::toContract($row);
        }
        return $out;
    }

    /** Les 4 kind, dans l'ordre de KINDS, valeurs vides pour un kind jamais réglé. */
    public static function listWithBlanks(int $ownerId, string $appId): array
    {
        $rows = self::findByOwner($ownerId, $appId);
        return array_map(fn(string $k) => $rows[$k] ?? self::blank($k), self::KINDS);
    }

    public static function blank(string $kind): array
    {
        return [
            'kind'               => $kind,
            'notifications'      => [],
            'quiet_from'         => null,
            'quiet_to'           => null,
            'show_entity_detail' => false,
        ];
    }

    /** @param array $d forme du contrat, déjà validée (quiet en HH:MM ou null) */
    public static function upsert(int $ownerId, string $appId, array $d): void
    {
        $stmt = self::db()->prepare(
            'INSERT INTO notification_defaults
                 (app_id, owner_id, kind, notifications, quiet_from, quiet_to, show_entity_detail)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 notifications = VALUES(notifications),
                 quiet_from = VALUES(quiet_from),
                 quiet_to = VALUES(quiet_to),
                 show_entity_detail = VALUES(show_entity_detail)'
        );
        $stmt->execute([
            $appId,
            $ownerId,
            $d['kind'],
            ReminderList::toStorage($d['notifications'] ?? []),
            $d['quiet_from'] ?? null,
            $d['quiet_to'] ?? null,
            !empty($d['show_entity_detail']) ? 1 : 0,
        ]);
    }

    public static function toContract(array $row): array
    {
        return [
            'kind'               => $row['kind'],
            'notifications'      => ReminderList::normalize($row['notifications']) ?? [],
            'quiet_from'         => $row['quiet_from'] !== null ? substr((string) $row['quiet_from'], 0, 5) : null,
            'quiet_to'           => $row['quiet_to'] !== null ? substr((string) $row['quiet_to'], 0, 5) : null,
            'show_entity_detail' => (bool) $row['show_entity_detail'],
        ];
    }
}
