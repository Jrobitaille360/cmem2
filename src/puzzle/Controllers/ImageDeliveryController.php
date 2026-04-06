<?php

namespace Puzzle\Controllers;

use AuthGroups\Utils\Response;
use Puzzle\Models\PuzzleImage;
use Puzzle\Models\PuzzleTheme;

/**
 * ImageDeliveryController — livraison des fichiers image via PHP.
 *
 * Les fichiers sont sous uploads/puzzle/ protégé par .htaccess (Deny from all).
 * PHP valide le device_token (déjà fait dans PuzzleRouteHandler) avant d'arriver ici.
 */
class ImageDeliveryController
{
    private string $uploadDir;

    public function __construct()
    {
        $this->uploadDir = defined('PUZZLE_UPLOAD_DIR')
            ? rtrim(\PUZZLE_UPLOAD_DIR, '/')
            : rtrim(__DIR__ . '/../../../uploads/puzzle', '/');
    }

    // -----------------------------------------------------------------------
    // GET /puzzle/thumb/{uid}
    // -----------------------------------------------------------------------

    public function serveThumb(string $uid): void
    {
        $path = (new PuzzleImage())->getThumbPath($uid);
        $this->serveFile($path, $uid);
    }

    // -----------------------------------------------------------------------
    // GET /puzzle/image/{uid}
    // -----------------------------------------------------------------------

    public function serveImage(string $uid): void
    {
        $path = (new PuzzleImage())->getFullPath($uid);
        $this->serveFile($path, $uid);
    }

    // -----------------------------------------------------------------------
    // GET /puzzle/thumb/theme/{slug}
    // -----------------------------------------------------------------------

    public function serveThemeThumb(string $slug): void
    {
        $path = (new PuzzleTheme())->getThumbPath($slug);
        $this->serveFile($path, $slug);
    }

    // -----------------------------------------------------------------------
    // Envoi sécurisé du fichier
    // -----------------------------------------------------------------------

    private function serveFile(?string $relativePath, string $identifier): void
    {
        if ($relativePath === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Image introuvable']);
            exit;
        }

        // Construire le chemin absolu et prévenir le path traversal
        $fullPath = realpath($this->uploadDir . '/' . ltrim($relativePath, '/'));
        $baseDir  = realpath($this->uploadDir);

        if ($fullPath === false || $baseDir === false || !str_starts_with($fullPath, $baseDir)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Image introuvable']);
            exit;
        }

        if (!is_file($fullPath)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Image introuvable']);
            exit;
        }

        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }
}
