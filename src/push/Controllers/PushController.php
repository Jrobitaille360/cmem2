<?php

namespace Push\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Push\Models\PushSubscription;
use Push\Services\WebPushService;

/**
 * Contrôleur Web Push — directive cmem_web 20260726_140426.
 *
 *   GET    /push/vapid-public-key  → clé publique VAPID (applicationServerKey côté client)
 *   POST   /push/subscribe         → enregistre ou actualise la subscription de l'appareil
 *   DELETE /push/subscribe         → désabonne l'appareil
 *
 * GET|PUT /push/preferences retiré en #497 (→ 404) : remplacé par /notifications/defaults
 * et /notifications/devices (#487).
 *
 * Toutes les routes exigent un JWT valide (middleware de BaseRouteHandler).
 * Portée owner-strict : un usager ne voit et ne supprime que ses propres subscriptions.
 */
class PushController
{
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
}
