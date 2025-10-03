# Système de Tokens Valides - Sécurité Améliorée

## Problème résolu

**AVANT**: Après un logout, les tokens JWT restaient valides jusqu'à leur expiration naturelle (24h), permettant une réutilisation non autorisée.

**MAINTENANT**: Les tokens sont stockés et gérés activement, permettant une invalidation immédiate au logout.

## Fonctionnalités

### 🔐 Sécurité renforcée
- ✅ Invalidation immédiate des tokens au logout
- ✅ Vérification active de la validité des tokens
- ✅ Protection contre la réutilisation des tokens révoqués
- ✅ Nettoyage automatique des tokens expirés

### 📊 Statistiques en temps réel
- ✅ Nombre d'utilisateurs connectés
- ✅ Sessions actives avec détails (IP, User-Agent, dernière activité)
- ✅ Statistiques d'activité (utilisateurs actifs dans les 5/30 dernières minutes)
- ✅ Durée moyenne des sessions

### 👥 Gestion des sessions
- ✅ Vue des sessions actives pour chaque utilisateur
- ✅ Déconnexion de tous les appareils
- ✅ Historique de connexion
- ✅ Détection des sessions suspectes

## Architecture

### Nouvelle table: `valid_tokens`

```sql
CREATE TABLE valid_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_hash VARCHAR(64) NOT NULL UNIQUE,  -- SHA256 du token
    user_id INT NOT NULL,
    user_agent TEXT,                         -- Identification du client
    ip_address VARCHAR(45),                  -- IPv4/IPv6
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### Services implémentés

#### `ValidTokenService`
- `registerToken()` - Enregistre un nouveau token lors du login
- `isTokenValid()` - Vérifie si un token existe et est valide
- `removeToken()` - Supprime un token spécifique (logout)
- `removeAllUserTokens()` - Déconnecte tous les appareils d'un utilisateur
- `getOnlineUsersStats()` - Statistiques en temps réel
- `getActiveSessions()` - Liste des sessions actives
- `cleanupExpiredTokens()` - Nettoyage des tokens expirés

#### `AuthService` (modifié)
- Vérifie maintenant la validité du token dans la base de données
- Met à jour automatiquement `last_used_at`
- Supprime les tokens des utilisateurs supprimés

## Workflow de sécurité

### 1. Login
```php
// 1. Génération du token JWT classique
$jwt = JWT::encode($payload, $secret, 'HS256');

// 2. Enregistrement dans valid_tokens
ValidTokenService::registerToken($jwt, $userId, $userAgent, $ipAddress);

// 3. Retour du token à l'utilisateur
return ['token' => $jwt];
```

### 2. Validation d'une requête
```php
// 1. Extraction du token depuis les headers
$token = AuthService::extractTokenFromHeader();

// 2. Vérification dans valid_tokens
if (!ValidTokenService::isTokenValid($token)) {
    return null; // Token invalide ou révoqué
}

// 3. Validation JWT classique
$userData = AuthService::validateToken($token);

// 4. Mise à jour de last_used_at (automatique)
```

### 3. Logout
```php
// 1. Extraction du token courant
$token = extractFromHeaders();

// 2. Suppression de valid_tokens
ValidTokenService::removeToken($token);

// 3. Token immédiatement inutilisable
```

## API Endpoints

### Statistiques (Admin uniquement)
```http
GET /stats/online-users
```
Retourne les statistiques d'utilisateurs en ligne.

### Sessions utilisateur
```http
GET /users/{userId}/sessions
```
Liste les sessions actives d'un utilisateur.

```http
POST /users/{userId}/logout-all
```
Déconnecte tous les appareils d'un utilisateur.

### Administration
```http
POST /admin/cleanup-tokens
```
Nettoie manuellement les tokens expirés.

## Vues SQL

### `v_active_sessions`
Vue détaillée des sessions actives avec informations utilisateur.

### `v_online_users_stats`
Vue agrégée pour les statistiques temps réel:
- `users_online`: Nombre d'utilisateurs uniques connectés
- `total_sessions`: Nombre total de sessions actives
- `avg_session_duration_minutes`: Durée moyenne des sessions
- `active_last_5min`: Utilisateurs actifs dans les 5 dernières minutes
- `active_last_30min`: Utilisateurs actifs dans les 30 dernières minutes

## Tests

### Tests unitaires
```bash
php tests/test_valid_tokens.php
```

### Tests API
```bash
php tests/test_api_token_security.php
```

## Maintenance

### Nettoyage automatique
Les tokens expirés sont nettoyés automatiquement via:
1. Procédure stockée `CleanupExpiredTokens()`
2. Event MySQL (optionnel, toutes les heures)
3. Endpoint admin `/admin/cleanup-tokens`

### Monitoring
Surveillez les métriques:
- Nombre de sessions actives
- Utilisateurs connectés simultanément
- Durée moyenne des sessions
- Tokens expirés nettoyés

## Migration

### 1. Exécuter le script SQL
```sql
source docs/valid_tokens.sql
```

### 2. Les nouveaux logins utiliseront automatiquement le système

### 3. Les anciens tokens resteront valides jusqu'à expiration (24h max)

## Impact sur les performances

- **Négligeable**: Une requête SELECT supplémentaire par validation de token
- **Optimisé**: Index sur `token_hash` pour des lookups rapides  
- **Nettoyage**: Suppression automatique des tokens expirés
- **Avantages**: Sécurité renforcée + statistiques temps réel

## Sécurité

- ✅ Tokens hashés (SHA256) en base pour confidentialité
- ✅ Invalidation immédiate au logout
- ✅ Suivi des IP et User-Agents pour détection d'anomalies
- ✅ Timestamps de dernière utilisation
- ✅ Nettoyage automatique des données expirées