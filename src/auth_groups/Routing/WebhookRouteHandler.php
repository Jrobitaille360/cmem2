<?php

namespace AuthGroups\Routing;

use AuthGroups\Controllers\LicenseController;
use AuthGroups\Utils\Response;
use AuthGroups\Services\LogService;

/**
 * Handler pour les webhooks de paiement (Stripe, PayPal, etc.)
 */
class WebhookRouteHandler
{
    private $licenseController;

    public function __construct()
    {
        $this->licenseController = new LicenseController();
    }

    /**
     * Router principal pour les webhooks
     */
    public function handleRequest($path, $method)
    {
        // Webhook de paiement
        if ($method === 'POST' && $path === 'webhook/payment') {
            return $this->handlePaymentWebhook();
        }

        // Webhook Stripe spécifique
        if ($method === 'POST' && $path === 'webhook/stripe') {
            return $this->handleStripeWebhook();
        }

        // Webhook PayPal spécifique
        if ($method === 'POST' && $path === 'webhook/paypal') {
            return $this->handlePayPalWebhook();
        }

        return Response::error('Webhook endpoint not found', null, 404);
    }

    /**
     * Gérer le webhook de paiement générique
     */
    private function handlePaymentWebhook()
    {
        try {
            // Récupérer le payload brut
            $payload = file_get_contents('php://input');
            $data = json_decode($payload, true);

            // Vérifier que les données sont valides
            if (!$data || !isset($data['event'])) {
                LogService::warning('Webhook payment: payload invalide', ['payload' => $payload]);
                return Response::error('Invalid payload', null, 400);
            }

            // Logger la réception du webhook (sans données sensibles)
            LogService::info('Webhook payment reçu', [
                'event' => $data['event'] ?? 'unknown',
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // Traiter selon le type d'événement
            switch ($data['event']) {
                case 'payment.success':
                case 'payment.completed':
                    return $this->handlePaymentSuccess($data);

                case 'payment.failed':
                    return $this->handlePaymentFailed($data);

                case 'subscription.renewed':
                    return $this->handleSubscriptionRenewed($data);

                case 'subscription.cancelled':
                    return $this->handleSubscriptionCancelled($data);

                default:
                    LogService::info('Webhook event non géré', ['event' => $data['event']]);
                    return Response::success(['message' => 'Event acknowledged but not processed']);
            }
        } catch (\Exception $e) {
            LogService::error('Erreur webhook payment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Response::error('Webhook processing failed', $e->getMessage(), 500);
        }
    }

    /**
     * Gérer le webhook Stripe
     */
    private function handleStripeWebhook()
    {
        try {
            $payload = file_get_contents('php://input');
            $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

            // Vérifier la signature Stripe
            if (!$this->verifyStripeSignature($payload, $sigHeader)) {
                LogService::warning('Stripe webhook: signature invalide');
                return Response::error('Invalid signature', null, 401);
            }

            $event = json_decode($payload, true);

            LogService::info('Stripe webhook reçu', [
                'type' => $event['type'] ?? 'unknown',
                'id' => $event['id'] ?? 'unknown'
            ]);

            // Traiter selon le type d'événement Stripe
            switch ($event['type']) {
                case 'checkout.session.completed':
                case 'payment_intent.succeeded':
                    $session = $event['data']['object'];
                    $userId = $session['metadata']['user_id'] ?? null;
                    $plan = $session['metadata']['plan'] ?? 'standard';

                    if ($userId) {
                        return $this->licenseController->generateLicenseAfterPayment($userId, $plan);
                    }
                    break;

                case 'customer.subscription.deleted':
                    $subscription = $event['data']['object'];
                    $userId = $subscription['metadata']['user_id'] ?? null;

                    if ($userId) {
                        return $this->licenseController->revokeLicense($userId, 'Subscription cancelled');
                    }
                    break;

                default:
                    LogService::info('Stripe event non géré', ['type' => $event['type']]);
            }

            return Response::success(['message' => 'Webhook processed']);
        } catch (\Exception $e) {
            LogService::error('Erreur webhook Stripe', [
                'error' => $e->getMessage()
            ]);
            return Response::error('Webhook processing failed', $e->getMessage(), 500);
        }
    }

    /**
     * Gérer le webhook PayPal
     */
    private function handlePayPalWebhook()
    {
        try {
            $payload = file_get_contents('php://input');
            $headers = getallheaders();

            // Vérifier la signature PayPal
            if (!$this->verifyPayPalSignature($payload, $headers)) {
                LogService::warning('PayPal webhook: signature invalide');
                return Response::error('Invalid signature', null, 401);
            }

            $event = json_decode($payload, true);

            LogService::info('PayPal webhook reçu', [
                'event_type' => $event['event_type'] ?? 'unknown',
                'id' => $event['id'] ?? 'unknown'
            ]);

            // Traiter selon le type d'événement PayPal
            switch ($event['event_type']) {
                case 'PAYMENT.SALE.COMPLETED':
                case 'CHECKOUT.ORDER.APPROVED':
                    $resource = $event['resource'];
                    $userId = $resource['custom_id'] ?? null; // User ID passé en custom_id
                    $plan = $resource['description'] ?? 'standard';

                    if ($userId) {
                        return $this->licenseController->generateLicenseAfterPayment($userId, $plan);
                    }
                    break;

                case 'BILLING.SUBSCRIPTION.CANCELLED':
                    $subscription = $event['resource'];
                    $userId = $subscription['custom_id'] ?? null;

                    if ($userId) {
                        return $this->licenseController->revokeLicense($userId, 'Subscription cancelled');
                    }
                    break;

                default:
                    LogService::info('PayPal event non géré', ['event_type' => $event['event_type']]);
            }

            return Response::success(['message' => 'Webhook processed']);
        } catch (\Exception $e) {
            LogService::error('Erreur webhook PayPal', [
                'error' => $e->getMessage()
            ]);
            return Response::error('Webhook processing failed', $e->getMessage(), 500);
        }
    }

    /**
     * Traiter le succès d'un paiement
     */
    private function handlePaymentSuccess($data)
    {
        $userId = $data['user_id'] ?? null;
        $plan = $data['plan'] ?? 'standard';

        if (!$userId) {
            return Response::error('Missing user_id', null, 400);
        }

        LogService::info('Traitement paiement réussi', [
            'user_id' => $userId,
            'plan' => $plan
        ]);

        return $this->licenseController->generateLicenseAfterPayment($userId, $plan);
    }

    /**
     * Traiter l'échec d'un paiement
     */
    private function handlePaymentFailed($data)
    {
        $userId = $data['user_id'] ?? null;
        $reason = $data['reason'] ?? 'Payment failed';

        LogService::warning('Paiement échoué', [
            'user_id' => $userId,
            'reason' => $reason
        ]);

        // Optionnel: envoyer un email à l'utilisateur
        // $this->notifyPaymentFailed($userId, $reason);

        return Response::success(['message' => 'Payment failure recorded']);
    }

    /**
     * Traiter le renouvellement d'un abonnement
     */
    private function handleSubscriptionRenewed($data)
    {
        $userId = $data['user_id'] ?? null;
        $plan = $data['plan'] ?? 'standard';

        if (!$userId) {
            return Response::error('Missing user_id', null, 400);
        }

        LogService::info('Renouvellement abonnement', [
            'user_id' => $userId,
            'plan' => $plan
        ]);

        return $this->licenseController->renewLicense($userId, $plan);
    }

    /**
     * Traiter l'annulation d'un abonnement
     */
    private function handleSubscriptionCancelled($data)
    {
        $userId = $data['user_id'] ?? null;

        if (!$userId) {
            return Response::error('Missing user_id', null, 400);
        }

        LogService::info('Annulation abonnement', [
            'user_id' => $userId
        ]);

        return $this->licenseController->revokeLicense($userId, 'Subscription cancelled by user');
    }

    /**
     * Vérifier la signature Stripe
     */
    private function verifyStripeSignature($payload, $sigHeader)
    {
        // Récupérer le secret depuis les variables d'environnement
        $endpointSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

        if (empty($endpointSecret)) {
            LogService::warning('STRIPE_WEBHOOK_SECRET non configuré');
            return false;
        }

        try {
            // Si vous utilisez la SDK Stripe
            // \Stripe\Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
            
            // Version manuelle (si pas de SDK)
            $elements = explode(',', $sigHeader);
            $signature = [];
            
            foreach ($elements as $element) {
                list($key, $value) = explode('=', $element, 2);
                if ($key === 't') {
                    $signature['timestamp'] = $value;
                } elseif ($key === 'v1') {
                    $signature['v1'] = $value;
                }
            }

            if (!isset($signature['timestamp']) || !isset($signature['v1'])) {
                return false;
            }

            // Vérifier que le timestamp n'est pas trop ancien (5 minutes)
            $timestamp = $signature['timestamp'];
            if (abs(time() - $timestamp) > 300) {
                LogService::warning('Stripe webhook: timestamp trop ancien');
                return false;
            }

            // Calculer la signature attendue
            $signedPayload = $timestamp . '.' . $payload;
            $expectedSignature = hash_hmac('sha256', $signedPayload, $endpointSecret);

            // Comparer les signatures (protection contre timing attacks)
            return hash_equals($expectedSignature, $signature['v1']);
            
        } catch (\Exception $e) {
            LogService::error('Erreur vérification signature Stripe', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Vérifier la signature PayPal
     */
    private function verifyPayPalSignature($payload, $headers)
    {
        // Récupérer les informations depuis les variables d'environnement
        $webhookId = $_ENV['PAYPAL_WEBHOOK_ID'] ?? '';

        if (empty($webhookId)) {
            LogService::warning('PAYPAL_WEBHOOK_ID non configuré');
            return false;
        }

        try {
            // Headers nécessaires pour la vérification PayPal
            $transmissionId = $headers['Paypal-Transmission-Id'] ?? $headers['PAYPAL-TRANSMISSION-ID'] ?? '';
            $transmissionTime = $headers['Paypal-Transmission-Time'] ?? $headers['PAYPAL-TRANSMISSION-TIME'] ?? '';
            $transmissionSig = $headers['Paypal-Transmission-Sig'] ?? $headers['PAYPAL-TRANSMISSION-SIG'] ?? '';
            $certUrl = $headers['Paypal-Cert-Url'] ?? $headers['PAYPAL-CERT-URL'] ?? '';
            $authAlgo = $headers['Paypal-Auth-Algo'] ?? $headers['PAYPAL-AUTH-ALGO'] ?? '';

            if (empty($transmissionId) || empty($transmissionSig)) {
                return false;
            }

            // Si vous utilisez PayPal SDK, utilisez leur méthode de vérification
            // Sinon, implémentez la vérification manuelle selon la documentation PayPal
            
            // Pour l'instant, on accepte (à implémenter selon vos besoins)
            LogService::info('Vérification PayPal signature', [
                'transmission_id' => $transmissionId,
                'webhook_id' => $webhookId
            ]);

            return true; // TODO: Implémenter la vérification complète
            
        } catch (\Exception $e) {
            LogService::error('Erreur vérification signature PayPal', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
