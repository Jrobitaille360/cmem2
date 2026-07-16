<?php

namespace WebDevice\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Models\AppUserSettings;
use AuthGroups\Utils\Response;
use Stripe\Services\EntitlementService;
use WebDevice\Models\WebDevice;

class WebDeviceController
{
    public function register(?array $user): void
    {
        LoggingMiddleware::logEntry();
        $input  = Response::getRequestParams();
        $userId = $user['user_id'] ?? null;
        $appId  = $input['app_id'] ?? '';
        $uuid   = $input['device_uuid'] ?? '';

        if (!$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id requis', null, 422);
            return;
        }

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            LoggingMiddleware::logExit(422);
            Response::error('device_uuid invalide (format UUID v4 requis)', null, 422);
            return;
        }

        $deviceModel   = new WebDevice();
        $existingDevice = $deviceModel->findByAppAndUuid($appId, $uuid);

        if (!$existingDevice && $userId && $appId === 'cmem') {
            $quotaError = EntitlementService::checkQuota(
                $userId,
                'max_devices',
                $deviceModel->countByUserAndApp($userId, $appId)
            );
            if ($quotaError) {
                LoggingMiddleware::logExit(403);
                Response::error('Quota d\'appareils atteint', $quotaError, 403);
                return;
            }
        }

        $deviceToken    = bin2hex(random_bytes(32));
        $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+365 days'));

        $device    = $deviceModel->upsertDevice($userId, $appId, $uuid, $deviceToken, $tokenExpiresAt);
        $pseudonym = $userId ? (new AppUserSettings())->get($userId, $appId) : null;

        LoggingMiddleware::logExit(200);
        Response::success('Device enregistré', [
            'device_token' => $device['device_token'],
            'expires_at'   => $device['token_expires_at'],
            'pseudonym'    => $pseudonym,
        ]);
    }
}
