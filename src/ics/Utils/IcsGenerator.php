<?php

namespace ICS\Utils;

use Sabre\VObject\Component\VCalendar;

/**
 * Wrapper sabre/vobject pour la génération ICS.
 * Remplace les générateurs manuels (Calendar, CalDAVServer).
 *
 * Avantages vs génération manuelle :
 *  - Line folding RFC 5545 §3.1 automatique (75 octets/ligne)  [item 1.2]
 *  - DTSTART/DTEND avec TZID quand timezone connu              [item 1.4]
 *  - Échappement des valeurs texte géré par sabre
 */
class IcsGenerator
{
    /**
     * Génère le contenu ICS complet d'un calendrier avec ses événements.
     *
     * @param array       $calendar  Ligne DB du calendrier (title, description, timezone)
     * @param array       $events    Tableau de lignes DB d'événements
     * @param string|null $method    iTIP METHOD optionnel (REQUEST, CANCEL…) — Phase 3.3
     */
    public static function generateCalendar(array $calendar, array $events, ?string $method = null, array $todos = [], array $journals = []): string
    {
        $timezone = $calendar['timezone'] ?? 'America/Montreal';

        // En-tête VCALENDAR (construit manuellement pour conserver X-WR-* et VTIMEZONE)
        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//CMEM Calendar//FR\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";

        // Phase 3.3 — METHOD iTIP
        if ($method !== null) {
            $ics .= "METHOD:" . strtoupper($method) . "\r\n";
        }

        $ics .= "X-WR-CALNAME:" . TimezoneHelper::escapeIcsText($calendar['title']) . "\r\n";

        if (!empty($calendar['description'])) {
            $ics .= "X-WR-CALDESC:" . TimezoneHelper::escapeIcsText($calendar['description']) . "\r\n";
        }

        $ics .= "X-WR-TIMEZONE:" . $timezone . "\r\n";
        // VTIMEZONE complet (transitions DST) via helper existant
        $ics .= TimezoneHelper::generateVTimezone($timezone);

        foreach ($events as $event) {
            // Phase 4.1 — pré-charger les occurrences annulées pour l'export EXDATE
            if (!empty($event['recurrence_rule']) && !empty($event['id'])) {
                $event['_cancelled_dates'] = \ICS\Models\EventOccurrence::getCancelledByEventId((int)$event['id']);
            }
            // sabre/vobject génère le VEVENT avec TZID + folding
            $ics .= self::buildVEvent($event, $timezone);
        }

        // Phase 5 — VTODO
        foreach ($todos as $todo) {
            $ics .= self::buildVTodo($todo, $timezone);
        }

        // Phase 5 — VJOURNAL
        foreach ($journals as $journal) {
            $ics .= self::buildVJournal($journal, $timezone);
        }

        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }

    /**
     * Génère un VCALENDAR à un seul événement (réponse GET CalDAV).
     *
     * @param array       $event             Ligne DB de l'événement
     * @param string      $calendarTimezone  Timezone du calendrier parent
     * @param string|null $method            iTIP METHOD optionnel — Phase 3.3
     */
    public static function generateSingleEvent(array $event, string $calendarTimezone, ?string $method = null): string
    {
        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//CMEM2//CalDAV Server//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";

        // Phase 3.3 — METHOD iTIP
        if ($method !== null) {
            $ics .= "METHOD:" . strtoupper($method) . "\r\n";
        }

        $ics .= TimezoneHelper::generateVTimezone($calendarTimezone);

        // Phase 4.1 — pré-charger les occurrences annulées pour l'export EXDATE
        if (!empty($event['recurrence_rule']) && !empty($event['id'])) {
            $event['_cancelled_dates'] = \ICS\Models\EventOccurrence::getCancelledByEventId((int)$event['id']);
        }

        $ics .= self::buildVEvent($event, $calendarTimezone);
        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }

    /**
     * Génère un VCALENDAR d'invitation avec METHOD:REQUEST (Phase 3.3 / 3.4).
     * Utilisé pour l'envoi d'emails d'invitation aux attendees.
     *
     * @param array  $event            Ligne DB enrichie (avec organizer_email / organizer_name)
     * @param string $calendarTimezone Timezone du calendrier parent
     */
    public static function generateInvitationIcs(array $event, string $calendarTimezone): string
    {
        return self::generateSingleEvent($event, $calendarTimezone, 'REQUEST');
    }

    /**
     * Génère un VCALENDAR d'annulation avec METHOD:CANCEL (Phase 3.3).
     *
     * @param array  $event            Ligne DB de l'événement à annuler
     * @param string $calendarTimezone Timezone du calendrier parent
     */
    public static function generateCancelIcs(array $event, string $calendarTimezone): string
    {
        $cancelEvent = array_merge($event, ['status' => 'CANCELLED']);
        return self::generateSingleEvent($cancelEvent, $calendarTimezone, 'CANCEL');
    }

    /**
     * Construit un bloc VEVENT via sabre/vobject.
     *
     * sabre/vobject sérialise automatiquement :
     *  - DTSTART;TZID=America/Montreal:20260401T140000  (item 1.4)
     *  - DTSTART:20260401T190000Z  pour les datetimes UTC
     *  - Folding des lignes > 75 octets                (item 1.2)
     *
     * @param array  $event            Ligne DB (uid, title, start_datetime, …)
     * @param string $calendarTimezone Timezone par défaut si l'événement n'en a pas
     */
    private static function buildVEvent(array $event, string $calendarTimezone): string
    {
        // VCalendar temporaire — sert uniquement à instancier correctement le VEVENT
        $tmpCal = new VCalendar();
        /** @var \Sabre\VObject\Component\VEvent $vevent */
        $vevent  = $tmpCal->add('VEVENT');

        // UID RFC-conforme (item 1.3) — utilise le champ DB si présent
        $uid = !empty($event['uid'])
            ? $event['uid']
            : ('event-' . ($event['id'] ?? '0') . '@cmem-calendar.local');
        // set (pas add) — sabre pose déjà un UID/DTSTAMP par défaut à la création du VEVENT
        $vevent->UID = $uid;

        // DTSTAMP — toujours UTC (RFC 5545 §3.8.7.2)
        $vevent->DTSTAMP = new \DateTime('now', new \DateTimeZone('UTC'));

        // Timezone effective de l'événement
        $eventTz = !empty($event['timezone']) ? $event['timezone'] : $calendarTimezone;

        if (!empty($event['all_day'])) {
            // Événements toute la journée — VALUE=DATE, pas de TZID
            $dtStart = new \DateTime(substr($event['start_datetime'], 0, 10));
            $vevent->add('DTSTART', $dtStart);
            $vevent->DTSTART['VALUE'] = 'DATE';

            // Phase 4.5 — DURATION vs DTEND (all-day)
            if (!empty($event['duration'])) {
                $vevent->add('DURATION', $event['duration']);
            } else {
                // DTEND exclusif : lendemain du dernier jour (RFC 5545 §3.6.1)
                $dtEnd = new \DateTime(substr($event['end_datetime'], 0, 10));
                $dtEnd->modify('+1 day');
                $vevent->add('DTEND', $dtEnd);
                $vevent->DTEND['VALUE'] = 'DATE';
            }
        } else {
            // DTSTART / DTEND avec TZID — sabre ajoute automatiquement TZID= (item 1.4)
            $tz     = new \DateTimeZone($eventTz);
            $dtStart = new \DateTime($event['start_datetime'], $tz);
            $vevent->add('DTSTART', $dtStart);

            // Phase 4.5 — DURATION vs DTEND (RFC 5545 §3.8.2.5)
            if (!empty($event['duration'])) {
                $vevent->add('DURATION', $event['duration']);
            } else {
                $dtEnd = new \DateTime($event['end_datetime'], $tz);
                $vevent->add('DTEND', $dtEnd);
            }
        }

        $vevent->add('SUMMARY', $event['title']);

        if (!empty($event['description'])) {
            $vevent->add('DESCRIPTION', $event['description']);
        }

        if (!empty($event['location'])) {
            $vevent->add('LOCATION', $event['location']);
        }

        // Phase 3.1 — ATTENDEE complet (RFC 5545 §3.8.4.1)
        if (!empty($event['attendees'])) {
            $attendees = \is_string($event['attendees'])
                ? json_decode($event['attendees'], true)
                : $event['attendees'];

            if (\is_array($attendees)) {
                $validRoles     = ['CHAIR', 'REQ-PARTICIPANT', 'OPT-PARTICIPANT', 'NON-PARTICIPANT'];
                $validPartstats = ['NEEDS-ACTION', 'ACCEPTED', 'DECLINED', 'TENTATIVE', 'DELEGATED'];
                $validCutypes   = ['INDIVIDUAL', 'GROUP', 'RESOURCE', 'ROOM', 'UNKNOWN'];

                foreach ($attendees as $attendee) {
                    if (empty($attendee['email'])) {
                        continue;
                    }
                    $params = [];

                    // RSVP, ROLE, PARTSTAT en premier pour tenir dans les 75 chars (RFC 5545 fold)
                    $params['RSVP'] = (!empty($attendee['rsvp'])) ? 'TRUE' : 'FALSE';

                    $role = strtoupper($attendee['role'] ?? 'REQ-PARTICIPANT');
                    $params['ROLE'] = \in_array($role, $validRoles, true) ? $role : 'REQ-PARTICIPANT';

                    $partstat = strtoupper($attendee['partstat'] ?? 'NEEDS-ACTION');
                    $params['PARTSTAT'] = \in_array($partstat, $validPartstats, true) ? $partstat : 'NEEDS-ACTION';

                    $cutype = strtoupper($attendee['cutype'] ?? 'INDIVIDUAL');
                    $params['CUTYPE'] = \in_array($cutype, $validCutypes, true) ? $cutype : 'INDIVIDUAL';

                    if (!empty($attendee['name'])) {
                        $params['CN'] = $attendee['name'];
                    }

                    $vevent->add($tmpCal->createProperty('ATTENDEE', 'mailto:' . $attendee['email'], $params));
                }
            }
        }

        // Phase 3.2 — ORGANIZER (RFC 5545 §3.8.4.3)
        if (!empty($event['organizer_email'])) {
            $orgParams = [];
            if (!empty($event['organizer_name'])) {
                $orgParams['CN'] = $event['organizer_name'];
            }
            $vevent->add($tmpCal->createProperty('ORGANIZER', 'mailto:' . $event['organizer_email'], $orgParams));
        }

        if (!empty($event['recurrence_rule'])) {
            $vevent->add('RRULE', $event['recurrence_rule']);
        }

        // Phase 4.1 — EXDATE : exceptions de récurrence (RFC 5545 §3.8.4.1)
        // Source : event_occurrences.is_cancelled, pré-chargé dans _cancelled_dates
        $cancelledDates = $event['_cancelled_dates'] ?? [];
        if (!empty($cancelledDates) && !empty($event['recurrence_rule'])) {
            $tz = new \DateTimeZone($eventTz);
            $formatted = [];
            foreach ($cancelledDates as $cd) {
                $dt = new \DateTime($cd['start_datetime'], $tz);
                $formatted[] = $dt->format('Ymd\THis');
            }
            if (!empty($formatted)) {
                if (!empty($event['all_day'])) {
                    $vevent->add('EXDATE', implode(',', $formatted));
                    $vevent->EXDATE['VALUE'] = 'DATE';
                } else {
                    $vevent->add('EXDATE', implode(',', $formatted));
                    $vevent->EXDATE['TZID'] = $eventTz;
                }
            }
        }

        // Phase 4.2 — RDATE : dates additionnelles (RFC 5545 §3.8.5.4)
        if (!empty($event['rdate'])) {
            $rdateStr = is_array($event['rdate']) ? implode(',', $event['rdate']) : $event['rdate'];
            $rdateParts = array_filter(array_map('trim', explode(',', $rdateStr)));
            if (!empty($rdateParts)) {
                $tz = new \DateTimeZone($eventTz);
                $formatted = [];
                foreach ($rdateParts as $rdatePart) {
                    $dt = new \DateTime($rdatePart, $tz);
                    $formatted[] = $dt->format('Ymd\THis');
                }
                if (!empty($formatted)) {
                    $vevent->add('RDATE', implode(',', $formatted));
                    $vevent->RDATE['TZID'] = $eventTz;
                }
            }
        }

        // Phase 4.3 — RELATED-TO : lien vers événement parent (RFC 5545 §3.8.4.5)
        if (!empty($event['related_to'])) {
            $relProp = $tmpCal->createProperty('RELATED-TO', $event['related_to']);
            $relProp['RELTYPE'] = 'PARENT';
            $vevent->add($relProp);
        }

        // Phase 2.1 — CATEGORIES
        if (!empty($event['categories'])) {
            $cats = is_string($event['categories'])
                ? json_decode($event['categories'], true)
                : $event['categories'];
            if (is_array($cats) && !empty($cats)) {
                $vevent->add('CATEGORIES', implode(',', $cats));
            }
        }

        // Phase 2.2 — PRIORITY (0 = non défini, on n'exporte pas 0 car c'est la valeur par défaut)
        $priority = isset($event['priority']) ? (int)$event['priority'] : 0;
        if ($priority !== 0) {
            $vevent->add('PRIORITY', (string)$priority);
        }

        // Phase 2.3 — CLASS
        if (!empty($event['class'])) {
            $vevent->add('CLASS', strtoupper($event['class']));
        }

        // Phase 2.4 — TRANSP
        if (!empty($event['transp'])) {
            $vevent->add('TRANSP', strtoupper($event['transp']));
        }

        // Phase 2.5 — URL (meeting_link déjà en DB)
        if (!empty($event['meeting_link'])) {
            $vevent->add('URL', $event['meeting_link']);
        }

        // Phase 2.5 — GEO
        if (!empty($event['geo_lat']) && !empty($event['geo_lng'])) {
            // RFC 5545 §3.8.1.6 : GEO:48.8534;2.3488
            $vevent->add('GEO', round((float)$event['geo_lat'], 6) . ';' . round((float)$event['geo_lng'], 6));
        }

        // Phase 2.6 — ATTACH
        if (!empty($event['attachments'])) {
            $attachments = is_string($event['attachments'])
                ? json_decode($event['attachments'], true)
                : $event['attachments'];
            if (is_array($attachments)) {
                foreach ($attachments as $att) {
                    if (!empty($att['url'])) {
                        $params = [];
                        if (!empty($att['mime_type'])) {
                            $params['FMTTYPE'] = $att['mime_type'];
                        }
                        $vevent->add($tmpCal->createProperty('ATTACH', $att['url'], $params));
                    } elseif (!empty($att['data_base64'])) {
                        $params = ['ENCODING' => 'BASE64'];
                        if (!empty($att['mime_type'])) {
                            $params['FMTTYPE'] = $att['mime_type'];
                        }
                        $vevent->add($tmpCal->createProperty('ATTACH', $att['data_base64'], $params));
                    }
                }
            }
        }

        $vevent->add('STATUS', strtoupper($event['status'] ?? 'CONFIRMED'));
        $vevent->add('SEQUENCE', (string)($event['sequence'] ?? 0));

        // CREATED / LAST-MODIFIED — doivent être UTC (RFC 5545 §3.8.7.1 / §3.8.7.3)
        if (!empty($event['created_at'])) {
            $tz = new \DateTimeZone($eventTz);
            $dt = new \DateTime($event['created_at'], $tz);
            $dt->setTimezone(new \DateTimeZone('UTC'));
            $vevent->add('CREATED', $dt);
        }

        $lastModifiedSrc = $event['updated_at'] ?? $event['last_modified'] ?? null;
        if (!empty($lastModifiedSrc)) {
            $tz = new \DateTimeZone($eventTz);
            $dt = new \DateTime($lastModifiedSrc, $tz);
            $dt->setTimezone(new \DateTimeZone('UTC'));
            $vevent->add('LAST-MODIFIED', $dt);
        }

        // Phase 4.4 — VALARM : blocs d'alarme dérivés de notifications JSON (RFC 5545 §3.6.6)
        if (!empty($event['notifications'])) {
            $notifications = is_string($event['notifications'])
                ? json_decode($event['notifications'], true)
                : $event['notifications'];

            if (is_array($notifications)) {
                foreach ($notifications as $notif) {
                    // Accepte 'minutes' ou 'minutes_before' (normalization input)
                    $minutes = isset($notif['minutes']) ? (int)$notif['minutes']
                        : (isset($notif['minutes_before']) ? (int)$notif['minutes_before'] : null);
                    if ($minutes === null) {
                        continue;
                    }
                    $trigger = ($minutes > 0) ? '-PT' . $minutes . 'M' : 'PT0S';
                    $typeRaw = strtoupper($notif['type'] ?? 'DISPLAY');
                    $action  = ($typeRaw === 'EMAIL') ? 'EMAIL' : 'DISPLAY';

                    /** @var \Sabre\VObject\Component $valarm */
                    $valarm = $tmpCal->createComponent('VALARM');
                    $valarm->add('ACTION', $action);
                    $valarm->add('TRIGGER', $trigger);
                    $valarm->add('DESCRIPTION', 'Rappel');

                    if ($action === 'EMAIL') {
                        $valarm->add('SUMMARY', 'Rappel : ' . ($event['title'] ?? 'Événement'));
                    }

                    $vevent->add($valarm);
                }
            }
        }

        // serialize() retourne BEGIN:VEVENT…END:VEVENT avec folding RFC 5545 §3.1
        return $vevent->serialize();
    }

    // ====================================================================
    // Phase 5.1 — VTODO
    // ====================================================================

    /**
     * Génère un VCALENDAR contenant des composants VTODO.
     *
     * @param array $calendar Ligne DB du calendrier
     * @param array $todos    Tableau de lignes DB de tâches (calendar_todos)
     */
    public static function generateTodosCalendar(array $calendar, array $todos): string
    {
        $timezone = $calendar['timezone'] ?? 'America/Montreal';

        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//CMEM Calendar//FR\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "X-WR-CALNAME:" . TimezoneHelper::escapeIcsText($calendar['title']) . "\r\n";
        $ics .= "X-WR-TIMEZONE:" . $timezone . "\r\n";
        $ics .= TimezoneHelper::generateVTimezone($timezone);

        foreach ($todos as $todo) {
            $ics .= self::buildVTodo($todo, $timezone);
        }

        $ics .= "END:VCALENDAR\r\n";
        return $ics;
    }

    /**
     * Construit un bloc VTODO (RFC 5545 §3.6.2) via sabre/vobject.
     *
     * @param array  $todo     Ligne DB de la tâche
     * @param string $timezone Timezone par défaut
     */
    private static function buildVTodo(array $todo, string $timezone): string
    {
        $tmpCal = new VCalendar();
        /** @var \Sabre\VObject\Component $vtodo */
        $vtodo  = $tmpCal->add('VTODO');

        $uid = !empty($todo['uid'])
            ? $todo['uid']
            : ('todo-' . ($todo['id'] ?? '0') . '@cmem-calendar.local');
        // set (pas add) — sabre pose déjà un UID/DTSTAMP par défaut à la création du VTODO
        $vtodo->UID = $uid;

        $vtodo->DTSTAMP = new \DateTime('now', new \DateTimeZone('UTC'));

        $tz = new \DateTimeZone(!empty($todo['timezone']) ? $todo['timezone'] : $timezone);
        $isAllDay = !empty($todo['is_all_day']);

        if (!empty($todo['dtstart'])) {
            if ($isAllDay) {
                $dt = new \DateTime(substr($todo['dtstart'], 0, 10));
                $vtodo->add('DTSTART', $dt);
                $vtodo->DTSTART['VALUE'] = 'DATE';
            } else {
                $vtodo->add('DTSTART', new \DateTime($todo['dtstart'], $tz));
            }
        }

        if (!empty($todo['due'])) {
            if ($isAllDay) {
                $dt = new \DateTime(substr($todo['due'], 0, 10));
                $vtodo->add('DUE', $dt);
                $vtodo->DUE['VALUE'] = 'DATE';
            } else {
                $vtodo->add('DUE', new \DateTime($todo['due'], $tz));
            }
        }

        if (!empty($todo['completed'])) {
            $dtCompleted = new \DateTime($todo['completed'], $tz);
            $dtCompleted->setTimezone(new \DateTimeZone('UTC'));
            $vtodo->add('COMPLETED', $dtCompleted);
        }

        $vtodo->add('SUMMARY', $todo['title'] ?? $todo['summary'] ?? '');

        if (!empty($todo['description'])) {
            $vtodo->add('DESCRIPTION', $todo['description']);
        }

        if (!empty($todo['location'])) {
            $vtodo->add('LOCATION', $todo['location']);
        }

        $vtodo->add('STATUS', strtoupper($todo['status'] ?? 'NEEDS-ACTION'));

        $priority = isset($todo['priority']) ? (int)$todo['priority'] : 0;
        if ($priority !== 0) {
            $vtodo->add('PRIORITY', (string)$priority);
        }

        $pct = isset($todo['percent_complete']) ? (int)$todo['percent_complete'] : 0;
        $vtodo->add('PERCENT-COMPLETE', (string)$pct);

        if (!empty($todo['categories'])) {
            $cats = is_string($todo['categories'])
                ? json_decode($todo['categories'], true)
                : $todo['categories'];
            if (is_array($cats) && !empty($cats)) {
                $vtodo->add('CATEGORIES', implode(',', $cats));
            }
        }

        if (!empty($todo['url'])) {
            $vtodo->add('URL', $todo['url']);
        }

        if (!empty($todo['related_to'])) {
            $relProp = $tmpCal->createProperty('RELATED-TO', $todo['related_to']);
            $relProp['RELTYPE'] = 'PARENT';
            $vtodo->add($relProp);
        }

        if (!empty($todo['organizer_email'])) {
            $orgParams = [];
            if (!empty($todo['organizer_name'])) {
                $orgParams['CN'] = $todo['organizer_name'];
            }
            $vtodo->add($tmpCal->createProperty('ORGANIZER', 'mailto:' . $todo['organizer_email'], $orgParams));
        }

        if (!empty($todo['attendees'])) {
            $attendees = is_string($todo['attendees'])
                ? json_decode($todo['attendees'], true)
                : $todo['attendees'];
            if (is_array($attendees)) {
                foreach ($attendees as $attendee) {
                    if (empty($attendee['email'])) {
                        continue;
                    }
                    $params = [];
                    if (!empty($attendee['name'])) {
                        $params['CN'] = $attendee['name'];
                    }
                    $params['PARTSTAT'] = strtoupper($attendee['partstat'] ?? 'NEEDS-ACTION');
                    $vtodo->add($tmpCal->createProperty('ATTENDEE', 'mailto:' . $attendee['email'], $params));
                }
            }
        }

        if (!empty($todo['recurrence_rule'])) {
            $vtodo->add('RRULE', $todo['recurrence_rule']);
        }

        $vtodo->add('SEQUENCE', (string)($todo['sequence'] ?? 0));

        if (!empty($todo['created_at'])) {
            $dt = new \DateTime($todo['created_at'], $tz);
            $dt->setTimezone(new \DateTimeZone('UTC'));
            $vtodo->add('CREATED', $dt);
        }
        if (!empty($todo['updated_at'])) {
            $dt = new \DateTime($todo['updated_at'], $tz);
            $dt->setTimezone(new \DateTimeZone('UTC'));
            $vtodo->add('LAST-MODIFIED', $dt);
        }

        return $vtodo->serialize();
    }

    // ====================================================================
    // Phase 5.2 — VJOURNAL
    // ====================================================================

    /**
     * Génère un VCALENDAR contenant des composants VJOURNAL.
     *
     * @param array $calendar  Ligne DB du calendrier
     * @param array $journals  Tableau de lignes DB de journaux (calendar_journals)
     */
    public static function generateJournalsCalendar(array $calendar, array $journals): string
    {
        $timezone = $calendar['timezone'] ?? 'America/Montreal';

        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//CMEM Calendar//FR\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "X-WR-CALNAME:" . TimezoneHelper::escapeIcsText($calendar['title']) . "\r\n";
        $ics .= "X-WR-TIMEZONE:" . $timezone . "\r\n";
        $ics .= TimezoneHelper::generateVTimezone($timezone);

        foreach ($journals as $journal) {
            $ics .= self::buildVJournal($journal, $timezone);
        }

        $ics .= "END:VCALENDAR\r\n";
        return $ics;
    }

    /**
     * Construit un bloc VJOURNAL (RFC 5545 §3.6.3) via sabre/vobject.
     *
     * @param array  $journal  Ligne DB du journal
     * @param string $timezone Timezone par défaut
     */
    private static function buildVJournal(array $journal, string $timezone): string
    {
        $tmpCal  = new VCalendar();
        /** @var \Sabre\VObject\Component $vjournal */
        $vjournal = $tmpCal->add('VJOURNAL');

        $uid = !empty($journal['uid'])
            ? $journal['uid']
            : ('journal-' . ($journal['id'] ?? '0') . '@cmem-calendar.local');
        // set (pas add) — sabre pose déjà un UID/DTSTAMP par défaut à la création du VJOURNAL
        $vjournal->UID = $uid;

        $vjournal->DTSTAMP = new \DateTime('now', new \DateTimeZone('UTC'));

        $tz = new \DateTimeZone(!empty($journal['timezone']) ? $journal['timezone'] : $timezone);

        if (!empty($journal['dtstart'])) {
            $vjournal->add('DTSTART', new \DateTime($journal['dtstart'], $tz));
        }

        $vjournal->add('SUMMARY', $journal['summary'] ?? '');

        if (!empty($journal['description'])) {
            $vjournal->add('DESCRIPTION', $journal['description']);
        }

        $statusMap = ['DRAFT' => 'DRAFT', 'FINAL' => 'FINAL', 'CANCELLED' => 'CANCELLED'];
        $status = strtoupper($journal['status'] ?? 'DRAFT');
        $vjournal->add('STATUS', $statusMap[$status] ?? 'DRAFT');

        if (!empty($journal['categories'])) {
            $cats = is_string($journal['categories'])
                ? json_decode($journal['categories'], true)
                : $journal['categories'];
            if (is_array($cats) && !empty($cats)) {
                $vjournal->add('CATEGORIES', implode(',', $cats));
            }
        }

        if (!empty($journal['url'])) {
            $vjournal->add('URL', $journal['url']);
        }

        if (!empty($journal['related_to'])) {
            $relProp = $tmpCal->createProperty('RELATED-TO', $journal['related_to']);
            $relProp['RELTYPE'] = 'PARENT';
            $vjournal->add($relProp);
        }

        if (!empty($journal['organizer_email'])) {
            $orgParams = [];
            if (!empty($journal['organizer_name'])) {
                $orgParams['CN'] = $journal['organizer_name'];
            }
            $vjournal->add($tmpCal->createProperty('ORGANIZER', 'mailto:' . $journal['organizer_email'], $orgParams));
        }

        $vjournal->add('SEQUENCE', (string)($journal['sequence'] ?? 0));

        if (!empty($journal['created_at'])) {
            $dt = new \DateTime($journal['created_at'], $tz);
            $dt->setTimezone(new \DateTimeZone('UTC'));
            $vjournal->add('CREATED', $dt);
        }
        if (!empty($journal['updated_at'])) {
            $dt = new \DateTime($journal['updated_at'], $tz);
            $dt->setTimezone(new \DateTimeZone('UTC'));
            $vjournal->add('LAST-MODIFIED', $dt);
        }

        return $vjournal->serialize();
    }

    // ====================================================================
    // Phase 5.3 — VFREEBUSY
    // ====================================================================

    /**
     * Génère un VCALENDAR contenant un composant VFREEBUSY (RFC 5545 §3.6.4).
     * Agrège les événements TRANSP=OPAQUE pour exposer les plages occupées.
     *
     * @param array  $calendar     Ligne DB du calendrier
     * @param array  $opaqueEvents Événements filtrés : transp = 'OPAQUE' (ou NULL)
     * @param string $dtstart      Début de la période demandée (format ISO ou datetime string)
     * @param string $dtend        Fin de la période demandée
     */
    public static function generateFreeBusy(
        array $calendar,
        array $opaqueEvents,
        string $dtstart,
        string $dtend
    ): string {
        $timezone = $calendar['timezone'] ?? 'America/Montreal';
        $tz       = new \DateTimeZone($timezone);
        $utc      = new \DateTimeZone('UTC');

        $tmpCal   = new VCalendar();
        /** @var \Sabre\VObject\Component $vfb */
        $vfb      = $tmpCal->add('VFREEBUSY');

        $dtstampUtc = new \DateTime('now', $utc);
        $vfb->add('DTSTAMP', $dtstampUtc);

        $dtStartUtc = (new \DateTime($dtstart, $tz))->setTimezone($utc);
        $dtEndUtc   = (new \DateTime($dtend,   $tz))->setTimezone($utc);
        $vfb->add('DTSTART', $dtStartUtc);
        $vfb->add('DTEND',   $dtEndUtc);

        // ORGANIZER = propriétaire du calendrier (si disponible)
        if (!empty($calendar['organizer_email'])) {
            $orgParams = [];
            if (!empty($calendar['organizer_name'])) {
                $orgParams['CN'] = $calendar['organizer_name'];
            }
            $vfb->add($tmpCal->createProperty('ORGANIZER', 'mailto:' . $calendar['organizer_email'], $orgParams));
        }

        // Construire les plages FREEBUSY depuis les événements opaques
        foreach ($opaqueEvents as $event) {
            $eventTz = !empty($event['timezone']) ? new \DateTimeZone($event['timezone']) : $tz;

            $evStart = (new \DateTime($event['start_datetime'], $eventTz))->setTimezone($utc);
            $evEnd   = (new \DateTime($event['end_datetime'],   $eventTz))->setTimezone($utc);

            // FREEBUSY:20260401T140000Z/20260401T150000Z
            $fbValue = $evStart->format('Ymd\THis\Z') . '/' . $evEnd->format('Ymd\THis\Z');
            $fbProp  = $tmpCal->createProperty('FREEBUSY', $fbValue);
            $fbProp['FBTYPE'] = 'BUSY';
            $vfb->add($fbProp);
        }

        // Construire le VCALENDAR complet
        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//CMEM Calendar//FR\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:REPLY\r\n";
        $ics .= $vfb->serialize();
        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }
}
