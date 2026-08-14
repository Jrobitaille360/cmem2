<?php

namespace Booking\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Models\User;
use AuthGroups\Utils\Response;
use Booking\Models\BookingPage;
use Booking\Models\BookingSlot;
use Booking\Services\BookingSlotService;
use ICS\Models\Calendar;
use Stripe\Config\CmemModules;
use Stripe\Services\EntitlementService;

/**
 * GET/PUT/DELETE /booking/page — configuration de la page de réservation de l'hôte authentifié.
 * Directive 20260813_163000_cmem_web_vers_cmem2_API__booking-public.md, Phase 2 du plan.
 *
 * Génération/régénération des zones : Phase 3 (Booking\Services\BookingSlotService, pas encore
 * branchée ici — PUT upsert la config seulement pour l'instant).
 */
class BookingPageController
{
    private const MODULE_KEY = 'booking';
    private const DEFAULT_APP_ID = 'puzzle';

    private BookingPage $pages;
    private BookingSlot $slots;
    private Calendar $calendars;

    public function __construct()
    {
        $this->pages     = new BookingPage();
        $this->slots     = new BookingSlot();
        $this->calendars = new Calendar();
    }

    private function appId(array $params): string
    {
        $appId = trim((string) ($params['app_id'] ?? ''));
        return $appId !== '' ? $appId : self::DEFAULT_APP_ID;
    }

    private function planCode(int $userId): string
    {
        $userData = (new User())->findById($userId);
        $override = $userData['cmem_plan_override'] ?? null;
        return EntitlementService::getEffectivePlanForCmem($userId, $override)['code'];
    }

    private function toApiShape(array $row): array
    {
        return [
            'id'                     => (int) $row['id'],
            'calendar_id'            => (int) $row['calendar_id'],
            'slug'                   => $row['slug'],
            'duration_minutes'       => (int) $row['duration_minutes'],
            'buffer_before_minutes'  => (int) $row['buffer_before_minutes'],
            'buffer_after_minutes'   => (int) $row['buffer_after_minutes'],
            'timezone'               => $row['timezone'],
            'horizon_days'           => (int) $row['horizon_days'],
            'availability_windows'   => json_decode($row['availability_windows'], true) ?? [],
            'event_title_template'   => $row['event_title_template'],
            'active'                 => (bool) $row['active'],
            'created_at'             => $row['created_at'],
            'updated_at'             => $row['updated_at'],
        ];
    }

    /**
     * Valide le corps de PUT /booking/page. Retourne la liste des erreurs (vide si valide).
     */
    private function validateInput(array $input): array
    {
        $errors = [];

        if (!isset($input['calendar_id']) || !ctype_digit((string) $input['calendar_id'])) {
            $errors['calendar_id'] = 'calendar_id requis (entier)';
        }

        $slug = (string) ($input['slug'] ?? '');
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            $errors['slug'] = 'slug requis, format [a-z0-9-]+';
        }

        if (!isset($input['duration_minutes']) || !ctype_digit((string) $input['duration_minutes'])
            || (int) $input['duration_minutes'] <= 0) {
            $errors['duration_minutes'] = 'duration_minutes requis (entier positif)';
        }

        foreach (['buffer_before_minutes', 'buffer_after_minutes'] as $f) {
            if (isset($input[$f]) && (!ctype_digit((string) $input[$f]) || (int) $input[$f] < 0)) {
                $errors[$f] = "{$f} doit être un entier positif ou nul";
            }
        }

        $timezone = (string) ($input['timezone'] ?? '');
        if ($timezone === '' || !in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            $errors['timezone'] = 'timezone requis, identifiant IANA valide';
        }

        $horizon = isset($input['horizon_days']) ? (int) $input['horizon_days'] : 30;
        if ($horizon < 1 || $horizon > 90) {
            $errors['horizon_days'] = 'horizon_days doit être compris entre 1 et 90';
        }

        $windows = $input['availability_windows'] ?? null;
        if (!is_array($windows) || empty($windows)) {
            $errors['availability_windows'] = 'availability_windows requis (tableau non vide)';
        } else {
            foreach ($windows as $i => $w) {
                if (!is_array($w)
                    || !array_key_exists('weekday', $w)
                    || !ctype_digit((string) $w['weekday'])
                    || (int) $w['weekday'] < 0 || (int) $w['weekday'] > 6
                    || !isset($w['start'], $w['end'])
                    || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $w['start'])
                    || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $w['end'])
                    || $w['start'] >= $w['end']) {
                    $errors['availability_windows'] = "entrée #{$i} invalide (weekday 0-6, start < end, format HH:MM)";
                    break;
                }
            }
        }

        return $errors;
    }

    public function get(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input  = Response::getRequestParams();
        $userId = (int) $user['user_id'];
        $appId  = $this->appId($input);

        $row = $this->pages->findByOwnerAndApp($userId, $appId);
        if ($row === null) {
            LoggingMiddleware::logExit(404);
            Response::error('Aucune page de réservation configurée', ['code' => 'BOOKING_PAGE_NOT_FOUND'], 404);
            return;
        }

        LoggingMiddleware::logExit(200);
        Response::success('OK', ['page' => $this->toApiShape($row)]);
    }

    public function put(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input  = Response::getRequestParams();
        $userId = (int) $user['user_id'];
        $appId  = $this->appId($input);

        $errors = $this->validateInput($input);
        if (!empty($errors)) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', $errors, 422);
            return;
        }

        $calendarId = (int) $input['calendar_id'];
        if (!$this->calendars->isOwner($calendarId, $userId)) {
            LoggingMiddleware::logExit(403);
            Response::error("Ce calendrier n'appartient pas à l'usager", ['code' => 'CALENDAR_NOT_OWNED'], 403);
            return;
        }

        $slug = (string) $input['slug'];
        $existingBySlug = $this->pages->findBySlug($appId, $slug);
        $existingByOwner = $this->pages->findByOwnerAndApp($userId, $appId);
        if ($existingBySlug !== null && (int) $existingBySlug['owner_id'] !== $userId) {
            LoggingMiddleware::logExit(409);
            Response::error('Ce slug est déjà utilisé', ['code' => 'SLUG_TAKEN'], 409);
            return;
        }

        $active = filter_var($input['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($active) {
            $plan = $this->planCode($userId);
            if (!CmemModules::isAvailable($plan, self::MODULE_KEY)) {
                LoggingMiddleware::logExit(403);
                Response::error("Le module « booking » n'est pas inclus dans votre plan.",
                    ['code' => 'MODULE_NOT_AVAILABLE', 'module' => self::MODULE_KEY], 403);
                return;
            }
        }

        $row = $this->pages->upsert([
            'owner_id'              => $userId,
            'app_id'                => $appId,
            'calendar_id'           => $calendarId,
            'slug'                  => $slug,
            'duration_minutes'      => (int) $input['duration_minutes'],
            'buffer_before_minutes' => (int) ($input['buffer_before_minutes'] ?? 0),
            'buffer_after_minutes'  => (int) ($input['buffer_after_minutes'] ?? 0),
            'timezone'              => (string) $input['timezone'],
            'horizon_days'          => (int) ($input['horizon_days'] ?? 30),
            'availability_windows'  => json_encode($input['availability_windows']),
            'event_title_template'  => (string) ($input['event_title_template'] ?? 'Rendez-vous : {guest_name}'),
            'active'                => $active,
        ]);

        (new BookingSlotService())->regenerate((int) $row['id']);

        LoggingMiddleware::logExit($existingByOwner === null ? 201 : 200);
        Response::success('OK', ['page' => $this->toApiShape($row)], $existingByOwner === null ? 201 : 200);
    }

    public function delete(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input  = Response::getRequestParams();
        $userId = (int) $user['user_id'];
        $appId  = $this->appId($input);

        $row = $this->pages->findByOwnerAndApp($userId, $appId);
        if ($row === null) {
            LoggingMiddleware::logExit(404);
            Response::error('Aucune page de réservation configurée', ['code' => 'BOOKING_PAGE_NOT_FOUND'], 404);
            return;
        }

        $this->pages->deactivate((int) $row['id']);
        $this->slots->deleteNonReservedFutureByPage((int) $row['id']);

        LoggingMiddleware::logExit(200);
        Response::success('Page de réservation désactivée', null);
    }
}
