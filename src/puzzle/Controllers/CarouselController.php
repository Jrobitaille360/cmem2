<?php

namespace Puzzle\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Puzzle\Models\PuzzleImage;
use Puzzle\Models\PuzzleDevice;

/**
 * CarouselController — carrousel principal, remplacement d'images
 */
class CarouselController
{
    // -----------------------------------------------------------------------
    // GET /puzzle/carousel  (device_token)
    // -----------------------------------------------------------------------

    public function getCarousel(array $device): void
    {
        LoggingMiddleware::logEntry();

        $lang   = $this->resolveLang();
        $images = (new PuzzleImage())->getCarousel($lang);

        LoggingMiddleware::logExit(200);
        Response::success('Carrousel chargé', [
            'images' => $images,
            'total'  => count($images),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /puzzle/carousel/replace-one  (device_token, gratuit, 1/jour)
    // -----------------------------------------------------------------------

    public function replaceOne(array $device): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $knownUids = $input['known_uids'] ?? [];
        $completed = $input['completed']  ?? [];

        if (!is_array($knownUids) || !is_array($completed) || empty($completed)) {
            LoggingMiddleware::logExit(422);
            Response::error('known_uids et completed requis', null, 422);
            return;
        }

        // Vérifier 1 remplacement par jour
        $lastReplaced = $device['last_replaced_at'] ?? null;
        if ($lastReplaced === date('Y-m-d')) {
            LoggingMiddleware::logExit(429);
            Response::error('Un remplacement a déjà eu lieu aujourd\'hui', ['code' => 'ALREADY_REPLACED_TODAY'], 429);
            return;
        }

        // Choisir l'image complétée la plus ancienne
        usort($completed, fn($a, $b) => strcmp($a['completed_at'] ?? '', $b['completed_at'] ?? ''));
        $replaceUid = $completed[0]['uid'] ?? null;

        if ($replaceUid === null) {
            LoggingMiddleware::logExit(422);
            Response::error('Données completed invalides', null, 422);
            return;
        }

        $lang  = $this->resolveLang();
        $image = (new PuzzleImage())->findReplacement($knownUids, $lang);

        if ($image === null) {
            LoggingMiddleware::logExit(404);
            Response::error('Aucune image de remplacement disponible', ['code' => 'NO_REPLACEMENT_AVAILABLE'], 404);
            return;
        }

        (new PuzzleDevice())->setLastReplacedAt((int) $device['id']);

        LoggingMiddleware::logExit(200);
        Response::success('Image de remplacement disponible', [
            'replaces_uid' => $replaceUid,
            'image'        => $image,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /puzzle/carousel/replace-all  (device_token + premium)
    // -----------------------------------------------------------------------

    public function replaceAll(array $device): void
    {
        LoggingMiddleware::logEntry();
        $input = Response::getRequestParams();

        $knownUids   = $input['known_uids']   ?? [];
        $replaceUids = $input['replace_uids'] ?? [];

        if (!is_array($knownUids) || !is_array($replaceUids) || empty($replaceUids)) {
            LoggingMiddleware::logExit(422);
            Response::error('known_uids et replace_uids requis', null, 422);
            return;
        }

        $lang            = $this->resolveLang();
        $imageModel      = new PuzzleImage();
        $replacements    = [];
        $unavailableCount = 0;

        // Accumuler les UIDs déjà assignés pour éviter les doublons dans la réponse
        $usedUids = $knownUids;

        foreach ($replaceUids as $replaceUid) {
            $image = $imageModel->findReplacement($usedUids, $lang);
            if ($image === null) {
                $unavailableCount++;
            } else {
                $replacements[] = [
                    'replaces_uid' => $replaceUid,
                    'image'        => $image,
                ];
                $usedUids[] = $image['uid'];
            }
        }

        LoggingMiddleware::logExit(200);
        Response::success('Images de remplacement disponibles', [
            'replacements'     => $replacements,
            'unavailable_count' => $unavailableCount,
        ]);
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    private function resolveLang(): string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'fr';
        $lang   = strtolower(substr(trim($header), 0, 2));
        return in_array($lang, ['fr', 'en', 'es'], true) ? $lang : 'fr';
    }
}
