<?php

namespace AuthGroups\Controllers;

use AuthGroups\Utils\Response;
use AuthGroups\Services\LogService;
use AuthGroups\Middleware\LoggingMiddleware;
use AuthGroups\Models\File;
use Exception;

class FileController
{
    private array $allowedMimeTypes = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'audio/mpeg',
        'audio/wav',
        'audio/ogg',
        'audio/mp3',
        'video/mp4',
        'video/avi',
        'video/quicktime',
        'video/x-msvideo',
        // Exécutables et archives Windows
        'application/x-msdownload',
        'application/x-dosexec',
        'application/x-msi',
        'application/zip',
        'application/x-zip-compressed',
        'application/x-7z-compressed',
        'application/octet-stream',
    ];

    private array $maxFileSizes = [
        'image'    => MAX_IMAGE_SIZE,
        'document' => MAX_DOCUMENT_SIZE,
        'audio'    => MAX_AUDIO_SIZE,
        'video'    => MAX_VIDEO_SIZE,
        'default'  => MAX_EXECUTABLE_SIZE,
    ];

    private array $executableMimeTypes = [
        'application/x-msdownload',
        'application/x-dosexec',
        'application/x-msi',
        'application/zip',
        'application/x-zip-compressed',
        'application/x-7z-compressed',
        'application/octet-stream',
    ];

    /**
     * Upload d'un fichier générique
     */
    public function upload(int $userId, string $role)
    {
        try
        {
            LoggingMiddleware::logEntry();

            LogService::info('Tentative d\'upload de fichier', [
                'user_id' => $userId,
                'files' => $_FILES
            ]);

            $uploadError = $_FILES['file']['error'] ?? null;
            if (!isset($_FILES['file']) || $uploadError !== UPLOAD_ERR_OK) {
                LoggingMiddleware::logExit(400);
                if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
                    Response::error('Fichier trop volumineux — maximum 20 MB', null, 400);
                } else {
                    Response::error('Aucun fichier valide uploadé', null, 400);
                }
                return false;
            }

            $file = $_FILES['file'];

            // Validation du fichier
            if (!$this->validateFile($file))
            {
                LoggingMiddleware::logExit(400);
                Response::error('Fichier invalide', null, 400);
                return false;
            }

            $input         = Response::getRequestParams();
            $description   = $input['description'] ?? null;
            $accessibility = $input['accessibility'] ?? 'private';
            $folderRaw     = trim($input['folder'] ?? '');

            if (!in_array($accessibility, ['public', 'private', 'grand-public'])) {
                LoggingMiddleware::logExit(422);
                Response::error('Valeur accessibility invalide — valeurs acceptées : public, private, grand-public', null, 422);
                return false;
            }

            if ($accessibility === 'grand-public'
                && strtolower($role) !== 'administrateur'
                && $folderRaw !== 'kestyon'
            ) {
                LoggingMiddleware::logExit(403);
                Response::error('Seul un administrateur peut uploader un fichier grand-public', null, 403);
                return false;
            }

            // Validation du paramètre folder (optionnel)
            if ($folderRaw !== '') {
                if (!$this->validateFolder($folderRaw)) {
                    LoggingMiddleware::logExit(400);
                    Response::error(
                        'Paramètre folder invalide — caractères autorisés : a-z, 0-9, - et _ ; longueur max 80',
                        null, 400
                    );
                    return false;
                }
                $subDir    = $folderRaw;
                $urlPrefix = '/uploads/' . $folderRaw . '/';
            } else {
                $subDir    = 'files';
                $urlPrefix = '/uploads/files/';
            }

            // 1. Créer le dossier uploads s'il n'existe pas
            $uploadDir = __DIR__ . '/../../../uploads/' . $subDir . '/';
            if (!is_dir($uploadDir))
            {
                mkdir($uploadDir, 0755, true);
            }

            // 2. Générer un nom unique sécurisé
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $uniqueName = uniqid() . '_' . time() . '.' . $fileExtension;
            $filePath = $uploadDir . $uniqueName;

            // 3. Déplacer le fichier vers le dossier uploads
            if (!move_uploaded_file($file['tmp_name'], $filePath))
            {
                LogService::error('Échec du déplacement du fichier', [
                    'user_id' => $userId,
                    'file_name' => $file['name'],
                    'destination' => $filePath
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de l\'enregistrement du fichier', null, 500);
                return false;
            }

            // 4. Créer l'entrée en base de données
            $fileModel = new File();
            $fileModel->original_name = $file['name'];
            $fileModel->description = $description;
            $fileModel->file_name = $uniqueName;
            $fileModel->file_path = $urlPrefix . $uniqueName;
            $fileModel->mime_type = $file['type'];
            $fileModel->file_size = $file['size'];
            $fileModel->media_type   = $this->getFileCategory($file['type']);
            $fileModel->uploaded_by  = $userId;
            $fileModel->accessibility = $accessibility;
            $fileModel->upload_ip    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';


            if (!$fileModel->create())
            {
                // Supprimer le fichier physique en cas d'échec en base
                unlink($filePath);
                LogService::error('Échec de l\'enregistrement en base de données', [
                    'user_id' => $userId,
                    'file_name' => $file['name']
                ]);
                LoggingMiddleware::logExit(500);
                Response::error('Erreur lors de l\'enregistrement en base de données', null, 500);
                return false;
            }

            $result = [
                'file' => [
                    'id'           => $fileModel->id,
                    'name'         => $fileModel->original_name,
                    'description'  => $fileModel->description,
                    'file_name'    => $fileModel->file_name,
                    'mime_type'    => $fileModel->mime_type,
                    'file_size'    => $fileModel->file_size,
                    'media_type'    => $fileModel->media_type,
                    'accessibility' => $fileModel->accessibility,
                    'upload_date'   => $fileModel->created_at,
                    'upload_ip'     => $fileModel->upload_ip,
                    'url'           => $fileModel->file_path,
                    'download_url'  => rtrim(APP_URL, '/') . '/files/' . $fileModel->id,
                    'owner_id'      => $userId,
                ]
            ];

            LogService::info('Fichier uploadé avec succès', $result['file']);
            LoggingMiddleware::logExit(201);
            Response::success('Fichier uploadé avec succès', $result, 201);
            return true;
        }
        catch (Exception $e)
        {
            LogService::error('Erreur lors de l\'upload', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            LoggingMiddleware::logExit(500);
            Response::error('Erreur lors de l\'upload: ' . $e->getMessage(), null, 500);
            return false;
        }
    }

    /**
     * Télécharger un fichier
     * 
     * @param int $fileId ID du fichier
     * @param int $userId ID de l'utilisateur qui fait la demande
     * @param string $role Rôle de l'utilisateur
     * @return void
     */
    public function download($fileId, $userId, $role): void {
        // Récupérer les informations du fichier
        $fileModel = new File();
        $fileInfo = $fileModel->findById($fileId);
        
        // Vérifier si le fichier existe
        if (!$fileInfo) {
            Response::error('Fichier non trouvé', null, 404);
            return;
        }
        
        // Vérifier les permissions selon l'accessibilité du fichier
        $accessibility = $fileInfo['accessibility'] ?? 'private';

        if ($accessibility === 'grand-public') {
            // Aucune vérification — accès sans JWT
        } elseif ($accessibility === 'public') {
            if (!$userId) {
                Response::error('Authentification requise', null, 401);
                return;
            }
        } else {
            // private
            $isAdmin = strtolower($role ?? '') === 'administrateur';
            $isOwner = $userId && (int)$fileInfo['uploaded_by'] === (int)$userId;
            if (!$isOwner && !$isAdmin) {
                Response::error('Accès non autorisé', null, 403);
                return;
            }
        }
        
        // Chemin complet vers le fichier
        // Utiliser le bon accès aux clés du tableau
        $filePath = __DIR__ . '/../../..' . $fileInfo['file_path'];
        
        // Vérifier si le fichier existe physiquement
        if (!file_exists($filePath) || !is_readable($filePath)) {
            Response::error('Fichier non disponible sur le serveur', null, 404);
            return;
        }
        
        // Déterminer le type MIME
        $mimeType = $fileInfo['mime_type'] ?? mime_content_type($filePath) ?? 'application/octet-stream';
        
        $isImage = str_starts_with($mimeType, 'image/');

        header('Content-Type: ' . $mimeType);
        // Images: inline + long cache (ID immuable = safe). Autres: attachment, no-cache.
        if ($isImage) {
            header('Content-Disposition: inline; filename="' . $fileInfo['original_name'] . '"');
            header('Cache-Control: public, max-age=31536000, immutable');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
        } else {
            header('Content-Description: File Transfer');
            header('Content-Disposition: attachment; filename="' . $fileInfo['original_name'] . '"');
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: 0');
        }
        header('Content-Length: ' . filesize($filePath));
        
        // Nettoyer les buffers de sortie
        if (ob_get_level()) ob_end_clean();
        flush();
        
        // Envoyer le fichier au client
        readfile($filePath);
        exit;
    }

    /**
     * Télécharger un fichier
     * 
     * @param int $fileId ID du fichier
     * @param int $userId ID de l'utilisateur qui fait la demande
     * @param string $role Rôle de l'utilisateur
     * @return void
     */
    public function getFileInfo($fileId, $userId, $role): void {
        $fileModel = new File();
        $fileInfo  = $fileModel->findById($fileId);

        if (!$fileInfo) {
            Response::error('Information non trouvée: Fichier non trouvé', null, 404);
            return;
        }

        $accessibility = $fileInfo['accessibility'] ?? 'private';

        if ($accessibility === 'grand-public') {
            // Aucune vérification — accès sans JWT
        } elseif ($accessibility === 'public') {
            if (!$userId) {
                Response::error('Authentification requise', null, 401);
                return;
            }
        } else {
            // private
            $isAdmin = strtolower($role ?? '') === 'administrateur';
            $isOwner = $userId && (int)$fileInfo['uploaded_by'] === (int)$userId;
            if (!$isOwner && !$isAdmin) {
                Response::error('Accès non autorisé', null, 403);
                return;
            }
        }

        Response::success('Information sur le fichier récupérée avec succès', [
            'file' => $fileInfo
        ]);
    }

    /**
     * Mettre à jour l'accessibilité d'un fichier
     * PATCH /files/{id}/accessibility
     */
    public function updateAccessibility(int $fileId, int $userId, string $role): void
    {
        $fileModel = new File();
        $fileInfo  = $fileModel->findById($fileId);

        if (!$fileInfo) {
            Response::error('Fichier non trouvé', null, 404);
            return;
        }

        $isAdmin = strtolower($role) === 'administrateur';
        $isOwner = (int)$fileInfo['uploaded_by'] === (int)$userId;

        if (!$isOwner && !$isAdmin) {
            Response::error('Accès non autorisé', null, 403);
            return;
        }

        $input         = Response::getRequestParams();
        $accessibility = $input['accessibility'] ?? '';

        if (!in_array($accessibility, ['public', 'private', 'grand-public'])) {
            Response::error('Valeur accessibility invalide — valeurs acceptées : public, private, grand-public', null, 422);
            return;
        }

        if ($accessibility === 'grand-public' && !$isAdmin) {
            Response::error('Seul un administrateur peut définir un fichier grand-public', null, 403);
            return;
        }

        if (!$fileModel->updateAccessibility($fileId, $accessibility)) {
            Response::error('Erreur lors de la mise à jour', null, 500);
            return;
        }

        Response::success('Accessibilité mise à jour', [
            'file_id'       => $fileId,
            'accessibility' => $accessibility,
        ]);
    }
    
    /**
     * Supprimer un fichier
     */
    public function delete(int $fileId, int $userId, string $role): void
    {
        try
        {
            $input = Response::getRequestParams();
            $forceDelete = isset($input['force_delete']) ? (bool)$input['force_delete'] : false;

            LogService::info('Tentative de suppression de fichier', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'role' => $role,
                'force_delete' => $forceDelete
            ]);

            // 1. Vérifier que le fichier existe
            $fileModel = new File();
            $fileInfo = $fileModel->findById($fileId, $forceDelete);
            if (!$fileInfo) {
                Response::error('Fichier non trouvé', null, 404);
                return;
            }

            // 2. Vérifier les permissions (propriétaire ou admin)
            $isOwner = ((int)$fileInfo['uploaded_by'] === (int)$userId);
            $isAdmin = (strtolower($role) === 'administrateur');
            if (!$isOwner && !$isAdmin) {
                Response::error('Accès non autorisé pour supprimer ce fichier', null, 403);
                return;
            }

            // 3. Supprimer le fichier physique si force_delete
            $filePath = __DIR__ . '/../../..' . $fileInfo['file_path'];
            $fileDeleted = false;
            if ($forceDelete && file_exists($filePath)) {
                $fileDeleted = unlink($filePath);
            }

            // 4. Supprimer l'entrée en base (soft delete ou hard delete)
            $fileModel->id = $fileId;
            $dbDeleted = $fileModel->delete($forceDelete);

            LogService::info('Fichier supprimé', [
                'file_id' => $fileId,
                'deleted_by' => $userId,
                'force_delete' => $forceDelete,
                'file_deleted' => $fileDeleted,
                'db_deleted' => $dbDeleted ?? null
            ]);

            Response::success('Fichier supprimé avec succès', [
                'file_id' => $fileId,
                'force_delete' => $forceDelete,
                'file_deleted' => $fileDeleted,
                'db_deleted' => $dbDeleted ?? null
            ]);
        }
        catch (Exception $e)
        {
            LogService::error('Erreur lors de la suppression', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::error('Erreur lors de la suppression', null, 500);
        }
    }

    /**
     * Restaurer un fichier supprimé (soft delete)
     */
    public function restore(int $fileId, int $userId, string $role): void
    {
        try
        {
            LogService::info('Tentative de restauration de fichier', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'role' => $role
            ]);

            // 1. Vérifier que le fichier existe et est supprimé
            $fileModel = new File();
            $fileInfo = $fileModel->findById($fileId, true); // Avec les supprimés
            if (!$fileInfo) {
                Response::error('Fichier non trouvé', null, 404);
                return;
            }

            // Vérifier que le fichier est bien supprimé (soft delete)
            if (is_null($fileInfo['deleted_at'])) {
                Response::error('Ce fichier n\'est pas supprimé', null, 400);
                return;
            }

            // 2. Vérifier les permissions (propriétaire ou admin)
            $isOwner = ((int)$fileInfo['uploaded_by'] === (int)$userId);
            $isAdmin = (strtolower($role) === 'administrateur');
            if (!$isOwner && !$isAdmin) {
                Response::error('Accès non autorisé pour restaurer ce fichier', null, 403);
                return;
            }

            // 3. Restaurer le fichier
            $fileModel->id = $fileId;
            $restored = $fileModel->restore();

            if (!$restored) {
                Response::error('Erreur lors de la restauration', null, 500);
                return;
            }

            LogService::info('Fichier restauré', [
                'file_id' => $fileId,
                'restored_by' => $userId
            ]);

            Response::success('Fichier restauré avec succès', [
                'file_id' => $fileId
            ]);
        }
        catch (Exception $e)
        {
            LogService::error('Erreur lors de la restauration', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            Response::error('Erreur lors de la restauration', null, 500);
        }
    }

    /**
     * Lister les fichiers d'un utilisateur
     */
    public function getUserFiles(int $targetUserId, int $requestingUserId, string $role): void
    {
        try
        {
            // Vérification des permissions
            if ($targetUserId !== $requestingUserId && $role !== 'ADMINISTRATEUR')
            {
                Response::error('Accès non autorisé', null, 403);
                return;
            }

            // Récupérer les paramètres de pagination
            $input = Response::getRequestParams();
            $page = max(1, intval($input['page'] ?? 1));
            $limit = min(100, max(1, intval($input['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            LogService::info('Récupération des fichiers utilisateur', [
                'target_user' => $targetUserId,
                'requesting_user' => $requestingUserId,
                'page' => $page,
                'limit' => $limit
            ]);

            // Récupérer les fichiers de l'utilisateur depuis la base de données
            $fileModel = new File();
            $files = $fileModel->getByUserId($targetUserId, $limit, $offset);
            
            // Récupérer les statistiques
            $stats = $fileModel->getUserFileStats($targetUserId);
            
            // Calculer les totaux
            $totalFiles = 0;
            $totalSize = 0;
            $categoriesCount = [];
            
            foreach ($stats as $stat) {
                $totalFiles += $stat['total_files'];
                $totalSize += $stat['total_size'] ?? 0;
                $categoriesCount[$stat['media_type']] = $stat['count_by_category'];
            }

            // Formater les fichiers pour la réponse
            $formattedFiles = [];
            foreach ($files as $file) {
                $formattedFiles[] = [
                    'id'            => (int)$file['id'],
                    'original_name' => $file['original_name'],
                    'description' => $file['description'],
                    'mime_type' => $file['mime_type'],
                    'media_type' => $file['media_type'],
                    'file_size' => (int)$file['file_size'],
                    'download_count' => (int)$file['download_count'],
                    'upload_date' => $file['created_at'],
                    'updated_at' => $file['updated_at'],
                    'url' => $file['file_path']
                ];
            }

            // Calculer les informations de pagination
            $totalPages = ceil($totalFiles / $limit);
            $hasNextPage = $page < $totalPages;
            $hasPreviousPage = $page > 1;

            $result = [
                'files' => $formattedFiles,
                'pagination' => [
                    'current_page' => $page,
                    'limit' => $limit,
                    'total_files' => $totalFiles,
                    'total_pages' => $totalPages,
                    'has_next_page' => $hasNextPage,
                    'has_previous_page' => $hasPreviousPage
                ],
                'statistics' => [
                    'total_files' => $totalFiles,
                    'total_size' => $totalSize,
                    'categories' => $categoriesCount
                ],
                'user_id' => $targetUserId
            ];

            Response::success('Fichiers récupérés avec succès', $result);
        }
        catch (Exception $e)
        {
            LogService::error('Erreur lors de la récupération des fichiers', [
                'target_user' => $targetUserId,
                'error' => $e->getMessage()
            ]);
            Response::error('Erreur lors de la récupération', null, 500);
        }
    }

    /**
     * Lister les fichiers d'un dossier (ADMINISTRATEUR uniquement)
     * GET /files?folder=<slug>
     */
    public function listByFolder(int $userId, string $role): void
    {
        if (strtolower($role) !== 'administrateur') {
            Response::error('Accès réservé aux administrateurs', null, 403);
            return;
        }

        $input  = Response::getRequestParams();
        $folder = trim($input['folder'] ?? '');

        if ($folder === '') {
            Response::error('Paramètre folder requis', null, 400);
            return;
        }

        if (!$this->validateFolder($folder)) {
            Response::error(
                'Paramètre folder invalide — caractères autorisés : a-z, 0-9, - et _ ; longueur max 80',
                null, 400
            );
            return;
        }

        $fileModel = new File();
        $files     = $fileModel->getByFolder($folder);

        Response::success('Fichiers récupérés', [
            'files'  => $files,
            'folder' => $folder,
            'total'  => count($files),
        ]);
    }

    /**
     * Valider un slug de dossier
     * Autorisé : a-z 0-9 - _  |  max 80 car.  |  interdit : .. / \ espaces
     */
    private function validateFolder(string $folder): bool
    {
        if (strlen($folder) > 80) {
            return false;
        }
        return (bool) preg_match('/^[a-z0-9_-]+$/', $folder);
    }

    /**
     * Valider un fichier uploadé
     */
    private function validateFile(array $file): bool
    {
        // Détecter le type MIME réel du fichier
        $realMimeType = mime_content_type($file['tmp_name']);

        // Vérifier le type MIME réel d'abord
        if (!in_array($realMimeType, $this->allowedMimeTypes))
        {
            Response::error("Type de fichier non autorisé. Type détecté: $realMimeType", null, 400);
            return false;
        }

        // Vérifier la taille (les exécutables/archives ont leur propre limite)
        if (in_array($realMimeType, $this->executableMimeTypes)) {
            $maxSize = MAX_EXECUTABLE_SIZE;
        } else {
            $fileType = $this->getFileCategory($realMimeType);
            $maxSize  = $this->maxFileSizes[$fileType] ?? $this->maxFileSizes['default'];
        }

        if ($file['size'] > $maxSize)
        {
            Response::error('Fichier trop volumineux. Taille maximum: ' . ($maxSize / 1024 / 1024) . ' MB', null, 400);
            return false;
        }

        // Vérifier l'extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'mp3', 'wav', 'ogg', 'mp4', 'avi', 'mov', 'exe', 'msi', 'zip', '7z'];

        if (!in_array($extension, $allowedExtensions))
        {
            Response::error('Extension de fichier non autorisée', null, 400);
            return false;
        }

        return true;
    }

    /**
     * Déterminer la catégorie d'un fichier
     */
    private function getFileCategory(string $mimeType): string
    {
        return File::getFileCategory($mimeType);
    }

    /**
     * GET /files/png-from-svg?id=<id>[&width=..&height=..&dpi=..&bg=..&scale=..]
     * Convertit un SVG stocké en PNG via rsvg-convert, Inkscape ou ImageMagick.
     */
    public function svgToPng(?int $userId, ?string $role): void
    {
        $id     = isset($_GET['id'])     ? (int) $_GET['id']     : 0;
        $width  = isset($_GET['width'])  ? (int) $_GET['width']  : null;
        $height = isset($_GET['height']) ? (int) $_GET['height'] : null;
        $dpi    = isset($_GET['dpi'])    ? (int) $_GET['dpi']    : 96;
        $bg     = $_GET['bg'] ?? '';
        $scale  = isset($_GET['scale'])  ? (float) $_GET['scale'] : 1.0;

        if ($id <= 0) {
            Response::error('Paramètre id requis', null, 400);
            return;
        }
        if ($width !== null && ($width < 1 || $width > 4096)) {
            Response::error('width invalide (1-4096 px)', null, 422);
            return;
        }
        if ($height !== null && ($height < 1 || $height > 4096)) {
            Response::error('height invalide (1-4096 px)', null, 422);
            return;
        }
        if ($dpi < 1 || $dpi > 600) {
            Response::error('dpi invalide (1-600)', null, 422);
            return;
        }
        if ($bg !== '' && !preg_match('/^[0-9a-fA-F]{1,8}$/', $bg)) {
            Response::error('bg invalide — format hex sans #, ex: ffffff', null, 422);
            return;
        }
        if ($scale <= 0 || $scale > 10) {
            Response::error('scale invalide (0.01-10)', null, 422);
            return;
        }

        $fileModel = new File();
        $fileInfo  = $fileModel->findById($id);
        if (!$fileInfo) {
            Response::error('Fichier SVG introuvable', null, 404);
            return;
        }

        $accessibility = $fileInfo['accessibility'] ?? 'private';
        if ($accessibility === 'grand-public') {
            // accès libre
        } elseif ($accessibility === 'public') {
            if (!$userId) {
                Response::error('Authentification requise', null, 401);
                return;
            }
        } else {
            $isAdmin = strtolower($role ?? '') === 'administrateur';
            $isOwner = $userId && (int) $fileInfo['uploaded_by'] === (int) $userId;
            if (!$isOwner && !$isAdmin) {
                Response::error('Accès non autorisé', null, 403);
                return;
            }
        }

        $mime  = $fileInfo['mime_type'] ?? '';
        $ext   = strtolower(pathinfo($fileInfo['file_name'] ?? '', PATHINFO_EXTENSION));
        $isSvg = $mime === 'image/svg+xml' || $ext === 'svg';
        if (!$isSvg) {
            Response::error('Le fichier n\'est pas un SVG', null, 422);
            return;
        }

        $svgPath = __DIR__ . '/../../..' . $fileInfo['file_path'];
        if (!file_exists($svgPath) || !is_readable($svgPath)) {
            Response::error('Fichier SVG introuvable sur le serveur', null, 404);
            return;
        }

        $outPath = tempnam(sys_get_temp_dir(), 'cmem2_png_') . '.png';
        $opts    = compact('width', 'height', 'dpi', 'bg', 'scale');

        try {
            $ok = $this->runSvgConversion($svgPath, $outPath, $opts);
        } catch (Exception $e) {
            if (file_exists($outPath)) unlink($outPath);
            LogService::error('Erreur conversion SVG→PNG', ['id' => $id, 'error' => $e->getMessage()]);
            Response::error('Erreur lors de la conversion SVG', null, 500);
            return;
        }

        if (!$ok || !file_exists($outPath) || filesize($outPath) === 0) {
            if (file_exists($outPath)) unlink($outPath);
            Response::error('Conversion SVG→PNG impossible — outil de conversion indisponible sur le serveur', null, 500);
            return;
        }

        $pngData = file_get_contents($outPath);
        unlink($outPath);

        if (ob_get_level()) ob_end_clean();
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        header('Content-Disposition: inline');
        header('Content-Length: ' . strlen($pngData));
        echo $pngData;
        exit;
    }

    private function detectSvgConverter(): string
    {
        foreach (['rsvg-convert', 'inkscape', 'convert'] as $cmd) {
            $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = @proc_open(['which', $cmd], $desc, $pipes);
            if (!is_resource($proc)) {
                continue;
            }
            $out  = stream_get_contents($pipes[1]);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            if ($code === 0 && trim($out) !== '') {
                return $cmd;
            }
        }
        return '';
    }

    private function runSvgConversion(string $svgPath, string $outPath, array $opts): bool
    {
        $converter = $this->detectSvgConverter();
        if ($converter === '') {
            return false;
        }

        $width  = $opts['width']  ?? null;
        $height = $opts['height'] ?? null;
        $dpi    = $opts['dpi']    ?? 96;
        $bg     = $opts['bg']     ?? '';
        $scale  = $opts['scale']  ?? 1.0;

        switch ($converter) {
            case 'rsvg-convert':
                $args = ['rsvg-convert'];
                if ($width !== null)  { $args[] = '--width';  $args[] = (string) $width;  }
                if ($height !== null) { $args[] = '--height'; $args[] = (string) $height; }
                $args[] = '--dpi-x'; $args[] = (string) $dpi;
                $args[] = '--dpi-y'; $args[] = (string) $dpi;
                if ($bg !== '') { $args[] = '--background-color'; $args[] = '#' . $bg; }
                if ($scale != 1.0 && $width === null && $height === null) {
                    $args[] = '--zoom'; $args[] = (string) $scale;
                }
                $args[] = '--format'; $args[] = 'png';
                $args[] = '--output'; $args[] = $outPath;
                $args[] = $svgPath;
                break;

            case 'inkscape':
                $args = ['inkscape', '--export-type=png'];
                if ($width !== null)  { $args[] = '--export-width='  . $width;  }
                if ($height !== null) { $args[] = '--export-height=' . $height; }
                $args[] = '--export-dpi=' . $dpi;
                if ($bg !== '') { $args[] = '--export-background=#' . $bg; }
                $args[] = '--export-filename=' . $outPath;
                $args[] = $svgPath;
                break;

            default: // ImageMagick convert
                $args = ['convert', '-density', (string) $dpi];
                if ($bg !== '') { $args[] = '-background'; $args[] = '#' . $bg; }
                if ($width !== null && $height !== null) {
                    $args[] = '-resize'; $args[] = "{$width}x{$height}!";
                } elseif ($width !== null) {
                    $args[] = '-resize'; $args[] = (string) $width;
                } elseif ($height !== null) {
                    $args[] = '-resize'; $args[] = 'x' . $height;
                } elseif ($scale != 1.0) {
                    $pct = (int) round($scale * 100);
                    $args[] = '-resize'; $args[] = "{$pct}%";
                }
                $args[] = $svgPath;
                $args[] = $outPath;
                break;
        }

        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($args, $desc, $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($proc) === 0;
    }


}