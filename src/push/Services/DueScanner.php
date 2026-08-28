<?php

namespace Push\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Push\Models\NotificationPref;

/**
 * Sélection des échéances à notifier pour un usager.
 *
 * Principe : une échéance est due dès qu'elle tombe dans ]maintenant − grâce ; maintenant + lead_minutes].
 * La fenêtre n'est pas bornée par la période du cron — l'idempotence
 * (push_notification_log) suffit à garantir un envoi unique, ce qui rend le balayage
 * insensible à un cron en retard, arrêté quelques heures ou relancé manuellement.
 *
 * Fuseaux : les datetimes des entités sont stockés dans le fuseau de l'entité
 * (calendar_events.timezone, calendar_todos.timezone) ; pour les entités sans fuseau
 * propre (opportunités), c'est celui de l'usager (users.timezone). Toutes les
 * comparaisons se font en UTC, jamais avec NOW() côté SQL.
 */
class DueScanner
{
    /** Tolérance sur les échéances déjà passées, par kind (minutes). */
    private const GRACE_MINUTES = [
        'event'            => 60,
        'recurring'        => 60,
        'task_due'         => 60,
        'contact_followup' => 7 * 24 * 60,
    ];

    private PDO $db;

    /** Trace du dernier scan() — alimentée pour le mode --verbose du cron. */
    public array $debug = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array{kind:string, entity_id:int, occurrence_key:string,
     *                          fire_at:string, app_id:string, title:string, body:string,
     *                          data:array}>
     */
    public function scan(int $ownerId, DateTimeImmutable $nowUtc): array
    {
        $prefs        = (new NotificationPref())->enabledByOwner($ownerId);
        $this->debug  = ['owner' => $ownerId, 'prefs_enabled' => array_keys($prefs), 'kinds' => []];

        if (empty($prefs)) {
            return [];
        }

        $userTz            = $this->userTimezone($ownerId);
        $this->debug['tz'] = $userTz;
        $due               = [];

        foreach ($prefs as $kind => $pref) {
            if ($this->inQuietHours($pref, $nowUtc, $userTz)) {
                $this->debug['kinds'][$kind] = 'plage silencieuse';
                continue;
            }

            $lead  = (int) $pref['lead_minutes'];
            $appId = $pref['app_id'];

            $items = match ($kind) {
                'event'            => $this->scanEvents($ownerId),
                'recurring'        => $this->scanRecurringOccurrences($ownerId),
                'task_due'         => $this->scanTodos($ownerId),
                // Deux sources sous le même kind (décision cmem_web 2026-07-26) :
                // relance portée par la fiche contact + échéance d'opportunité.
                'contact_followup' => array_merge(
                    $this->scanRelancesContact($ownerId, $userTz),
                    $this->scanOpportunites($ownerId, $userTz)
                ),
                default            => [],
            };

            $retenus = 0;

            foreach ($items as $item) {
                if (!$this->isDue($item['fire_at'], $nowUtc, $lead, $kind)) {
                    continue;
                }
                $retenus++;

                $data = [
                    'type'       => $kind,
                    'id'         => $item['entity_id'],
                    'occurrence' => $item['occurrence_iso'],
                ];
                // Un kind peut couvrir plusieurs entités (contact_followup) : le client a
                // besoin de savoir quelle fiche ouvrir.
                if (isset($item['entity'])) {
                    $data['entity'] = $item['entity'];
                }

                $showDetail = (bool) ($pref['show_entity_detail'] ?? false);
                $realTitle  = $item['title'] ?? '';

                $due[] = [
                    'kind'           => $kind,
                    'entity_id'      => $item['entity_id'],
                    'occurrence_key' => $item['occurrence_key'],
                    'fire_at'        => $item['fire_at']->format('Y-m-d H:i:s'),
                    'app_id'         => $appId,
                    'title'          => ($showDetail && $realTitle !== '') ? $realTitle : WebPushService::genericTitle(),
                    'body'           => self::genericBody($kind, $lead),
                    'data'           => $data,
                ];
            }

            $this->debug['kinds'][$kind] = sprintf(
                'candidats=%d retenus=%d lead=%dmin', count($items), $retenus, $lead
            );
        }

        return $due;
    }

    // ------------------------------------------------------------------
    // Fenêtre et plage silencieuse
    // ------------------------------------------------------------------

    private function isDue(DateTimeImmutable $fireAt, DateTimeImmutable $nowUtc, int $lead, string $kind): bool
    {
        $upper = $nowUtc->modify("+{$lead} minutes");
        $grace = self::GRACE_MINUTES[$kind] ?? 60;
        $lower = $nowUtc->modify("-{$grace} minutes");

        return $fireAt > $lower && $fireAt <= $upper;
    }

    /** Plage « ne pas déranger » évaluée dans le fuseau de l'usager (gère le passage minuit). */
    private function inQuietHours(array $pref, DateTimeImmutable $nowUtc, string $userTz): bool
    {
        if (empty($pref['quiet_from']) || empty($pref['quiet_to'])) {
            return false;
        }

        $localTime = (int) $nowUtc->setTimezone(new DateTimeZone($userTz))->format('Hi');
        $from      = (int) str_replace(':', '', substr((string) $pref['quiet_from'], 0, 5));
        $to        = (int) str_replace(':', '', substr((string) $pref['quiet_to'], 0, 5));

        if ($from === $to) {
            return false;
        }
        if ($from < $to) {
            return $localTime >= $from && $localTime < $to;
        }
        // Plage à cheval sur minuit (ex. 22:00 → 07:00)
        return $localTime >= $from || $localTime < $to;
    }

    /**
     * Fuseau de l'usager, par ordre de priorité :
     *   1. `users.timezone` — posé par le client (Intl.DateTimeFormat), fait autorité ;
     *   2. le fuseau de son premier calendrier — repli pour les comptes antérieurs à la
     *      colonne, qui n'ont encore jamais renseigné leur fuseau ;
     *   3. `America/Montreal`.
     *
     * Un identifiant absent de la base IANA du serveur (tzdata plus ancien, saisie directe
     * en base) est ignoré au profit du repli suivant, jamais passé à DateTimeZone.
     */
    private function userTimezone(int $ownerId): string
    {
        $stmt = $this->db->prepare("SELECT timezone FROM users WHERE id = ?");
        $stmt->execute([$ownerId]);
        $tz = (string) ($stmt->fetchColumn() ?: '');

        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            return $tz;
        }

        $stmt = $this->db->prepare(
            "SELECT timezone FROM calendars
              WHERE user_id = ? AND deleted_at IS NULL AND timezone IS NOT NULL
              ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([$ownerId]);
        $tz = (string) ($stmt->fetchColumn() ?: '');

        return $tz !== '' && in_array($tz, timezone_identifiers_list(), true) ? $tz : 'America/Montreal';
    }

    private function toUtc(string $naiveDatetime, string $timezone): ?DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($naiveDatetime, new DateTimeZone($timezone)))
                ->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Sources d'échéances
    // ------------------------------------------------------------------

    /** Événements simples (sans RRULE). */
    private function scanEvents(int $ownerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, title, start_datetime, timezone
               FROM calendar_events
              WHERE user_id = ?
                AND deleted_at IS NULL
                AND status <> 'cancelled'
                AND recurrence_rule IS NULL
                AND start_datetime BETWEEN (UTC_TIMESTAMP() - INTERVAL 3 DAY)
                                       AND (UTC_TIMESTAMP() + INTERVAL 3 DAY)"
        );
        $stmt->execute([$ownerId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fireAt = $this->toUtc($row['start_datetime'], $row['timezone'] ?: 'America/Montreal');
            if ($fireAt === null) {
                continue;
            }
            $out[] = [
                'entity_id'      => (int) $row['id'],
                'occurrence_key' => '-',
                'fire_at'        => $fireAt,
                'occurrence_iso' => $fireAt->format('c'),
                'title'          => (string) $row['title'],
            ];
        }
        return $out;
    }

    /** Occurrences des événements récurrents. */
    private function scanRecurringOccurrences(int $ownerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT o.event_id,
                    o.occurrence_date,
                    COALESCE(o.modified_start_datetime, o.start_datetime) AS start_datetime,
                    e.timezone,
                    e.title
               FROM event_occurrences o
               JOIN calendar_events e ON e.id = o.event_id
              WHERE e.user_id = ?
                AND e.deleted_at IS NULL
                AND e.status <> 'cancelled'
                AND e.recurrence_rule IS NOT NULL
                AND o.is_cancelled = 0
                AND COALESCE(o.modified_start_datetime, o.start_datetime)
                    BETWEEN (UTC_TIMESTAMP() - INTERVAL 3 DAY) AND (UTC_TIMESTAMP() + INTERVAL 3 DAY)"
        );
        $stmt->execute([$ownerId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fireAt = $this->toUtc($row['start_datetime'], $row['timezone'] ?: 'America/Montreal');
            if ($fireAt === null) {
                continue;
            }
            $out[] = [
                'entity_id'      => (int) $row['event_id'],
                'occurrence_key' => (string) $row['occurrence_date'],
                'fire_at'        => $fireAt,
                'occurrence_iso' => $fireAt->format('c'),
                'title'          => (string) $row['title'],
            ];
        }
        return $out;
    }

    /** Tâches (VTODO) avec une date limite. */
    private function scanTodos(int $ownerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, title, due, timezone
               FROM calendar_todos
              WHERE user_id = ?
                AND deleted_at IS NULL
                AND due IS NOT NULL
                AND status NOT IN ('COMPLETED', 'CANCELLED')
                AND due BETWEEN (UTC_TIMESTAMP() - INTERVAL 3 DAY) AND (UTC_TIMESTAMP() + INTERVAL 3 DAY)"
        );
        $stmt->execute([$ownerId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fireAt = $this->toUtc($row['due'], $row['timezone'] ?: 'America/Montreal');
            if ($fireAt === null) {
                continue;
            }
            $out[] = [
                'entity_id'      => (int) $row['id'],
                'occurrence_key' => '-',
                'fire_at'        => $fireAt,
                'occurrence_iso' => $fireAt->format('c'),
                'title'          => (string) $row['title'],
            ];
        }
        return $out;
    }

    /**
     * Relances de contact (modèle A1) : fiches actives dont la relance n'est pas encore
     * faite. Date sans heure → échéance à 00:00 dans le fuseau de l'usager.
     *
     * L'occurrence_key est préfixée `relance:` : `push_notification_log` est unique sur
     * (owner, kind, entity_id, occurrence_key) et les deux sources de contact_followup
     * partagent le même kind — sans préfixe, un contact et une opportunité de même id
     * échéant le même jour s'annuleraient l'un l'autre.
     */
    private function scanRelancesContact(int $ownerId, string $userTz): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, prenom, nom, date_relance
                   FROM contacts
                  WHERE user_id = ?
                    AND supprime_le IS NULL
                    AND date_relance IS NOT NULL
                    AND relance_faite_le IS NULL
                    AND date_relance BETWEEN (CURDATE() - INTERVAL 30 DAY)
                                         AND (CURDATE() + INTERVAL 30 DAY)"
            );
            $stmt->execute([$ownerId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Pilier Contacts ou colonnes de relance absents de cette installation.
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $fireAt = $this->toUtc($row['date_relance'] . ' 00:00:00', $userTz);
            if ($fireAt === null) {
                continue;
            }
            $out[] = [
                'entity_id'      => (int) $row['id'],
                'entity'         => 'contact',
                'occurrence_key' => 'relance:' . $row['date_relance'],
                'fire_at'        => $fireAt,
                'occurrence_iso' => $fireAt->format('c'),
                'title'          => trim($row['prenom'] . ' ' . $row['nom']),
            ];
        }
        return $out;
    }

    /**
     * Suivis CRM : opportunités encore ouvertes dont la date de clôture prévue approche
     * ou est dépassée. Date sans heure → l'échéance est fixée à 00:00 dans le fuseau de
     * l'usager, ce qui rend le déclenchement indépendant de l'heure du balayage.
     */
    private function scanOpportunites(int $ownerId, string $userTz): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, titre, date_cloture_prevue
                   FROM opportunite
                  WHERE user_id = ?
                    AND supprime_le IS NULL
                    AND etape NOT IN ('gagne', 'perdu')
                    AND date_cloture_prevue IS NOT NULL
                    AND date_cloture_prevue BETWEEN (CURDATE() - INTERVAL 30 DAY)
                                                AND (CURDATE() + INTERVAL 30 DAY)"
            );
            $stmt->execute([$ownerId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Pilier Contacts absent de cette installation → aucun suivi à notifier.
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $fireAt = $this->toUtc($row['date_cloture_prevue'] . ' 00:00:00', $userTz);
            if ($fireAt === null) {
                continue;
            }
            $out[] = [
                'entity_id'      => (int) $row['id'],
                'entity'         => 'opportunite',
                'occurrence_key' => (string) $row['date_cloture_prevue'],
                'fire_at'        => $fireAt,
                'occurrence_iso' => $fireAt->format('c'),
                'title'          => (string) $row['titre'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Corps générique — aucun détail d'entité (directive §6)
    // ------------------------------------------------------------------

    public static function genericBody(string $kind, int $leadMinutes): string
    {
        $delai = match (true) {
            $leadMinutes >= 1440 => 'dans ' . (int) round($leadMinutes / 1440) . ' jour(s)',
            $leadMinutes >= 60   => 'dans ' . (int) round($leadMinutes / 60) . ' heure(s)',
            default              => "dans {$leadMinutes} minutes",
        };

        return match ($kind) {
            'event', 'recurring' => "Vous avez un événement {$delai}.",
            'task_due'           => "Une tâche arrive à échéance {$delai}.",
            'contact_followup'   => "Un suivi de contact est à faire {$delai}.",
            default              => "Vous avez un rappel {$delai}.",
        };
    }
}
