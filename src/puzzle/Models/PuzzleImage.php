<?php

namespace Puzzle\Models;

use AuthGroups\Models\BaseModel;
use PDO;

class PuzzleImage extends BaseModel
{
    protected $table = 'puzzle_images';

    public function create()
    {
        throw new \LogicException('Utiliser les méthodes spécifiques');
    }

    public function update()
    {
        throw new \LogicException('Utiliser les méthodes spécifiques');
    }

    /**
     * Retourne les 30 premières images actives du carrousel avec leurs labels traduits.
     *
     * @param string $lang  fr|en|es
     */
    public function getCarousel(string $lang): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT
                pi.uid,
                pi.thumb_path,
                pi.full_path,
                pi.created_at,
                COALESCE(
                    (SELECT label FROM puzzle_image_translations WHERE image_id = pi.id AND lang = ?),
                    (SELECT label FROM puzzle_image_translations WHERE image_id = pi.id AND lang = 'fr')
                ) AS label
            FROM puzzle_images pi
            WHERE pi.status = 'active' AND pi.is_carousel = 1
            ORDER BY pi.sort_order ASC
            LIMIT 30
        ");
        $stmt->execute([$lang]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($r) => $this->formatImage($r), $rows);
    }

    /**
     * Sélectionne une image de remplacement : active, pas dans $knownUids.
     */
    public function findReplacement(array $knownUids, string $lang): ?array
    {
        $placeholders = $knownUids ? implode(',', array_fill(0, count($knownUids), '?')) : "'__none__'";
        $params = array_merge([$lang], $knownUids);

        $stmt = $this->getDb()->prepare("
            SELECT
                pi.uid,
                pi.thumb_path,
                pi.full_path,
                pi.created_at,
                COALESCE(
                    (SELECT label FROM puzzle_image_translations WHERE image_id = pi.id AND lang = ?),
                    (SELECT label FROM puzzle_image_translations WHERE image_id = pi.id AND lang = 'fr')
                ) AS label
            FROM puzzle_images pi
            WHERE pi.status = 'active'
              AND pi.uid NOT IN ({$placeholders})
            ORDER BY RAND()
            LIMIT 1
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->formatImage($row) : null;
    }

    /**
     * Retourne toutes les images actives d'un thème (par slug).
     */
    public function getByThemeSlug(int $themeId, string $lang): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT
                pi.uid,
                pi.thumb_path,
                pi.full_path,
                pi.created_at,
                COALESCE(
                    (SELECT label FROM puzzle_image_translations WHERE image_id = pi.id AND lang = ?),
                    (SELECT label FROM puzzle_image_translations WHERE image_id = pi.id AND lang = 'fr')
                ) AS label
            FROM puzzle_images pi
            INNER JOIN puzzle_image_themes pit ON pit.image_id = pi.id
            WHERE pi.status = 'active' AND pit.theme_id = ?
            ORDER BY pi.sort_order ASC
        ");
        $stmt->execute([$lang, $themeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($r) => $this->formatImage($r), $rows);
    }

    /** Retourne l'image active par UID, avec ses thèmes. */
    public function findActiveByUid(string $uid, string $lang = 'fr'): ?array
    {
        $stmt = $this->getDb()->prepare("
            SELECT
                pi.id,
                pi.uid,
                pi.thumb_path,
                pi.full_path,
                pi.created_at,
                COALESCE(
                    (SELECT label FROM puzzle_image_translations WHERE image_id = pi.id AND lang = ?),
                    (SELECT label FROM puzzle_image_translations WHERE image_id = pi.id AND lang = 'fr')
                ) AS label
            FROM puzzle_images pi
            WHERE pi.uid = ? AND pi.status = 'active'
        ");
        $stmt->execute([$lang, $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->formatImage($row) : null;
    }

    /** Retourne l'ID interne d'une image active par son UID. */
    public function getIdByUid(string $uid): ?int
    {
        $stmt = $this->getDb()->prepare(
            "SELECT id FROM puzzle_images WHERE uid = ? AND status = 'active'"
        );
        $stmt->execute([$uid]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int) $val : null;
    }

    /** Retourne l'UID d'une image par son ID interne. */
    public function getUidById(int $id): ?string
    {
        $stmt = $this->getDb()->prepare(
            "SELECT uid FROM puzzle_images WHERE id = ?"
        );
        $stmt->execute([$id]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : null;
    }

    /** Chemin physique complet vers le thumbnail. */
    public function getThumbPath(string $uid): ?string
    {
        $stmt = $this->getDb()->prepare(
            "SELECT thumb_path FROM puzzle_images WHERE uid = ? AND status = 'active'"
        );
        $stmt->execute([$uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['thumb_path'] : null;
    }

    /** Chemin physique complet vers l'image complète. */
    public function getFullPath(string $uid): ?string
    {
        $stmt = $this->getDb()->prepare(
            "SELECT full_path FROM puzzle_images WHERE uid = ? AND status = 'active'"
        );
        $stmt->execute([$uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['full_path'] : null;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function formatImage(array $row): array
    {
        $apiBase = defined('API_BASE_URL') ? rtrim(API_BASE_URL, '/') : '';
        $uid     = $row['uid'];
        $themes  = $this->getThemeSlugsForImage($uid);

        return [
            'uid'        => $uid,
            'label'      => $row['label'] ?? '',
            'thumb_url'  => "{$apiBase}/puzzle/thumb/{$uid}",
            'full_url'   => "{$apiBase}/puzzle/image/{$uid}",
            'themes'     => $themes,
            'created_at' => date('c', strtotime($row['created_at'])),
        ];
    }

    private function getThemeSlugsForImage(string $uid): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT pt.slug
            FROM puzzle_themes pt
            INNER JOIN puzzle_image_themes pit ON pit.theme_id = pt.id
            INNER JOIN puzzle_images pi ON pi.id = pit.image_id
            WHERE pi.uid = ?
        ");
        $stmt->execute([$uid]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
}
