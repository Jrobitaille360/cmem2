<?php

namespace Stripe\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Models\Group;
use AuthGroups\Utils\Response;
use AuthGroups\Utils\RoleHelper;
use Stripe\Models\StripeSubscription;
use Stripe\Services\StripeService;

class BillingController
{
    /**
     * true si $userId peut administrer le groupe $groupId (admin du groupe OU rôle système
     * ADMINISTRATEUR+) — même garde que GroupManagerController::update()/delete().
     */
    private function isGroupAdmin(int $groupId, int $userId, string $userRole): bool
    {
        return (new Group())->isGroupAdmin($groupId, $userId) || RoleHelper::isAtLeast($userRole, 'ADMINISTRATEUR');
    }

    public function checkout(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $appId   = $input['app_id']   ?? '';
        $plan    = $input['plan']     ?? '';
        $groupId = isset($input['group_id']) ? (int) $input['group_id'] : null;

        if (!$appId || !$plan) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id et plan sont requis', null, 422);
            return;
        }

        $allowedPlans = $groupId ? ['team'] : ['monthly', 'yearly'];
        if (!in_array($plan, $allowedPlans, true)) {
            LoggingMiddleware::logExit(422);
            Response::error(
                $groupId ? 'plan doit être team pour un abonnement de groupe' : 'plan doit être monthly ou yearly',
                null,
                422
            );
            return;
        }

        if ($groupId && !$this->isGroupAdmin($groupId, $user['user_id'], $user['role'])) {
            LoggingMiddleware::logExit(403);
            Response::error('Seul un admin du groupe peut initier cet abonnement', [
                'code' => 'GROUP_ADMIN_REQUIRED',
            ], 403);
            return;
        }

        try {
            $result = StripeService::createCheckoutSession(
                $user['user_id'],
                $appId,
                $user['email'],
                $plan,
                $groupId
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

        $appId   = $input['app_id'] ?? '';
        $groupId = isset($input['group_id']) ? (int) $input['group_id'] : null;

        if (!$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id est requis', null, 422);
            return;
        }

        if ($groupId && !$this->isGroupAdmin($groupId, $user['user_id'], $user['role'])) {
            LoggingMiddleware::logExit(403);
            Response::error('Seul un admin du groupe peut gérer cet abonnement', [
                'code' => 'GROUP_ADMIN_REQUIRED',
            ], 403);
            return;
        }

        $customerId = $groupId
            ? (new StripeSubscription())->findStripeCustomerByGroupAndApp($groupId, $appId)
            : (new StripeSubscription())->findStripeCustomerByUserAndApp($user['user_id'], $appId);

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
            Response::success('Déjà traité', ['skipped' => true]);
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
        Response::success('Événement traité', ['received' => true]);
    }
}
