<?php

namespace Stripe\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Stripe\Models\StripeSubscription;
use Stripe\Services\StripeService;

class BillingController
{
    public function checkout(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $appId = $input['app_id'] ?? '';
        $plan  = $input['plan']   ?? '';

        if (!$appId || !$plan) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id et plan sont requis', null, 422);
            return;
        }

        if (!in_array($plan, ['monthly', 'yearly'], true)) {
            LoggingMiddleware::logExit(422);
            Response::error('plan doit être monthly ou yearly', null, 422);
            return;
        }

        try {
            $result = StripeService::createCheckoutSession(
                $user['user_id'],
                $appId,
                $user['email'],
                $plan
            );
        } catch (\RuntimeException $e) {
            LoggingMiddleware::logExit(500);
            Response::error($e->getMessage(), null, 500);
            return;
        }

        LoggingMiddleware::logExit(200);
        Response::success('Session de paiement créée', [
            'checkout_url' => $result['checkout_url'],
            'session_id'   => $result['session_id'],
        ]);
    }

    public function portal(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $appId = $input['app_id'] ?? '';

        if (!$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id est requis', null, 422);
            return;
        }

        $customerId = (new StripeSubscription())->findStripeCustomerByUserAndApp($user['user_id'], $appId);

        if (!$customerId) {
            LoggingMiddleware::logExit(404);
            Response::error('Aucun abonnement Stripe trouvé', null, 404);
            return;
        }

        try {
            $result = StripeService::createPortalSession($customerId, $appId);
        } catch (\RuntimeException $e) {
            LoggingMiddleware::logExit(500);
            Response::error($e->getMessage(), null, 500);
            return;
        }

        LoggingMiddleware::logExit(200);
        Response::success('Session portail créée', ['portal_url' => $result['portal_url']]);
    }

    public function webhook(): void
    {
        LoggingMiddleware::logEntry();

        $payload   = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        try {
            $event = StripeService::verifyWebhookSignature($payload, $sigHeader);
        } catch (\InvalidArgumentException $e) {
            LoggingMiddleware::logExit(400);
            Response::error($e->getMessage(), null, 400);
            return;
        }

        if (StripeService::isEventProcessed($event['id'])) {
            LoggingMiddleware::logExit(200);
            Response::success('Déjà traité');
            return;
        }

        $data = $event['data']['object'] ?? [];

        switch ($event['type']) {
            case 'checkout.session.completed':
                StripeService::handleCheckoutCompleted($data);
                break;
            case 'customer.subscription.updated':
                StripeService::handleSubscriptionUpdated($data);
                break;
            case 'invoice.payment_succeeded':
                StripeService::handlePaymentSucceeded($data);
                break;
            case 'invoice.payment_failed':
                StripeService::handlePaymentFailed($data);
                break;
            case 'customer.subscription.deleted':
                StripeService::handleSubscriptionDeleted($data);
                break;
        }

        StripeService::markEventProcessed($event['id'], $event['type']);

        LoggingMiddleware::logExit(200);
        Response::success('Événement traité');
    }
}
