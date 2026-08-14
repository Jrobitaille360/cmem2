<?php

namespace Booking\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Models\User;
use AuthGroups\Services\EmailService;
use AuthGroups\Services\RateLimitService;
use AuthGroups\Utils\Response;
use Booking\Models\BookingPage;
use Booking\Models\BookingSlot;
use Booking\Services\BookingGateService;
use DateTimeZone;
use ICS\Models\CalendarEvent;

/**
 * POST /booking/public/{slug}/book et POST /booking/public/cancel/{token}.
 * Directive 20260813_163000_cmem_web_vers_cmem2_API__booking-public.md, Phase 5 du plan.
 */
class BookingReservationController
{
    private const DEFAULT_APP_ID = 'puzzle';

    private BookingPage $pages;
    private BookingSlot $slots;

    public function __construct()
    {
        $this->pages = new BookingPage();
        $this->slots = new BookingSlot();
    }

    private function appId(array $params): string
    {
        $appId = trim((string) ($params['app_id'] ?? ''));
        return $appId !== '' ? $appId : self::DEFAULT_APP_ID;
    }

    private function usablePage(string $appId, string $slug): ?array
    {
        $page = $this->pages->findBySlug($appId, $slug);
        return BookingGateService::isPageUsable($page) ? $page : null;
    }

    public function book(string $slug): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        $appId = $this->appId($input);

        $ip = RateLimitService::getClientIp();
        if (!RateLimitService::check($ip, 'booking_book', 5, 1)) {
            LoggingMiddleware::logExit(429);
            Response::error('Trop de requêtes, réessayez dans une minute.', ['code' => 'RATE_LIMITED'], 429);
            return;
        }
        RateLimitService::record($ip, 'booking_book');

        $page = $this->usablePage($appId, $slug);
        if ($page === null) {
            LoggingMiddleware::logExit(404);
            Response::error('Page de réservation indisponible.', ['code' => 'BOOKING_UNAVAILABLE'], 404);
            return;
        }

        $errors = $this->validateBookInput($input);
        if (!empty($errors)) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', $errors, 422);
            return;
        }

        $slotId = (int) $input['slot_id'];
        $slotRow = $this->slots->findByIdForPage($slotId, (int) $page['id']);
        if ($slotRow === null) {
            LoggingMiddleware::logExit(422);
            Response::error("Ce créneau n'existe pas pour cette page", ['code' => 'SLOT_INVALID'], 422);
            return;
        }

        $guestName     = trim((string) $input['guest_name']);
        $guestEmail    = trim((string) $input['guest_email']);
        $guestTimezone = trim((string) $input['guest_timezone']);
        $cancelToken   = bin2hex(random_bytes(32));

        $reserved = $this->slots->reserve($slotId, $guestName, $guestEmail, $guestTimezone, $cancelToken);
        if (!$reserved) {
            LoggingMiddleware::logExit(409);
            Response::error("Ce créneau vient d'être réservé.", ['code' => 'SLOT_TAKEN'], 409);
            return;
        }

        $eventId = null;
        try {
            $hostTz = new DateTimeZone($page['timezone']);
            $startLocal = (new \DateTime($slotRow['start_datetime'] . ' UTC'))->setTimezone($hostTz);
            $endLocal   = (new \DateTime($slotRow['end_datetime'] . ' UTC'))->setTimezone($hostTz);

            $title = str_replace('{guest_name}', $guestName, (string) $page['event_title_template']);

            $event = new CalendarEvent();
            $event->calendarId   = (int) $page['calendar_id'];
            $event->userId       = (int) $page['owner_id'];
            $event->title        = $title;
            $event->description  = "Réservation en ligne — invité : {$guestEmail}";
            $event->startDatetime = $startLocal->format('Y-m-d H:i:s');
            $event->endDatetime   = $endLocal->format('Y-m-d H:i:s');
            $event->status        = 'confirmed';
            $event->timezone      = $page['timezone'];

            $created = $event->create();
            $eventId = (int) $created['id'];
            $this->slots->attachEvent($slotId, $eventId);

            $host = (new User())->findById((int) $page['owner_id']);
            $cancelBase = rtrim((string) ($_ENV['CMEMWEB_APP_URL'] ?? ''), '/');
            $cancelUrl  = $cancelBase !== ''
                ? "{$cancelBase}/book/{$slug}?cancel_token={$cancelToken}"
                : "/booking/public/cancel/{$cancelToken}";

            (new EmailService())->sendBookingConfirmation(
                $guestEmail,
                $guestName,
                $host['name'] ?? 'votre hôte',
                $startLocal->format('Y-m-d H:i'),
                $endLocal->format('Y-m-d H:i'),
                $page['timezone'],
                $cancelUrl
            );
        } catch (\Throwable $e) {
            // Échec après la réservation atomique : on libère la zone plutôt que de la laisser
            // bloquée sans événement ni moyen d'annulation pour l'invité.
            $this->slots->release($slotId);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de la création de l\'événement', null, 500);
            return;
        }

        LoggingMiddleware::logExit(200);
        Response::success('Réservation confirmée', [
            'cancel_token'   => $cancelToken,
            'event_id'       => $eventId,
            'start_datetime' => gmdate('Y-m-d\TH:i:s\Z', strtotime($slotRow['start_datetime'] . ' UTC')),
            'end_datetime'   => gmdate('Y-m-d\TH:i:s\Z', strtotime($slotRow['end_datetime'] . ' UTC')),
        ]);
    }

    public function cancel(string $token): void
    {
        LoggingMiddleware::logEntry();

        $ip = RateLimitService::getClientIp();
        if (!RateLimitService::check($ip, 'booking_cancel', 5, 1)) {
            LoggingMiddleware::logExit(429);
            Response::error('Trop de requêtes, réessayez dans une minute.', ['code' => 'RATE_LIMITED'], 429);
            return;
        }
        RateLimitService::record($ip, 'booking_cancel');

        $slotRow = $this->slots->findByCancelToken($token);
        if ($slotRow === null) {
            LoggingMiddleware::logExit(404);
            Response::error('Jeton d\'annulation invalide ou déjà utilisé', ['code' => 'CANCEL_TOKEN_INVALID'], 404);
            return;
        }

        $released = $this->slots->releaseByToken((int) $slotRow['id'], $token);
        if (!$released) {
            // Course avec une autre annulation concurrente sur le même jeton : idempotent → 404.
            LoggingMiddleware::logExit(404);
            Response::error('Jeton d\'annulation invalide ou déjà utilisé', ['code' => 'CANCEL_TOKEN_INVALID'], 404);
            return;
        }

        if (!empty($slotRow['event_id'])) {
            $event = new CalendarEvent();
            $event->id = (int) $slotRow['event_id'];
            $event->status = 'cancelled';
            $event->update();
        }

        LoggingMiddleware::logExit(200);
        Response::success('Réservation annulée', null);
    }

    private function validateBookInput(array $input): array
    {
        $errors = [];

        if (!isset($input['slot_id']) || !ctype_digit((string) $input['slot_id'])) {
            $errors['slot_id'] = 'slot_id requis (entier)';
        }
        if (trim((string) ($input['guest_name'] ?? '')) === '') {
            $errors['guest_name'] = 'guest_name requis';
        }
        if (!filter_var($input['guest_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors['guest_email'] = 'guest_email requis (email valide)';
        }
        $guestTz = (string) ($input['guest_timezone'] ?? '');
        if ($guestTz === '' || !in_array($guestTz, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            $errors['guest_timezone'] = 'guest_timezone requis, identifiant IANA valide';
        }

        return $errors;
    }
}
