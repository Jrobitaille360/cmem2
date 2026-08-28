<?php

namespace Push\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Push\Models\NotificationPref;
use Push\Models\PushSubscription;
use Push\Services\WebPushService;

/**
 * Contrôleur Web Push — directive cmem_web 20260726_140426.
 *
 *   GET    /push/vapid-public-key  → clé publique VAPID (applicationServerKey côté client)
 *   POST   /push/subscribe         → enregistre ou actualise la subscription de l'appareil
 *   DELETE /push/subscribe         → désabonne l'appareil
 *   GET    /push/preferences       → préférences du COMPTE (4 kinds, défauts inclus)
 *   PUT    /push/preferences       → met à jour les préférences
 *
 * Toutes les routes exigent un JWT valide (middleware de BaseRouteHandler).
 * Portée owner-strict : un usager ne voit et ne supprime que ses propres subscriptions.
 */
class PushController
{
    private const DEFAULT_APP_ID = 'puzzle';

    private function appId(array $params): string
    {
        $appId = trim((string) ($params['app_id'] ?? ''));
        return $appId !== '' ? $appId : self::DEFAULT_APP_ID;
    }

    // ------------------------------------------------------------------
    // GET /push/vapid-public-key
    // ------------------------------------------------------------------
    public function vapidPublicKey(array $user): void
    {
        LoggingMiddleware::logEntry();

        $publicKey = WebPushService::publicKey();
        if ($publicKey === '') {
            LoggingMiddleware::logExit(503);
            Response::error('Web Push non configuré sur ce serveur', null, 503);
            return;
        }

        LoggingMiddleware::logExit(200);
        Response::success('Clé publique VAPID', ['publicKey' => $publicKey]);
    }

    // ------------------------------------------------------------------
    // POST /push/subscribe
    // ------------------------------------------------------------------
    public function subscribe(array $user): void
    {
        LoggingMiddleware::logEntry();
        $params = Response::getRequestParams();
        $errors = [];

        $appId = trim((string) ($params['app_id'] ?? ''));
        if ($appId === '') {
            $errors['app_id'] = 'app_id requis';
        }

        $endpoint = trim((string) ($params['endpoint'] ?? ''));
        if ($endpoint === '') {
            $errors['endpoint'] = 'endpoint requis';
        } elseif (!preg_match('#^https://#i', $endpoint) || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            $errors['endpoint'] = 'endpoint doit être une URL https valide';
        } elseif (strlen($endpoint) > 2048) {
            $errors['endpoint'] = 'endpoint trop long (max 2048)';
        }

        $keys   = is_array($params['keys'] ?? null) ? $params['keys'] : [];
        $p256dh = trim((string) ($keys['p256dh'] ?? ''));
        $auth   = trim((string) ($keys['auth'] ?? ''));

        if ($p256dh === '') {
            $errors['keys.p256dh'] = 'clé publique client (p256dh) requise';
        }
        if ($auth === '') {
            $errors['keys.auth'] = 'secret d\'authentification (auth) requis';
        }

        $deviceLabel = isset($params['device_label']) && trim((string) $params['device_label']) !== ''
            ? mb_substr(trim((string) $params['device_label']), 0, 190)
            : null;

        if (!empty($errors)) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', $errors, 422);
            return;
        }

        $result = (new PushSubscription())->upsert(
            (int) $user['user_id'],
            $appId,
            $endpoint,
            $p256dh,
            $auth,
            $deviceLabel
        );

        $status = $result['created'] ? 201 : 200;
        LoggingMiddleware::logExit($status);
        Response::success(
            $result['created'] ? 'Subscription enregistrée' : 'Subscription mise à jour',
            ['subscription' => PushSubscription::toContract($result['subscription'])],
            $status
        );
    }

    // ------------------------------------------------------------------
    // DELETE /push/subscribe
    // ------------------------------------------------------------------
    public function unsubscribe(array $user): void
    {
        LoggingMiddleware::logEntry();
        $params   = Response::getRequestParams();
        $endpoint = trim((string) ($params['endpoint'] ?? ''));

        if ($endpoint === '') {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', ['endpoint' => 'endpoint requis'], 422);
            return;
        }

        $deleted = (new PushSubscription())->deleteByOwnerAndEndpoint((int) $user['user_id'], $endpoint);

        if (!$deleted) {
            LoggingMiddleware::logExit(404);
            Response::error('Subscription non trouvée', null, 404);
            return;
        }

        // 204 No Content : aucune enveloppe JSON (le corps doit rester vide).
        LoggingMiddleware::logExit(204);
        http_response_code(204);
        exit;
    }

    // ------------------------------------------------------------------
    // GET /push/preferences
    // ------------------------------------------------------------------
    public function getPreferences(array $user): void
    {
        LoggingMiddleware::logEntry();
        $params = Response::getRequestParams();

        $prefs = (new NotificationPref())->findByOwnerWithDefaults(
            (int) $user['user_id'],
            $this->appId($params)
        );

        LoggingMiddleware::logExit(200);
        Response::success('Préférences de notification', [
            'scope'       => 'account',
            'preferences' => $prefs,
        ]);
    }

    // ------------------------------------------------------------------
    // PUT /push/preferences
    // ------------------------------------------------------------------
    public function putPreferences(array $user): void
    {
        LoggingMiddleware::logEntry();
        $params = Response::getRequestParams();
        $appId  = $this->appId($params);

        $incoming = $params['preferences'] ?? null;
        if (!is_array($incoming) || empty($incoming)) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', ['preferences' => 'liste de préférences requise'], 422);
            return;
        }

        $errors    = [];
        $validated = [];

        foreach ($incoming as $i => $pref) {
            if (!is_array($pref)) {
                $errors["preferences.{$i}"] = 'objet attendu';
                continue;
            }

            $kind = (string) ($pref['kind'] ?? '');
            if (!in_array($kind, NotificationPref::KINDS, true)) {
                $errors["preferences.{$i}.kind"] =
                    'kind invalide (attendu : ' . implode(', ', NotificationPref::KINDS) . ')';
                continue;
            }

            $lead = (int) ($pref['lead_minutes'] ?? NotificationPref::DEFAULT_LEAD);
            if (!in_array($lead, NotificationPref::LEAD_MINUTES, true)) {
                $errors["preferences.{$i}.lead_minutes"] =
                    'lead_minutes invalide (attendu : ' . implode(', ', NotificationPref::LEAD_MINUTES) . ')';
                continue;
            }

            $quietFrom = self::normalizeTime($pref['quiet_from'] ?? null);
            $quietTo   = self::normalizeTime($pref['quiet_to'] ?? null);

            if ($quietFrom === false || $quietTo === false) {
                $errors["preferences.{$i}.quiet"] = 'format horaire attendu HH:MM';
                continue;
            }
            if (($quietFrom === null) !== ($quietTo === null)) {
                $errors["preferences.{$i}.quiet"] = 'quiet_from et quiet_to doivent être fournis ensemble';
                continue;
            }

            $validated[$kind] = [
                'kind'                => $kind,
                'lead_minutes'        => $lead,
                'quiet_from'          => $quietFrom,
                'quiet_to'            => $quietTo,
                'enabled'             => (bool) ($pref['enabled'] ?? false),
                'show_entity_detail'  => (bool) ($pref['show_entity_detail'] ?? false),
            ];
        }

        if (!empty($errors)) {
            LoggingMiddleware::logExit(422);
            Response::error('Données invalides', $errors, 422);
            return;
        }

        $model = new NotificationPref();
        foreach ($validated as $pref) {
            $model->upsert(
                (int) $user['user_id'],
                $appId,
                $pref['kind'],
                $pref['lead_minutes'],
                $pref['quiet_from'],
                $pref['quiet_to'],
                $pref['enabled'],
                $pref['show_entity_detail']
            );
        }

        LoggingMiddleware::logExit(200);
        Response::success('Préférences mises à jour', [
            'scope'       => 'account',
            'preferences' => $model->findByOwnerWithDefaults((int) $user['user_id'], $appId),
        ]);
    }

    /** null si absent, false si format invalide, sinon 'HH:MM:00'. */
    private static function normalizeTime($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', trim($value))) {
            return false;
        }
        return trim($value) . ':00';
    }
}
