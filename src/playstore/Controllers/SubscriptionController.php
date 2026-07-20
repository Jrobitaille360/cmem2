<?php

namespace Playstore\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Playstore\Models\PlaystoreSubscription;
use Playstore\Services\PlaystoreSubscriptionService;

class SubscriptionController
{
    public function verify(array $device): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $purchaseToken       = $input['purchase_token']       ?? '';
        $productId           = $input['product_id']           ?? '';
        $appId               = $input['app_id']               ?? '';
        $linkedPurchaseToken = $input['linked_purchase_token'] ?? null;

        if (!$purchaseToken || !$productId || !$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('purchase_token, product_id et app_id requis', null, 422);
            return;
        }

        if ($appId === 'puzzle') {
            LoggingMiddleware::logExit(410);
            Response::error('PROVIDER_DISABLED — puzzle ne supporte plus Google Play, utiliser Stripe', null, 410);
            return;
        }

        try {
            $result = PlaystoreSubscriptionService::verify(
                $device['device_uuid'],
                $appId,
                $purchaseToken,
                $productId,
                $linkedPurchaseToken ?: null
            );
        } catch (\RuntimeException $e) {
            LoggingMiddleware::logExit(422);
            Response::error($e->getMessage(), null, 422);
            return;
        }

        LoggingMiddleware::logExit(200);
        Response::success('Abonnement vérifié', $result);
    }

    public function getStatus(array $device): void
    {
        LoggingMiddleware::logEntry();
        $appId = $_GET['app_id'] ?? '';

        if (!$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id requis', null, 422);
            return;
        }

        if ($appId === 'puzzle') {
            LoggingMiddleware::logExit(410);
            Response::error('PROVIDER_DISABLED — puzzle ne supporte plus Google Play, utiliser Stripe', null, 410);
            return;
        }

        $result = PlaystoreSubscriptionService::getStatus($device['device_uuid'], $appId);

        LoggingMiddleware::logExit(200);
        Response::success('Statut récupéré', $result);
    }

    public function cancel(array $device): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();
        $appId = $input['app_id'] ?? $_GET['app_id'] ?? '';

        if (!$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id requis', null, 422);
            return;
        }

        if ($appId === 'puzzle') {
            LoggingMiddleware::logExit(410);
            Response::error('PROVIDER_DISABLED — puzzle ne supporte plus Google Play, utiliser Stripe', null, 410);
            return;
        }

        (new PlaystoreSubscription())->markCancelled($device['device_uuid'], $appId);

        LoggingMiddleware::logExit(200);
        Response::success('Abonnement annulé');
    }
}
