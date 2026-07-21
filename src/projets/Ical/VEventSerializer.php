<?php

namespace Projets\Ical;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Sérialise chaque tâche (contrat §6) en VEVENT (Annexe B, §8).
 *
 * Convention de stockage : dtstart/due non-allDay sont des chaînes DATETIME
 * déjà en UTC (naïves, sans offset) — aucune conversion de fuseau n'est
 * appliquée ici, seulement un reformatage (§9.5 : les dates allDay restent
 * flottantes de bout en bout, jamais un instant UTC).
 */
final class VEventSerializer
{
    private const CRLF   = "\r\n";
    private const PRODID = '-//journauxdebord//cmem2 projets//FR';
    private const DOMAIN = 'cmem.journauxdebord.com';

    /** @param array<int,array<string,mixed>> $taches contrat §6 */
    public function buildCalendar(array $taches): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:' . self::PRODID,
            'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
        foreach ($taches as $t) {
            foreach ($this->buildVEvent($t) as $l) { $lines[] = $l; }
        }
        $lines[] = 'END:VCALENDAR';
        return implode(self::CRLF, array_map([$this, 'fold'], $lines)) . self::CRLF;
    }

    /** @return string[] lignes non pliées (déjà échappées) ; [] si tâche sans date */
    private function buildVEvent(array $t): array
    {
        $start  = $t['dtstart'] ?? ($t['due'] ?? null);
        if ($start === null) { return []; }
        $allDay = (bool) ($t['allDay'] ?? false);
        $end    = null;
        if (!empty($t['dtstart']) && !empty($t['due'])) {
            $end = $t['due'];
        } elseif (empty($t['dtstart']) && !empty($t['due'])) {
            $allDay = true; $start = $t['due'];
        }

        $status = (string) ($t['status'] ?? 'NEEDS-ACTION');
        $L = ['BEGIN:VEVENT'];
        $L[] = 'UID:' . $this->uid($t['id']);
        $L[] = 'DTSTAMP:' . $this->fmtUtc(gmdate('Y-m-d H:i:s'));
        $L[] = 'SEQUENCE:' . (int) ($t['sequence'] ?? 0);

        if ($allDay) {
            $startDate = $this->dateFlottante($start);
            $L[] = 'DTSTART;VALUE=DATE:' . $startDate;
            $L[] = 'DTEND;VALUE=DATE:' . $this->jourSuivant($this->dateFlottante($end ?? $start));
        } else {
            $L[] = 'DTSTART:' . $this->fmtUtc($start);
            if ($end !== null) { $L[] = 'DTEND:' . $this->fmtUtc($end); }
        }

        $prefixe = ($status === 'COMPLETED') ? '✓ ' : '';
        $L[] = 'SUMMARY:' . $this->esc($prefixe . (string) $t['title']);
        $desc = trim((string) ($t['description'] ?? ''));
        $meta = 'Statut : ' . $status;
        if (isset($t['percentComplete'])) { $meta .= ' — Progression : ' . (int) $t['percentComplete'] . '%'; }
        $L[] = 'DESCRIPTION:' . $this->esc($desc === '' ? $meta : ($desc . "\n" . $meta));

        $L[] = 'STATUS:' . ($status === 'CANCELLED' ? 'CANCELLED' : 'CONFIRMED');
        if (isset($t['priority'])) { $L[] = 'PRIORITY:' . max(0, min(9, (int) $t['priority'])); }

        if (!empty($t['categories'])) {
            $L[] = 'CATEGORIES:' . implode(',', array_map([$this, 'esc'], (array) $t['categories']));
        }

        if (!empty($t['parentId'])) { $L[] = 'RELATED-TO;RELTYPE=PARENT:' . $this->uid($t['parentId']); }
        foreach (($t['dependsOn'] ?? []) as $dep) {
            $prop = 'RELATED-TO;RELTYPE=' . $this->reltypeIcal((string) ($dep['type'] ?? 'FS'));
            if (!empty($dep['lagDays'])) { $prop .= ';GAP=P' . abs((int) $dep['lagDays']) . 'D'; }
            $L[] = $prop . ':' . $this->uid($dep['taskId']);
        }

        if (!empty($t['url'])) { $L[] = 'URL:' . (string) $t['url']; }

        if (!empty($t['rappelMinutesAvant'])) {
            $L[] = 'BEGIN:VALARM';
            $L[] = 'ACTION:DISPLAY';
            $L[] = 'DESCRIPTION:' . $this->esc((string) $t['title']);
            $L[] = 'TRIGGER;RELATED=START:-PT' . (int) $t['rappelMinutesAvant'] . 'M';
            $L[] = 'END:VALARM';
        }
        $L[] = 'END:VEVENT';
        return $L;
    }

    private function uid($id): string { return 'evt-' . $id . '@' . self::DOMAIN; }

    private function reltypeIcal(string $type): string
    {
        return ['FS' => 'FINISHTOSTART', 'SS' => 'STARTTOSTART',
                'FF' => 'FINISHTOFINISH', 'SF' => 'STARTTOFINISH'][strtoupper($type)] ?? 'FINISHTOSTART';
    }

    /** Formate une chaîne DATETIME déjà en UTC (naïve) en Ymd\THis\Z, sans conversion de fuseau. */
    private function fmtUtc(string $v): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr(str_replace('T', ' ', $v), 0, 19), new DateTimeZone('UTC'));
        if (!$d) { $d = new DateTimeImmutable('now', new DateTimeZone('UTC')); }
        return $d->format('Ymd\THis\Z');
    }

    /** Date FLOTTANTE en AAAAMMJJ, sans conversion de fuseau (§9.5). */
    private function dateFlottante(string $d): string
    {
        return str_replace('-', '', substr($d, 0, 10));
    }

    /** Jour suivant en arithmétique de DATE pure (fin exclusive). */
    private function jourSuivant(string $ymd): string
    {
        $d = DateTimeImmutable::createFromFormat('!Ymd', $ymd, new DateTimeZone('UTC'));
        return $d->modify('+1 day')->format('Ymd');
    }

    private function esc(string $v): string
    {
        $v = str_replace('\\', '\\\\', $v);
        $v = str_replace(';', '\;', $v);
        $v = str_replace(',', '\,', $v);
        return str_replace(["\r\n", "\r", "\n"], '\n', $v);
    }

    private function fold(string $line): string
    {
        if (strlen($line) <= 75) { return $line; }
        $out = ''; $len = 0;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $b = strlen($ch);
            if ($len + $b > 75) { $out .= self::CRLF . ' '; $len = 1; }
            $out .= $ch; $len += $b;
        }
        return $out;
    }
}
