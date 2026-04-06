<?php

namespace Puzzle\Services;

use AuthGroups\Utils\Response;

/**
 * AdminImageService — pipeline GD pour l'upload des images et thumbnails admin.
 *
 * Gère :
 *  - processImageUpload(string $uid): array  — full + thumb depuis $_FILES['image']
 *  - processThemeThumb(string $slug): array  — thumb de thème depuis $_FILES['thumb']
 *
 * Format de sortie uniforme : JPEG quelle que soit la source (JPEG ou PNG acceptés).
 * Chemins retournés : relatifs depuis PUZZLE_UPLOAD_DIR (pour stockage en DB).
 */
class AdminImageService
{
    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024; // 10 Mo
    private const MAX_THUMB_BYTES =  5 * 1024 * 1024; //  5 Mo
    private const MAX_FULL_WIDTH  = 2000;
    private const THUMB_WIDTH     = 400;

    // -----------------------------------------------------------------------
    // API publique
    // -----------------------------------------------------------------------

    /**
     * Traite $_FILES['image'] et génère le full + le thumbnail JPEG.
     * Retourne les chemins relatifs pour stockage en DB.
     *
     * @param string $uid  UUID v4 déjà généré
     * @return array ['full_path' => 'images/{uid}.jpg', 'thumb_path' => 'thumbs/{uid}.jpg']
     */
    public function processImageUpload(string $uid): array
    {
        $file = $_FILES['image'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            Response::error('Champ image absent ou erreur d\'upload', [
                ['field' => 'image', 'code' => 'required', 'message' => 'Le fichier image est obligatoire.'],
            ], 422);
        }

        if ($file['size'] > self::MAX_IMAGE_BYTES) {
            Response::error('Fichier trop lourd (max 10 Mo)', [
                ['field' => 'image', 'code' => 'too_large', 'message' => 'Le fichier dépasse 10 Mo.'],
            ], 422);
        }

        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            Response::error('Format non supporté (JPEG ou PNG uniquement)', [
                ['field' => 'image', 'code' => 'invalid_type', 'message' => 'Seuls JPEG et PNG sont acceptés.'],
            ], 422);
        }

        return $this->runGdPipeline($file['tmp_name'], $mime, $uid, 'images', 'thumbs');
    }

    /**
     * Traite $_FILES['thumb'] pour générer le thumbnail d'un thème.
     * Retourne le chemin relatif pour stockage en DB.
     *
     * @param string $slug  Slug du thème (utilisé comme nom de fichier)
     * @return array ['thumb_path' => 'themes/{slug}.jpg']
     */
    public function processThemeThumb(string $slug): array
    {
        $file = $_FILES['thumb'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            Response::error('Champ thumb absent ou erreur d\'upload', [
                ['field' => 'thumb', 'code' => 'required', 'message' => 'Le thumbnail est obligatoire.'],
            ], 422);
        }

        if ($file['size'] > self::MAX_THUMB_BYTES) {
            Response::error('Fichier trop lourd (max 5 Mo)', [
                ['field' => 'thumb', 'code' => 'too_large', 'message' => 'Le fichier dépasse 5 Mo.'],
            ], 422);
        }

        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            Response::error('Format non supporté (JPEG ou PNG uniquement)', [
                ['field' => 'thumb', 'code' => 'invalid_type', 'message' => 'Seuls JPEG et PNG sont acceptés.'],
            ], 422);
        }

        $base     = rtrim(PUZZLE_UPLOAD_DIR, '/');
        $themesDir = $base . '/themes';
        if (!is_dir($themesDir)) {
            mkdir($themesDir, 0755, true);
        }

        $src  = $this->loadGdImage($file['tmp_name'], $mime);
        $flat = $this->flattenAlpha($src);

        [$w, $h] = [imagesx($flat), imagesy($flat)];
        $thumbH  = (int) ($h * self::THUMB_WIDTH / $w);
        $thumb   = imagecreatetruecolor(self::THUMB_WIDTH, $thumbH);
        imagecopyresampled($thumb, $flat, 0, 0, 0, 0, self::THUMB_WIDTH, $thumbH, $w, $h);

        imagejpeg($thumb, $themesDir . '/' . $slug . '.jpg', 85);

        return ['thumb_path' => 'themes/' . $slug . '.jpg'];
    }

    // -----------------------------------------------------------------------
    // Pipeline GD interne
    // -----------------------------------------------------------------------

    /**
     * Charge, aplatit, redimensionne et sauvegarde en JPEG.
     * Crée les sous-répertoires si nécessaire.
     */
    private function runGdPipeline(
        string $tmpPath,
        string $mime,
        string $uid,
        string $fullSubdir,
        string $thumbSubdir
    ): array {
        $base = rtrim(PUZZLE_UPLOAD_DIR, '/');

        foreach ([$base . '/' . $fullSubdir, $base . '/' . $thumbSubdir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $src  = $this->loadGdImage($tmpPath, $mime);
        $flat = $this->flattenAlpha($src);

        // Redimensionner le full si largeur > MAX_FULL_WIDTH
        [$w, $h] = [imagesx($flat), imagesy($flat)];
        if ($w > self::MAX_FULL_WIDTH) {
            $fullH   = (int) ($h * self::MAX_FULL_WIDTH / $w);
            $resized = imagecreatetruecolor(self::MAX_FULL_WIDTH, $fullH);
            imagecopyresampled($resized, $flat, 0, 0, 0, 0, self::MAX_FULL_WIDTH, $fullH, $w, $h);
            $flat   = $resized;
            [$w, $h] = [self::MAX_FULL_WIDTH, $fullH];
        }

        // Sauvegarder le full
        // rtrim() — PUZZLE_UPLOAD_DIR n'a pas de '/' final garanti
        imagejpeg($flat, $base . '/' . $fullSubdir . '/' . $uid . '.jpg', 92);

        // Générer le thumbnail (THUMB_WIDTH px de large)
        $thumbH = (int) ($h * self::THUMB_WIDTH / $w);
        $thumb  = imagecreatetruecolor(self::THUMB_WIDTH, $thumbH);
        imagecopyresampled($thumb, $flat, 0, 0, 0, 0, self::THUMB_WIDTH, $thumbH, $w, $h);
        imagejpeg($thumb, $base . '/' . $thumbSubdir . '/' . $uid . '.jpg', 85);

        return [
            'full_path'  => $fullSubdir  . '/' . $uid . '.jpg',
            'thumb_path' => $thumbSubdir . '/' . $uid . '.jpg',
        ];
    }

    private function loadGdImage(string $tmpPath, string $mime): \GdImage
    {
        $src = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($tmpPath),
            'image/png'  => imagecreatefrompng($tmpPath),
            default      => false,
        };

        if ($src === false) {
            Response::error('Fichier image corrompu ou illisible', [
                ['field' => 'image', 'code' => 'gd_error', 'message' => 'Impossible de lire l\'image avec GD.'],
            ], 422);
        }

        return $src;
    }

    /**
     * Aplatit le canal alpha sur fond blanc (PNG transparent → JPEG sans transparence).
     * Détruit $src et retourne la nouvelle ressource GD.
     */
    private function flattenAlpha(\GdImage $src): \GdImage
    {
        $w    = imagesx($src);
        $h    = imagesy($src);
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $src, 0, 0, 0, 0, $w, $h);
        return $flat;
    }
}
