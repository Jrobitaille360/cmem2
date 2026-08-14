<?php

namespace Booking\Services;

use Booking\Models\BookingPage;
use Booking\Models\BookingSlot;
use DateInterval;
use DateTime;
use DateTimeZone;
use ICS\Models\EventOccurrence;

/**
 * Génération / régénération des zones réservables d'une page de booking.
 * Phase 3 du plan (docs/PLAN_booking-public.md).
 *
 * Convention horaire : les créneaux candidats et les événements du calendrier hôte
 * (calendar_events, naïfs, wall-clock — même convention que ICS\Controllers\CalendarController::
 * getFreeBusy) sont comparés en heure locale de la page (`booking_pages.timezone`). Seules les
 * zones survivantes sont converties en UTC pour l'écriture dans `booking_slots`.
 *
 * Buffers : `buffer_before_minutes`/`buffer_after_minutes` élargissent la fenêtre de conflit
 * vérifiée contre le calendrier (pas d'espacement entre deux créneaux générés consécutifs).
 */
class BookingSlotService
{
    private BookingPage $pages;
    private BookingSlot $slots;

    public function __construct()
    {
        $this->pages = new BookingPage();
        $this->slots = new BookingSlot();
    }

    /**
     * Supprime les zones non réservées futures de la page, puis régénère sur l'horizon configuré,
     * en excluant tout ce qui chevauche un événement OPAQUE du calendrier de l'hôte. Les zones déjà
     * réservées ne sont jamais touchées.
     */
    public function regenerate(int $bookingPageId): int
    {
        $page = $this->pages->findById($bookingPageId);
        if ($page === null) {
            throw new \InvalidArgumentException("booking_pages introuvable : {$bookingPageId}");
        }

        $this->slots->deleteNonReservedFutureByPage($bookingPageId);

        $tz  = new DateTimeZone($page['timezone']);
        $now = new DateTime('now', $tz);

        $horizonDays = (int) $page['horizon_days'];
        $duration    = (int) $page['duration_minutes'];
        $bufferBefore = (int) $page['buffer_before_minutes'];
        $bufferAfter  = (int) $page['buffer_after_minutes'];
        $windows     = json_decode($page['availability_windows'], true) ?? [];
        if (empty($windows) || $duration <= 0) {
            return 0;
        }

        $windowsByWeekday = [];
        foreach ($windows as $w) {
            $windowsByWeekday[(int) $w['weekday']][] = $w;
        }

        $rangeStart = (clone $now)->setTime(0, 0, 0);
        $rangeEnd   = (clone $now)->add(new DateInterval("P{$horizonDays}D"))->setTime(23, 59, 59);

        // Padding de la plage interrogée par les buffers, pour capter les événements qui
        // débordent juste avant/après l'horizon et pourraient bloquer un créneau en bord de plage.
        $queryStart = (clone $rangeStart)->sub(new DateInterval('PT' . max($bufferBefore, 1) . 'M'));
        $queryEnd   = (clone $rangeEnd)->add(new DateInterval('PT' . max($bufferAfter, 1) . 'M'));

        $busy = EventOccurrence::getExpandedOpaqueByCalendarId(
            (int) $page['calendar_id'],
            $queryStart->format('Y-m-d H:i:s'),
            $queryEnd->format('Y-m-d H:i:s')
        );

        $candidates = [];
        $cursorDay = clone $rangeStart;
        while ($cursorDay <= $rangeEnd) {
            $weekday = (int) $cursorDay->format('w'); // 0 = dimanche, même convention que Date.getDay() JS
            foreach ($windowsByWeekday[$weekday] ?? [] as $w) {
                $candidates = array_merge(
                    $candidates,
                    $this->generateDayCandidates($cursorDay, $w, $duration, $now)
                );
            }
            $cursorDay->add(new DateInterval('P1D'));
        }

        $toInsert = [];
        foreach ($candidates as [$startLocal, $endLocal]) {
            $busyStart = (clone $startLocal)->sub(new DateInterval('PT' . $bufferBefore . 'M'));
            $busyEnd   = (clone $endLocal)->add(new DateInterval('PT' . $bufferAfter . 'M'));

            if ($this->overlapsAny($busyStart, $busyEnd, $busy)) {
                continue;
            }

            $startUtc = (clone $startLocal)->setTimezone(new DateTimeZone('UTC'));
            $endUtc   = (clone $endLocal)->setTimezone(new DateTimeZone('UTC'));
            $toInsert[] = ['start' => $startUtc->format('Y-m-d H:i:s'), 'end' => $endUtc->format('Y-m-d H:i:s')];
        }

        return $this->slots->insertMany($bookingPageId, $toInsert);
    }

    /** @return array<int, array{0: DateTime, 1: DateTime}> */
    private function generateDayCandidates(DateTime $day, array $window, int $duration, DateTime $now): array
    {
        $tz = $day->getTimezone();
        [$sh, $sm] = array_map('intval', explode(':', $window['start']));
        [$eh, $em] = array_map('intval', explode(':', $window['end']));

        $cursor = (clone $day)->setTime($sh, $sm, 0);
        $windowEnd = (clone $day)->setTime($eh, $em, 0);

        $out = [];
        while (true) {
            $slotEnd = (clone $cursor)->add(new DateInterval("PT{$duration}M"));
            if ($slotEnd > $windowEnd) {
                break;
            }
            if ($cursor > $now) {
                $out[] = [clone $cursor, $slotEnd];
            }
            $cursor = $slotEnd;
        }

        return $out;
    }

    /** @param array<int, array{start_datetime: string, end_datetime: string}> $busy */
    private function overlapsAny(DateTime $start, DateTime $end, array $busy): bool
    {
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr   = $end->format('Y-m-d H:i:s');

        foreach ($busy as $occurrence) {
            if ($startStr < $occurrence['end_datetime'] && $occurrence['start_datetime'] < $endStr) {
                return true;
            }
        }
        return false;
    }
}
