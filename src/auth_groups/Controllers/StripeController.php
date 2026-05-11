<?php

namespace AuthGroups\Controllers;

use AuthGroups\Services\StripeService;
use AuthGroups\Services\LogService;

/**
 * StripeController — endpoint webhook Stripe.
 *
 * Routes :
 *   POST /stripe/webhook  → réception des événements Stripe (sans JWT)
 */
class StripeController
{
    public function webhook(): void
    {
        $payload   = (string) file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        try {
            $event = StripeService::verifyWebhookSignature($payload, $sigHeader);
        } catch (\Throwable $e) {
            LogService::warning('Webhook Stripe rejeté', ['error' => $e->getMessage()]);
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            return;
        }

        $eventId = $event['id']           ?? '';
        $type    = $event['type']          ?? '';
        $obj     = $event['data']['object'] ?? [];

        // Idempotency — skip already-processed events
        if ($eventId && StripeService::isEventProcessed($eventId)) {
            http_response_code(200);
            echo json_encode(['received' => true, 'skipped' => true]);
            return;
        }

        try {
            switch ($type) {
                case 'checkout.session.completed':
                    StripeService::handleCheckoutCompleted($obj);
                    break;
                case 'customer.subscription.updated':
                    StripeService::handleSubscriptionUpdated($obj);
                    break;
                case 'invoice.payment_succeeded':
                    StripeService::handlePaymentSucceeded($obj);
                    break;
                case 'invoice.payment_failed':
                    StripeService::handlePaymentFailed($obj);
                    break;
                case 'customer.subscription.deleted':
                    StripeService::handleSubscriptionDeleted($obj);
                    break;
                default:
                    LogService::info('Webhook Stripe ignoré', ['type' => $type]);
            }
        } catch (\Throwable $e) {
            LogService::error('Erreur traitement webhook Stripe', [
                'type'  => $type,
                'error' => $e->getMessage(),
            ]);
            http_response_code(500);
            return;
        }

        if ($eventId) {
            StripeService::markEventProcessed($eventId, $type);
        }

        http_response_code(200);
        echo json_encode(['received' => true]);
    }
}
