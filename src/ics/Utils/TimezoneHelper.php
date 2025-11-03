<?php

namespace ICS\Utils;

use DateTimeZone;
use Exception;

/**
 * Classe utilitaire pour la gestion des fuseaux horaires
 * Conforme RFC 5545 (iCalendar)
 */
class TimezoneHelper
{
    /**
     * Valide si un timezone est valide
     * 
     * @param string $timezone
     * @return bool
     */
    public static function isValidTimezone(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Retourne une liste de timezones valides couramment utilisés
     * 
     * @return array
     */
    public static function getCommonTimezones(): array
    {
        return [
            'America/Montreal',
            'America/Toronto',
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Los_Angeles',
            'America/Vancouver',
            'Europe/Paris',
            'Europe/London',
            'Europe/Berlin',
            'Asia/Tokyo',
            'Asia/Shanghai',
            'Australia/Sydney',
            'UTC'
        ];
    }

    /**
     * Génère un bloc VTIMEZONE complet pour un timezone donné
     * Simplifié pour les timezones principaux
     * 
     * @param string $timezone
     * @return string
     */
    public static function generateVTimezone(string $timezone): string
    {
        if (!self::isValidTimezone($timezone)) {
            $timezone = 'America/Montreal';
        }

        // Pour simplifier, on génère un VTIMEZONE basique
        // Pour une solution complète, utiliser une bibliothèque comme sabre/vobject
        
        $vtimezone = "BEGIN:VTIMEZONE\r\n";
        $vtimezone .= "TZID:" . $timezone . "\r\n";
        
        // Récupérer les informations de transition pour l'année en cours
        try {
            $tz = new DateTimeZone($timezone);
            $transitions = $tz->getTransitions(time(), time() + 31536000); // 1 an
            
            if (count($transitions) > 0) {
                $offset = $transitions[0]['offset'];
                $offsetHours = floor(abs($offset) / 3600);
                $offsetMinutes = floor((abs($offset) % 3600) / 60);
                $offsetSign = $offset >= 0 ? '+' : '-';
                $offsetString = sprintf('%s%02d%02d', $offsetSign, $offsetHours, $offsetMinutes);
                
                // Standard time (simplifié)
                $vtimezone .= "BEGIN:STANDARD\r\n";
                $vtimezone .= "DTSTART:19700101T000000\r\n";
                $vtimezone .= "TZOFFSETFROM:" . $offsetString . "\r\n";
                $vtimezone .= "TZOFFSETTO:" . $offsetString . "\r\n";
                $vtimezone .= "TZNAME:" . $transitions[0]['abbr'] . "\r\n";
                $vtimezone .= "END:STANDARD\r\n";
            }
        } catch (Exception $e) {
            // Fallback pour UTC+0
            $vtimezone .= "BEGIN:STANDARD\r\n";
            $vtimezone .= "DTSTART:19700101T000000\r\n";
            $vtimezone .= "TZOFFSETFROM:+0000\r\n";
            $vtimezone .= "TZOFFSETTO:+0000\r\n";
            $vtimezone .= "TZNAME:UTC\r\n";
            $vtimezone .= "END:STANDARD\r\n";
        }
        
        $vtimezone .= "END:VTIMEZONE\r\n";
        
        return $vtimezone;
    }

    /**
     * Convertit une date en format iCalendar UTC
     * 
     * @param string $datetime Date au format 'Y-m-d H:i:s'
     * @param string $sourceTimezone Timezone source de la date
     * @return string Date au format iCalendar UTC (ex: 20231225T120000Z)
     */
    public static function toICalDateTimeUTC(string $datetime, string $sourceTimezone = 'America/Montreal'): string
    {
        try {
            if (!self::isValidTimezone($sourceTimezone)) {
                $sourceTimezone = 'America/Montreal';
            }
            
            $dt = new \DateTime($datetime, new DateTimeZone($sourceTimezone));
            $dt->setTimezone(new DateTimeZone('UTC'));
            
            return $dt->format('Ymd\THis\Z');
        } catch (Exception $e) {
            // Fallback
            return gmdate('Ymd\THis\Z', strtotime($datetime));
        }
    }

    /**
     * Convertit une date en format iCalendar avec timezone local
     * 
     * @param string $datetime Date au format 'Y-m-d H:i:s'
     * @param string $timezone Timezone de la date
     * @return string Date au format iCalendar avec TZID
     */
    public static function toICalDateTimeWithTZ(string $datetime, string $timezone): string
    {
        try {
            if (!self::isValidTimezone($timezone)) {
                $timezone = 'America/Montreal';
            }
            
            $dt = new \DateTime($datetime, new DateTimeZone($timezone));
            
            return $dt->format('Ymd\THis');
        } catch (Exception $e) {
            // Fallback
            return date('Ymd\THis', strtotime($datetime));
        }
    }

    /**
     * Parse une date iCalendar et la convertit en format MySQL
     * 
     * @param string $icalDate Date au format iCalendar (ex: 20231225T120000Z ou 20231225)
     * @param string $targetTimezone Timezone cible pour le stockage
     * @return string Date au format 'Y-m-d H:i:s'
     */
    public static function fromICalDateTime(string $icalDate, string $targetTimezone = 'America/Montreal'): string
    {
        try {
            // Nettoyer la date
            $isUTC = (strpos($icalDate, 'Z') !== false);
            $icalDate = str_replace(['T', 'Z'], ['', ''], $icalDate);
            
            if (strlen($icalDate) == 8) {
                // Date seulement (all-day event)
                $year = substr($icalDate, 0, 4);
                $month = substr($icalDate, 4, 2);
                $day = substr($icalDate, 6, 2);
                
                return "$year-$month-$day 00:00:00";
            } else {
                // Date avec heure
                $year = substr($icalDate, 0, 4);
                $month = substr($icalDate, 4, 2);
                $day = substr($icalDate, 6, 2);
                $hour = substr($icalDate, 8, 2);
                $minute = substr($icalDate, 10, 2);
                $second = substr($icalDate, 12, 2);
                
                $dateString = "$year-$month-$day $hour:$minute:$second";
                
                if ($isUTC) {
                    // Convertir de UTC vers le timezone cible
                    $dt = new \DateTime($dateString, new DateTimeZone('UTC'));
                    if (self::isValidTimezone($targetTimezone)) {
                        $dt->setTimezone(new DateTimeZone($targetTimezone));
                    }
                    return $dt->format('Y-m-d H:i:s');
                } else {
                    return $dateString;
                }
            }
        } catch (Exception $e) {
            // Fallback
            $cleaned = str_replace(['T', 'Z'], ['', ''], $icalDate);
            if (strlen($cleaned) == 8) {
                return date('Y-m-d 00:00:00', strtotime($cleaned));
            } else {
                return date('Y-m-d H:i:s', strtotime($cleaned));
            }
        }
    }

    /**
     * Échappe le texte pour le format iCalendar
     * 
     * @param string $text
     * @return string
     */
    public static function escapeIcsText(string $text): string
    {
        return str_replace(["\n", "\r", ",", ";", "\\"], ["\\n", "", "\\,", "\\;", "\\\\"], $text);
    }

    /**
     * Déséchappe le texte du format iCalendar
     * 
     * @param string $text
     * @return string
     */
    public static function unescapeIcsText(string $text): string
    {
        return str_replace(["\\n", "\\,", "\\;", "\\\\"], ["\n", ",", ";", "\\"], $text);
    }
}
