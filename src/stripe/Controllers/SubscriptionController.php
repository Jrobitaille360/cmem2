<?php

namespace Stripe\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Stripe\Services\StripeSubscriptionService;

class SubscriptionController
{
    public function getStatus(array $user): void
    {
        LoggingMiddleware::logEntry();

        $appId = $_GET['app_id'] ?? '';

        if (!$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id est requis', null, 422);
            return;
        }

        $result = StripeSubscriptionService::getStatus($user['user_id'], $appId);

        LoggingMiddleware::logExit(200);
        Response::success('Statut récupéré', $result);
    }

    public function cancel(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $appId = $input['app_id'] ?? $_GET['app_id'] ?? '';

        if (!$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id est requis', null, 422);
            return;
        }

        try {
            StripeSubscriptionService::cancel($user['user_id'], $appId);
        } catch (\RuntimeException $e) {
            LoggingMiddleware::logExit(422);
            Response::error($e->getMessage(), null, 422);
            return;
        }

        LoggingMiddleware::logExit(200);
        Response::success('Abonnement Stripe annulé (fin de période)');
    }
}
