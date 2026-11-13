<?php

namespace AuthGroups\Services;

use AuthGroups\Models\NotificationDefault;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Rappels unifiés — sélection des rappels dus d'un usager (directive cmem_web #487,
 * contrat #236 §6). Une seule sélection pour les deux canaux (EMAIL, DISPLAY).
 *
 * Rappels effectifs d'un élément = sa liste `notifications`, sinon le défaut de son kind
 * (notification_defaults). Chaque rappel vise fire_at = échéance − minutes_before.
 *
 * Règle de déclenchement : un rappel est dû quand fire_at ≤ maintenant et que son retard
 * reste dans la tolérance du kind (GRACE_MINUTES). Un rappel dont le moment est passé
 * depuis plus longtemps (élément créé ou déplacé tard) n'est pas envoyé : un rappel
 * « 1 jour avant » ajouté 10 minutes avant l'échéance ne part pas.
 *
 * La fenêtre n'est pas bornée par la période du cron : l'idempotence (reminder_log) garantit
 * l'envoi unique, ce qui rend le balayage insensible à un cron en retard ou relancé.
 *
 * Plage silencieuse du kind : vaut pour les deux canaux ; un rappel tombé dans la plage est
 * retenu (pas journalisé) et part à la fin de la plage s'il est encore dans la tolérance —
 * comportement repris du DueScanner.
 *
 * Fuseaux : datetimes d'événement / tâche dans le fuseau de l'élément ; échéances date seule
 * (tâche « toute la journée », tâche de projet, relance, clôture) à 00:00 dans le fuseau de
 * l'usager. Comparaisons en UTC, jamais avec NOW() côté SQL.
 */
class ReminderScanner
{
    /** Tolérance de retard par kind (minutes). */
    public const GRACE_MINUTES = [
        'event'            => 60,
        'recurring'        => 60,
        'task_due'         => 60,
        'contact_followup' => 7 * 24 * 60,
    ];

    /** Horizon de recherche des échéances : délai maximal d'un rappel (4 semaines) + 1 jour. */
    private const HORIZON_MINUTES = ReminderList::MAX_LEAD + 1440;

    private PDO $db;

    /** Trace du dernier scan() — mode --verbose du cron. */
    public array $debug = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array{kind:string, entity_type:string, entity_id:int, occurrence_key:string,
     *   due_at:DateTimeImmutable, fire_at:DateTimeImmutable, channel:string, minutes_before:int,
     *   title:string, real_title:string, body:string, data:array, encrypted:bool}>
     */
    public function scan(int $ownerId, string $appId, DateTimeImmutable $nowUtc): array
    {
        $userTz   = $this->userTimezone($ownerId);
        $defaults = NotificationDefault::findByOwner($ownerId, $appId);

        $this->debug = ['owner' => $ownerId, 'tz' => $userTz, 'kinds' => []];

        $items = array_merge(
            $this->scanEvents($ownerId, $nowUtc),
            $this->scanRecurring($ownerId, $nowUtc, $defaults),
            $this->scanTodos($ownerId, $nowUtc, $userTz),
            $this->scanContacts($ownerId, $userTz),
            $this->scanOpportunites($ownerId, $userTz)
        );

        $due = [];
        foreach ($items as $item) {
            $kind = $item['kind'];
            $def  = $defaults[$kind] ?? NotificationDefault::blank($kind);
            $list = $item['notifications'] ?? $def['notifications'];

            $this->debug['kinds'][$kind]['candidats'] = ($this->debug['kinds'][$kind]['candidats'] ?? 0) + 1;

            if (empty($list)) {
                continue;
            }
            if (self::inQuietHours($def['quiet_from'], $def['quiet_to'], $nowUtc, $userTz)) {
                $this->debug['kinds'][$kind]['silence'] = true;
                continue;
            }

            $showDetail = !empty($def['show_entity_detail']) && empty($item['encrypted']);
            $realTitle  = (string) ($item['title'] ?? '');
            $grace      = self::GRACE_MINUTES[$kind] ?? 60;

            foreach ($list as $r) {
                $lead   = (int) $r['minutes_before'];
                $fireAt = $item['due_at']->modify("-{$lead} minutes");
                if ($fireAt > $nowUtc || $fireAt <= $nowUtc->modify("-{$grace} minutes")) {
                    continue;
                }

                $data = [
                    'type'       => $kind,
                    'id'         => $item['entity_id'],
                    'entity'     => $item['entity_type'],
                    'occurrence' => $item['due_at']->format('c'),
                ];

                $due[] = [
                    'kind'           => $kind,
                    'entity_type'    => $item['entity_type'],
                    'entity_id'      => $item['entity_id'],
                    'occurrence_key' => $item['occurrence_key'],
                    'due_at'         => $item['due_at'],
                    'fire_at'        => $fireAt,
                    'channel'        => $r['type'],
                    'minutes_before' => $lead,
                    'title'          => ($showDetail && $realTitle !== '') ? $realTitle : self::genericTitle(),
                    'real_title'     => $realTitle,
                    'body'           => $this->buildDetailedBody($kind, $item, $userTz) ?? self::genericBody($kind, $lead),
                    'generic_body'   => self::genericBody($kind, $lead),
                    'data'           => $data,
                    'encrypted'      => !empty($item['encrypted']),
                ];
                $this->debug['kinds'][$kind]['dus'] = ($this->debug['kinds'][$kind]['dus'] ?? 0) + 1;
            }
        }

        return $due;
    }

    public static function genericTitle(): string
    {
        $title = trim((string) ($_ENV['PUSH_GENERIC_TITLE'] ?? ''));
        return $title !== '' ? $title : 'Rappel';
    }

    // ------------------------------------------------------------------
    // Plage silencieuse, fuseaux
    // ------------------------------------------------------------------

    /** Plage « ne pas déranger » évaluée dans le fuseau de l'usager (passage minuit géré ; from = to → ignorée). */
    public static function inQuietHours(?string $from, ?string $to, DateTimeImmutable $nowUtc, string $userTz): bool
    {
        if (empty($from) || empty($to)) {
            return false;
        }
        $local = (int) $nowUtc->setTimezone(new DateTimeZone($userTz))->format('Hi');
        $f     = (int) str_replace(':', '', substr($from, 0, 5));
        $t     = (int) str_replace(':', '', substr($to, 0, 5));

        if ($f === $t) {
            return false;
        }
        return $f < $t ? ($local >= $f && $local < $t) : ($local >= $f || $local < $t);
    }

    /**
     * Fuseau de l'usager : users.timezone, sinon celui de son premier calendrier, sinon
     * America/Montreal. Un identifiant inconnu du tzdata du serveur est ignoré.
     */
    public function userTimezone(int $ownerId): string
    {
        $stmt = $this->db->prepare('SELECT timezone FROM users WHERE id = ?');
        $stmt->execute([$ownerId]);
        $tz = (string) ($stmt->fetchColumn() ?: '');
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            return $tz;
        }

        $stmt = $this->db->prepare(
            'SELECT timezone FROM calendars
              WHERE user_id = ? AND deleted_at IS NULL AND timezone IS NOT NULL
              ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$ownerId]);
        $tz = (string) ($stmt->fetchColumn() ?: '');

        return $tz !== '' && in_array($tz, timezone_identifiers_list(), true) ? $tz : 'America/Montreal';
    }

    private static function toUtc(string $naive, string $tz): ?DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($naive, new DateTimeZone($tz ?: 'America/Montreal')))
                ->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Bornes SQL (naïves, larges d'un jour pour couvrir tous les fuseaux). */
    private static function sqlWindow(DateTimeImmutable $nowUtc, int $backMinutes): array
    {
        return [
            $nowUtc->modify('-' . ($backMinutes + 1440) . ' minutes')->format('Y-m-d H:i:s'),
            $nowUtc->modify('+' . (self::HORIZON_MINUTES + 1440) . ' minutes')->format('Y-m-d H:i:s'),
        ];
    }

    // ------------------------------------------------------------------
    // Sources d'échéances
    // ------------------------------------------------------------------

    /** Événements simples (sans RRULE ni RDATE) — kind event. */
    private function scanEvents(int $ownerId, DateTimeImmutable $nowUtc): array
    {
        [$from, $to] = self::sqlWindow($nowUtc, self::GRACE_MINUTES['event']);
        $stmt = $this->db->prepare(
            "SELECT id, title, start_datetime, end_datetime, all_day, timezone, notifications
               FROM calendar_events
              WHERE user_id = ? AND deleted_at IS NULL AND status <> 'cancelled'
                AND recurrence_rule IS NULL AND (rdate IS NULL OR rdate = '')
                AND start_datetime BETWEEN ? AND ?"
        );
        $stmt->execute([$ownerId, $from, $to]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tz    = $row['timezone'] ?: 'America/Montreal';
            $dueAt = self::toUtc($row['start_datetime'], $tz);
            if ($dueAt === null) {
                continue;
            }
            $out[] = [
                'kind'           => 'event',
                'entity_type'    => 'event',
                'entity_id'      => (int) $row['id'],
                'occurrence_key' => '-',
                'due_at'         => $dueAt,
                'notifications'  => ReminderList::normalize($row['notifications']),
                'title'          => (string) $row['title'],
                'start_datetime' => (string) $row['start_datetime'],
                'end_datetime'   => (string) $row['end_datetime'],
                'all_day'        => (bool) $row['all_day'],
                'timezone'       => $tz,
            ];
        }
        return $out;
    }

    /**
     * Occurrences des séries (kind recurring), développées à la volée avec les exceptions
     * (annulation, déplacement) — même mécanisme que les rappels courriel #438. Chaque série
     * n'est développée que sur [maintenant − tolérance ; maintenant + plus grand délai].
     */
    private function scanRecurring(int $ownerId, DateTimeImmutable $nowUtc, array $defaults): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM calendar_events
              WHERE user_id = ? AND deleted_at IS NULL AND status <> 'cancelled'
                AND (recurrence_rule IS NOT NULL OR (rdate IS NOT NULL AND rdate <> ''))
                AND start_datetime <= ?"
        );
        $stmt->execute([$ownerId, $nowUtc->modify('+' . (self::HORIZON_MINUTES + 1440) . ' minutes')->format('Y-m-d H:i:s')]);

        $defaultList = $defaults['recurring']['notifications'] ?? [];
        $out         = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $event) {
            $list = ReminderList::normalize($event['notifications']) ?? $defaultList;
            if (empty($list)) {
                continue;
            }
            $maxLead = max(array_map(fn($r) => (int) $r['minutes_before'], $list));
            $tz      = $event['timezone'] ?: 'America/Montreal';
            $zone    = new DateTimeZone($tz);

            $start = $nowUtc->modify('-' . (self::GRACE_MINUTES['recurring'] + 1440) . ' minutes')->setTimezone($zone)->format('Y-m-d H:i:s');
            $end   = $nowUtc->modify('+' . ($maxLead + 1440) . ' minutes')->setTimezone($zone)->format('Y-m-d H:i:s');

            try {
                $occurrences = \ICS\Models\EventOccurrence::expandEventInRange($event, $start, $end);
            } catch (\Throwable $e) {
                LogService::warning('ReminderScanner : expansion impossible', [
                    'event_id' => $event['id'],
                    'error'    => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($occurrences as $occ) {
                if (!empty($occ['is_cancelled'])) {
                    continue;
                }
                $dueAt = self::toUtc((string) $occ['start_datetime'], $tz);
                if ($dueAt === null) {
                    continue;
                }
                $out[] = [
                    'kind'           => 'recurring',
                    'entity_type'    => 'event',
                    'entity_id'      => (int) $event['id'],
                    'occurrence_key' => (string) $occ['occurrence_date'],
                    'due_at'         => $dueAt,
                    'notifications'  => $list,
                    'title'          => (string) ($occ['title'] ?? $event['title']),
                    'start_datetime' => (string) $occ['start_datetime'],
                    'end_datetime'   => (string) $occ['end_datetime'],
                    'all_day'        => (bool) $event['all_day'],
                    'timezone'       => $tz,
                ];
            }
        }
        return $out;
    }

    /**
     * Tâches (calendar_todos) et tâches de projet (project_id renseigné) — kind task_due.
     * Tâche chiffrée (enc_alg) : titre toujours générique.
     */
    private function scanTodos(int $ownerId, DateTimeImmutable $nowUtc, string $userTz): array
    {
        [$from, $to] = self::sqlWindow($nowUtc, self::GRACE_MINUTES['task_due']);
        $stmt = $this->db->prepare(
            "SELECT id, project_id, title, due, is_all_day, all_day, timezone, enc_alg, notifications
               FROM calendar_todos
              WHERE user_id = ? AND deleted_at IS NULL AND due IS NOT NULL
                AND status NOT IN ('COMPLETED', 'CANCELLED')
                AND due BETWEEN ? AND ?"
        );
        $stmt->execute([$ownerId, $from, $to]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $isProject = $row['project_id'] !== null;
            $allDay    = $isProject ? (bool) $row['all_day'] || substr((string) $row['due'], 11) === '00:00:00'
                                    : (bool) $row['is_all_day'];
            // Date seule (toute la journée, tâche de projet) : 00:00 dans le fuseau de l'usager.
            $dueAt = $allDay || $isProject
                ? self::toUtc(substr((string) $row['due'], 0, 10) . ($allDay ? ' 00:00:00' : substr((string) $row['due'], 10)), $userTz)
                : self::toUtc((string) $row['due'], $row['timezone'] ?: 'America/Montreal');
            if ($dueAt === null) {
                continue;
            }
            $encrypted = !empty($row['enc_alg']);
            $out[] = [
                'kind'           => 'task_due',
                'entity_type'    => $isProject ? 'project_task' : 'todo',
                'entity_id'      => (int) $row['id'],
                'occurrence_key' => '-',
                'due_at'         => $dueAt,
                'notifications'  => ReminderList::normalize($row['notifications']),
                'title'          => $encrypted ? '' : (string) $row['title'],
                'all_day'        => $allDay,
                'encrypted'      => $encrypted,
            ];
        }
        return $out;
    }

    /**
     * Relances de contact : date_relance à 00:00 dans le fuseau de l'usager, relance non faite.
     * occurrence_key préfixée `relance:` (contact et opportunité partagent le kind).
     */
    private function scanContacts(int $ownerId, string $userTz): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, prenom, nom, organisation, date_relance, enc_alg, notifications
                   FROM contacts
                  WHERE user_id = ? AND supprime_le IS NULL
                    AND date_relance IS NOT NULL AND relance_faite_le IS NULL
                    AND date_relance BETWEEN (CURDATE() - INTERVAL 30 DAY) AND (CURDATE() + INTERVAL 30 DAY)"
            );
            $stmt->execute([$ownerId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return []; // pilier Contacts absent de cette installation
        }

        $out = [];
        foreach ($rows as $row) {
            $dueAt = self::toUtc($row['date_relance'] . ' 00:00:00', $userTz);
            if ($dueAt === null) {
                continue;
            }
            $encrypted = !empty($row['enc_alg']);
            $name      = trim($row['prenom'] . ' ' . $row['nom']);
            $out[] = [
                'kind'           => 'contact_followup',
                'entity_type'    => 'contact',
                'entity_id'      => (int) $row['id'],
                'occurrence_key' => 'relance:' . $row['date_relance'],
                'due_at'         => $dueAt,
                'notifications'  => ReminderList::normalize($row['notifications']),
                'title'          => $encrypted ? '' : ($name !== '' ? $name : (string) $row['organisation']),
                'encrypted'      => $encrypted,
            ];
        }
        return $out;
    }

    /** Opportunités ouvertes : date_cloture_prevue à 00:00 dans le fuseau de l'usager. */
    private function scanOpportunites(int $ownerId, string $userTz): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, titre, date_cloture_prevue, notifications
                   FROM opportunite
                  WHERE user_id = ? AND supprime_le IS NULL
                    AND etape NOT IN ('gagne', 'perdu')
                    AND date_cloture_prevue IS NOT NULL
                    AND date_cloture_prevue BETWEEN (CURDATE() - INTERVAL 30 DAY) AND (CURDATE() + INTERVAL 30 DAY)"
            );
            $stmt->execute([$ownerId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $dueAt = self::toUtc($row['date_cloture_prevue'] . ' 00:00:00', $userTz);
            if ($dueAt === null) {
                continue;
            }
            $out[] = [
                'kind'           => 'contact_followup',
                'entity_type'    => 'opportunite',
                'entity_id'      => (int) $row['id'],
                'occurrence_key' => (string) $row['date_cloture_prevue'],
                'due_at'         => $dueAt,
                'notifications'  => ReminderList::normalize($row['notifications']),
                'title'          => (string) $row['titre'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Corps — date/heure réelle de l'élément, jamais son titre (directive cmem_web #210)
    // ------------------------------------------------------------------

    private const MOIS_ABBR = [
        1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin',
        7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
    ];

    /** @param array{due_at:DateTimeImmutable,all_day?:bool,start_datetime?:string,end_datetime?:string,timezone?:string} $item */
    public function buildDetailedBody(string $kind, array $item, string $userTz): ?string
    {
        return match ($kind) {
            'event', 'recurring' => $this->buildEventBody($item, $userTz),
            'task_due'           => $this->buildTaskDueBody($item, $userTz),
            default              => null,
        };
    }

    private function buildEventBody(array $item, string $userTz): ?string
    {
        if (!empty($item['all_day'])) {
            $startDate = new DateTimeImmutable(substr((string) $item['start_datetime'], 0, 10));
            $endDate   = new DateTimeImmutable(substr((string) $item['end_datetime'], 0, 10));

            return $startDate->format('Y-m-d') === $endDate->format('Y-m-d')
                ? self::formatDateFr($startDate)
                : self::formatDateFr($startDate) . ' - ' . self::formatDateFr($endDate);
        }

        $endUtc = self::toUtc((string) $item['end_datetime'], $item['timezone'] ?? 'America/Montreal');
        if ($endUtc === null) {
            return null;
        }
        $zone = new DateTimeZone($userTz);
        return self::formatTimeFr($item['due_at']->setTimezone($zone)) . ' - ' . self::formatTimeFr($endUtc->setTimezone($zone));
    }

    private function buildTaskDueBody(array $item, string $userTz): string
    {
        $due = $item['due_at']->setTimezone(new DateTimeZone($userTz));
        return !empty($item['all_day'])
            ? self::formatDateFr($due)
            : self::formatDateFr($due) . ' ' . self::formatTimeFr($due);
    }

    private static function formatDateFr(\DateTimeInterface $d): string
    {
        $texte = $d->format('j') . ' ' . self::MOIS_ABBR[(int) $d->format('n')];
        if ((int) $d->format('Y') !== (int) date('Y')) {
            $texte .= ' ' . $d->format('Y');
        }
        return $texte;
    }

    private static function formatTimeFr(\DateTimeInterface $d): string
    {
        return $d->format('G') . 'h' . $d->format('i');
    }

    /** Corps générique — aucun détail d'entité (directive §6). */
    public static function genericBody(string $kind, int $leadMinutes): string
    {
        $delai = match (true) {
            $leadMinutes === 0   => 'maintenant',
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
