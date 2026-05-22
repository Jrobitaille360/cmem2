<?php

namespace Playstore\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Models\AppUserSettings;
use AuthGroups\Utils\Response;
use Playstore\Models\AndroidDevice;

class DeviceController
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

        $deviceToken    = bin2hex(random_bytes(32));
        $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+365 days'));

        $device    = (new AndroidDevice())->upsertDevice($userId, $appId, $uuid, $deviceToken, $tokenExpiresAt);
        $pseudonym = $userId ? (new AppUserSettings())->get($userId, $appId) : null;

        LoggingMiddleware::logExit(200);
        Response::success('Device enregistré', [
            'device_token' => $device['device_token'],
            'expires_at'   => $device['token_expires_at'],
            'pseudonym'    => $pseudonym,
        ]);
    }

    public function getPseudonym(array $user): void
    {
        LoggingMiddleware::logEntry();
        $userId = $user['user_id'];
        $appId  = $_GET['app_id'] ?? '';

        if (!$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id requis', null, 422);
            return;
        }

        $pseudonym = (new AppUserSettings())->get($userId, $appId);

        LoggingMiddleware::logExit(200);
        Response::success('Pseudonyme récupéré', ['pseudonym' => $pseudonym]);
    }

    public function setPseudonym(array $user): void
    {
        LoggingMiddleware::logEntry();
        $input     = Response::getRequestParams();
        $userId    = $user['user_id'];
        $appId     = $input['app_id'] ?? '';
        $pseudonym = $input['pseudonym'] ?? '';

        if (!$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id requis', null, 422);
            return;
        }

        if (strlen($pseudonym) < 2 || strlen($pseudonym) > 64) {
            LoggingMiddleware::logExit(422);
            Response::error('pseudonym doit contenir entre 2 et 64 caractères', null, 422);
            return;
        }

        if (!preg_match('/^[\p{L}\p{N}_.\-]+$/u', $pseudonym)) {
            LoggingMiddleware::logExit(422);
            Response::error('pseudonym contient des caractères invalides', null, 422);
            return;
        }

        $model = new AppUserSettings();

        if (!$model->isAvailable($appId, $pseudonym, $userId)) {
            LoggingMiddleware::logExit(409);
            Response::error('Pseudonyme déjà pris', null, 409);
            return;
        }

        try {
            $model->set($userId, $appId, $pseudonym);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                LoggingMiddleware::logExit(409);
                Response::error('Pseudonyme déjà pris', null, 409);
                return;
            }
            throw $e;
        }

        LoggingMiddleware::logExit(200);
        Response::success('Pseudonyme défini', ['pseudonym' => $pseudonym]);
    }

    public function deletePseudonym(array $user): void
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

        (new AppUserSettings())->clear($userId, $appId);

        LoggingMiddleware::logExit(200);
        Response::success('Pseudonyme supprimé');
    }

    public function checkPseudonym(array $user, string $pseudo): void
    {
        LoggingMiddleware::logEntry();
        $userId = $user['user_id'];
        $appId  = $_GET['app_id'] ?? '';

        if (!$appId) {
            LoggingMiddleware::logExit(422);
            Response::error('app_id requis', null, 422);
            return;
        }

        $available = (new AppUserSettings())->isAvailable($appId, $pseudo, $userId);

        LoggingMiddleware::logExit(200);
        Response::success('Disponibilité vérifiée', ['available' => $available]);
    }
}
