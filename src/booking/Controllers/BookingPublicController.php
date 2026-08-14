<?php

namespace Booking\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Models\User;
use AuthGroups\Services\RateLimitService;
use AuthGroups\Utils\Response;
use Booking\Models\BookingPage;
use Booking\Models\BookingSlot;
use Booking\Services\BookingGateService;

/**
 * Endpoints publics — GET /booking/public/{slug}[/slots]. Sans authentification.
 * Directive 20260813_163000_cmem_web_vers_cmem2_API__booking-public.md, Phase 4 du plan.
 *
 * 404 BOOKING_UNAVAILABLE uniforme sur les 3 causes (page inexistante, inactive, plan
 * rétrogradé) — volontaire, pour ne pas révéler l'état interne d'un hôte par énumération de slug.
 */
class BookingPublicController
{
    private const DEFAULT_APP_ID = 'puzzle';
    private const MAX_RANGE_DAYS = 60;

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

    /** Page existante, active, et dont le plan de l'hôte inclut toujours booking. Sinon null. */
    private function usablePage(string $appId, string $slug): ?array
    {
        $page = $this->pages->findBySlug($appId, $slug);
        return BookingGateService::isPageUsable($page) ? $page : null;
    }

    private function unavailable(): void
    {
        Response::error('Page de réservation indisponible.', ['code' => 'BOOKING_UNAVAILABLE'], 404);
    }

    public function get(string $slug): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        $appId = $this->appId($input);

        $page = $this->usablePage($appId, $slug);
        if ($page === null) {
            LoggingMiddleware::logExit(404);
            $this->unavailable();
            return;
        }

        $host = (new User())->findById((int) $page['owner_id']);

        LoggingMiddleware::logExit(200);
        Response::success('OK', [
            'host_name'        => $host['name'] ?? null,
            'duration_minutes' => (int) $page['duration_minutes'],
            'timezone'         => $page['timezone'],
        ]);
    }

    public function slots(string $slug): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        $appId = $this->appId($input);

        $ip = RateLimitService::getClientIp();
        if (!RateLimitService::check($ip, 'booking_slots', 20, 1)) {
            LoggingMiddleware::logExit(429);
            Response::error('Trop de requêtes, réessayez dans une minute.', ['code' => 'RATE_LIMITED'], 429);
            return;
        }
        RateLimitService::record($ip, 'booking_slots');

        $page = $this->usablePage($appId, $slug);
        if ($page === null) {
            LoggingMiddleware::logExit(404);
            $this->unavailable();
            return;
        }

        $from = (string) ($input['from'] ?? '');
        $to   = (string) ($input['to'] ?? '');
        $fromTs = strtotime($from);
        $toTs   = strtotime($to);

        if ($from === '' || $to === '' || $fromTs === false || $toTs === false || $toTs <= $fromTs) {
            LoggingMiddleware::logExit(422);
            Response::error('Paramètres from/to invalides', ['code' => 'INVALID_RANGE'], 422);
            return;
        }

        if (($toTs - $fromTs) > self::MAX_RANGE_DAYS * 86400) {
            LoggingMiddleware::logExit(422);
            Response::error('Plage maximale de ' . self::MAX_RANGE_DAYS . ' jours dépassée',
                ['code' => 'RANGE_TOO_WIDE'], 422);
            return;
        }

        $fromUtc = gmdate('Y-m-d H:i:s', $fromTs);
        $toUtc   = gmdate('Y-m-d H:i:s', $toTs);

        $rows = $this->slots->findFreeInRange((int) $page['id'], $fromUtc, $toUtc);

        LoggingMiddleware::logExit(200);
        Response::success('OK', [
            'duration_minutes' => (int) $page['duration_minutes'],
            'timezone'         => $page['timezone'],
            'slots'            => array_map(fn($r) => [
                'id'             => (int) $r['id'],
                'start_datetime' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['start_datetime'] . ' UTC')),
                'end_datetime'   => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['end_datetime'] . ' UTC')),
            ], $rows),
        ]);
    }
}
