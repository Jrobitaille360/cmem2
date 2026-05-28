<?php

namespace AuthGroups\Controllers;

use AuthGroups\Services\SubscriptionService;
use AuthGroups\Services\LogService;
use AuthGroups\Models\Subscription;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\Validator;
use AuthGroups\Middleware\LoggingMiddleware;
use Exception;

/**
 * Contrôleur des abonnements Premium.
 *
 * Routes :
 *   GET    /subscription/status          → statut de toutes les apps (JWT requis)
 *   GET    /subscription/status?app_id=  → statut d'une app précise (JWT requis)
 *   POST   /subscription/verify          → validation provider + activation (JWT requis)
 *   POST   /subscription/checkout        → création session Stripe (JWT requis)
 *   POST   /subscription/portal          → session Stripe Billing Portal (JWT requis)
 *   DELETE /subscription/cancel          → annulation d'un abonnement (JWT requis)
 */
class SubscriptionController
{
    // -----------------------------------------------------------------------
    // GET /subscription/status[?app_id=xxx]
    // -----------------------------------------------------------------------

    public function getStatus(array $request): void
    {
        LoggingMiddleware::logEntry();

        $userId = (int) $request['user']['user_id'];
        $appId  = trim($_GET['app_id'] ?? '');

        if ($appId !== '') {
            $status = SubscriptionService::getStatus($userId, $appId);

            if ($status['provider'] === 'google_play' && class_exists(\Puzzle\Services\GooglePlayService::class)) {
                $active = (new Subscription())->findActive($userId, $appId);
                if ($active && !empty($active['purchase_token'])) {
                    $status = $this->syncGooglePlayStatus($userId, $appId, $status, $active);
                }
            }

            LoggingMiddleware::logExit(200);
            Response::success('Statut Premium récupéré', ['app_id' => $appId] + $status);
            return;
        }

        $statuses = SubscriptionService::getAllStatuses($userId);
        LoggingMiddleware::logExit(200);
        Response::success('Statuts Premium récupérés', ['subscriptions' => $statuses]);
    }

    private function syncGooglePlayStatus(int $userId, string $appId, array $status, array $active): array
    {
        try {
            $result = (new \Puzzle\Services\GooglePlayService())->validateSubscription(
                $active['purchase_token'],
                $active['product_id'] ?? ''
            );

            if ($result === null) {
                LogService::warning('GooglePlay sync échoué (fail-safe) — statut DB conservé', [
                    'user_id' => $userId,
                    'app_id'  => $appId,
                ]);
                return $status;
            }

            $isPremium = (bool) $result['is_premium'];
            $expiresAt = $result['expires_at'];

            (new Subscription())->renewByPurchaseToken(
                $active['purchase_token'],
                $appId,
                $expiresAt,
                $isPremium
            );

            LogService::info('GooglePlay sync — statut mis à jour', [
                'user_id'    => $userId,
                'app_id'     => $appId,
                'is_premium' => $isPremium,
                'expires_at' => $expiresAt,
            ]);

            return [
                'is_premium' => $isPremium,
                'show_ads'   => !$isPremium,
                'is_trial'   => (bool) ($result['is_trial'] ?? false),
                'trial_end'  => $result['trial_end'] ?? null,
                'expires_at' => $expiresAt,
                'provider'   => 'google_play',
                'plan'       => $status['plan'],
            ];

        } catch (\Throwable $e) {
            LogService::warning('GooglePlay sync exception (fail-safe)', [
                'user_id' => $userId,
                'app_id'  => $appId,
                'error'   => $e->getMessage(),
            ]);
            return $status;
        }
    }

    // -----------------------------------------------------------------------
    // POST /subscription/verify
    // Body : { app_id, provider, product_id, plan, purchase_token?, stripe_sub_id? }
    // -----------------------------------------------------------------------

    public function verify(array $request): void
    {
        LoggingMiddleware::logEntry();

        $userId = (int) $request['user']['user_id'];
        $input  = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'app_id'     => 'required|string',
            'provider'   => 'required|string',
            'product_id' => 'required|string',
            'plan'       => 'required|string',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        $provider = $input['provider'];
        $allowed  = ['stripe', 'google_play', 'apple', 'microsoft'];
        if (!in_array($provider, $allowed, true)) {
            LoggingMiddleware::logExit(400);
            Response::error('Provider invalide', ['provider' => 'Valeurs acceptées : ' . implode(', ', $allowed)], 400);
            return;
        }

        $plan     = $input['plan'];
        $allowedPlans = ['monthly', 'yearly'];
        if (!in_array($plan, $allowedPlans, true)) {
            LoggingMiddleware::logExit(400);
            Response::error('Plan invalide', ['plan' => 'Valeurs acceptées : ' . implode(', ', $allowedPlans)], 400);
            return;
        }

        try {
            $durationDays = ($plan === 'yearly') ? 365 : 31;
            $startedAt    = date('Y-m-d H:i:s');
            $expiresAt    = date('Y-m-d H:i:s', strtotime("+{$durationDays} days"));

            SubscriptionService::activatePremium($userId, $input['app_id'], [
                'provider'       => $provider,
                'product_id'     => $input['product_id'],
                'plan'           => $plan,
                'started_at'     => $startedAt,
                'expires_at'     => $expiresAt,
                'purchase_token' => $input['purchase_token'] ?? null,
                'stripe_sub_id'  => $input['stripe_sub_id']  ?? null,
                'is_trial'       => isset($input['is_trial'])  ? (int) $input['is_trial']  : 0,
                'trial_end'      => $input['trial_end'] ?? null,
            ]);

            $status = SubscriptionService::getStatus($userId, $input['app_id']);

            LogService::info('Abonnement activé via /subscription/verify', [
                'user_id'  => $userId,
                'app_id'   => $input['app_id'],
                'provider' => $provider,
            ]);

            LoggingMiddleware::logExit(200);
            Response::success('Abonnement Premium activé', ['app_id' => $input['app_id']] + $status);

        } catch (Exception $e) {
            LogService::error('Erreur activation abonnement', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de l\'activation de l\'abonnement', null, 500);
        }
    }

    // -----------------------------------------------------------------------
    // POST /subscription/checkout  — DÉPRÉCIÉE depuis v2.7.0
    // Utiliser POST /v2/billing/checkout
    // -----------------------------------------------------------------------

    public function checkout(array $request): void
    {
        LoggingMiddleware::logEntry();
        LoggingMiddleware::logExit(410);
        Response::error('Route dépréciée — utiliser POST /v2/billing/checkout', null, 410);
    }

    // -----------------------------------------------------------------------
    // POST /subscription/portal  — DÉPRÉCIÉE depuis v2.7.0
    // Utiliser POST /v2/billing/portal
    // -----------------------------------------------------------------------

    public function portal(array $request): void
    {
        LoggingMiddleware::logEntry();
        LoggingMiddleware::logExit(410);
        Response::error('Route dépréciée — utiliser POST /v2/billing/portal', null, 410);
    }

    // -----------------------------------------------------------------------
    // DELETE /subscription/cancel
    // Body : { app_id }
    // -----------------------------------------------------------------------

    public function cancel(array $request): void
    {
        LoggingMiddleware::logEntry();

        $userId = (int) $request['user']['user_id'];
        $input  = Response::getRequestParams();

        $validation = Validator::validate($input, [
            'app_id' => 'required|string',
        ]);

        if (!$validation['valid']) {
            LoggingMiddleware::logExit(400);
            Response::error('Données invalides', $validation['errors'], 400);
            return;
        }

        try {
            SubscriptionService::deactivatePremium($userId, trim($input['app_id']));

            LoggingMiddleware::logExit(200);
            Response::success('Abonnement annulé — l\'accès Premium reste actif jusqu\'à la fin de la période payée');

        } catch (Exception $e) {
            LogService::error('Erreur annulation abonnement', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de l\'annulation', null, 500);
        }
    }
}
