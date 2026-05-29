<?php

namespace Puzzle\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Models\AppUserSettings;
use AuthGroups\Utils\Response;
use Playstore\Models\AndroidDevice;
use WebDevice\Models\WebDevice;

/**
 * SyncController — sauvegarde en ligne (blob opaque)
 */
class SyncController
{
    private const MAX_BACKUP_BYTES = 524288; // 512 Ko

    // -----------------------------------------------------------------------
    // POST /v2/puzzle/backup  (device_token + premium)
    // -----------------------------------------------------------------------

    public function saveBackup(array $device): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        if (!isset($input['backup'])) {
            LoggingMiddleware::logExit(422);
            Response::error('Champ backup requis', null, 422);
            return;
        }

        $backupJson = json_encode($input['backup']);
        if ($backupJson === false || strlen($backupJson) > self::MAX_BACKUP_BYTES) {
            LoggingMiddleware::logExit(413);
            Response::error('Sauvegarde trop volumineuse (max 512 Ko)', null, 413);
            return;
        }

        $this->getDeviceModel($device)->saveBackup((int) $device['id'], $backupJson);

        LoggingMiddleware::logExit(200);
        Response::success('Sauvegarde enregistrée', [
            'saved_at' => date('c'),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /v2/puzzle/backup/claim  (device_token + premium)
    // -----------------------------------------------------------------------

    public function claimBackup(array $device): void
    {
        LoggingMiddleware::logEntry();
        $input     = Response::getRequestParams();
        $pseudonym = trim($input['pseudonym'] ?? '');
        $appId     = $device['app_id'] ?? 'puzzle';

        if ($pseudonym === '') {
            LoggingMiddleware::logExit(422);
            Response::error('Champ pseudonym requis', ['code' => 'PSEUDONYM_REQUIRED'], 422);
            return;
        }

        $ownerId = (new AppUserSettings())->findUserByPseudonym($appId, $pseudonym);

        if ($ownerId === null) {
            LoggingMiddleware::logExit(404);
            Response::error('Pseudonyme introuvable', ['code' => 'PSEUDONYM_NOT_FOUND'], 404);
            return;
        }

        $androidModel = new AndroidDevice();
        $webModel     = new WebDevice();

        $owner = $androidModel->findLatestWithBackupByUser($ownerId, $appId)
              ?? $webModel->findLatestWithBackupByUser($ownerId, $appId);

        if ($owner === null || $owner['backup_json'] === null) {
            LoggingMiddleware::logExit(404);
            Response::error('Aucune sauvegarde pour ce pseudonyme', ['code' => 'BACKUP_NOT_FOUND'], 404);
            return;
        }

        $this->getDeviceModel($device)->saveBackup((int) $device['id'], $owner['backup_json']);

        $backup = json_decode($owner['backup_json'], true);

        LoggingMiddleware::logExit(200);
        Response::success('Progression récupérée', [
            'pseudonym' => $pseudonym,
            'backup'    => $backup,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /v2/puzzle/backup  (device_token + premium)
    // -----------------------------------------------------------------------

    public function getBackup(array $device): void
    {
        LoggingMiddleware::logEntry();

        $backupJson = $device['backup_json'] ?? null;
        $savedAt    = $device['backup_saved_at'] ?? null;

        if ($backupJson === null) {
            LoggingMiddleware::logExit(404);
            Response::error('Aucune sauvegarde disponible', null, 404);
            return;
        }

        $backup = json_decode($backupJson, true);

        LoggingMiddleware::logExit(200);
        Response::success('Sauvegarde récupérée', [
            'backup'   => $backup,
            'saved_at' => $savedAt ? date('c', strtotime($savedAt)) : null,
        ]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function getDeviceModel(array $device): AndroidDevice|WebDevice
    {
        return ($device['_device_type'] ?? 'web') === 'android'
            ? new AndroidDevice()
            : new WebDevice();
    }
}
