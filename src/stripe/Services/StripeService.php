<?php

namespace Stripe\Services;

use AuthGroups\Services\LogService;
use Stripe\Models\StripeSubscription;

class StripeService
{
    private const API_BASE = 'https://api.stripe.com';

    // -----------------------------------------------------------------------
    // Checkout
    // -----------------------------------------------------------------------

    public static function createCheckoutSession(
        int    $userId,
        string $appId,
        string $userEmail,
        string $plan
    ): array {
        $priceConst = 'STRIPE_PRICE_' . strtoupper($appId) . '_' . strtoupper($plan);
        $priceId    = defined($priceConst) ? constant($priceConst) : '';

        if (!$priceId) {
            throw new \RuntimeException("{$priceConst} non configuré");
        }

        $customerId = self::getOrCreateCustomer($userId, $appId, $userEmail);

        $params = http_build_query([
            'customer'             => $customerId,
            'mode'                 => 'subscription',
            'payment_method_types' => ['card'],
            'line_items'           => [['price' => $priceId, 'quantity' => 1]],
            'subscription_data'    => [
                'trial_period_days' => 7,
                'metadata'          => ['user_id' => (string) $userId, 'app_id' => $appId],
            ],
            'success_url'          => 'https://journauxdebord.com/' . $appId . '/subscription/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'           => 'https://journauxdebord.com/' . $appId . '/subscription/cancel',
            'client_reference_id'  => (string) $userId,
        ]);

        $session = self::request('POST', '/v1/checkout/sessions', $params);

        return [
            'checkout_url' => $session['url'],
            'session_id'   => $session['id'],
        ];
    }

    public static function getOrCreateCustomer(int $userId, string $appId, string $userEmail): string
    {
        $model    = new StripeSubscription();
        $existing = $model->findStripeCustomerByUserAndApp($userId, $appId);
        if ($existing) {
            return $existing;
        }

        $params   = http_build_query([
            'email'    => $userEmail,
            'metadata' => ['user_id' => (string) $userId, 'app_id' => $appId],
        ]);
        $customer = self::request('POST', '/v1/customers', $params);
        return $customer['id'];
    }

    // -----------------------------------------------------------------------
    // Billing Portal
    // -----------------------------------------------------------------------

    public static function createPortalSession(string $customerId, string $appId): array
    {
        $params = http_build_query([
            'customer'   => $customerId,
            'return_url' => 'https://journauxdebord.com/' . $appId . '/subscription/manage-return',
        ]);

        $session = self::request('POST', '/v1/billing_portal/sessions', $params);
        return ['portal_url' => $session['url']];
    }

    // -----------------------------------------------------------------------
    // Cancel
    // -----------------------------------------------------------------------

    public static function cancelSubscription(string $stripeSubId): void
    {
        $params = http_build_query(['cancel_at_period_end' => 'true']);
        self::request('POST', '/v1/subscriptions/' . $stripeSubId, $params);

        (new StripeSubscription())->updateByStripeSubId($stripeSubId, ['cancel_at_period_end' => 1]);
    }

    // -----------------------------------------------------------------------
    // Webhook — vérification de signature
    // -----------------------------------------------------------------------

    public static function verifyWebhookSignature(string $payload, string $sigHeader): array
    {
        $secret = defined('STRIPE_WEBHOOK_SECRET') ? \STRIPE_WEBHOOK_SECRET : '';
        if (!$secret) {
            throw new \RuntimeException('STRIPE_WEBHOOK_SECRET non configuré');
        }

        $parts = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$k, $v]  = explode('=', $part, 2) + ['', ''];
            $parts[$k] = $v;
        }

        $timestamp = $parts['t']  ?? '';
        $v1        = $parts['v1'] ?? '';

        if (!$timestamp || !$v1) {
            throw new \InvalidArgumentException('En-tête Stripe-Signature malformé');
        }

        if (abs(time() - (int) $timestamp) > 300) {
            throw new \InvalidArgumentException('Timestamp Stripe expiré (> 300 s)');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        if (!hash_equals($expected, $v1)) {
            throw new \InvalidArgumentException('Signature Stripe invalide');
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new \InvalidArgumentException('Payload Stripe non JSON');
        }

        return $event;
    }

    // -----------------------------------------------------------------------
    // Idempotency helpers
    // -----------------------------------------------------------------------

    public static function isEventProcessed(string $eventId): bool
    {
        require_once __DIR__ . '/../../auth_groups/database.php';
        $db   = \Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT 1 FROM stripe_processed_events WHERE event_id = ? LIMIT 1');
        $stmt->execute([$eventId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function markEventProcessed(string $eventId, string $eventType): void
    {
        require_once __DIR__ . '/../../auth_groups/database.php';
        $db   = \Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            'INSERT IGNORE INTO stripe_processed_events (event_id, event_type) VALUES (?, ?)'
        );
        $stmt->execute([$eventId, $eventType]);
    }

    // -----------------------------------------------------------------------
    // Handlers d'événements webhook
    // -----------------------------------------------------------------------

    public static function handleCheckoutCompleted(array $session): void
    {
        $userId = (int) ($session['client_reference_id'] ?? 0);
        $appId  = $session['metadata']['app_id'] ?? 'puzzle';
        $subId  = $session['subscription'] ?? null;
        $custId = $session['customer']     ?? null;

        if (!$userId || !$subId) {
            return;
        }

        (new StripeSubscription())->upsert([
            'user_id'                => $userId,
            'app_id'                 => $appId,
            'stripe_customer_id'     => $custId,
            'stripe_subscription_id' => $subId,
            'plan'                   => 'monthly',
            'status'                 => 'trialing',
            'is_trial'               => 1,
            'trial_end'              => date('Y-m-d H:i:s', strtotime('+7 days')),
            'expires_at'             => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);

        LogService::info('Stripe checkout.session.completed', [
            'user_id' => $userId,
            'sub_id'  => $subId,
            'app_id'  => $appId,
        ]);
    }

    public static function handleSubscriptionUpdated(array $sub): void
    {
        $subId    = $sub['id'] ?? null;
        $status   = $sub['status'] ?? '';
        $isTrial  = ($status === 'trialing') ? 1 : 0;
        $trialEnd = !empty($sub['trial_end'])
            ? date('Y-m-d H:i:s', $sub['trial_end'])
            : null;
        $expiresAt = !empty($sub['current_period_end'])
            ? date('Y-m-d H:i:s', $sub['current_period_end'])
            : null;
        $interval = $sub['items']['data'][0]['price']['recurring']['interval'] ?? 'month';
        $plan     = ($interval === 'year') ? 'yearly' : 'monthly';

        $userId = (int) ($sub['metadata']['user_id'] ?? 0);
        $appId  = $sub['metadata']['app_id']  ?? 'puzzle';
        $custId = $sub['customer']            ?? null;

        $isPremiumStatus = in_array($status, ['trialing', 'active', 'past_due'], true);
        $dbStatus = $isPremiumStatus ? $status : 'expired';

        if (!$subId) {
            return;
        }

        $model  = new StripeSubscription();
        $fields = [
            'status'    => $dbStatus,
            'is_trial'  => $isTrial,
            'trial_end' => $trialEnd,
            'expires_at'=> $expiresAt,
            'plan'      => $plan,
        ];

        if ($userId && $appId) {
            $model->upsert(array_merge($fields, [
                'user_id'                => $userId,
                'app_id'                 => $appId,
                'stripe_customer_id'     => $custId,
                'stripe_subscription_id' => $subId,
            ]));
        } else {
            $model->updateByStripeSubId($subId, $fields);
        }

        LogService::info('Stripe subscription.updated', ['sub_id' => $subId, 'status' => $status]);
    }

    public static function handlePaymentSucceeded(array $invoice): void
    {
        $subId     = $invoice['subscription'] ?? null;
        $periodEnd = $invoice['lines']['data'][0]['period']['end'] ?? null;
        $expiresAt = $periodEnd ? date('Y-m-d H:i:s', $periodEnd) : null;

        if (!$subId) {
            return;
        }

        (new StripeSubscription())->updateByStripeSubId($subId, [
            'is_trial'  => 0,
            'trial_end' => null,
            'status'    => 'active',
            'expires_at'=> $expiresAt,
        ]);

        LogService::info('Stripe invoice.payment_succeeded', ['sub_id' => $subId]);
    }

    public static function handlePaymentFailed(array $invoice): void
    {
        $subId     = $invoice['subscription']        ?? null;
        $willRetry = isset($invoice['next_payment_attempt']);

        if (!$subId || $willRetry) {
            return;
        }

        (new StripeSubscription())->updateByStripeSubId($subId, ['status' => 'past_due']);

        LogService::warning('Stripe invoice.payment_failed', ['sub_id' => $subId]);
    }

    public static function handleSubscriptionDeleted(array $sub): void
    {
        $subId     = $sub['id'] ?? null;
        $expiresAt = !empty($sub['current_period_end'])
            ? date('Y-m-d H:i:s', $sub['current_period_end'])
            : date('Y-m-d H:i:s');

        if (!$subId) {
            return;
        }

        (new StripeSubscription())->updateByStripeSubId($subId, [
            'status'    => 'cancelled',
            'expires_at'=> $expiresAt,
        ]);

        LogService::info('Stripe subscription.deleted', ['sub_id' => $subId]);
    }

    // -----------------------------------------------------------------------
    // HTTP interne
    // -----------------------------------------------------------------------

    private static function request(string $method, string $path, string $body = ''): array
    {
        $key = defined('STRIPE_SECRET_KEY') ? \STRIPE_SECRET_KEY : '';
        if (!$key) {
            throw new \RuntimeException('STRIPE_SECRET_KEY non configuré');
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", [
                    'Authorization: Bearer ' . $key,
                    'Content-Type: application/x-www-form-urlencoded',
                    'Stripe-Version: 2024-04-10',
                    'Content-Length: ' . strlen($body),
                ]) . "\r\n",
                'content'       => $body,
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(self::API_BASE . $path, false, $ctx);

        if ($response === false) {
            throw new \RuntimeException('Requête Stripe échouée (connexion)');
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            throw new \RuntimeException('Erreur Stripe : ' . ($data['error']['message'] ?? 'inconnue'));
        }

        return $data;
    }
}
