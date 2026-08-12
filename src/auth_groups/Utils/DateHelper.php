<?php

namespace AuthGroups\Utils;

class DateHelper
{
    /**
     * Reformate un datetime MySQL ('Y-m-d H:i:s') en ISO 8601 UTC ('Y-m-d\TH:i:s\Z').
     * La session DB tourne en UTC (DB_TIMEZONE=+00:00) : simple reformatage, pas de
     * conversion de fuseau nécessaire.
     */
    public static function toIso8601Utc(?string $mysqlDatetime): ?string
    {
        if ($mysqlDatetime === null || $mysqlDatetime === '') {
            return null;
        }
        return str_replace(' ', 'T', $mysqlDatetime) . 'Z';
    }
}
