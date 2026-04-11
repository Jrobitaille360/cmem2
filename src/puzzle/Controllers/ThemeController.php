<?php

namespace Puzzle\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Utils\Response;
use Puzzle\Models\PuzzleTheme;
use Puzzle\Models\PuzzleImage;

/**
 * ThemeController — inventaire thématique (abonnés)
 */
class ThemeController
{
    // -----------------------------------------------------------------------
    // GET /puzzle/themes  (device_token + premium)
    // -----------------------------------------------------------------------

    public function getThemes(array $device): void
    {
        LoggingMiddleware::logEntry();

        $lang   = $this->resolveLang();
        $themes = (new PuzzleTheme())->getActiveThemes($lang);
        LoggingMiddleware::logExit(200);
        Response::success('Thèmes chargés', ['themes' => $themes]);
    }

    // -----------------------------------------------------------------------
    // GET /puzzle/themes/{slug}/images  (device_token + premium)
    // -----------------------------------------------------------------------

    public function getThemeImages(string $slug, array $device): void
    {
        LoggingMiddleware::logEntry();

        $lang       = $this->resolveLang();
        $themeModel = new PuzzleTheme();
        $theme      = $themeModel->findActiveBySlug($slug, $lang);

        if (!$theme) {
            LoggingMiddleware::logExit(404);
            Response::error('Thème introuvable', ['code' => 'THEME_NOT_FOUND'], 404);
            return;
        }

        $images = (new PuzzleImage())->getByThemeSlug((int) $theme['id'], $lang);

        LoggingMiddleware::logExit(200);
        Response::success('Images du thème chargées', [
            'theme'  => ['slug' => $theme['slug'], 'label' => $theme['label']],
            'images' => $images,
            'total'  => count($images),
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
