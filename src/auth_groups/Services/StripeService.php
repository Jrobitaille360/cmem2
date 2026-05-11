<?php

namespace AuthGroups\Services;

use AuthGroups\Models\Subscription;

/**
 * StripeService — création de sessions Checkout et traitement des webhooks.
 *
 * Implémentation HTTP native (sans SDK) pour éviter les dépendances externes.
 * Vérification de signature webhook : HMAC-SHA256(secret, timestamp.payload).
 */
class StripeService
{
    private const API_BASE = 'https://api.stripe.com';

    // -----------------------------------------------------------------------
    // Checkout
    // -----------------------------------------------------------------------

    /**
     * Crée une Stripe Checkout Session avec essai de 7 jours.
     *
     * @return array { checkout_url: string, session_id: string }
     */
    public static function createCheckoutSession(
        int    $userId,
        string $userEmail,
        string $appId,
        string $plan
    ): array {
        $priceId = ($plan === 'yearly')
            ? (defined('STRIPE_PRICE_PUZZLE_YEARLY')  ? \STRIPE_PRICE_PUZZLE_YEARLY  : '')
            : (defined('STRIPE_PRICE_PUZZLE_MONTHLY') ? \STRIPE_PRICE_PUZZLE_MONTHLY : '');

        if (!$priceId) {
            throw new \RuntimeException("STRIPE_PRICE_PUZZLE_" . strtoupper($plan) . " non configuré");
        }

        $customerId = self::getOrCreateCustomer($userId, $userEmail);

        $params = http_build_query([
            'customer'                                   => $customerId,
            'mode'                                       => 'subscription',
            'payment_method_types'                       => ['card'],
            'line_items'                                 => [['price' => $priceId, 'quantity' => 1]],
            'subscription_data'                          => [
                'trial_period_days' => 7,
                'metadata'          => ['user_id' => (string) $userId, 'app_id' => $appId],
            ],
            'success_url'                                => 'https://journauxdebord.com/puzzle/subscription/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'                                 => 'https://journauxdebord.com/puzzle/subscription/cancel',
            'client_reference_id'                        => (string) $userId,
        ]);

        $session = self::request('POST', '/v1/checkout/sessions', $params);

        return [
            'checkout_url' => $session['url'],
            'session_id'   => $session['id'],
        ];
    }

    /**
     * Retrouve ou crée un Stripe Customer lié à ce user_id.
     */
    public static function getOrCreateCustomer(int $userId, string $userEmail): string
    {
        $model    = new Subscription();
        $existing = $model->findStripeCustomerByUserId($userId);
        if ($existing) {
            return $existing;
        }

        $params   = http_build_query([
            'email'             => $userEmail,
            'metadata'          => ['user_id' => (string) $userId],
        ]);
        $customer = self::request('POST', '/v1/customers', $params);
        return $customer['id'];
    }

    // -----------------------------------------------------------------------
    // Billing Portal
    // -----------------------------------------------------------------------

    /**
     * Crée une session Stripe Billing Portal pour un customer existant.
     *
     * @return array { portal_url: string }
     */
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
    // Webhook — vérification de signature
    // -----------------------------------------------------------------------

    /**
     * Vérifie la signature Stripe et retourne l'événement décodé.
     *
     * @throws \InvalidArgumentException si la signature est invalide ou expirée
     */
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
        require_once __DIR__ . '/../database.php';
        $db   = \Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT 1 FROM stripe_processed_events WHERE event_id = ? LIMIT 1');
        $stmt->execute([$eventId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function markEventProcessed(string $eventId, string $eventType): void
    {
        require_once __DIR__ . '/../database.php';
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
        $userId  = (int) ($session['client_reference_id'] ?? 0);
        $appId   = $session['metadata']['app_id'] ?? 'puzzle';
        $subId   = $session['subscription'] ?? null;
        $custId  = $session['customer']     ?? null;

        if (!$userId || !$subId) {
            return;
        }

        $model = new Subscription();
        $model->upsert([
            'user_id'         => $userId,
            'app_id'          => $appId,
            'provider'        => 'stripe',
            'product_id'      => 'stripe_subscription',
            'stripe_sub_id'   => $subId,
            'stripe_customer' => $custId,
            'plan'            => 'monthly',
            'is_premium'      => 1,
            'show_ads'        => 0,
            'is_trial'        => 1,
            'trial_end'       => date('Y-m-d H:i:s', strtotime('+7 days')),
            'started_at'      => date('Y-m-d H:i:s'),
            'expires_at'      => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);

        LogService::info('Stripe checkout.session.completed', [
            'user_id' => $userId,
            'sub_id'  => $subId,
            'app_id'  => $appId,
        ]);
    }

    public static function handleSubscriptionUpdated(array $sub): void
    {
        $subId     = $sub['id'] ?? null;
        $status    = $sub['status'] ?? '';
        $isTrial   = ($status === 'trialing') ? 1 : 0;
        $trialEnd  = !empty($sub['trial_end'])
            ? date('Y-m-d H:i:s', $sub['trial_end'])
            : null;
        $expiresAt = !empty($sub['current_period_end'])
            ? date('Y-m-d H:i:s', $sub['current_period_end'])
            : null;
        $isPremium = in_array($status, ['trialing', 'active', 'past_due'], true) ? 1 : 0;
        $interval  = $sub['items']['data'][0]['price']['recurring']['interval'] ?? 'month';
        $plan      = ($interval === 'year') ? 'yearly' : 'monthly';

        $userId  = (int) ($sub['metadata']['user_id'] ?? 0);
        $appId   = $sub['metadata']['app_id']  ?? 'puzzle';
        $custId  = $sub['customer']            ?? null;

        if (!$subId) {
            return;
        }

        $fields = [
            'is_premium' => $isPremium,
            'show_ads'   => $isPremium ? 0 : 1,
            'is_trial'   => $isTrial,
            'trial_end'  => $trialEnd,
            'expires_at' => $expiresAt,
            'plan'       => $plan,
            'status'     => $isPremium ? 'active' : 'expired',
        ];

        $model = new Subscription();
        if ($userId && $appId) {
            // Upsert when user context available (covers new subscriptions)
            $model->upsert(array_merge($fields, [
                'user_id'         => $userId,
                'app_id'          => $appId,
                'provider'        => 'stripe',
                'product_id'      => 'stripe_subscription',
                'stripe_sub_id'   => $subId,
                'stripe_customer' => $custId,
                'started_at'      => date('Y-m-d H:i:s'),
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

        $model = new Subscription();
        $model->updateByStripeSubId($subId, [
            'is_premium' => 1,
            'show_ads'   => 0,
            'is_trial'   => 0,
            'trial_end'  => null,
            'status'     => 'active',
            'expires_at' => $expiresAt,
        ]);

        LogService::info('Stripe invoice.payment_succeeded', ['sub_id' => $subId]);
    }

    public static function handlePaymentFailed(array $invoice): void
    {
        $subId     = $invoice['subscription']       ?? null;
        $willRetry = isset($invoice['next_payment_attempt']);

        if (!$subId || $willRetry) {
            return;
        }

        $model = new Subscription();
        $model->updateByStripeSubId($subId, [
            'is_premium' => 0,
            'show_ads'   => 1,
            'status'     => 'past_due',
        ]);

        LogService::warning('Stripe invoice.payment_failed — accès révoqué', ['sub_id' => $subId]);
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

        $model = new Subscription();
        $model->updateByStripeSubId($subId, [
            'is_premium' => 0,
            'show_ads'   => 1,
            'is_trial'   => 0,
            'status'     => 'cancelled',
            'expires_at' => $expiresAt,
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
