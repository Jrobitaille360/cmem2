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
     * Parse les propriétés d'un VCALENDAR (X-WR-CALNAME, X-WR-CALDESC, X-WR-TIMEZONE).
     */
    public static function parseCalendarProperties(string $icsContent): array
    {
        $vcalendar = Reader::read($icsContent, Reader::OPTION_FORGIVING);
        $properties = [];

        foreach (['X-WR-CALNAME', 'X-WR-CALDESC', 'X-WR-TIMEZONE'] as $prop) {
            if (isset($vcalendar->{$prop})) {
                $properties[$prop] = (string)$vcalendar->{$prop};
            }
        }

        return $properties;
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
