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
     * @param array $calendar  Ligne DB du calendrier (title, description, timezone)
     * @param array $events    Tableau de lignes DB d'événements
     */
    public static function generateCalendar(array $calendar, array $events): string
    {
        $timezone = $calendar['timezone'] ?? 'America/Montreal';

        // En-tête VCALENDAR (construit manuellement pour conserver X-WR-* et VTIMEZONE)
        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//CMEM Calendar//FR\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "X-WR-CALNAME:" . TimezoneHelper::escapeIcsText($calendar['title']) . "\r\n";

        if (!empty($calendar['description'])) {
            $ics .= "X-WR-CALDESC:" . TimezoneHelper::escapeIcsText($calendar['description']) . "\r\n";
        }

        $ics .= "X-WR-TIMEZONE:" . $timezone . "\r\n";
        // VTIMEZONE complet (transitions DST) via helper existant
        $ics .= TimezoneHelper::generateVTimezone($timezone);

        foreach ($events as $event) {
            // sabre/vobject génère le VEVENT avec TZID + folding
            $ics .= self::buildVEvent($event, $timezone);
        }

        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }

    /**
     * Génère un VCALENDAR à un seul événement (réponse GET CalDAV).
     *
     * @param array  $event             Ligne DB de l'événement
     * @param string $calendarTimezone  Timezone du calendrier parent
     */
    public static function generateSingleEvent(array $event, string $calendarTimezone): string
    {
        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//CMEM2//CalDAV Server//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= TimezoneHelper::generateVTimezone($calendarTimezone);
        $ics .= self::buildVEvent($event, $calendarTimezone);
        $ics .= "END:VCALENDAR\r\n";

        return $ics;
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
        $vevent  = $tmpCal->add('VEVENT');

        // UID RFC-conforme (item 1.3) — utilise le champ DB si présent
        $uid = !empty($event['uid'])
            ? $event['uid']
            : ('event-' . ($event['id'] ?? '0') . '@cmem-calendar.local');
        $vevent->add('UID', $uid);

        // DTSTAMP — toujours UTC (RFC 5545 §3.8.7.2)
        $vevent->add('DTSTAMP', new \DateTime('now', new \DateTimeZone('UTC')));

        // Timezone effective de l'événement
        $eventTz = !empty($event['timezone']) ? $event['timezone'] : $calendarTimezone;

        if (!empty($event['all_day'])) {
            // Événements toute la journée — VALUE=DATE, pas de TZID
            $dtStart = new \DateTime(substr($event['start_datetime'], 0, 10));
            $vevent->add('DTSTART', $dtStart);
            $vevent->DTSTART['VALUE'] = 'DATE';

            // DTEND exclusif : lendemain du dernier jour (RFC 5545 §3.6.1)
            $dtEnd = new \DateTime(substr($event['end_datetime'], 0, 10));
            $dtEnd->modify('+1 day');
            $vevent->add('DTEND', $dtEnd);
            $vevent->DTEND['VALUE'] = 'DATE';
        } else {
            // DTSTART / DTEND avec TZID — sabre ajoute automatiquement TZID= (item 1.4)
            $tz     = new \DateTimeZone($eventTz);
            $dtStart = new \DateTime($event['start_datetime'], $tz);
            $vevent->add('DTSTART', $dtStart);

            $dtEnd = new \DateTime($event['end_datetime'], $tz);
            $vevent->add('DTEND', $dtEnd);
        }

        $vevent->add('SUMMARY', $event['title']);

        if (!empty($event['description'])) {
            $vevent->add('DESCRIPTION', $event['description']);
        }

        if (!empty($event['location'])) {
            $vevent->add('LOCATION', $event['location']);
        }

        // Participants
        if (!empty($event['attendees'])) {
            $attendees = is_string($event['attendees'])
                ? json_decode($event['attendees'], true)
                : $event['attendees'];

            if (is_array($attendees)) {
                foreach ($attendees as $attendee) {
                    if (!empty($attendee['email'])) {
                        $vevent->add('ATTENDEE', 'mailto:' . $attendee['email']);
                    }
                }
            }
        }

        if (!empty($event['recurrence_rule'])) {
            $vevent->add('RRULE', $event['recurrence_rule']);
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

        // serialize() retourne BEGIN:VEVENT…END:VEVENT avec folding RFC 5545 §3.1
        return $vevent->serialize();
    }
}
