<?php

namespace Puzzle\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Puzzle\Models\PuzzleDevice;
use Puzzle\Models\PuzzleImage;
use Puzzle\Models\SharedPuzzle;
use Puzzle\Services\SharedPuzzleService;

/**
 * SharedController — casse-têtes partagés entre deux appareils abonnés
 */
class SharedController
{
    private int $pollActiveWindow;
    private int $eventRetentionHours;

    public function __construct()
    {
        $this->pollActiveWindow    = (int) (defined('PUZZLE_POLL_ACTIVE_WINDOW_SECONDS') ? \PUZZLE_POLL_ACTIVE_WINDOW_SECONDS : 10);
        $this->eventRetentionHours = (int) (defined('PUZZLE_EVENT_RETENTION_HOURS')      ? \PUZZLE_EVENT_RETENTION_HOURS      : 24);
    }

    // -----------------------------------------------------------------------
    // POST /puzzle/shared  (device_token + premium)
    // -----------------------------------------------------------------------

    public function createShared(array $device): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $imageUid      = trim($input['image_uid']     ?? '');
        $pieceCount    = (int) ($input['piece_count'] ?? 0);
        $partnerPseudo = trim($input['partner_pseudo'] ?? '');

        if ($imageUid === '' || $pieceCount < 2 || $partnerPseudo === '') {
            LoggingMiddleware::logExit(422);
            Response::error('image_uid, piece_count (≥2) et partner_pseudo requis', null, 422);
            return;
        }

        // Valider image
        $image = (new PuzzleImage())->findActiveByUid($imageUid);
        if ($image === null) {
            LoggingMiddleware::logExit(404);
            Response::error('Image introuvable', null, 404);
            return;
        }

        // Trouver le partenaire (abonné actif)
        $partner      = (new PuzzleDevice())->findByPseudonymCI($partnerPseudo);
        $debugPremium = defined('PUZZLE_DEBUG_PREMIUM') && \PUZZLE_DEBUG_PREMIUM;
        $partnerOk    = $debugPremium
            || $partner && $partner['is_premium'] && strtotime($partner['premium_expires_at'] ?? '0') >= time();
        if (!$partner || !$partnerOk) {
            LoggingMiddleware::logExit(404);
            Response::error('Partenaire introuvable ou non abonné', ['code' => 'PARTNER_NOT_FOUND'], 404);
            return;
        }

        // Vérifier qu'une partie active n'existe pas déjà entre ces deux joueurs
        $sharedModel = new SharedPuzzle();
        if ($sharedModel->activeGameExists((int) $device['id'], (int) $partner['id'])) {
            LoggingMiddleware::logExit(409);
            Response::error('Une partie active existe déjà avec ce partenaire', ['code' => 'ALREADY_IN_GAME'], 409);
            return;
        }

        $sharedUid = (new SharedPuzzleService())->generateUuid();
        $imageId   = $this->getImageIdByUid($imageUid);

        $sharedId = $sharedModel->createFromData([
            'shared_uid'  => $sharedUid,
            'image_id'    => $imageId,
            'piece_count' => $pieceCount,
            'creator_id'  => (int) $device['id'],
            'partner_id'  => (int) $partner['id'],
        ]);

        // Toutes les pièces démarrent en état 'tray' (insertPieces initialise l'état)
        $sharedModel->insertPieces($sharedId, $pieceCount);

        LoggingMiddleware::logExit(201);
        Response::success('Casse-tête partagé créé', [
            'uid'            => $sharedUid,
            'image_uid'      => $imageUid,
            'piece_count'    => $pieceCount,
            'creator_pseudo' => $device['pseudonym'] ?? '',
            'partner_pseudo' => $partnerPseudo,
            'completion'     => 0,
            'is_creator'     => true,
            'partner_active' => false,
            'status'         => 'active',
        ], 201);
    }

    // -----------------------------------------------------------------------
    // GET /puzzle/shared  (device_token + premium)
    // -----------------------------------------------------------------------

    public function listShared(array $device): void
    {
        LoggingMiddleware::logEntry();

        $list = (new SharedPuzzle())->listActiveForDevice(
            (int) $device['id'],
            $this->pollActiveWindow
        );

        LoggingMiddleware::logExit(200);
        Response::success('Casse-tête partagés chargés', ['games' => $list]);
    }

    // -----------------------------------------------------------------------
    // GET /puzzle/shared/{shared_uid}/state  (device_token + premium)
    // -----------------------------------------------------------------------

    public function getState(string $sharedUid, array $device): void
    {
        LoggingMiddleware::logEntry();

        $shared = $this->resolveShared($sharedUid, $device);
        if ($shared === null) return;

        $sharedModel    = new SharedPuzzle();
        $pieces         = $sharedModel->getPieces((int) $shared['id']);
        $lastEventIdRow = $this->getLastEventId((int) $shared['id']);

        LoggingMiddleware::logExit(200);
        Response::success('État chargé', [
            'shared_uid'    => $shared['shared_uid'],
            'image_uid'     => $this->getImageUidById((int) $shared['image_id']),
            'piece_count'   => (int) $shared['piece_count'],
            'seed'          => $shared['seed'] !== null ? (int) $shared['seed'] : null,
            'completion'    => (int) $shared['completion'],
            'last_event_id' => $lastEventIdRow,
            'pieces'        => $pieces,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /puzzle/shared/{shared_uid}/pick  (device_token + premium)
    // -----------------------------------------------------------------------

    public function pick(string $sharedUid, array $device): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $shared = $this->resolveShared($sharedUid, $device);
        if ($shared === null) return;

        $pieceId = isset($input['piece_id']) ? (int) $input['piece_id'] : null;
        if ($pieceId === null) {
            LoggingMiddleware::logExit(422);
            Response::error('piece_id requis', null, 422);
            return;
        }

        $sharedModel = new SharedPuzzle();
        $result      = $sharedModel->pickPiece((int) $shared['id'], $pieceId, (int) $device['id']);

        if (!$result['ok']) {
            $httpCode = ($result['code'] === 'LOCKED') ? 423 : 409;
            LoggingMiddleware::logExit($httpCode);
            Response::error('Impossible de prendre cette pièce', ['code' => $result['code']], $httpCode);
            return;
        }

        $eventId = $sharedModel->insertEvent(
            (int) $shared['id'],
            (int) $device['id'],
            $pieceId,
            'held',
            null,
            null,
            0
        );

        LoggingMiddleware::logExit(200);
        Response::success('Pièce prise', [
            'piece_id' => $pieceId,
            'state'    => 'held',
            'held_by'  => $result['held_by'],
            'event_id' => $eventId,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /puzzle/shared/{shared_uid}/drop  (device_token + premium)
    // -----------------------------------------------------------------------

    public function drop(string $sharedUid, array $device): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $shared = $this->resolveShared($sharedUid, $device);
        if ($shared === null) return;

        $pieceId     = isset($input['piece_id']) ? (int) $input['piece_id'] : null;
        $x           = isset($input['x'])        ? (float) $input['x']      : null;
        $y           = isset($input['y'])        ? (float) $input['y']      : null;
        $rotation    = (int) ($input['rotation'] ?? 0);
        $toTray      = (bool) ($input['to_tray'] ?? false);
        $lockedHint  = (bool) ($input['locked']  ?? false);

        if ($pieceId === null || (!$toTray && ($x === null || $y === null))) {
            LoggingMiddleware::logExit(422);
            Response::error('piece_id requis ; x et y requis sauf si to_tray=true', null, 422);
            return;
        }

        $sharedModel = new SharedPuzzle();
        $result      = $sharedModel->dropPiece(
            (int) $shared['id'],
            $pieceId,
            (int) $device['id'],
            $x    ?? 0.0,
            $y    ?? 0.0,
            $rotation,
            $toTray,
            $lockedHint
        );

        if (!$result['ok']) {
            LoggingMiddleware::logExit(409);
            Response::error('Cette pièce n\'est pas tenue par vous', ['code' => $result['code']], 409);
            return;
        }

        $eventId = $sharedModel->insertEvent(
            (int) $shared['id'],
            (int) $device['id'],
            $pieceId,
            $result['state'],
            $result['x'],
            $result['y'],
            $result['rotation']
        );

        $sharedModel->purgeOldEvents($this->eventRetentionHours);

        LoggingMiddleware::logExit(200);
        Response::success('Pièce posée', [
            'piece_id'   => $pieceId,
            'state'      => $result['state'],
            'x'          => $result['x'],
            'y'          => $result['y'],
            'rotation'   => $result['rotation'],
            'completion' => $result['completion'],
            'event_id'   => $eventId,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /puzzle/shared/{shared_uid}/events?after={last_event_id}
    // -----------------------------------------------------------------------

    public function getEvents(string $sharedUid, array $device): void
    {
        LoggingMiddleware::logEntry();

        $shared = $this->resolveShared($sharedUid, $device);
        if ($shared === null) return;

        $afterEventId = (int) ($_GET['after'] ?? 0);
        $ttlSeconds   = (int) (defined('PUZZLE_HELD_TTL_SECONDS') ? \PUZZLE_HELD_TTL_SECONDS : 30);

        $sharedModel = new SharedPuzzle();

        // Expirer les pièces tenues depuis trop longtemps (opportuniste)
        $sharedModel->expireHeldPieces((int) $shared['id'], $ttlSeconds);

        [$events, $partnerActive] = $sharedModel->getPartnerEvents(
            (int) $shared['id'],
            (int) $device['id'],
            $afterEventId,
            $this->pollActiveWindow
        );

        $lastEventId = empty($events) ? $afterEventId : (int) end($events)['event_id'];

        LoggingMiddleware::logExit(200);
        Response::success(
            empty($events) ? 'Aucun événement' : 'Événements disponibles',
            [
                'events'         => $events,
                'last_event_id'  => $lastEventId,
                'completion'     => (int) $shared['completion'],
                'partner_active' => $partnerActive,
            ]
        );
    }

    // -----------------------------------------------------------------------
    // POST /puzzle/shared/{shared_uid}/leave  (device_token + premium)
    // -----------------------------------------------------------------------

    public function leave(string $sharedUid, array $device): void
    {
        LoggingMiddleware::logEntry();

        $shared = $this->resolveShared($sharedUid, $device);
        if ($shared === null) return;

        $sharedModel = new SharedPuzzle();
        $sharedModel->releaseHeldPieces((int) $shared['id'], (int) $device['id']);
        $sharedModel->archive((int) $shared['id']);

        LoggingMiddleware::logExit(200);
        Response::success('Vous avez quitté le casse-tête partagé');
    }

    // -----------------------------------------------------------------------
    // DELETE /puzzle/shared/{shared_uid}  (créateur uniquement)
    // -----------------------------------------------------------------------

    public function deleteShared(string $sharedUid, array $device): void
    {
        LoggingMiddleware::logEntry();

        $shared = $this->resolveShared($sharedUid, $device);
        if ($shared === null) return;

        if ((int) $shared['creator_id'] !== (int) $device['id']) {
            LoggingMiddleware::logExit(403);
            Response::error('Seul le créateur peut supprimer ce casse-tête', ['code' => 'NOT_CREATOR'], 403);
            return;
        }

        (new SharedPuzzle())->deleteById((int) $shared['id']);

        LoggingMiddleware::logExit(204);
        http_response_code(204);
    }

    // -----------------------------------------------------------------------
    // Helpers privés
    // -----------------------------------------------------------------------

    /** Résout le partagé ou envoie une réponse d'erreur et retourne null. */
    private function resolveShared(string $sharedUid, array $device): ?array
    {
        $shared = (new SharedPuzzle())->findActiveByUidAndDevice($sharedUid, (int) $device['id']);
        if (!$shared) {
            LoggingMiddleware::logExit(404);
            Response::error('Casse-tête partagé introuvable ou archivé', ['code' => 'SHARED_NOT_FOUND'], 404);
            return null;
        }
        return $shared;
    }

    /** Retourne l'ID interne d'une image par son UID. */
    private function getImageIdByUid(string $uid): int
    {
        return (new PuzzleImage())->getIdByUid($uid) ?? 0;
    }

    /** Retourne l'UID d'une image par son ID interne. */
    private function getImageUidById(int $id): string
    {
        return (new PuzzleImage())->getUidById($id) ?? '';
    }

    /** Retourne le dernier event_id d'un partagé. */
    private function getLastEventId(int $sharedId): int
    {
        return (new SharedPuzzle())->getLastEventId($sharedId);
    }
}
