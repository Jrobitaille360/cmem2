<?php
/**
 * Modèle pour la gestion des clés API
 * Permet l'authentification alternative au JWT
 */

namespace AuthGroups\Models;

use PDO;
use PDOException;

class ApiKey extends BaseModel
{
    protected $table = 'api_keys';
    
    // Longueur de la clé API (sans préfixe)
    const KEY_LENGTH = 32; // 32 caractères aléatoires
    
    // Préfixes disponibles
    const PREFIX_LIVE = 'ag_live';
    const PREFIX_TEST = 'ag_test';
    
    // Environnements
    const ENV_PRODUCTION = 'production';
    const ENV_TEST = 'test';
    
    // Scopes disponibles
    const SCOPE_READ = 'read';
    const SCOPE_WRITE = 'write';
    const SCOPE_DELETE = 'delete';
    const SCOPE_ADMIN = 'admin';
    const SCOPE_ALL = '*';
    
    /**
     * Implémentation create (requis par BaseModel)
     */
    public function create()
    {
        // Non utilisé directement - utiliser la méthode static generate() à la place
        throw new \Exception("Use ApiKey::generate() instead of create()");
    }
    
    /**
     * Implémentation update (requis par BaseModel)
     */
    public function update()
    {
        // Mise à jour basique si nécessaire
        $db = $this->getDb();
        $stmt = $db->prepare("
            UPDATE {$this->table}
            SET name = :name, notes = :notes, metadata = :metadata
            WHERE id = :id
        ");
        
        return $stmt->execute([
            ':id' => $this->id,
            ':name' => $this->name ?? '',
            ':notes' => $this->notes ?? null,
            ':metadata' => $this->metadata ?? null
        ]);
    }
    
    /**
     * Générer une nouvelle clé API
     * 
     * @param int $userId ID de l'utilisateur
     * @param string $name Nom descriptif de la clé
     * @param array $options Options (scopes, environment, expires_in, etc.)
     * @return array ['key' => clé complète, 'data' => données en DB]
     */
    public static function generate(int $userId, string $name, array $options = []): array
    {
        // Déterminer l'environnement
        $environment = $options['environment'] ?? self::ENV_PRODUCTION;
        $prefix = $environment === self::ENV_TEST ? self::PREFIX_TEST : self::PREFIX_LIVE;
        
        // Générer une clé aléatoire sécurisée
        $randomKey = self::generateSecureKey(self::KEY_LENGTH);
        $fullKey = $prefix . '_' . $randomKey;
        
        // Hash de la clé pour stockage
        $keyHash = hash('sha256', $fullKey);
        $last4 = substr($randomKey, -4);
        
        // Scopes (permissions)
        $scopes = $options['scopes'] ?? [self::SCOPE_READ, self::SCOPE_WRITE];
        
        // Rate limiting
        $rateLimitPerMinute = $options['rate_limit_per_minute'] ?? 60;
        $rateLimitPerHour = $options['rate_limit_per_hour'] ?? 3600;
        
        // Expiration
        $expiresAt = null;
        if (isset($options['expires_in_days'])) {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$options['expires_in_days']} days"));
        }
        
        // Métadonnées
        $metadata = $options['metadata'] ?? null;
        if ($metadata && !is_string($metadata)) {
            $metadata = json_encode($metadata);
        }
        
        // Insérer en base de données
        $model = new self();
        $db = $model->getDb();
        $stmt = $db->prepare("
            INSERT INTO api_keys 
            (user_id, name, key_prefix, key_hash, last_4, scopes, environment, 
             rate_limit_per_minute, rate_limit_per_hour, expires_at, metadata, notes)
            VALUES 
            (:user_id, :name, :key_prefix, :key_hash, :last_4, :scopes, :environment,
             :rate_limit_per_minute, :rate_limit_per_hour, :expires_at, :metadata, :notes)
        ");
        
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
            ':key_prefix' => $prefix,
            ':key_hash' => $keyHash,
            ':last_4' => $last4,
            ':scopes' => json_encode($scopes),
            ':environment' => $environment,
            ':rate_limit_per_minute' => $rateLimitPerMinute,
            ':rate_limit_per_hour' => $rateLimitPerHour,
            ':expires_at' => $expiresAt,
            ':metadata' => $metadata,
            ':notes' => $options['notes'] ?? null
        ]);
        
        $keyId = $db->lastInsertId();
        
        // Récupérer les données complètes
        $keyData = $model->findById($keyId);
        
        return [
            'key' => $fullKey, // La clé complète (à montrer UNE SEULE FOIS)
            'data' => $keyData
        ];
    }
    
    /**
     * Générer une clé aléatoire sécurisée
     */
    private static function generateSecureKey(int $length): string
    {
        $bytes = random_bytes($length);
        return bin2hex($bytes);
    }
    
    /**
     * Valider une clé API et retourner les données si valide
     * 
     * @param string $apiKey Clé API complète (avec préfixe)
     * @return array|false|null Données de la clé si valide, false si révoquée/expirée, null si inexistante
     */
    public static function validate(string $apiKey)
    {
        // Hash de la clé fournie
        $keyHash = hash('sha256', $apiKey);
        
        $model = new self();
        $db = $model->getDb();
        
        // D'abord, chercher la clé sans filtrer par révocation/expiration
        $stmt = $db->prepare("
            SELECT * FROM api_keys
            WHERE key_hash = :key_hash
        ");
        
        $stmt->execute([':key_hash' => $keyHash]);
        $keyData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Clé inexistante
        if (!$keyData) {
            return null;
        }
        
        // Clé révoquée
        if ($keyData['revoked_at'] !== null) {
            return false; // Indique que la clé existe mais est révoquée
        }
        
        // Clé expirée
        if ($keyData['expires_at'] !== null && strtotime($keyData['expires_at']) < time()) {
            return false; // Indique que la clé existe mais est expirée
        }
        
        // Mettre à jour les statistiques d'utilisation
        self::updateUsageStats($keyData['id']);
        
        // Décoder les scopes JSON
        if ($keyData['scopes']) {
            $keyData['scopes'] = json_decode($keyData['scopes'], true);
        }
        
        // Décoder metadata JSON
        if ($keyData['metadata']) {
            $keyData['metadata'] = json_decode($keyData['metadata'], true);
        }
        
        return $keyData;
    }
    
    /**
     * Mettre à jour les statistiques d'utilisation
     */
    private static function updateUsageStats(int $keyId): void
    {
        $model = new self();
        $db = $model->getDb();
        $stmt = $db->prepare("
            UPDATE api_keys
            SET total_requests = total_requests + 1,
                last_used_at = NOW(),
                last_used_ip = :ip
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':id' => $keyId,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }
    
    /**
     * Vérifier si une clé a un scope spécifique
     */
    public static function hasScope(array $keyData, string $scope): bool
    {
        $scopes = $keyData['scopes'] ?? [];
        
        // Si le scope est '*', tous les scopes sont autorisés
        if (in_array(self::SCOPE_ALL, $scopes)) {
            return true;
        }
        
        return in_array($scope, $scopes);
    }
    
    /**
     * Révoquer une clé API
     */
    public static function revoke(int $keyId, ?string $reason = null): bool
    {
        $model = new self();
        $db = $model->getDb();
        $stmt = $db->prepare("
            UPDATE api_keys
            SET revoked_at = NOW(),
                revoked_reason = :reason
            WHERE id = :id AND revoked_at IS NULL
        ");
        
        return $stmt->execute([
            ':id' => $keyId,
            ':reason' => $reason
        ]);
    }
    
    /**
     * Lister toutes les clés d'un utilisateur
     */
    public static function getByUserId(int $userId, bool $activeOnly = false): array
    {
        $model = new self();
        $db = $model->getDb();
        
        $sql = "SELECT * FROM api_keys WHERE user_id = :user_id";
        
        if ($activeOnly) {
            $sql .= " AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())";
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        
        $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Décoder JSON pour chaque clé
        foreach ($keys as &$key) {
            if ($key['scopes']) {
                $key['scopes'] = json_decode($key['scopes'], true);
            }
            if ($key['metadata']) {
                $key['metadata'] = json_decode($key['metadata'], true);
            }
            
            // Ajouter un statut calculé
            $key['status'] = self::calculateStatus($key);
        }
        
        return $keys;
    }
    
    /**
     * Calculer le statut d'une clé
     */
    private static function calculateStatus(array $keyData): string
    {
        if ($keyData['revoked_at']) {
            return 'revoked';
        }
        
        if ($keyData['expires_at'] && strtotime($keyData['expires_at']) < time()) {
            return 'expired';
        }
        
        return 'active';
    }
    
    /**
     * Vérifier le rate limiting pour une clé
     * 
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => timestamp]
     */
    public static function checkRateLimit(int $keyId): array
    {
        $model = new self();
        $db = $model->getDb();
        
        // Récupérer les limites de la clé
        $stmt = $db->prepare("
            SELECT rate_limit_per_minute, rate_limit_per_hour 
            FROM api_keys
            WHERE id = :id
        ");
        $stmt->execute([':id' => $keyId]);
        $limits = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$limits) {
            return ['allowed' => false, 'remaining' => 0, 'reset_at' => time()];
        }
        
        // TODO: Implémenter un système de comptage avec Redis ou table séparée
        // Pour l'instant, on autorise toutes les requêtes
        
        return [
            'allowed' => true,
            'remaining' => $limits['rate_limit_per_minute'],
            'reset_at' => time() + 60
        ];
    }
    
    /**
     * Obtenir les statistiques d'une clé
     */
    public static function getStats(int $keyId): ?array
    {
        $model = new self();
        $db = $model->getDb();
        $stmt = $db->prepare("
            SELECT 
                id,
                name,
                environment,
                total_requests,
                last_used_at,
                last_used_ip,
                created_at,
                DATEDIFF(NOW(), created_at) as age_days,
                CASE 
                    WHEN last_used_at IS NOT NULL 
                    THEN DATEDIFF(NOW(), last_used_at)
                    ELSE NULL
                END as days_since_last_use
            FROM api_keys
            WHERE id = :id
        ");
        
        $stmt->execute([':id' => $keyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Nettoyer les clés expirées (marquer comme révoquées)
     */
    public static function cleanupExpired(): int
    {
        $model = new self();
        $db = $model->getDb();
        $stmt = $db->prepare("
            UPDATE api_keys
            SET revoked_at = NOW(),
                revoked_reason = 'Expired automatically'
            WHERE expires_at IS NOT NULL 
              AND expires_at < NOW() 
              AND revoked_at IS NULL
        ");
        
        $stmt->execute();
        return $stmt->rowCount();
    }
}
