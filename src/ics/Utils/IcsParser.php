<?php

namespace ICS\Utils;

use Sabre\VObject\Reader;

/**
 * Wrapper sabre/vobject pour le parsing ICS.
 * Remplace les 3 parseurs manuels (Calendar, CalendarEvent, CalDAVServer).
 * Gère automatiquement : line-unfolding RFC 5545 §3.1, TZID, multi-valeur.
 */
class IcsParser
{
    /**
     * Parse les propriétés d'un VCALENDAR (X-WR-CALNAME, X-WR-CALDESC, X-WR-TIMEZONE, METHOD).
     */
    public static function parseCalendarProperties(string $icsContent): array
    {
        $vcalendar = Reader::read($icsContent, Reader::OPTION_FORGIVING);
        $properties = [];

        foreach (['X-WR-CALNAME', 'X-WR-CALDESC', 'X-WR-TIMEZONE', 'METHOD'] as $prop) {
            if (isset($vcalendar->{$prop})) {
                $properties[$prop] = (string)$vcalendar->{$prop};
            }
        }

        return $properties;
    }

    /**
     * Retourne la valeur de METHOD d'un VCALENDAR iTIP (REQUEST, REPLY, CANCEL…).
     * Retourne null si absent. — Phase 3.3
     */
    public static function getMethod(string $icsContent): ?string
    {
        $vcalendar = Reader::read($icsContent, Reader::OPTION_FORGIVING);
        return isset($vcalendar->METHOD) ? strtoupper((string)$vcalendar->METHOD) : null;
    }

    /**
     * Parse tous les VEVENT d'un fichier ICS.
     * Retourne un tableau d'événements normalisés (clés en snake_case).
     *
     * Chaque entrée contient :
     *   uid, title, description, location, status, rrule,
     *   start_datetime, end_datetime, all_day (bool)
     */
    public static function parseEvents(string $icsContent): array
    {
        $vcalendar = Reader::read($icsContent, Reader::OPTION_FORGIVING);
        $events = [];

        if (!isset($vcalendar->VEVENT)) {
            return [];
        }

        foreach ($vcalendar->VEVENT as $vevent) {
            /** @var \Sabre\VObject\Component $vevent */
            $events[] = self::normalizeVEvent($vevent);
        }

        return $events;
    }

    /**
     * Parse un seul VEVENT depuis un contenu ICS (PUT CalDAV).
     * Retourne null si aucun VEVENT trouvé.
     */
    public static function parseSingleEvent(string $icsContent): ?array
    {
        $vcalendar = Reader::read($icsContent, Reader::OPTION_FORGIVING);

        if (!isset($vcalendar->VEVENT)) {
            return null;
        }

        /** @var \Sabre\VObject\Component $firstVEvent */
        $firstVEvent = $vcalendar->VEVENT;
        return self::normalizeVEvent($firstVEvent);
    }

    /**
     * Normalise un composant VEvent sabre/vobject en tableau PHP.
     *
     * Les datetimes sont stockés en heure locale (America/Montreal par défaut),
     * cohérent avec la convention actuelle de la DB et TimezoneHelper.
     *
     * @param \Sabre\VObject\Component $vevent
     * @param string $localTimezone  Timezone cible pour le stockage en DB
     */
    private static function normalizeVEvent(\Sabre\VObject\Component $vevent, string $localTimezone = 'America/Montreal'): array
    {
        $event = [];

        $event['uid']         = isset($vevent->UID)         ? (string)$vevent->UID         : null;
        $event['title']       = isset($vevent->SUMMARY)     ? (string)$vevent->SUMMARY     : 'Sans titre';
        $event['description'] = isset($vevent->DESCRIPTION) ? (string)$vevent->DESCRIPTION : null;
        $event['location']    = isset($vevent->LOCATION)    ? (string)$vevent->LOCATION    : null;
        $event['status']      = isset($vevent->STATUS)      ? strtolower((string)$vevent->STATUS) : 'confirmed';
        $event['rrule']       = isset($vevent->RRULE)       ? (string)$vevent->RRULE       : null;
        $event['sequence']    = isset($vevent->SEQUENCE)    ? (int)(string)$vevent->SEQUENCE : 0;

        // Phase 2.1 — CATEGORIES → tableau de chaînes
        if (isset($vevent->CATEGORIES)) {
            $raw = (string)$vevent->CATEGORIES;
            $event['categories'] = array_values(array_filter(array_map('trim', explode(',', $raw))));
        } else {
            $event['categories'] = null;
        }

        // Phase 2.2 — PRIORITY
        $event['priority'] = isset($vevent->PRIORITY) ? (int)(string)$vevent->PRIORITY : 0;

        // Phase 2.3 — CLASS
        $event['class'] = isset($vevent->CLASS)
            ? strtoupper((string)$vevent->CLASS)
            : 'PUBLIC';

        // Phase 2.4 — TRANSP
        $event['transp'] = isset($vevent->TRANSP)
            ? strtoupper((string)$vevent->TRANSP)
            : 'OPAQUE';

        // Phase 2.5 — URL (→ meeting_link)
        $event['url'] = isset($vevent->URL) ? (string)$vevent->URL : null;

        // Phase 2.5 — GEO : "lat;lng"
        if (isset($vevent->GEO)) {
            $parts = explode(';', (string)$vevent->GEO, 2);
            $event['geo_lat'] = isset($parts[0]) ? (float)$parts[0] : null;
            $event['geo_lng'] = isset($parts[1]) ? (float)$parts[1] : null;
        } else {
            $event['geo_lat'] = null;
            $event['geo_lng'] = null;
        }

        // Phase 3.1 — ATTENDEE → [{email, name, role, partstat, rsvp, cutype}]
        $attendees = [];
        if (isset($vevent->ATTENDEE)) {
            foreach ($vevent->ATTENDEE as $attendeeProp) {
                $raw   = (string)$attendeeProp;
                $email = (stripos($raw, 'mailto:') === 0) ? substr($raw, 7) : $raw;
                if (empty($email)) {
                    continue;
                }
                $entry = ['email' => $email];
                if (isset($attendeeProp['CN'])) {
                    $entry['name'] = (string)$attendeeProp['CN'];
                }
                if (isset($attendeeProp['ROLE'])) {
                    $entry['role'] = (string)$attendeeProp['ROLE'];
                }
                if (isset($attendeeProp['PARTSTAT'])) {
                    $entry['partstat'] = (string)$attendeeProp['PARTSTAT'];
                }
                if (isset($attendeeProp['RSVP'])) {
                    $entry['rsvp'] = strtoupper((string)$attendeeProp['RSVP']) === 'TRUE';
                }
                if (isset($attendeeProp['CUTYPE'])) {
                    $entry['cutype'] = (string)$attendeeProp['CUTYPE'];
                }
                $attendees[] = $entry;
            }
        }
        $event['attendees'] = empty($attendees) ? null : $attendees;

        // Phase 3.2 — ORGANIZER
        $event['organizer_email'] = null;
        $event['organizer_name']  = null;
        if (isset($vevent->ORGANIZER)) {
            $orgRaw = (string)$vevent->ORGANIZER;
            $event['organizer_email'] = (stripos($orgRaw, 'mailto:') === 0)
                ? substr($orgRaw, 7)
                : $orgRaw;
            if (isset($vevent->ORGANIZER['CN'])) {
                $event['organizer_name'] = (string)$vevent->ORGANIZER['CN'];
            }
        }

        // Phase 2.6 — ATTACH → [{url|data_base64, mime_type}]
        $attachments = [];
        if (isset($vevent->ATTACH)) {
            foreach ($vevent->ATTACH as $attachProp) {
                $encoding = strtoupper((string)($attachProp['ENCODING'] ?? ''));
                $mimeType = isset($attachProp['FMTTYPE']) ? (string)$attachProp['FMTTYPE'] : null;
                $value    = (string)$attachProp;

                if ($encoding === 'BASE64') {
                    $entry = ['data_base64' => $value];
                } else {
                    $entry = ['url' => $value];
                }
                if ($mimeType !== null) {
                    $entry['mime_type'] = $mimeType;
                }
                $attachments[] = $entry;
            }
        }
        $event['attachments'] = empty($attachments) ? null : $attachments;

        // Phase 4.3 — RELATED-TO : UID de l'événement parent (RFC 5545 §3.8.4.5)
        $event['related_to'] = null;
        if (isset($vevent->{'RELATED-TO'})) {
            $event['related_to'] = (string)$vevent->{'RELATED-TO'};
        }

        // Phase 4.5 — DURATION (RFC 5545 §3.8.2.5)
        $event['duration'] = isset($vevent->DURATION) ? (string)$vevent->DURATION : null;

        // Phase 4.2 — RDATE : dates additionnelles → CSV de datetimes locales
        $rdates = [];
        if (isset($vevent->RDATE)) {
            $tz = new \DateTimeZone($localTimezone);
            foreach ($vevent->RDATE as $rdateProp) {
                /** @var \Sabre\VObject\Property\ICalendar\DateTime $rdateProp */
                try {
                    foreach ($rdateProp->getDateTimes() as $dt) {
                        $rdates[] = $dt->setTimezone($tz)->format('Y-m-d H:i:s');
                    }
                } catch (\Exception $e) {
                    // Ignorer les valeurs RDATE non parsables
                }
            }
        }
        $event['rdate'] = empty($rdates) ? null : implode(',', $rdates);

        // Phase 4.1 — EXDATE : liste de datetimes annulées → pour création d'occurrences annulées
        $exdates = [];
        if (isset($vevent->EXDATE)) {
            $tz = new \DateTimeZone($localTimezone);
            foreach ($vevent->EXDATE as $exdateProp) {
                /** @var \Sabre\VObject\Property\ICalendar\DateTime $exdateProp */
                try {
                    foreach ($exdateProp->getDateTimes() as $dt) {
                        $exdates[] = $dt->setTimezone($tz)->format('Y-m-d H:i:s');
                    }
                } catch (\Exception $e) {
                    // Ignorer les valeurs EXDATE non parsables
                }
            }
        }
        $event['exdates'] = empty($exdates) ? null : $exdates;

        // Phase 4.4 — VALARM → notifications JSON [{type, minutes}]
        $notifications = [];
        if (isset($vevent->VALARM)) {
            foreach ($vevent->VALARM as $alarm) {
                /** @var \Sabre\VObject\Component $alarm */
                $actionRaw = strtolower((string)($alarm->ACTION ?? 'display'));
                $type      = ($actionRaw === 'email') ? 'email' : 'notification';
                $minutes   = 0;

                if (isset($alarm->TRIGGER)) {
                    $triggerStr = (string)$alarm->TRIGGER;
                    // Formats : -PT30M, PT1H30M, -P1D, PT0S
                    if (preg_match('/-?PT(?:(\d+)H)?(?:(\d+)M)?/', $triggerStr, $m)) {
                        $minutes = ((int)($m[1] ?? 0)) * 60 + (int)($m[2] ?? 0);
                    } elseif (preg_match('/-?P(\d+)D/', $triggerStr, $m)) {
                        $minutes = (int)$m[1] * 24 * 60;
                    }
                }

                $notifications[] = ['type' => $type, 'minutes' => $minutes];
            }
        }
        $event['notifications'] = empty($notifications) ? null : $notifications;

        // DTSTART
        if (isset($vevent->DTSTART)) {
            /** @var \Sabre\VObject\Property\ICalendar\DateTime $dtstart */
            $dtstart  = $vevent->DTSTART;
            $isAllDay = ($dtstart->getValueType() === 'DATE');
            $event['all_day'] = $isAllDay;

            if ($isAllDay) {
                $event['start_datetime'] = $dtstart->getDateTime()->format('Y-m-d') . ' 00:00:00';
            } else {
                $tz = new \DateTimeZone($localTimezone);
                $event['start_datetime'] = $dtstart->getDateTime($tz)->format('Y-m-d H:i:s');
            }
        } else {
            $event['all_day']        = false;
            $event['start_datetime'] = null;
        }

        // DTEND — Phase 4.5 : si absent mais DURATION présent, calculer end_datetime
        if (isset($vevent->DTEND)) {
            /** @var \Sabre\VObject\Property\ICalendar\DateTime $dtend */
            $dtend    = $vevent->DTEND;
            $isAllDay = ($dtend->getValueType() === 'DATE');

            if ($isAllDay) {
                // RFC 5545 : DTEND est exclusif pour les événements all-day → recule d'un jour
                $dt = $dtend->getDateTime();
                $dt->modify('-1 day');
                $event['end_datetime'] = $dt->format('Y-m-d') . ' 23:59:59';
            } else {
                $tz = new \DateTimeZone($localTimezone);
                $event['end_datetime'] = $dtend->getDateTime($tz)->format('Y-m-d H:i:s');
            }
        } elseif (!empty($event['duration']) && !empty($event['start_datetime'])) {
            // Phase 4.5 — calculer end_datetime depuis DTSTART + DURATION
            try {
                $dtStart  = new \DateTime($event['start_datetime']);
                $interval = new \DateInterval(ltrim($event['duration'], '-'));
                $dtStart->add($interval);
                $event['end_datetime'] = $dtStart->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                $event['end_datetime'] = $event['start_datetime'];
            }
        } else {
            $event['end_datetime'] = $event['start_datetime'];
        }

        return $event;
    }

    // ====================================================================
    // Phase 5.1 — VTODO parsing
    // ====================================================================

    /**
     * Parse tous les VTODO d'un fichier ICS.
     * Retourne un tableau de tâches normalisées (clés en snake_case).
     */
    public static function parseTodos(string $icsContent): array
    {
        $vcalendar = Reader::read($icsContent, Reader::OPTION_FORGIVING);

        if (!isset($vcalendar->VTODO)) {
            return [];
        }

        $todos = [];
        foreach ($vcalendar->VTODO as $vtodo) {
            $todos[] = self::normalizeVTodo($vtodo);
        }
        return $todos;
    }

    /**
     * Normalise un composant VTODO sabre/vobject en tableau PHP.
     *
     * @param \Sabre\VObject\Component $vtodo
     * @param string $localTimezone Timezone cible pour le stockage en DB
     */
    private static function normalizeVTodo($vtodo, string $localTimezone = 'America/Montreal'): array
    {
        $tz   = new \DateTimeZone($localTimezone);
        $todo = [];

        $todo['uid']         = isset($vtodo->UID)         ? (string)$vtodo->UID         : null;
        $todo['title']       = isset($vtodo->SUMMARY)     ? (string)$vtodo->SUMMARY     : 'Sans titre';
        $todo['description'] = isset($vtodo->DESCRIPTION) ? (string)$vtodo->DESCRIPTION : null;
        $todo['location']    = isset($vtodo->LOCATION)    ? (string)$vtodo->LOCATION    : null;
        $todo['status']      = isset($vtodo->STATUS)
            ? strtoupper((string)$vtodo->STATUS)
            : 'NEEDS-ACTION';
        $todo['priority']    = isset($vtodo->PRIORITY)    ? (int)(string)$vtodo->PRIORITY : 0;
        $todo['percent_complete'] = isset($vtodo->{'PERCENT-COMPLETE'})
            ? (int)(string)$vtodo->{'PERCENT-COMPLETE'}
            : 0;
        $todo['sequence']    = isset($vtodo->SEQUENCE)    ? (int)(string)$vtodo->SEQUENCE : 0;
        $todo['url']         = isset($vtodo->URL)         ? (string)$vtodo->URL          : null;
        $todo['related_to']  = isset($vtodo->{'RELATED-TO'}) ? (string)$vtodo->{'RELATED-TO'} : null;

        if (isset($vtodo->CATEGORIES)) {
            $raw = (string)$vtodo->CATEGORIES;
            $todo['categories'] = array_values(array_filter(array_map('trim', explode(',', $raw))));
        } else {
            $todo['categories'] = null;
        }

        $todo['dtstart']   = isset($vtodo->DTSTART)
            ? (($vtodo->DTSTART instanceof \Sabre\VObject\Property\ICalendar\DateTime) ? $vtodo->DTSTART->getDateTime($tz)->format('Y-m-d H:i:s') : (string)$vtodo->DTSTART)
            : null;
        $todo['due']       = isset($vtodo->DUE)
            ? (($vtodo->DUE instanceof \Sabre\VObject\Property\ICalendar\DateTime) ? $vtodo->DUE->getDateTime($tz)->format('Y-m-d H:i:s') : (string)$vtodo->DUE)
            : null;
        $todo['completed'] = isset($vtodo->COMPLETED)
            ? (($vtodo->COMPLETED instanceof \Sabre\VObject\Property\ICalendar\DateTime) ? $vtodo->COMPLETED->getDateTime($tz)->format('Y-m-d H:i:s') : (string)$vtodo->COMPLETED)
            : null;

        $todo['organizer_email'] = null;
        $todo['organizer_name']  = null;
        if (isset($vtodo->ORGANIZER)) {
            $orgRaw = (string)$vtodo->ORGANIZER;
            $todo['organizer_email'] = (stripos($orgRaw, 'mailto:') === 0)
                ? substr($orgRaw, 7) : $orgRaw;
            if (isset($vtodo->ORGANIZER['CN'])) {
                $todo['organizer_name'] = (string)$vtodo->ORGANIZER['CN'];
            }
        }

        $attendees = [];
        if (isset($vtodo->ATTENDEE)) {
            foreach ($vtodo->ATTENDEE as $attendeeProp) {
                $raw   = (string)$attendeeProp;
                $email = (stripos($raw, 'mailto:') === 0) ? substr($raw, 7) : $raw;
                if (empty($email)) {
                    continue;
                }
                $entry = ['email' => $email];
                if (isset($attendeeProp['CN'])) {
                    $entry['name'] = (string)$attendeeProp['CN'];
                }
                if (isset($attendeeProp['PARTSTAT'])) {
                    $entry['partstat'] = (string)$attendeeProp['PARTSTAT'];
                }
                $attendees[] = $entry;
            }
        }
        $todo['attendees'] = empty($attendees) ? null : $attendees;

        return $todo;
    }

    // ====================================================================
    // Phase 5.2 — VJOURNAL parsing
    // ====================================================================

    /**
     * Parse tous les VJOURNAL d'un fichier ICS.
     * Retourne un tableau de journaux normalisés (clés en snake_case).
     */
    public static function parseJournals(string $icsContent): array
    {
        $vcalendar = Reader::read($icsContent, Reader::OPTION_FORGIVING);

        if (!isset($vcalendar->VJOURNAL)) {
            return [];
        }

        $journals = [];
        foreach ($vcalendar->VJOURNAL as $vjournal) {
            $journals[] = self::normalizeVJournal($vjournal);
        }
        return $journals;
    }

    /**
     * Normalise un composant VJOURNAL sabre/vobject en tableau PHP.
     *
     * @param \Sabre\VObject\Component $vjournal
     * @param string $localTimezone Timezone cible pour le stockage en DB
     */
    private static function normalizeVJournal($vjournal, string $localTimezone = 'America/Montreal'): array
    {
        $tz      = new \DateTimeZone($localTimezone);
        $journal = [];

        $journal['uid']         = isset($vjournal->UID)         ? (string)$vjournal->UID         : null;
        $journal['summary']     = isset($vjournal->SUMMARY)     ? (string)$vjournal->SUMMARY     : 'Sans titre';
        $journal['description'] = isset($vjournal->DESCRIPTION) ? (string)$vjournal->DESCRIPTION : null;
        $journal['status']      = isset($vjournal->STATUS)
            ? strtoupper((string)$vjournal->STATUS)
            : 'DRAFT';
        $journal['sequence']    = isset($vjournal->SEQUENCE)    ? (int)(string)$vjournal->SEQUENCE : 0;
        $journal['url']         = isset($vjournal->URL)         ? (string)$vjournal->URL          : null;
        $journal['related_to']  = isset($vjournal->{'RELATED-TO'}) ? (string)$vjournal->{'RELATED-TO'} : null;

        if (isset($vjournal->CATEGORIES)) {
            $raw = (string)$vjournal->CATEGORIES;
            $journal['categories'] = array_values(array_filter(array_map('trim', explode(',', $raw))));
        } else {
            $journal['categories'] = null;
        }

        $journal['dtstart'] = isset($vjournal->DTSTART)
            ? (($vjournal->DTSTART instanceof \Sabre\VObject\Property\ICalendar\DateTime) ? $vjournal->DTSTART->getDateTime($tz)->format('Y-m-d H:i:s') : (string)$vjournal->DTSTART)
            : null;

        $journal['organizer_email'] = null;
        $journal['organizer_name']  = null;
        if (isset($vjournal->ORGANIZER)) {
            $orgRaw = (string)$vjournal->ORGANIZER;
            $journal['organizer_email'] = (stripos($orgRaw, 'mailto:') === 0)
                ? substr($orgRaw, 7) : $orgRaw;
            if (isset($vjournal->ORGANIZER['CN'])) {
                $journal['organizer_name'] = (string)$vjournal->ORGANIZER['CN'];
            }
        }

        return $journal;
    }
}
