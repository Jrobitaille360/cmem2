<?php

namespace AuthGroups\Models;

use PDO;
use Exception;

/**
 * Modèle File pour la gestion des fichiers uploadés
 */
class File extends BaseModel
{
    protected $table = 'files';

    // Propriétés du fichier
    public $original_name;
    public $description;
    public $file_name;
    public $file_path;
    public $mime_type;
    public $file_size;
    public $media_type;
    public $uploaded_by;
    public $accessibility;
    public $upload_ip;
    public $download_count;


    /**
     * Créer un nouveau fichier en base
     */
    public function create()
    {
        $query = "INSERT INTO {$this->table}
                 (original_name, description, file_name, file_path, mime_type, file_size,
                  media_type, uploaded_by, accessibility, upload_ip)
                 VALUES (:original_name, :description, :file_name, :file_path, :mime_type, :file_size,
                         :media_type, :uploaded_by, :accessibility, :upload_ip)";

        $stmt = $this->getDb()->prepare($query);

        // Nettoyage des données
        $this->original_name = htmlspecialchars(strip_tags($this->original_name));
        $this->file_name = htmlspecialchars(strip_tags($this->file_name));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->accessibility = in_array($this->accessibility, ['public', 'private']) ? $this->accessibility : 'private';

        $stmt->bindParam(':original_name', $this->original_name);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':file_name', $this->file_name);
        $stmt->bindParam(':file_path', $this->file_path);
        $stmt->bindParam(':mime_type', $this->mime_type);
        $stmt->bindParam(':file_size', $this->file_size, PDO::PARAM_INT);
        $stmt->bindParam(':media_type', $this->media_type);
        $stmt->bindParam(':uploaded_by', $this->uploaded_by, PDO::PARAM_INT);
        $stmt->bindParam(':accessibility', $this->accessibility);
        $stmt->bindParam(':upload_ip', $this->upload_ip);

        if ($stmt->execute()) {
            $this->id = $this->getDb()->lastInsertId();
            $this->created_at = date('Y-m-d H:i:s');
            return true;
        }

        return false;
    }

    /**
     * Mettre à jour l'enregistrement courant
     */
    public function update()
    {
        if (!$this->id) {
            return false;
        }

        $query = "UPDATE {$this->table}
                 SET original_name = :original_name, description = :description,
                     accessibility = :accessibility, updated_at = NOW()
                 WHERE id = :id AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);

        $original_name  = htmlspecialchars(strip_tags($this->original_name));
        $description    = htmlspecialchars(strip_tags($this->description));
        $accessibility  = in_array($this->accessibility, ['public', 'private']) ? $this->accessibility : 'private';

        $stmt->bindParam(':original_name', $original_name);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':accessibility', $accessibility);
        $stmt->bindParam(':id', $this->id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Récupérer les fichiers d'un utilisateur
     */
    public function getByUserId($userId, $limit = 20, $offset = 0)
    {
        $query = "SELECT
                    id, original_name, description, file_name, file_path, mime_type,
                    file_size, media_type, accessibility, download_count,
                    created_at, updated_at
                  FROM {$this->table}
                  WHERE uploaded_by = :user_id AND deleted_at IS NULL
                  ORDER BY created_at DESC
                  LIMIT :limit OFFSET :offset";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Vérifier si un utilisateur est propriétaire du fichier
     */
    public function isOwner($fileId, $userId)
    {
        $query = "SELECT COUNT(*) as count FROM {$this->table} 
                 WHERE id = :file_id AND uploaded_by = :user_id AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':file_id', $fileId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }

    /**
     * Incrémenter le compteur de téléchargements
     */
    public function incrementDownloadCount($fileId)
    {
        $query = "UPDATE {$this->table} 
                 SET download_count = download_count + 1 
                 WHERE id = :file_id AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':file_id', $fileId, PDO::PARAM_INT);
        
        return $stmt->execute();
    }

    /**
     * Obtenir les statistiques de fichiers d'un utilisateur
     */
    public function getUserFileStats($userId)
    {
        $query = "SELECT 
                    COUNT(*) as total_files,
                    SUM(file_size) as total_size,
                    media_type,
                    COUNT(*) as count_by_category
                  FROM {$this->table}
                  WHERE uploaded_by = :user_id AND deleted_at IS NULL
                  GROUP BY media_type";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lister les fichiers d'un sous-dossier uploads/<folder>/
     */
    public function getByFolder(string $folder): array
    {
        $pattern = '/uploads/' . $folder . '/%';
        $query   = "SELECT id, original_name, file_name, mime_type, file_size, file_path, created_at
                    FROM {$this->table}
                    WHERE file_path LIKE :pattern AND deleted_at IS NULL
                    ORDER BY created_at DESC";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindValue(':pattern', $pattern);
        $stmt->execute();

        return array_map(static function (array $f): array {
            return [
                'id'            => (int) $f['id'],
                'original_name' => $f['original_name'],
                'file_name'     => $f['file_name'],
                'mime_type'     => $f['mime_type'],
                'file_size'     => (int) $f['file_size'],
                'url'           => $f['file_path'],
                'upload_date'   => $f['created_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function getFileCategory(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/'))
        {
            return 'image';
        }
        elseif (str_starts_with($mimeType, 'audio/'))
        {
            return 'audio';
        }
        elseif (str_starts_with($mimeType, 'video/'))
        {
            return 'video';
        }
        elseif (in_array($mimeType, [
            'application/x-msdownload',
            'application/x-dosexec',
            'application/x-msi',
            'application/zip',
            'application/x-zip-compressed',
            'application/x-7z-compressed',
            'application/octet-stream',
        ]))
        {
            return 'default';
        }
        else
        {
            return 'document';
        }
    }

    /**
     * Mettre à jour uniquement l'accessibilité d'un fichier
     */
    public function updateAccessibility($fileId, string $accessibility): bool
    {
        if (!in_array($accessibility, ['public', 'private'])) {
            return false;
        }

        $query = "UPDATE {$this->table}
                 SET accessibility = :accessibility, updated_at = NOW()
                 WHERE id = :id AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':accessibility', $accessibility);
        $stmt->bindParam(':id', $fileId, PDO::PARAM_INT);

        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    public function deleteById($fileId)
    {
        if (!$fileId) {
            return false;
        }

        $query = "UPDATE {$this->table} 
                 SET deleted_at = NOW()
                 WHERE id = :id AND deleted_at IS NULL";

        $stmt = $this->getDb()->prepare($query);
        $stmt->bindParam(':id', $fileId, PDO::PARAM_INT);

        return $stmt->execute();
    }

}
