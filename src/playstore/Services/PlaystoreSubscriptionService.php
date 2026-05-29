<?php

namespace Playstore\Services;

use AuthGroups\Services\LogService;
use Playstore\Models\PlaystoreSubscription;

class PlaystoreSubscriptionService
{
    public static function verify(
        string $callerDeviceUuid,
        string $appId,
        string $purchaseToken,
        string $productId,
        ?string $linkedPurchaseToken = null
    ): array {
        $result = (new GooglePlayService())->validateSubscription($appId, $productId, $purchaseToken);

        if ($result === null) {
            throw new \RuntimeException('Token Google Play invalide ou inaccessible');
        }

        // Upgrade/downgrade: expire the previous token before upserting the new one.
        // Google validation must succeed first — never expire old token on a rejected new token.
        if ($linkedPurchaseToken) {
            $model   = new PlaystoreSubscription();
            $expired = $model->expireByToken($linkedPurchaseToken, $appId);
            if (!$expired) {
                LogService::warning('PlaystoreSubscriptionService::verify', [
                    'msg'                   => 'linked_purchase_token not found in DB',
                    'linked_purchase_token' => $linkedPurchaseToken,
                    'app_id'                => $appId,
                ]);
            }
        }

        // obfuscatedExternalAccountId = device_uuid de l'appareil original (achat initial).
        // Sur nouvel appareil : Google retourne le device_uuid original → upsert sur ce uuid.
        // Si absent (achat sans setObfuscatedAccountId) : fallback sur l'appareil appelant.
        $ownerDeviceUuid = $result['device_uuid'] ?? $callerDeviceUuid;

        $isPremium      = (bool) $result['is_premium'];
        $status         = $isPremium ? 'active' : 'expired';
        $expiresAt      = $result['expires_at'] ?? null;
        $verifiedAt     = $isPremium ? gmdate('Y-m-d H:i:s') : null;
        $finalProductId = $result['product_id'] ?? $productId;

        (new PlaystoreSubscription())->upsertSubscription(
            $ownerDeviceUuid,
            $appId,
            $purchaseToken,
            $finalProductId,
            $status,
            $expiresAt,
            $verifiedAt
        );

        LogService::info('PlaystoreSubscriptionService::verify', [
            'owner_device_uuid'      => $ownerDeviceUuid,
            'caller_device_uuid'     => $callerDeviceUuid,
            'app_id'                 => $appId,
            'product_id'             => $finalProductId,
            'status'                 => $status,
            'linked_purchase_token'  => $linkedPurchaseToken,
        ]);

        return [
            'is_premium' => $isPremium,
            'status'     => $status,
            'expires_at' => $expiresAt,
            'product_id' => $finalProductId,
        ];
    }

    public static function getStatus(string $deviceUuid, string $appId): array
    {
        $model = new PlaystoreSubscription();
        $row   = $model->findByDevice($deviceUuid, $appId);

        if (!$row) {
            return [
                'is_premium' => false,
                'status'     => null,
                'expires_at' => null,
                'product_id' => null,
                'provider'   => 'playstore',
            ];
        }

        $expiresTs = $row['expires_at'] ? strtotime($row['expires_at']) : null;
        $now       = time();

        // Revalidate via Google Play if active and expires within 7 days (or already past).
        // This covers the grace-period case where Google extends expiryTime while the local
        // expires_at still shows the original date.
        $needsRevalidation = $row['status'] === 'active'
            && $expiresTs !== null
            && $expiresTs <= ($now + 7 * 86400);

        if ($needsRevalidation) {
            $gpResult = (new GooglePlayService())->validateSubscription(
                $appId,
                $row['product_id'],
                $row['purchase_token']
            );

            if ($gpResult !== null) {
                $isPremium  = (bool) $gpResult['is_premium'];
                $status     = $isPremium ? 'active' : 'expired';
                $expiresAt  = $gpResult['expires_at'] ?? null;
                $verifiedAt = $isPremium ? gmdate('Y-m-d H:i:s') : null;

                $model->upsertSubscription(
                    $deviceUuid,
                    $appId,
                    $row['purchase_token'],
                    $row['product_id'],
                    $status,
                    $expiresAt,
                    $verifiedAt
                );

                return [
                    'is_premium' => $isPremium,
                    'status'     => $status,
                    'expires_at' => $expiresAt,
                    'product_id' => $row['product_id'],
                    'provider'   => 'playstore',
                ];
            }
        }

        // Fallback: Google unreachable or no revalidation needed — derive from DB.
        $isPremium = $row['status'] === 'active'
            && $expiresTs !== null
            && $expiresTs > $now;

        return [
            'is_premium' => $isPremium,
            'status'     => $row['status'],
            'expires_at' => $row['expires_at'],
            'product_id' => $row['product_id'],
            'provider'   => 'playstore',
        ];
    }
}
