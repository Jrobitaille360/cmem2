<?php

namespace Playstore\Services;

use AuthGroups\Services\LogService;
use Playstore\Models\PlaystoreSubscription;

class PlaystoreSubscriptionService
{
    public static function verify(
        int    $userId,
        string $appId,
        string $purchaseToken,
        string $productId
    ): array {
        $result = (new GooglePlayService())->validateSubscription($appId, $productId, $purchaseToken);

        if ($result === null) {
            throw new \RuntimeException('Token Google Play invalide ou inaccessible');
        }

        $isPremium  = (bool) $result['is_premium'];
        $status     = $isPremium ? 'active' : 'expired';
        $expiresAt  = $result['expires_at'] ?? null;
        $verifiedAt = $isPremium ? date('Y-m-d H:i:s') : null;
        $finalProductId = $result['product_id'] ?? $productId;

        (new PlaystoreSubscription())->upsertSubscription(
            $userId,
            $appId,
            $purchaseToken,
            $finalProductId,
            $status,
            $expiresAt,
            $verifiedAt
        );

        LogService::info('PlaystoreSubscriptionService::verify', [
            'user_id'    => $userId,
            'app_id'     => $appId,
            'product_id' => $finalProductId,
            'status'     => $status,
        ]);

        return [
            'is_premium' => $isPremium,
            'status'     => $status,
            'expires_at' => $expiresAt,
            'product_id' => $finalProductId,
        ];
    }

    public static function getStatus(int $userId, string $appId): array
    {
        $model = new PlaystoreSubscription();

        $model->expireStale($userId, $appId);

        $row = $model->findLatestActive($userId, $appId);

        if ($row && $row['expires_at'] !== null) {
            $expiresTs  = strtotime($row['expires_at']);
            $soonThresh = time() + 86400; // 24h

            if ($expiresTs < $soonThresh) {
                $gpResult = (new GooglePlayService())->validateSubscription(
                    $appId,
                    $row['product_id'],
                    $row['purchase_token']
                );

                if ($gpResult !== null) {
                    $isPremium  = (bool) $gpResult['is_premium'];
                    $status     = $isPremium ? 'active' : 'expired';
                    $expiresAt  = $gpResult['expires_at'] ?? null;
                    $verifiedAt = $isPremium ? date('Y-m-d H:i:s') : null;

                    $model->upsertSubscription(
                        $userId,
                        $appId,
                        $row['purchase_token'],
                        $row['product_id'],
                        $status,
                        $expiresAt,
                        $verifiedAt
                    );

                    $row = $model->findLatestActive($userId, $appId);
                }
            }
        }

        if (!$row) {
            return [
                'is_premium' => false,
                'status'     => null,
                'expires_at' => null,
                'product_id' => null,
                'provider'   => 'playstore',
            ];
        }

        return [
            'is_premium' => $row['status'] === 'active',
            'status'     => $row['status'],
            'expires_at' => $row['expires_at'],
            'product_id' => $row['product_id'],
            'provider'   => 'playstore',
        ];
    }
}
