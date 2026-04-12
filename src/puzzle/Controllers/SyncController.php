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
    // POST /puzzle/backup/claim  (device_token + premium)
    // -----------------------------------------------------------------------

    public function claimBackup(array $device): void
    {
        LoggingMiddleware::logEntry();
        $input     = Response::getRequestParams();
        $pseudonym = trim($input['pseudonym'] ?? '');

        if ($pseudonym === '') {
            LoggingMiddleware::logExit(422);
            Response::error('Champ pseudonym requis', ['code' => 'PSEUDONYM_REQUIRED'], 422);
            return;
        }

        $deviceModel = new PuzzleDevice();
        $owner = $deviceModel->findByPseudonymCI($pseudonym);

        if (!$owner) {
            LoggingMiddleware::logExit(404);
            Response::error('Pseudonyme introuvable', ['code' => 'PSEUDONYM_NOT_FOUND'], 404);
            return;
        }

        if ($owner['backup_json'] === null) {
            LoggingMiddleware::logExit(404);
            Response::error('Aucune sauvegarde pour ce pseudonyme', ['code' => 'BACKUP_NOT_FOUND'], 404);
            return;
        }

        // Copier le backup sur le device courant
        $deviceModel->saveBackup((int) $device['id'], $owner['backup_json']);

        // Transférer l'ownership du pseudonyme
        $deviceModel->clearPseudonym((int) $owner['id']);
        $deviceModel->setPseudonym((int) $device['id'], $owner['pseudonym']);

        $backup = json_decode($owner['backup_json'], true);

        LoggingMiddleware::logExit(200);
        Response::success('Progression récupérée', [
            'pseudonym' => $owner['pseudonym'],
            'backup'    => $backup,
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
