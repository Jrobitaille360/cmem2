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
        $this->pollActiveWindow    = (int) (defined('PUZZLE_POLL_ACTIVE_WINDOW_SECONDS') ? PUZZLE_POLL_ACTIVE_WINDOW_SECONDS : 10);
        $this->eventRetentionHours = (int) (defined('PUZZLE_EVENT_RETENTION_HOURS')      ? PUZZLE_EVENT_RETENTION_HOURS      : 24);
    }

    // -----------------------------------------------------------------------
    // POST /puzzle/shared  (device_token + premium)
    // -----------------------------------------------------------------------

    public function createShared(array $device): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $imageUid         = trim($input['image_uid']        ?? '');
        $pieceCount       = (int) ($input['piece_count']    ?? 0);
        $partnerPseudonym = trim($input['partner_pseudonym'] ?? '');
        $initialPieces    = $input['initial_pieces'] ?? null;

        if ($imageUid === '' || $pieceCount < 2 || $partnerPseudonym === '') {
            LoggingMiddleware::logExit(422);
            Response::error('image_uid, piece_count (≥2) et partner_pseudonym requis', null, 422);
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
        $partner = (new PuzzleDevice())->findByPseudonym($partnerPseudonym);
        if (!$partner || !$partner['is_premium'] || strtotime($partner['premium_expires_at'] ?? '0') < time()) {
            LoggingMiddleware::logExit(404);
            Response::error('Partenaire introuvable ou non abonné', ['code' => 'PARTNER_NOT_FOUND'], 404);
            return;
        }

        $svc = new SharedPuzzleService();

        if ($initialPieces !== null && is_array($initialPieces)) {
            $seed   = null;
            $pieces = $initialPieces;
        } else {
            $seed   = $svc->generateSeed();
            $pieces = $svc->generatePiecesFromSeed($pieceCount, $seed);
        }

        $sharedUid = $svc->generateUuid();

        // Récupérer l'id interne de l'image
        $imageInternal = (new PuzzleImage())->findActiveByUid($imageUid);
        // On a besoin de l'id interne — refaire une requête directe
        $imageId = $this->getImageIdByUid($imageUid);

        $sharedModel = new SharedPuzzle();
        $sharedId    = $sharedModel->createFromData([
            'shared_uid' => $sharedUid,
            'image_id'   => $imageId,
            'piece_count' => $pieceCount,
            'seed'        => $seed,
            'creator_id'  => (int) $device['id'],
            'partner_id'  => (int) $partner['id'],
        ]);

        $sharedModel->insertPieces($sharedId, $pieces);

        LoggingMiddleware::logExit(201);
        Response::success('Casse-tête partagé créé', [
            'shared_uid'        => $sharedUid,
            'image_uid'         => $imageUid,
            'image_label'       => $image['label'],
            'piece_count'       => $pieceCount,
            'seed'              => $seed,
            'creator_pseudonym' => $device['pseudonym'],
            'partner_pseudonym' => $partnerPseudonym,
            'created_at'        => date('c'),
        ], 201);
    }

    // -----------------------------------------------------------------------
    // GET /puzzle/shared  (device_token + premium)
    // -----------------------------------------------------------------------

    public function listShared(array $device): void
    {
        LoggingMiddleware::logEntry();

        $list = (new SharedPuzzle())->listActiveForDevice((int) $device['id']);

        LoggingMiddleware::logExit(200);
        Response::success('Casse-tête partagés chargés', ['shared_puzzles' => $list]);
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
    // POST /puzzle/shared/{shared_uid}/move  (device_token + premium)
    // -----------------------------------------------------------------------

    public function move(string $sharedUid, array $device): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $shared = $this->resolveShared($sharedUid, $device);
        if ($shared === null) return;

        $pieceId  = isset($input['piece_id']) ? (int) $input['piece_id'] : null;
        $x        = isset($input['x'])        ? (float) $input['x']        : null;
        $y        = isset($input['y'])        ? (float) $input['y']        : null;
        $rotation = (int) ($input['rotation'] ?? 0);
        $locked   = (bool) ($input['locked']  ?? false);

        if ($pieceId === null || $x === null || $y === null) {
            LoggingMiddleware::logExit(422);
            Response::error('piece_id, x, y requis', null, 422);
            return;
        }

        $sharedModel = new SharedPuzzle();
        $completion  = $sharedModel->movePiece((int) $shared['id'], $pieceId, $x, $y, $rotation, $locked);
        $eventId     = $sharedModel->insertEvent((int) $shared['id'], (int) $device['id'], $pieceId, $x, $y, $rotation, $locked);

        // Purge opportuniste des anciens événements
        $sharedModel->purgeOldEvents($this->eventRetentionHours);

        LoggingMiddleware::logExit(200);
        Response::success('Mouvement enregistré', [
            'event_id'   => $eventId,
            'completion' => $completion,
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

        $sharedModel = new SharedPuzzle();
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

        (new SharedPuzzle())->archive((int) $shared['id']);

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

        LoggingMiddleware::logExit(200);
        Response::success('Casse-tête partagé supprimé');
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
