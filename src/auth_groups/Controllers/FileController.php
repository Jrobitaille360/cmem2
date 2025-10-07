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
        'video/x-msvideo'
    ];

    private array $maxFileSizes = [
        'image' => 5 * 1024 * 1024,    // 5 MB
        'document' => 10 * 1024 * 1024, // 10 MB
        'audio' => 20 * 1024 * 1024,    // 20 MB
        'video' => 50 * 1024 * 1024,    // 50 MB
        'default' => 5 * 1024 * 1024    // 5 MB
    ];

    /**
     * Upload d'un fichier générique
     */
    public function upload(int $userId)
    {
        try
        {
            LoggingMiddleware::logEntry();

            LogService::info('Tentative d\'upload de fichier', [
                'user_id' => $userId,
                'files' => $_FILES
            ]);

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK)
            {
                LoggingMiddleware::logExit(400);
                Response::error('Aucun fichier valide uploadé', null, 400);
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

            $input =  Response::getRequestParams();
            $description = $input['description'] ?? null;

            // 1. Créer le dossier uploads s'il n'existe pas
            $uploadDir = __DIR__ . '/../../uploads/files/';
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
            $fileModel->file_path = '/uploads/files/' . $uniqueName;
            $fileModel->mime_type = $file['type'];
            $fileModel->file_size = $file['size'];
            $fileModel->media_type = $this->getFileCategory($file['type']);
            $fileModel->uploaded_by = $userId;
            $fileModel->upload_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';


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
                'file_id' => $fileModel->id,
                'original_name' => $fileModel->original_name,
                'description' => $fileModel->description,
                'file_name' => $fileModel->file_name,
                'mime_type' => $fileModel->mime_type,
                'file_size' => $fileModel->file_size,
                'media_type' => $fileModel->media_type,
                'upload_date' => $fileModel->created_at,
                'upload_ip' => $fileModel->upload_ip,
                'url' => $fileModel->file_path,
                'owner_id' => $userId
            ];

            LogService::info('Fichier uploadé avec succès', $result);
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
        
        // Vérifier les permissions
            // Autoriser l'admin ou l'uploader
            if (!isset($userId) || (strtolower($role) !== 'administrateur' && (int)$fileInfo['uploaded_by'] != (int)$userId)) {
                Response::error('Accès non autorisé', null, 403);
                return;
            }
        
        // Chemin complet vers le fichier
        // Utiliser le bon accès aux clés du tableau
        $filePath = __DIR__ . '/../..' . $fileInfo['file_path'];
        
        // Vérifier si le fichier existe physiquement
        if (!file_exists($filePath) || !is_readable($filePath)) {
            Response::error('Fichier non disponible sur le serveur', null, 404);
            return;
        }
        
        // Déterminer le type MIME
        $mimeType = $fileInfo['mime_type'] ?? mime_content_type($filePath) ?? 'application/octet-stream';
        
        // Configurer les en-têtes pour le téléchargement
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $fileInfo['original_name'] . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
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
        // Récupérer les informations du fichier
        $fileModel = new File();
        $fileInfo = $fileModel->findById($fileId);
        
        // Vérifier si le fichier existe
        if (!$fileInfo) {
            Response::error('Information non trouvée: Fichier non trouvé', null, 404);
            return;
        }

        Response::success('Information sur le fichier récupérée avec succès', [
            'data' => $fileInfo
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
            $filePath = __DIR__ . '/../..' . $fileInfo['file_path'];
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
                    'file_id' => (int)$file['id'],
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

        // Vérifier la taille
        $fileType = $this->getFileCategory($realMimeType);
        $maxSize = $this->maxFileSizes[$fileType] ?? $this->maxFileSizes['default'];

        if ($file['size'] > $maxSize)
        {
            Response::error('Fichier trop volumineux. Taille maximum: ' . ($maxSize / 1024 / 1024) . ' MB', null, 400);
            return false;
        }

        // Vérifier l'extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'mp3', 'wav', 'ogg', 'mp4', 'avi', 'mov'];

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


}