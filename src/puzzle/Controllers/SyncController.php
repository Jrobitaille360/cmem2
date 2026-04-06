<?php

namespace Puzzle\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Puzzle\Models\PuzzleDevice;

/**
 * SyncController — sauvegarde en ligne (blob opaque)
 */
class SyncController
{
    private const MAX_BACKUP_BYTES = 524288; // 512 Ko

    // -----------------------------------------------------------------------
    // POST /puzzle/backup  (device_token + premium)
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

        $deviceModel = new PuzzleDevice();
        $deviceModel->saveBackup((int) $device['id'], $backupJson);

        LoggingMiddleware::logExit(200);
        Response::success('Sauvegarde enregistrée', [
            'saved_at' => date('c'),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /puzzle/backup  (device_token + premium)
    // -----------------------------------------------------------------------

    public function getBackup(array $device): void
    {
        LoggingMiddleware::logEntry();

        $backupJson = $device['backup_json'] ?? null;
        $savedAt    = $device['updated_at']  ?? null;

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
}
