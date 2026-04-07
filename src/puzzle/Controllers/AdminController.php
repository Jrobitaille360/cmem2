<?php

namespace Puzzle\Controllers;

use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Services\LogService;
use AuthGroups\Utils\Response;
use Puzzle\Services\AdminImageService;
use PDO;

/**
 * AdminController — endpoints admin du plugin Puzzle.
 *
 * Appelé exclusivement depuis PuzzleRouteHandler::handleAdminRoute(),
 * qui a déjà validé le JWT et le rôle ADMINISTRATEUR.
 *
 * Routes images :
 *   GET    /puzzle/admin/images                → listImages()
 *   POST   /puzzle/admin/images                → createImage()
 *   PUT    /puzzle/admin/images/reorder        → reorderImages()
 *   PUT    /puzzle/admin/images/{uid}          → updateImage()
 *   DELETE /puzzle/admin/images/{uid}          → deleteImage()
 *
 * Routes thèmes :
 *   GET    /puzzle/admin/themes                         → listThemes()
 *   POST   /puzzle/admin/themes                         → createTheme()
 *   GET    /puzzle/admin/themes/{slug}                  → getTheme()
 *   PUT    /puzzle/admin/themes/{slug}                  → updateTheme()
 *   DELETE /puzzle/admin/themes/{slug}                  → deleteTheme()
 *   PUT    /puzzle/admin/themes/{slug}/images           → setThemeImages()
 *   POST   /puzzle/admin/themes/{slug}/images/{uid}     → addThemeImage()
 *   DELETE /puzzle/admin/themes/{slug}/images/{uid}     → removeThemeImage()
 */
class AdminController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = \Database::getInstance()->getConnection();
    }

    // -----------------------------------------------------------------------
    // Dispatch images
    // -----------------------------------------------------------------------

    public function handleImages(string $s3, string $s4, string $method, array $user): void
    {
        // PUT /puzzle/admin/images/reorder — avant le bloc {uid} pour éviter ambiguïté
        if ($s3 === 'reorder' && $method === 'PUT') {
            $this->reorderImages();
            return;
        }

        // GET /puzzle/admin/images
        if ($s3 === '' && $method === 'GET') {
            $this->listImages();
            return;
        }

        // POST /puzzle/admin/images
        if ($s3 === '' && $method === 'POST') {
            $this->createImage();
            return;
        }

        // PUT /puzzle/admin/images/{uid}
        if ($s3 !== '' && $method === 'PUT') {
            $this->updateImage($s3);
            return;
        }

        // DELETE /puzzle/admin/images/{uid}
        if ($s3 !== '' && $method === 'DELETE') {
            $this->deleteImage($s3);
            return;
        }

        Response::error('Endpoint non trouvé', null, 404);
    }

    // -----------------------------------------------------------------------
    // Dispatch thèmes
    // -----------------------------------------------------------------------

    public function handleThemes(string $s3, string $s4, string $s5, string $method, array $user): void
    {
        // GET /puzzle/admin/themes
        if ($s3 === '' && $method === 'GET') {
            $this->listThemes();
            return;
        }

        // POST /puzzle/admin/themes
        if ($s3 === '' && $method === 'POST') {
            $this->createTheme();
            return;
        }

        if ($s3 !== '') {
            // GET /puzzle/admin/themes/{slug}  (4.6)
            if ($s4 === '' && $method === 'GET') {
                $this->getTheme($s3);
                return;
            }

            if ($s4 === 'images') {
                // POST /puzzle/admin/themes/{slug}/images/{uid}  (4.7)
                if ($s5 !== '' && $method === 'POST') {
                    $this->addThemeImage($s3, $s5);
                    return;
                }

                // DELETE /puzzle/admin/themes/{slug}/images/{uid}  (4.8)
                if ($s5 !== '' && $method === 'DELETE') {
                    $this->removeThemeImage($s3, $s5);
                    return;
                }

                // PUT /puzzle/admin/themes/{slug}/images  (4.5)
                if ($s5 === '' && $method === 'PUT') {
                    $this->setThemeImages($s3);
                    return;
                }
            }

            // PUT /puzzle/admin/themes/{slug}
            if ($method === 'PUT') {
                $this->updateTheme($s3);
                return;
            }

            // DELETE /puzzle/admin/themes/{slug}
            if ($method === 'DELETE') {
                $this->deleteTheme($s3);
                return;
            }
        }

        Response::error('Endpoint non trouvé', null, 404);
    }

    // -----------------------------------------------------------------------
    // Images — opérations
    // -----------------------------------------------------------------------

    private function listImages(): void
    {
        LoggingMiddleware::logEntry();

        $status  = $_GET['status']   ?? 'all';
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 50)));

        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'all';
        }

        $where  = $status === 'all' ? '1=1' : 'status = :status';
        $params = $status === 'all' ? [] : [':status' => $status];

        // Compte total
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM puzzle_images WHERE {$where}");
        $countStmt->execute($params);
        $total    = (int) $countStmt->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset   = ($page - 1) * $perPage;

        // Images paginées
        $stmt = $this->db->prepare("
            SELECT id, uid, is_carousel, sort_order, status, created_at
            FROM puzzle_images
            WHERE {$where}
            ORDER BY sort_order ASC, id ASC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($images)) {
            LoggingMiddleware::logExit(200);
            Response::paginated([], [
                'total' => 0, 'page' => $page, 'per_page' => $perPage, 'last_page' => 1,
            ], 'Images chargées');
            return;
        }

        // Translations en batch
        $ids          = array_column($images, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $transStmt    = $this->db->prepare(
            "SELECT image_id, lang, label FROM puzzle_image_translations WHERE image_id IN ({$placeholders})"
        );
        $transStmt->execute($ids);
        $transMap = [];
        foreach ($transStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $transMap[$row['image_id']][$row['lang']] = $row['label'];
        }

        $apiBase = defined('API_BASE_URL') ? rtrim(\API_BASE_URL, '/') : '';
        $data    = array_map(function ($img) use ($transMap, $apiBase) {
            $uid = $img['uid'];
            return [
                'uid'          => $uid,
                'thumb_url'    => "{$apiBase}/puzzle/admin/thumb/{$uid}",
                'full_url'     => "{$apiBase}/puzzle/admin/image/{$uid}",
                'is_carousel'  => (bool) $img['is_carousel'],
                'sort_order'   => (int)  $img['sort_order'],
                'status'       => $img['status'],
                'translations' => $transMap[$img['id']] ?? (object) [],
                'created_at'   => $img['created_at'],
            ];
        }, $images);

        LoggingMiddleware::logExit(200);
        Response::paginated($data, [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'last_page'=> $lastPage,
        ], 'Images chargées');
    }

    private function createImage(): void
    {
        LoggingMiddleware::logEntry();

        $input   = Response::getRequestParams();
        $labelFr = trim($input['label_fr'] ?? '');

        if ($labelFr === '') {
            LoggingMiddleware::logExit(422);
            Response::error('Le label français est obligatoire', [
                ['field' => 'label_fr', 'code' => 'required', 'message' => 'Le label français est obligatoire.'],
            ], 422);
        }
        if (mb_strlen($labelFr) > 255) {
            LoggingMiddleware::logExit(422);
            Response::error('label_fr trop long (max 255 car.)', [
                ['field' => 'label_fr', 'code' => 'max_length', 'message' => 'Le label français dépasse 255 caractères.'],
            ], 422);
        }

        $uid   = $this->generateUuidV4();
        $paths = (new AdminImageService())->processImageUpload($uid);

        $sortOrder = isset($input['sort_order'])
            ? (int) $input['sort_order']
            : $this->nextSortOrder('puzzle_images');
        $status = in_array($input['status'] ?? '', ['active', 'inactive'], true)
            ? $input['status']
            : 'active';

        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                INSERT INTO puzzle_images (uid, thumb_path, full_path, sort_order, status, created_at)
                VALUES (:uid, :thumb, :full, :sort, :status, NOW())
            ")->execute([
                ':uid'    => $uid,
                ':thumb'  => $paths['thumb_path'],
                ':full'   => $paths['full_path'],
                ':sort'   => $sortOrder,
                ':status' => $status,
            ]);
            $imageId = (int) $this->db->lastInsertId();

            $this->upsertImageTranslations($imageId, [
                'fr' => $labelFr,
                'en' => trim($input['label_en'] ?? ''),
                'es' => trim($input['label_es'] ?? ''),
            ]);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            LogService::error('Erreur création image admin', ['error' => $e->getMessage()]);
            Response::error('Erreur serveur lors de la création', null, 500);
        }

        $apiBase = defined('API_BASE_URL') ? rtrim(\API_BASE_URL, '/') : '';
        LoggingMiddleware::logExit(201);
        Response::success('Image créée', [
            'uid'          => $uid,
            'thumb_url'    => "{$apiBase}/puzzle/admin/thumb/{$uid}",
            'full_url'     => "{$apiBase}/puzzle/admin/image/{$uid}",
            'status'       => $status,
            'translations' => [
                'fr' => $labelFr,
                'en' => trim($input['label_en'] ?? '') ?: null,
                'es' => trim($input['label_es'] ?? '') ?: null,
            ],
        ], 201);
    }

    private function updateImage(string $uid): void
    {
        LoggingMiddleware::logEntry();

        if (!$this->isValidUuid($uid)) {
            LoggingMiddleware::logExit(404);
            Response::error('Image introuvable', null, 404);
        }

        $image = $this->findImageByUid($uid);
        if (!$image) {
            LoggingMiddleware::logExit(404);
            Response::error('Image introuvable', null, 404);
        }

        $input  = Response::getRequestParams();
        $fields = [];
        $params = [':id' => $image['id']];

        if (array_key_exists('sort_order', $input)) {
            $fields[] = 'sort_order = :sort_order';
            $params[':sort_order'] = (int) $input['sort_order'];
        }
        if (array_key_exists('status', $input)) {
            if (!in_array($input['status'], ['active', 'inactive'], true)) {
                LoggingMiddleware::logExit(422);
                Response::error('status invalide', [
                    ['field' => 'status', 'code' => 'invalid', 'message' => 'Valeurs acceptées : active, inactive.'],
                ], 422);
            }
            $fields[] = 'status = :status';
            $params[':status'] = $input['status'];
        }
        if (array_key_exists('is_carousel', $input)) {
            $fields[] = 'is_carousel = :is_carousel';
            $params[':is_carousel'] = $input['is_carousel'] ? 1 : 0;
        }

        if (!empty($fields)) {
            $set = implode(', ', $fields);
            $this->db->prepare("UPDATE puzzle_images SET {$set} WHERE id = :id")->execute($params);
        }

        $translations = [];
        foreach (['fr', 'en', 'es'] as $lang) {
            $key = "label_{$lang}";
            if (array_key_exists($key, $input)) {
                $translations[$lang] = trim($input[$key]);
            }
        }
        if (!empty($translations)) {
            $this->upsertImageTranslations($image['id'], $translations);
        }

        $apiBase = defined('API_BASE_URL') ? rtrim(\API_BASE_URL, '/') : '';
        LoggingMiddleware::logExit(200);
        Response::success('Image mise à jour',
            $this->formatImageForAdmin($this->findImageByUid($uid), $apiBase)
        );
    }

    private function deleteImage(string $uid): void
    {
        LoggingMiddleware::logEntry();

        if (!$this->isValidUuid($uid)) {
            LoggingMiddleware::logExit(404);
            Response::error('Image introuvable', null, 404);
        }

        $image = $this->findImageByUid($uid);
        if (!$image) {
            LoggingMiddleware::logExit(404);
            Response::error('Image introuvable', null, 404);
        }

        // Vérifier usage dans une session partagée active
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM puzzle_shared WHERE image_id = :id AND status = 'active'"
        );
        $stmt->execute([':id' => $image['id']]);
        if ((int) $stmt->fetchColumn() > 0) {
            LoggingMiddleware::logExit(409);
            Response::error('Image utilisée dans une session active.', null, 409);
        }

        // Supprimer les fichiers physiques (protection anti-traversal)
        $base = rtrim(PUZZLE_UPLOAD_DIR, '/');
        foreach (['thumb_path', 'full_path'] as $key) {
            if (empty($image[$key])) continue;
            $absPath = realpath($base . '/' . ltrim($image[$key], '/'));
            $baseDir = realpath($base);
            if ($absPath && $baseDir && str_starts_with($absPath, $baseDir) && is_file($absPath)) {
                unlink($absPath);
            }
        }

        // Supprimer en DB (CASCADE sur puzzle_image_translations et puzzle_image_themes)
        $this->db->prepare("DELETE FROM puzzle_images WHERE id = :id")
                 ->execute([':id' => $image['id']]);

        LoggingMiddleware::logExit(200);
        Response::success('Image supprimée.');
    }

    private function reorderImages(): void
    {
        LoggingMiddleware::logEntry();

        $input = Response::getRequestParams();
        $order = $input['order'] ?? [];

        if (!is_array($order) || empty($order)) {
            LoggingMiddleware::logExit(422);
            Response::error('Champ order requis', [
                ['field' => 'order', 'code' => 'required', 'message' => 'Le tableau order est obligatoire.'],
            ], 422);
        }

        $stmt    = $this->db->prepare("UPDATE puzzle_images SET sort_order = :sort WHERE uid = :uid");
        $updated = 0;
        foreach ($order as $item) {
            if (!isset($item['uid'], $item['sort_order'])) continue;
            if (!$this->isValidUuid((string) $item['uid'])) continue;
            $stmt->execute([':sort' => (int) $item['sort_order'], ':uid' => $item['uid']]);
            $updated += $stmt->rowCount();
        }

        LoggingMiddleware::logExit(200);
        Response::success('Ordre mis à jour', ['updated' => $updated]);
    }

    // -----------------------------------------------------------------------
    // Thèmes — opérations
    // -----------------------------------------------------------------------

    private function listThemes(): void
    {
        LoggingMiddleware::logEntry();

        $stmt = $this->db->prepare("
            SELECT pt.id, pt.slug, pt.thumb_path, pt.sort_order, pt.status,
                   COUNT(pit.image_id) AS image_count
            FROM puzzle_themes pt
            LEFT JOIN puzzle_image_themes pit ON pit.theme_id = pt.id
            GROUP BY pt.id
            ORDER BY pt.sort_order ASC, pt.id ASC
        ");
        $stmt->execute();
        $themes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $transMap = [];
        if (!empty($themes)) {
            $ids          = array_column($themes, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $transStmt    = $this->db->prepare(
                "SELECT theme_id, lang, label FROM puzzle_theme_translations WHERE theme_id IN ({$placeholders})"
            );
            $transStmt->execute($ids);
            foreach ($transStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $transMap[$row['theme_id']][$row['lang']] = $row['label'];
            }
        }

        $apiBase = defined('API_BASE_URL') ? rtrim(\API_BASE_URL, '/') : '';
        $data    = array_map(function ($t) use ($transMap, $apiBase) {
            return [
                'slug'        => $t['slug'],
                'thumb_url'   => "{$apiBase}/puzzle/thumb/theme/{$t['slug']}",
                'sort_order'  => (int) $t['sort_order'],
                'status'      => $t['status'],
                'image_count' => (int) $t['image_count'],
                'translations'=> $transMap[$t['id']] ?? (object) [],
            ];
        }, $themes);

        LoggingMiddleware::logExit(200);
        Response::success('Thèmes chargés', $data);
    }

    private function createTheme(): void
    {
        LoggingMiddleware::logEntry();

        $input   = Response::getRequestParams();
        $slug    = trim($input['slug'] ?? '');
        $labelFr = trim($input['label_fr'] ?? '');

        if ($slug === '' || !preg_match('/^[a-z0-9_-]+$/', $slug)) {
            LoggingMiddleware::logExit(422);
            Response::error('slug invalide ou absent', [
                ['field' => 'slug', 'code' => 'invalid', 'message' => 'Le slug doit être URL-safe (a-z, 0-9, -, _).'],
            ], 422);
        }
        if ($labelFr === '') {
            LoggingMiddleware::logExit(422);
            Response::error('Le label français est obligatoire', [
                ['field' => 'label_fr', 'code' => 'required', 'message' => 'Le label français est obligatoire.'],
            ], 422);
        }

        // Unicité du slug
        $check = $this->db->prepare("SELECT COUNT(*) FROM puzzle_themes WHERE slug = :slug");
        $check->execute([':slug' => $slug]);
        if ((int) $check->fetchColumn() > 0) {
            LoggingMiddleware::logExit(409);
            Response::error('Ce slug existe déjà', [
                ['field' => 'slug', 'code' => 'duplicate', 'message' => 'Un thème avec ce slug existe déjà.'],
            ], 409);
        }

        $thumbData = (new AdminImageService())->processThemeThumb($slug);
        $sortOrder = isset($input['sort_order'])
            ? (int) $input['sort_order']
            : $this->nextSortOrder('puzzle_themes');
        $status = in_array($input['status'] ?? '', ['active', 'inactive'], true)
            ? $input['status']
            : 'active';

        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                INSERT INTO puzzle_themes (slug, thumb_path, sort_order, status, created_at)
                VALUES (:slug, :thumb, :sort, :status, NOW())
            ")->execute([
                ':slug'   => $slug,
                ':thumb'  => $thumbData['thumb_path'],
                ':sort'   => $sortOrder,
                ':status' => $status,
            ]);
            $themeId = (int) $this->db->lastInsertId();

            $this->upsertThemeTranslations($themeId, [
                'fr' => $labelFr,
                'en' => trim($input['label_en'] ?? ''),
                'es' => trim($input['label_es'] ?? ''),
            ]);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            LogService::error('Erreur création thème admin', ['error' => $e->getMessage()]);
            Response::error('Erreur serveur lors de la création', null, 500);
        }

        $apiBase = defined('API_BASE_URL') ? rtrim(\API_BASE_URL, '/') : '';
        LoggingMiddleware::logExit(201);
        Response::success('Thème créé', [
            'slug'        => $slug,
            'thumb_url'   => "{$apiBase}/puzzle/admin/thumb/theme/{$slug}",
            'sort_order'  => $sortOrder,
            'status'      => $status,
            'image_count' => 0,
            'translations'=> [
                'fr' => $labelFr,
                'en' => trim($input['label_en'] ?? '') ?: null,
                'es' => trim($input['label_es'] ?? '') ?: null,
            ],
        ], 201);
    }

    private function updateTheme(string $slug): void
    {
        LoggingMiddleware::logEntry();

        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            LoggingMiddleware::logExit(404);
            Response::error('Thème introuvable', null, 404);
        }

        $theme = $this->findThemeBySlug($slug);
        if (!$theme) {
            LoggingMiddleware::logExit(404);
            Response::error('Thème introuvable', null, 404);
        }

        $input  = Response::getRequestParams();
        $fields = [];
        $params = [':id' => $theme['id']];

        if (array_key_exists('sort_order', $input)) {
            $fields[] = 'sort_order = :sort_order';
            $params[':sort_order'] = (int) $input['sort_order'];
        }
        if (array_key_exists('status', $input)) {
            if (!in_array($input['status'], ['active', 'inactive'], true)) {
                LoggingMiddleware::logExit(422);
                Response::error('status invalide', [
                    ['field' => 'status', 'code' => 'invalid', 'message' => 'Valeurs acceptées : active, inactive.'],
                ], 422);
            }
            $fields[] = 'status = :status';
            $params[':status'] = $input['status'];
        }

        // Nouveau thumbnail si fourni
        if (isset($_FILES['thumb']) && $_FILES['thumb']['error'] === UPLOAD_ERR_OK) {
            $thumbData        = (new AdminImageService())->processThemeThumb($slug);
            $fields[]         = 'thumb_path = :thumb_path';
            $params[':thumb_path'] = $thumbData['thumb_path'];
        }

        if (!empty($fields)) {
            $set = implode(', ', $fields);
            $this->db->prepare("UPDATE puzzle_themes SET {$set} WHERE id = :id")->execute($params);
        }

        $translations = [];
        foreach (['fr', 'en', 'es'] as $lang) {
            $key = "label_{$lang}";
            if (array_key_exists($key, $input)) {
                $translations[$lang] = trim($input[$key]);
            }
        }
        if (!empty($translations)) {
            $this->upsertThemeTranslations($theme['id'], $translations);
        }

        $apiBase = defined('API_BASE_URL') ? rtrim(\API_BASE_URL, '/') : '';
        LoggingMiddleware::logExit(200);
        Response::success('Thème mis à jour',
            $this->formatThemeForAdmin($this->findThemeBySlug($slug), $apiBase)
        );
    }

    private function deleteTheme(string $slug): void
    {
        LoggingMiddleware::logEntry();

        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            LoggingMiddleware::logExit(404);
            Response::error('Thème introuvable', null, 404);
        }

        $theme = $this->findThemeBySlug($slug);
        if (!$theme) {
            LoggingMiddleware::logExit(404);
            Response::error('Thème introuvable', null, 404);
        }

        // Supprimer le thumbnail physique
        if (!empty($theme['thumb_path'])) {
            $base    = rtrim(PUZZLE_UPLOAD_DIR, '/');
            $absPath = realpath($base . '/' . ltrim($theme['thumb_path'], '/'));
            $baseDir = realpath($base);
            if ($absPath && $baseDir && str_starts_with($absPath, $baseDir) && is_file($absPath)) {
                unlink($absPath);
            }
        }

        // Supprimer en DB (CASCADE sur puzzle_theme_translations et puzzle_image_themes)
        $this->db->prepare("DELETE FROM puzzle_themes WHERE id = :id")
                 ->execute([':id' => $theme['id']]);

        LoggingMiddleware::logExit(200);
        Response::success('Thème supprimé.');
    }

    private function getTheme(string $slug): void
    {
        LoggingMiddleware::logEntry();

        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            LoggingMiddleware::logExit(404);
            Response::error('Thème introuvable', null, 404);
            return;
        }

        $theme = $this->findThemeBySlug($slug);
        if (!$theme) {
            LoggingMiddleware::logExit(404);
            Response::error('Thème introuvable', null, 404);
            return;
        }

        $stmt = $this->db->prepare("
            SELECT pi.uid
            FROM puzzle_image_themes pit
            JOIN puzzle_images pi ON pi.id = pit.image_id
            WHERE pit.theme_id = :theme_id
            ORDER BY pi.sort_order ASC, pi.id ASC
        ");
        $stmt->execute([':theme_id' => $theme['id']]);
        $imageUids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $apiBase = defined('API_BASE_URL') ? rtrim(\API_BASE_URL, '/') : '';
        $data = $this->formatThemeForAdmin($theme, $apiBase);
        $data['image_uids'] = $imageUids;

        LoggingMiddleware::logExit(200);
        Response::success('Thème chargé', $data);
    }

    private function addThemeImage(string $slug, string $uid): void
    {
        LoggingMiddleware::logEntry();

        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            LoggingMiddleware::logExit(404);
            Response::error('Thème introuvable', null, 404);
            return;
        }

        $theme = $this->findThemeBySlug($slug);
        if (!$theme) {
            LoggingMiddleware::logExit(404);
            Response::error('Thème introuvable', null, 404);
            return;
        }

        if (!$this->isValidUuid($uid)) {
            LoggingMiddleware::logExit(404);
            Response::error('Image introuvable', null, 404);
            return;
        }

        $row = $this->db->prepare('SELECT id FROM puzzle_images WHERE uid = :uid');
        $row->execute([':uid' => $uid]);
        $imageId = $row->fetchColumn();
        if ($imageId === false) {
            LoggingMiddleware::logExit(404);
            Response::error('Image introuvable', null, 404);
            return;
        }

        $check = $this->db->prepare(
            'SELECT COUNT(*) FROM puzzle_image_themes WHERE image_id = :image_id AND theme_id = :theme_id'
        );
        $check->execute([':image_id' => $imageId, ':theme_id' => $theme['id']]);
        if ((int) $check->fetchColumn() > 0) {
            LoggingMiddleware::logExit(409);
            Response::error('Image déjà dans le thème', null, 409);
            return;
        }

        $this->db->prepare(
            'INSERT INTO puzzle_image_themes (image_id, theme_id) VALUES (:image_id, :theme_id)'
        )->execute([':image_id' => $imageId, ':theme_id' => $theme['id']]);

        LoggingMiddleware::logExit(200);
        Response::success('Image ajoutée au thème.');
    }

    private function removeThemeImage(string $slug, string $uid): void
    {
        LoggingMiddleware::logEntry();

        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            LoggingMiddleware::logExit(404);
            Response::error('Thème introuvable', null, 404);
            return;
        }

        $theme = $this->findThemeBySlug($slug);
        if (!$theme) {
            LoggingMiddleware::logExit(404);
            Response::error('Thème introuvable', null, 404);
            return;
        }

        if (!$this->isValidUuid($uid)) {
            LoggingMiddleware::logExit(404);
            Response::error('Image ou association introuvable', null, 404);
            return;
        }

        $row = $this->db->prepare('SELECT id FROM puzzle_images WHERE uid = :uid');
        $row->execute([':uid' => $uid]);
        $imageId = $row->fetchColumn();
        if ($imageId === false) {
            LoggingMiddleware::logExit(404);
            Response::error('Image ou association introuvable', null, 404);
            return;
        }

        $stmt = $this->db->prepare(
            'DELETE FROM puzzle_image_themes WHERE image_id = :image_id AND theme_id = :theme_id'
        );
        $stmt->execute([':image_id' => $imageId, ':theme_id' => $theme['id']]);

        if ($stmt->rowCount() === 0) {
            LoggingMiddleware::logExit(404);
            Response::error('Image ou association introuvable', null, 404);
            return;
        }

        LoggingMiddleware::logExit(200);
        Response::success('Image retirée du thème.');
    }

    private function setThemeImages(string $slug): void
    {
        LoggingMiddleware::logEntry();

        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            LoggingMiddleware::logExit(404);
            Response::error('Thème introuvable', null, 404);
        }

        $theme = $this->findThemeBySlug($slug);
        if (!$theme) {
            LoggingMiddleware::logExit(404);
            Response::error('Thème introuvable', null, 404);
        }

        $input     = Response::getRequestParams();
        $imageUids = $input['image_uids'] ?? [];

        if (!is_array($imageUids)) {
            LoggingMiddleware::logExit(422);
            Response::error('image_uids doit être un tableau', null, 422);
        }

        // Résoudre les UIDs valides vers leurs IDs internes
        $imageIds = [];
        foreach ($imageUids as $imageUid) {
            if (!$this->isValidUuid((string) $imageUid)) continue;
            $row = $this->db->prepare("SELECT id FROM puzzle_images WHERE uid = :uid");
            $row->execute([':uid' => $imageUid]);
            $id = $row->fetchColumn();
            if ($id !== false) {
                $imageIds[] = (int) $id;
            }
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("DELETE FROM puzzle_image_themes WHERE theme_id = :id")
                     ->execute([':id' => $theme['id']]);

            $insertStmt = $this->db->prepare(
                "INSERT IGNORE INTO puzzle_image_themes (image_id, theme_id) VALUES (:image_id, :theme_id)"
            );
            foreach ($imageIds as $imageId) {
                $insertStmt->execute([':image_id' => $imageId, ':theme_id' => $theme['id']]);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            LogService::error('Erreur setThemeImages', ['error' => $e->getMessage()]);
            Response::error('Erreur serveur', null, 500);
        }

        LoggingMiddleware::logExit(200);
        Response::success('Images du thème mises à jour', ['count' => count($imageIds)]);
    }

    // -----------------------------------------------------------------------
    // Helpers DB — images
    // -----------------------------------------------------------------------

    private function findImageByUid(string $uid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, uid, thumb_path, full_path, is_carousel, sort_order, status, created_at
            FROM puzzle_images
            WHERE uid = :uid
        ");
        $stmt->execute([':uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $tStmt = $this->db->prepare(
            "SELECT lang, label FROM puzzle_image_translations WHERE image_id = :id"
        );
        $tStmt->execute([':id' => $row['id']]);
        $row['translations'] = [];
        foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $row['translations'][$t['lang']] = $t['label'];
        }
        return $row;
    }

    private function upsertImageTranslations(int $imageId, array $translations): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO puzzle_image_translations (image_id, lang, label)
            VALUES (:image_id, :lang, :label)
            ON DUPLICATE KEY UPDATE label = VALUES(label)
        ");
        foreach ($translations as $lang => $label) {
            if ($label === '') continue;
            $stmt->execute([':image_id' => $imageId, ':lang' => $lang, ':label' => $label]);
        }
    }

    private function formatImageForAdmin(array $row, string $apiBase): array
    {
        $uid = $row['uid'];
        return [
            'uid'          => $uid,
            'thumb_url'    => "{$apiBase}/puzzle/admin/thumb/{$uid}",
            'full_url'     => "{$apiBase}/puzzle/admin/image/{$uid}",
            'is_carousel'  => (bool) $row['is_carousel'],
            'sort_order'   => (int)  $row['sort_order'],
            'status'       => $row['status'],
            'translations' => $row['translations'] ?? (object) [],
            'created_at'   => $row['created_at'],
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers DB — thèmes
    // -----------------------------------------------------------------------

    private function findThemeBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("
            SELECT pt.id, pt.slug, pt.thumb_path, pt.sort_order, pt.status,
                   COUNT(pit.image_id) AS image_count
            FROM puzzle_themes pt
            LEFT JOIN puzzle_image_themes pit ON pit.theme_id = pt.id
            WHERE pt.slug = :slug
            GROUP BY pt.id
        ");
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $tStmt = $this->db->prepare(
            "SELECT lang, label FROM puzzle_theme_translations WHERE theme_id = :id"
        );
        $tStmt->execute([':id' => $row['id']]);
        $row['translations'] = [];
        foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $row['translations'][$t['lang']] = $t['label'];
        }
        return $row;
    }

    private function upsertThemeTranslations(int $themeId, array $translations): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO puzzle_theme_translations (theme_id, lang, label)
            VALUES (:theme_id, :lang, :label)
            ON DUPLICATE KEY UPDATE label = VALUES(label)
        ");
        foreach ($translations as $lang => $label) {
            if ($label === '') continue;
            $stmt->execute([':theme_id' => $themeId, ':lang' => $lang, ':label' => $label]);
        }
    }

    private function formatThemeForAdmin(array $row, string $apiBase): array
    {
        return [
            'slug'        => $row['slug'],
            'thumb_url'   => "{$apiBase}/puzzle/thumb/theme/{$row['slug']}",
            'sort_order'  => (int) $row['sort_order'],
            'status'      => $row['status'],
            'image_count' => (int) $row['image_count'],
            'translations'=> $row['translations'] ?? (object) [],
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers généraux
    // -----------------------------------------------------------------------

    private function nextSortOrder(string $table): int
    {
        $stmt = $this->db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM `{$table}`");
        return (int) $stmt->fetchColumn();
    }

    private function isValidUuid(string $uid): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uid
        );
    }

    private function generateUuidV4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
