<?php

namespace Puzzle\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class PuzzleTheme extends BaseModel
{
    protected $table = 'puzzle_themes';

    public function create()
    {
        throw new \LogicException('Utiliser les méthodes spécifiques');
    }

    public function update()
    {
        throw new \LogicException('Utiliser les méthodes spécifiques');
    }

    /** Retourne tous les thèmes actifs avec labels traduits et nombre d'images. */
    public function getActiveThemes(string $lang): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT
                pt.id,
                pt.slug,
                pt.thumb_path,
                COALESCE(
                    (SELECT label FROM puzzle_theme_translations WHERE theme_id = pt.id AND lang = ?),
                    (SELECT label FROM puzzle_theme_translations WHERE theme_id = pt.id AND lang = 'fr')
                ) AS label,
                COUNT(pit.image_id) AS image_count
            FROM puzzle_themes pt
            LEFT JOIN puzzle_image_themes pit ON pit.theme_id = pt.id
            LEFT JOIN puzzle_images pi ON pi.id = pit.image_id AND pi.status = 'active'
            WHERE pt.status = 'active'
            GROUP BY pt.id
            ORDER BY pt.sort_order ASC
        ");
        $stmt->execute([$lang]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $apiBase = defined('API_BASE_URL') ? rtrim(\API_BASE_URL, '/') : '';

        return array_map(fn($r) => [
            'slug'        => $r['slug'],
            'label'       => $r['label'] ?? '',
            'thumb_url'   => "{$apiBase}/puzzle/thumb/theme/{$r['slug']}",
            'image_count' => (int) $r['image_count'],
        ], $rows);
    }

    /** Retourne un thème actif par slug, ou null si inexistant. */
    public function findActiveBySlug(string $slug, string $lang): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT
                pt.id,
                pt.slug,
                pt.thumb_path,
                COALESCE(
                    (SELECT label FROM puzzle_theme_translations WHERE theme_id = pt.id AND lang = ?),
                    (SELECT label FROM puzzle_theme_translations WHERE theme_id = pt.id AND lang = 'fr')
                ) AS label
            FROM puzzle_themes pt
            WHERE pt.slug = ? AND pt.status = 'active'
        ");
        $stmt->execute([$lang, $slug]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Chemin physique du thumbnail de thème. */
    public function getThumbPath(string $slug): ?string
    {
        $stmt = $this->getDb()->prepare(
            "SELECT thumb_path FROM puzzle_themes WHERE slug = ? AND status = 'active'"
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['thumb_path'] : null;
    }
}
