<?php

namespace AuthGroups\Services;

/**
 * Rappels unifiés — forme, normalisation et validation de la liste `notifications`
 * (directive cmem_web #487, contrat #236 §2-§3).
 *
 * Forme unique : [{ "type": "EMAIL"|"DISPLAY", "minutes_before": int }, ...]
 *
 * Sémantique du champ (identique pour toutes les entités) :
 *   absent → création : défaut du type ; mise à jour : inchangé
 *   null   → défaut du type (stocké NULL)
 *   []     → aucun rappel
 *   [...]  → la liste remplace le défaut du type
 */
class ReminderList
{
    public const CHANNELS = ['EMAIL', 'DISPLAY'];

    /** Délais permis pour les défauts par type (Q2). Les listes par élément acceptent 0..MAX_LEAD. */
    public const DEFAULT_LEADS = [0, 5, 10, 15, 30, 60, 120, 1440, 2880];

    public const MAX_LEAD  = 40320; // 4 semaines
    public const MAX_ITEMS = 10;

    /**
     * Lecture : ramène une valeur stockée (JSON ou tableau, formes héritées comprises)
     * à la forme du contrat. NULL reste NULL (défaut du type).
     *
     * Formes héritées : type email / notification / AUDIO / DISPLAY minuscules, clé `minutes`.
     * Les entrées illisibles sont ignorées plutôt que de casser la lecture.
     */
    public static function normalize(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
            if (!is_array($raw)) {
                return null;
            }
        }
        if (!is_array($raw)) {
            return null;
        }

        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $minutes = $item['minutes_before'] ?? $item['minutes'] ?? null;
            if (!is_numeric($minutes)) {
                continue;
            }
            $out[] = [
                'type'           => self::normalizeChannel((string) ($item['type'] ?? '')),
                'minutes_before' => max(0, (int) $minutes),
            ];
        }
        return $out;
    }

    /** EMAIL reste EMAIL ; tout le reste (DISPLAY, notification, AUDIO, inconnu) devient DISPLAY. */
    public static function normalizeChannel(string $type): string
    {
        return strtoupper($type) === 'EMAIL' ? 'EMAIL' : 'DISPLAY';
    }

    /**
     * Écriture : valide la liste envoyée par le client.
     *
     * @param mixed  $value       valeur du champ (déjà présent dans le corps)
     * @param bool   $forDefaults true = délais restreints à DEFAULT_LEADS
     * @param string $field       préfixe du champ dans les erreurs
     * @return array{ok:bool, value:?array, error:?array}
     *         value = NULL (défaut du type) ou liste normalisée ;
     *         error = { code, field, message } au premier défaut rencontré
     */
    public static function validate(mixed $value, bool $forDefaults = false, string $field = 'notifications'): array
    {
        if ($value === null) {
            return self::ok(null);
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if ($value !== 'null' && !is_array($decoded)) {
                return self::fail('REMINDER_LIST_INVALID', $field, 'La liste de rappels doit être un tableau');
            }
            $value = $decoded;
            if ($value === null) {
                return self::ok(null);
            }
        }
        if (!is_array($value) || array_keys($value) !== range(0, max(0, count($value) - 1)) && $value !== []) {
            return self::fail('REMINDER_LIST_INVALID', $field, 'La liste de rappels doit être un tableau');
        }
        if (count($value) > self::MAX_ITEMS) {
            return self::fail('REMINDER_TOO_MANY', $field, 'Au plus ' . self::MAX_ITEMS . ' rappels');
        }

        $out  = [];
        $seen = [];
        foreach ($value as $i => $item) {
            if (!is_array($item)) {
                return self::fail('REMINDER_LIST_INVALID', "{$field}.{$i}", 'Un rappel doit être un objet');
            }

            $type = strtoupper((string) ($item['type'] ?? ''));
            if (!in_array($type, self::CHANNELS, true)) {
                return self::fail('REMINDER_CHANNEL_UNKNOWN', "{$field}.{$i}.type",
                    'Canal de rappel inconnu : EMAIL ou DISPLAY attendu');
            }

            $m = array_key_exists('minutes_before', $item) ? $item['minutes_before'] : ($item['minutes'] ?? null);
            if (is_string($m) && preg_match('/^\d+$/', $m)) {
                $m = (int) $m;
            }
            $leadOk = is_int($m) && $m >= 0 && $m <= self::MAX_LEAD
                && (!$forDefaults || in_array($m, self::DEFAULT_LEADS, true));
            if (!$leadOk) {
                return self::fail('REMINDER_LEAD_INVALID', "{$field}.{$i}.minutes_before", $forDefaults
                    ? 'Délai hors liste : ' . implode(', ', self::DEFAULT_LEADS)
                    : 'Délai entier de 0 à ' . self::MAX_LEAD . ' minutes attendu');
            }

            $key = "{$type}|{$m}";
            if (isset($seen[$key])) {
                return self::fail('REMINDER_DUPLICATE', "{$field}.{$i}", 'Rappel en double (même canal et même délai)');
            }
            $seen[$key] = true;

            $out[] = ['type' => $type, 'minutes_before' => $m];
        }

        return self::ok($out);
    }

    /**
     * Contrôleurs : lit `notifications` dans le corps de requête. Si le champ est présent et
     * invalide, émet le 422 codé et retourne false.
     *
     * @return array{present:bool, value:?array}|false
     */
    public static function extract(array $input): array|false
    {
        if (!array_key_exists('notifications', $input)) {
            return ['present' => false, 'value' => null];
        }
        $check = self::validate($input['notifications']);
        if (!$check['ok']) {
            \AuthGroups\Middleware\LoggingMiddleware::logExit(422);
            \AuthGroups\Utils\Response::error($check['error']['message'], $check['error'], 422);
            return false;
        }
        return ['present' => true, 'value' => $check['value']];
    }

    /** Premier délai d'une liste (compatibilité `rappelMinutesAvant`), NULL si vide ou NULL. */
    public static function firstLead(?array $list): ?int
    {
        return isset($list[0]['minutes_before']) ? (int) $list[0]['minutes_before'] : null;
    }

    /** Valeur à stocker en base : NULL ou JSON. */
    public static function toStorage(?array $list): ?string
    {
        return $list === null ? null : json_encode($list, JSON_UNESCAPED_UNICODE);
    }

    private static function ok(?array $value): array
    {
        return ['ok' => true, 'value' => $value, 'error' => null];
    }

    private static function fail(string $code, string $field, string $message): array
    {
        return ['ok' => false, 'value' => null, 'error' => ['code' => $code, 'field' => $field, 'message' => $message]];
    }
}
