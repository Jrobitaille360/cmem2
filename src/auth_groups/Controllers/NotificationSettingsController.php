<?php

namespace AuthGroups\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Models\NotificationDefault;
use AuthGroups\Services\ReminderList;
use AuthGroups\Utils\DateHelper;
use AuthGroups\Utils\Response;
use PDO;

/**
 * Rappels unifiés — réglages du compte (directive cmem_web #487, contrat #236 §4-§5).
 *
 *   GET    /notifications/defaults?app_id=        → défauts par type (4 kind)
 *   PUT    /notifications/defaults                → remplace les kind fournis
 *   GET    /notifications/devices?app_id=         → appareils (push_subscriptions)
 *   PUT    /notifications/devices/{id}            → kinds, plage silencieuse, libellé
 *   DELETE /notifications/devices/{id}?app_id=    → supprime l'abonnement (204)
 *
 * app_id obligatoire partout (P6) : aucun défaut serveur.
 * Hébergé dans AuthGroups (toujours chargé) : les appareils sont lus directement dans
 * push_subscriptions, sans dépendre de l'autoloader du plugin Push.
 */
class NotificationSettingsController
{
    private static function db(): PDO
    {
        return \Database::getInstance()->getConnection();
    }

    // ------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------

    private static function fail(string $code, string $field, string $message, int $status = 422): void
    {
        LoggingMiddleware::logExit($status);
        Response::error($message, ['code' => $code, 'field' => $field], $status);
    }

    /** app_id requis (P6) ; null = 422 déjà émis. */
    private static function appId(array $params): ?string
    {
        $appId = trim((string) ($params['app_id'] ?? ''));
        if ($appId === '' || strlen($appId) > 64) {
            self::fail('APP_ID_REQUIRED', 'app_id', 'app_id requis');
            return null;
        }
        return $appId;
    }

    /**
     * Valide une plage silencieuse (HH:MM). Retourne [from, to] normalisés, ou null après
     * avoir émis le 422 (format ou paire incomplète).
     */
    private static function quietRange(mixed $from, mixed $to, string $prefix): ?array
    {
        $norm = [];
        foreach (['quiet_from' => $from, 'quiet_to' => $to] as $k => $v) {
            if ($v === null || $v === '') {
                $norm[$k] = null;
                continue;
            }
            if (!is_string($v) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($v))) {
                self::fail('QUIET_TIME_INVALID', $prefix . $k, 'Format horaire attendu HH:MM');
                return null;
            }
            $norm[$k] = trim($v);
        }
        if (($norm['quiet_from'] === null) !== ($norm['quiet_to'] === null)) {
            $missing = $norm['quiet_from'] === null ? 'quiet_from' : 'quiet_to';
            self::fail('QUIET_RANGE_INCOMPLETE', $prefix . $missing, 'quiet_from et quiet_to doivent être fournis ensemble');
            return null;
        }
        return [$norm['quiet_from'], $norm['quiet_to']];
    }

    // ------------------------------------------------------------------
    // Défauts par type
    // ------------------------------------------------------------------

    public function getDefaults(array $user): void
    {
        LoggingMiddleware::logEntry();
        $appId = self::appId(Response::getRequestParams());
        if ($appId === null) {
            return;
        }
        LoggingMiddleware::logExit(200);
        Response::success('Rappels par défaut', [
            'defaults' => NotificationDefault::listWithBlanks((int) $user['user_id'], $appId),
        ]);
    }

    public function putDefaults(array $user): void
    {
        LoggingMiddleware::logEntry();
        $params = Response::getRequestParams();
        $appId  = self::appId($params);
        if ($appId === null) {
            return;
        }

        $incoming = $params['defaults'] ?? null;
        if (!is_array($incoming) || $incoming === [] || array_keys($incoming) !== range(0, count($incoming) - 1)) {
            self::fail('DEFAULTS_REQUIRED', 'defaults', 'Liste « defaults » requise');
            return;
        }

        // Tout valider avant d'écrire : une requête refusée ne laisse aucune trace.
        $validated = [];
        foreach ($incoming as $i => $d) {
            if (!is_array($d)) {
                self::fail('DEFAULTS_REQUIRED', "defaults.{$i}", 'Objet attendu');
                return;
            }
            $kind = (string) ($d['kind'] ?? '');
            if (!in_array($kind, NotificationDefault::KINDS, true)) {
                self::fail('REMINDER_KIND_UNKNOWN', "defaults.{$i}.kind",
                    'kind inconnu (attendu : ' . implode(', ', NotificationDefault::KINDS) . ')');
                return;
            }
            $check = ReminderList::validate($d['notifications'] ?? [], true, "defaults.{$i}.notifications");
            if (!$check['ok']) {
                LoggingMiddleware::logExit(422);
                Response::error($check['error']['message'], $check['error'], 422);
                return;
            }
            $quiet = self::quietRange($d['quiet_from'] ?? null, $d['quiet_to'] ?? null, "defaults.{$i}.");
            if ($quiet === null) {
                return;
            }
            $validated[$kind] = [
                'kind'               => $kind,
                'notifications'      => $check['value'] ?? [],
                'quiet_from'         => $quiet[0],
                'quiet_to'           => $quiet[1],
                'show_entity_detail' => filter_var($d['show_entity_detail'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        $ownerId = (int) $user['user_id'];
        foreach ($validated as $d) {
            NotificationDefault::upsert($ownerId, $appId, $d);
        }

        LoggingMiddleware::logExit(200);
        Response::success('Rappels par défaut mis à jour', [
            'defaults' => NotificationDefault::listWithBlanks($ownerId, $appId),
        ]);
    }

    // ------------------------------------------------------------------
    // Appareils
    // ------------------------------------------------------------------

    private static function findDevice(int $id, int $ownerId, string $appId): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT * FROM push_subscriptions WHERE id = ? AND owner_id = ? AND app_id = ?'
        );
        $stmt->execute([$id, $ownerId, $appId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** kinds stockés : NULL = les 4 types (abonnement antérieur au contrat ou jamais réglé). */
    public static function deviceKinds(?string $raw): array
    {
        if ($raw === null) {
            return NotificationDefault::KINDS;
        }
        $list = json_decode($raw, true);
        return is_array($list) ? array_values(array_intersect(NotificationDefault::KINDS, $list)) : NotificationDefault::KINDS;
    }

    private static function deviceToContract(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'device_label'    => $row['device_label'],
            'endpoint_hash'   => $row['endpoint_hash'],
            'created_at'      => DateHelper::toIso8601Utc($row['created_at']),
            'last_success_at' => DateHelper::toIso8601Utc($row['last_seen_at']),
            'kinds'           => self::deviceKinds($row['kinds']),
            'quiet_from'      => $row['quiet_from'] !== null ? substr((string) $row['quiet_from'], 0, 5) : null,
            'quiet_to'        => $row['quiet_to'] !== null ? substr((string) $row['quiet_to'], 0, 5) : null,
        ];
    }

    public function listDevices(array $user): void
    {
        LoggingMiddleware::logEntry();
        $appId = self::appId(Response::getRequestParams());
        if ($appId === null) {
            return;
        }
        $stmt = self::db()->prepare(
            'SELECT * FROM push_subscriptions WHERE owner_id = ? AND app_id = ? ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([(int) $user['user_id'], $appId]);

        LoggingMiddleware::logExit(200);
        Response::success('Appareils', [
            'devices' => array_map([self::class, 'deviceToContract'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
        ]);
    }

    public function updateDevice(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $p     = Response::getRequestParams();
        $appId = self::appId($p);
        if ($appId === null) {
            return;
        }
        $device = self::findDevice($id, (int) $user['user_id'], $appId);
        if (!$device) {
            LoggingMiddleware::logExit(404);
            Response::error('Appareil non trouvé', null, 404);
            return;
        }

        $sets = [];
        $vals = [];

        if (array_key_exists('kinds', $p)) {
            if (!is_array($p['kinds'])) {
                self::fail('REMINDER_KIND_UNKNOWN', 'kinds', 'Liste de kind attendue');
                return;
            }
            $kinds = [];
            foreach (array_values($p['kinds']) as $i => $k) {
                if (!is_string($k) || !in_array($k, NotificationDefault::KINDS, true)) {
                    self::fail('REMINDER_KIND_UNKNOWN', "kinds.{$i}",
                        'kind inconnu (attendu : ' . implode(', ', NotificationDefault::KINDS) . ')');
                    return;
                }
                $kinds[$k] = true;
            }
            $sets[] = 'kinds = ?';
            // Ordre canonique, sans doublon
            $vals[] = json_encode(array_values(array_filter(NotificationDefault::KINDS, fn($k) => isset($kinds[$k]))));
        }

        if (array_key_exists('quiet_from', $p) || array_key_exists('quiet_to', $p)) {
            $from  = array_key_exists('quiet_from', $p) ? $p['quiet_from'] : ($device['quiet_from'] !== null ? substr($device['quiet_from'], 0, 5) : null);
            $to    = array_key_exists('quiet_to', $p) ? $p['quiet_to'] : ($device['quiet_to'] !== null ? substr($device['quiet_to'], 0, 5) : null);
            $quiet = self::quietRange($from, $to, '');
            if ($quiet === null) {
                return;
            }
            $sets[] = 'quiet_from = ?';
            $vals[] = $quiet[0];
            $sets[] = 'quiet_to = ?';
            $vals[] = $quiet[1];
        }

        if (array_key_exists('device_label', $p)) {
            $label  = trim((string) ($p['device_label'] ?? ''));
            $sets[] = 'device_label = ?';
            $vals[] = $label === '' ? null : mb_substr($label, 0, 190);
        }

        if ($sets) {
            $vals[] = $id;
            self::db()->prepare('UPDATE push_subscriptions SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        }

        LoggingMiddleware::logExit(200);
        Response::success('Appareil mis à jour', [
            'device' => self::deviceToContract(self::findDevice($id, (int) $user['user_id'], $appId)),
        ]);
    }

    public function deleteDevice(array $user, int $id): void
    {
        LoggingMiddleware::logEntry();
        $appId = self::appId(Response::getRequestParams());
        if ($appId === null) {
            return;
        }
        $stmt = self::db()->prepare('DELETE FROM push_subscriptions WHERE id = ? AND owner_id = ? AND app_id = ?');
        $stmt->execute([$id, (int) $user['user_id'], $appId]);
        if ($stmt->rowCount() === 0) {
            LoggingMiddleware::logExit(404);
            Response::error('Appareil non trouvé', null, 404);
            return;
        }
        // 204 No Content : corps vide (même effet que DELETE /push/subscribe)
        LoggingMiddleware::logExit(204);
        http_response_code(204);
        exit;
    }
}
