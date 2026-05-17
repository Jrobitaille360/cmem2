<?php

namespace Playstore\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Playstore\Models\PlaystoreSubscription;
use Playstore\Services\PlaystoreSubscriptionService;

class SubscriptionController
{
    public function verify(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input  = Response::getRequestParams();
        $userId = $user['user_id'];

        $purchaseToken = $input['purchase_token'] ?? '';
        $productId     = $input['product_id']     ?? '';
        $appId         = $input['app_id']          ?? '';

        if (!$purchaseToken || !$productId || !$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('purchase_token, product_id et app_id requis', null, 422);
            return;
        }

        try {
            $result = PlaystoreSubscriptionService::verify($userId, $appId, $purchaseToken, $productId);
        } catch (\RuntimeException $e) {
            LoggingMiddleware::logExit(422);
            Response::error($e->getMessage(), null, 422);
            return;
        }

        LoggingMiddleware::logExit(200);
        Response::success('Abonnement vérifié', $result);
    }

    public function getStatus(array $user): void
    {
        LoggingMiddleware::logEntry();
        $userId = $user['user_id'];
        $appId  = $_GET['app_id'] ?? '';

        if (!$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id requis', null, 422);
            return;
        }

        $result = PlaystoreSubscriptionService::getStatus($userId, $appId);

        LoggingMiddleware::logExit(200);
        Response::success('Statut récupéré', $result);
    }

    public function cancel(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input  = Response::getRequestParams();
        $userId = $user['user_id'];
        $appId  = $input['app_id'] ?? $_GET['app_id'] ?? '';

        if (!$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id requis', null, 422);
            return;
        }

        (new PlaystoreSubscription())->markCancelled($userId, $appId);

        LoggingMiddleware::logExit(200);
        Response::success('Abonnement annulé');
    }
}
