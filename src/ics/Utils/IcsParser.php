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

        return self::normalizeVEvent($vcalendar->VEVENT);
    }

    /**
     * Normalise un composant VEvent sabre/vobject en tableau PHP.
     *
     * Les datetimes sont stockés en heure locale (America/Montreal par défaut),
     * cohérent avec la convention actuelle de la DB et TimezoneHelper.
     *
     * @param \Sabre\VObject\Component\VEvent $vevent
     * @param string $localTimezone  Timezone cible pour le stockage en DB
     */
    private static function normalizeVEvent($vevent, string $localTimezone = 'America/Montreal'): array
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

        // DTSTART
        if (isset($vevent->DTSTART)) {
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

        // DTEND
        if (isset($vevent->DTEND)) {
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
        } else {
            $event['end_datetime'] = $event['start_datetime'];
        }

        return $event;
    }
}
