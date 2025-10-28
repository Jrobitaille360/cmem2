# Structure SQL de la table api_keys

## Table `api_keys`

Cette table est définie dans le fichier SQL `create_proc_reset_auth_groups.sql`.

```sql
CREATE TABLE IF NOT EXISTS api_keys (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    
    -- Informations de la clé
    name VARCHAR(255) NOT NULL COMMENT 'Nom descriptif de la clé API',
    key_prefix VARCHAR(10) NOT NULL DEFAULT 'ag_live' COMMENT 'Préfixe de la clé (ag_live, ag_test)',
    key_hash VARCHAR(255) NOT NULL COMMENT 'Hash SHA-256 de la clé complète',
    last_4 VARCHAR(4) NOT NULL COMMENT '4 derniers caractères visibles de la clé',
    
    -- Permissions et configuration
    scopes JSON DEFAULT NULL COMMENT 'Permissions/scopes de la clé (JSON array)',
    environment ENUM('production', 'test') NOT NULL DEFAULT 'production' COMMENT 'Environnement (production/test)',
    
    -- Rate limiting
    rate_limit_per_minute INT(11) DEFAULT 60 COMMENT 'Nombre max de requêtes par minute',
    rate_limit_per_hour INT(11) DEFAULT 3600 COMMENT 'Nombre max de requêtes par heure',
    
    -- Statistiques d'utilisation
    total_requests INT(11) DEFAULT 0 COMMENT 'Nombre total de requêtes effectuées',
    last_used_at DATETIME DEFAULT NULL COMMENT 'Dernière utilisation de la clé',
    last_used_ip VARCHAR(45) DEFAULT NULL COMMENT 'Dernière IP ayant utilisé la clé',
    
    -- Expiration et révocation
    expires_at DATETIME DEFAULT NULL COMMENT 'Date expiration de la clé (NULL = jamais)',
    revoked_at DATETIME DEFAULT NULL COMMENT 'Date de révocation (NULL = active)',
    revoked_reason VARCHAR(255) DEFAULT NULL COMMENT 'Raison de la révocation',
    
    -- Métadonnées
    metadata JSON DEFAULT NULL COMMENT 'Métadonnées additionnelles (JSON object)',
    notes TEXT DEFAULT NULL COMMENT 'Notes internes sur la clé',
    
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    
    -- Index et contraintes
    UNIQUE KEY unique_key_hash (key_hash),
    INDEX idx_user_id (user_id),
    INDEX idx_key_prefix (key_prefix),
    INDEX idx_environment (environment),
    INDEX idx_expires_at (expires_at),
    INDEX idx_revoked_at (revoked_at),
    INDEX idx_created_at (created_at),
    INDEX idx_last_used_at (last_used_at),
    
    -- Clé étrangère
    CONSTRAINT fk_api_keys_user_id 
        FOREIGN KEY (user_id) 
        REFERENCES users(id) 
        ON DELETE CASCADE
        ON UPDATE CASCADE
        
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stockage des clés API pour authentification';
```

## Vues SQL associées

### Vue `active_api_keys`

```sql
CREATE OR REPLACE VIEW active_api_keys AS
SELECT 
    id,
    user_id,
    name,
    key_prefix,
    last_4,
    scopes,
    environment,
    rate_limit_per_minute,
    rate_limit_per_hour,
    total_requests,
    last_used_at,
    last_used_ip,
    expires_at,
    metadata,
    created_at,
    updated_at,
    CASE 
        WHEN expires_at IS NOT NULL AND expires_at < NOW() THEN TRUE
        ELSE FALSE 
    END AS is_expired
FROM api_keys
WHERE revoked_at IS NULL
  AND (expires_at IS NULL OR expires_at > NOW());
```

### Vue `api_keys_stats_by_user`

```sql
CREATE OR REPLACE VIEW api_keys_stats_by_user AS
SELECT 
    user_id,
    COUNT(*) AS total_keys,
    SUM(CASE WHEN revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) THEN 1 ELSE 0 END) AS active_keys,
    SUM(CASE WHEN revoked_at IS NOT NULL THEN 1 ELSE 0 END) AS revoked_keys,
    SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < NOW() AND revoked_at IS NULL THEN 1 ELSE 0 END) AS expired_keys,
    SUM(total_requests) AS total_requests_all_keys,
    MAX(last_used_at) AS most_recent_usage,
    MAX(created_at) AS most_recent_key_created
FROM api_keys
GROUP BY user_id;
```

## Procédure stockée de nettoyage

### `cleanup_expired_api_keys()`

```sql
CREATE PROCEDURE IF NOT EXISTS cleanup_expired_api_keys()
BEGIN
    -- Marquer comme révoquées les clés expirées non encore révoquées
    UPDATE api_keys 
    SET revoked_at = NOW(),
        revoked_reason = 'Expired automatically'
    WHERE expires_at IS NOT NULL 
      AND expires_at < NOW() 
      AND revoked_at IS NULL;
    
    SELECT ROW_COUNT() AS keys_auto_revoked;
END$$
```

## Description des champs

| Champ | Type | Description | Flutter |
|-------|------|-------------|---------|
| `id` | INT(11) | Identifiant unique de la clé | ✅ `id` |
| `user_id` | INT(11) | ID de l'utilisateur propriétaire (2 dans notre cas) | ✅ `userId` |
| `name` | VARCHAR(255) | Nom descriptif de la clé | ✅ `name` |
| `key_prefix` | VARCHAR(10) | Préfixe ag_live ou ag_test | ✅ `keyPrefix` |
| `key_hash` | VARCHAR(255) | Hash SHA-256 (jamais envoyé au client) | ❌ Backend only |
| `last_4` | VARCHAR(4) | 4 derniers caractères visibles | ✅ `last4` |
| `scopes` | JSON | Array des permissions | ✅ `scopes` |
| `environment` | ENUM | production ou test | ✅ Included in response |
| `rate_limit_per_minute` | INT(11) | Max requêtes/minute | ✅ Included in response |
| `rate_limit_per_hour` | INT(11) | Max requêtes/heure | ✅ Included in response |
| `total_requests` | INT(11) | Compteur de requêtes | ✅ `totalRequests` |
| `last_used_at` | DATETIME | Dernière utilisation | ✅ `lastUsedAt` |
| `last_used_ip` | VARCHAR(45) | Dernière IP | ❌ Backend only |
| `expires_at` | DATETIME | Date d'expiration | ✅ `expiresAt` |
| `revoked_at` | DATETIME | Date de révocation | ✅ `revokedAt` |
| `revoked_reason` | VARCHAR(255) | Raison révocation | ❌ Backend only |
| `metadata` | JSON | Métadonnées additionnelles | ❌ Backend only |
| `notes` | TEXT | Notes internes | ❌ Backend only |
| `created_at` | DATETIME | Date de création | ✅ `createdAt` |
| `updated_at` | DATETIME | Dernière modification | ✅ `updatedAt` |
| `deleted_at` | DATETIME | Soft delete | ❌ Backend only |

## Exemple de clé complète générée

Format : `{prefix}_{random_string}`

```text
ag_live_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6
```

Stockage :

- `key_prefix` = "ag_live"
- `key_hash` = SHA256 de la clé complète
- `last_4` = "o5p6"

Affichage client :

```text
ag_live••••o5p6
```

## Exemple de données

```sql
INSERT INTO api_keys (
    user_id, 
    name, 
    key_prefix, 
    key_hash, 
    last_4, 
    scopes, 
    environment,
    rate_limit_per_minute,
    rate_limit_per_hour
) VALUES (
    2,
    'Clé de production',
    'ag_live',
    'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    'b855',
    '["read:users", "write:users", "read:groups"]',
    'production',
    60,
    3600
);
```

## Requêtes utiles

### Lister toutes les clés d'un utilisateur

```sql
SELECT 
    id,
    name,
    key_prefix,
    last_4,
    scopes,
    environment,
    total_requests,
    last_used_at,
    expires_at,
    revoked_at,
    created_at
FROM api_keys
WHERE user_id = 2
  AND deleted_at IS NULL
ORDER BY created_at DESC;
```

### Compter les clés actives d'un utilisateur

```sql
SELECT COUNT(*) as active_keys
FROM api_keys
WHERE user_id = 2
  AND revoked_at IS NULL
  AND (expires_at IS NULL OR expires_at > NOW())
  AND deleted_at IS NULL;
```

### Statistiques par utilisateur

```sql
SELECT * FROM api_keys_stats_by_user WHERE user_id = 2;
```

## Sécurité

### ✅ Bonnes pratiques implémentées

1. **Hash SHA-256** : La clé complète n'est jamais stockée en clair
2. **Clé unique** : `UNIQUE KEY unique_key_hash` empêche les doublons
3. **Soft delete** : `deleted_at` permet de garder l'historique
4. **Rate limiting** : Protection contre les abus
5. **Cascade delete** : Si l'utilisateur est supprimé, ses clés aussi
6. **Index optimisés** : Recherches rapides par user_id, status, etc.
7. **IPv6 compatible** : `VARCHAR(45)` pour last_used_ip

### 🔐 Flux de sécurité

1. **Génération** : Clé aléatoire sécurisée (32+ caractères)
2. **Hashage** : SHA-256 avant stockage
3. **Affichage** : Clé complète montrée une seule fois
4. **Usage** : Comparaison avec le hash stocké
5. **Révocation** : Instantanée via `revoked_at`

---

**Note** : Cette table est créée automatiquement lors de l'exécution de la procédure `ResetAuthenticationGroups()` définie dans `create_proc_reset_auth_groups.sql`.
