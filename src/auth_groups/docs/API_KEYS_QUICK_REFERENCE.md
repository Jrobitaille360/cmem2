# Commandes Rapides - API Keys

Référence rapide de toutes les commandes et requêtes pour gérer le système API Keys.

---

## 🗄️ Base de données

### Installation

```bash
# Créer la table api_keys
mysql -u root -p cmem2_db < docs/create_table_api_keys.sql

# Vérifier la création
mysql -u root -p cmem2_db -e "DESCRIBE api_keys;"
```

### Requêtes utiles

```sql
-- Connexion
mysql -u root -p cmem2_db

-- Statistiques globales
SELECT 
  COUNT(*) as total_keys,
  COUNT(CASE WHEN revoked_at IS NULL THEN 1 END) as active,
  COUNT(CASE WHEN revoked_at IS NOT NULL THEN 1 END) as revoked,
  COUNT(CASE WHEN expires_at < NOW() THEN 1 END) as expired
FROM api_keys;

-- Clés actives
SELECT * FROM active_api_keys;

-- Stats par utilisateur
SELECT * FROM api_keys_stats_by_user;

-- Clés d'un utilisateur spécifique
SELECT id, name, key_prefix, last_4, scopes, total_requests, last_used_at
FROM api_keys
WHERE user_id = 1 AND revoked_at IS NULL;

-- Clés les plus utilisées (24h)
SELECT name, total_requests, last_used_at
FROM api_keys
WHERE last_used_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY total_requests DESC
LIMIT 10;

-- Clés expirant bientôt (7 jours)
SELECT name, user_id, expires_at, DATEDIFF(expires_at, NOW()) as days_left
FROM api_keys
WHERE expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
ORDER BY expires_at ASC;

-- Nettoyer les clés expirées
CALL cleanup_expired_api_keys();

-- Révoquer toutes les clés d'un utilisateur (admin)
UPDATE api_keys 
SET revoked_at = NOW(), revoked_reason = 'Account suspended'
WHERE user_id = 123;

-- Supprimer définitivement les clés révoquées il y a plus de 30 jours
DELETE FROM api_keys
WHERE revoked_at IS NOT NULL
  AND revoked_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

---

## 🧪 Tests

### Test automatisé complet

```bash
# Lancer tous les tests
php tests/api_keys/test_api_keys_basic.php

# Résultat attendu : tous les tests passent
```

### Tests manuels avec curl

#### 1. Login (obtenir JWT token)

```bash
# Se connecter
curl -X POST http://localhost/cmem2_API/users/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "your_email@example.com",
    "password": "your_password"
  }'

# Sauvegarder le token
TOKEN="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
```

#### 2. Créer une API key

```bash
# Clé de production
curl -X POST http://localhost/cmem2_API/api-keys \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Production Integration",
    "scopes": ["read", "write"],
    "environment": "production",
    "expires_in_days": 90,
    "rate_limit_per_minute": 60,
    "rate_limit_per_hour": 3600
  }'

# Clé de test
curl -X POST http://localhost/cmem2_API/api-keys \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Integration",
    "scopes": ["*"],
    "environment": "test",
    "expires_in_days": 7
  }'

# ⚠️ COPIER LA CLÉ IMMÉDIATEMENT !
API_KEY="ag_live_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8"
```

#### 3. Lister les clés

```bash
# Toutes vos clés
curl -X GET http://localhost/cmem2_API/api-keys \
  -H "Authorization: Bearer $TOKEN"

# Avec filtres (production uniquement)
curl -X GET "http://localhost/cmem2_API/api-keys?environment=production" \
  -H "Authorization: Bearer $TOKEN"
```

#### 4. Détails d'une clé

```bash
# Obtenir stats détaillées
curl -X GET http://localhost/cmem2_API/api-keys/1 \
  -H "Authorization: Bearer $TOKEN"
```

#### 5. Utiliser une API key

```bash
# Méthode 1 : Header X-API-Key (recommandé)
curl -X GET http://localhost/cmem2_API/groups \
  -H "X-API-Key: $API_KEY"

# Méthode 2 : Authorization Bearer
curl -X GET http://localhost/cmem2_API/groups \
  -H "Authorization: Bearer $API_KEY"

# Test avec création de ressource
curl -X POST http://localhost/cmem2_API/groups \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Group",
    "description": "Created with API key"
  }'
```

#### 6. Régénérer une clé

```bash
# Régénérer (révoque l'ancienne, crée une nouvelle)
curl -X POST http://localhost/cmem2_API/api-keys/1/regenerate \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "reason": "Rotation de sécurité planifiée"
  }'

# ⚠️ COPIER LA NOUVELLE CLÉ !
```

#### 7. Révoquer une clé

```bash
# Révocation manuelle
curl -X DELETE http://localhost/cmem2_API/api-keys/1 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "reason": "Clé compromise - rotation immédiate"
  }'
```

---

## 🔍 Vérifications

### Santé de l'API

```bash
# Health check
curl http://localhost/cmem2_API/health

# Réponse attendue
# {"success":true,"data":{"status":"healthy"}}
```

### Vérifier les endpoints

```bash
# Liste des routes disponibles
curl http://localhost/cmem2_API/help

# Vérifier spécifiquement /api-keys
curl http://localhost/cmem2_API/help | grep -i "api-keys"
```

### Vérifier les headers de rate limit

```bash
# Avec verbosité pour voir les headers
curl -v -X GET http://localhost/cmem2_API/groups \
  -H "X-API-Key: $API_KEY" 2>&1 | grep -i "ratelimit"

# Devrait afficher :
# X-RateLimit-Remaining: 59
# X-RateLimit-Reset: 2025-10-07 15:32:00
```

---

## 📊 Monitoring

### Logs en temps réel

```bash
# Logs Apache/PHP (Linux)
tail -f /var/log/apache2/error.log

# Logs XAMPP (Windows)
tail -f C:/xampp/apache/logs/error.log

# Filtrer pour API keys uniquement
tail -f /var/log/apache2/error.log | grep -i "apikey"
```

### Stats d'usage

```bash
# Via API (nécessite JWT)
curl -X GET http://localhost/cmem2_API/stats/api-keys \
  -H "Authorization: Bearer $TOKEN"

# Ou via SQL
mysql -u root -p cmem2_db -e "
  SELECT 
    name,
    total_requests,
    last_used_at,
    TIMESTAMPDIFF(HOUR, created_at, NOW()) as age_hours
  FROM api_keys
  WHERE revoked_at IS NULL
  ORDER BY total_requests DESC
  LIMIT 10;
"
```

---

## 🔧 Maintenance

### Nettoyage automatique

```bash
# Script de nettoyage manuel
mysql -u root -p cmem2_db -e "CALL cleanup_expired_api_keys();"

# Via cron (ajouter au crontab)
crontab -e
# Ajouter :
# 0 2 * * * mysql -u root -pPASSWORD cmem2_db -e "CALL cleanup_expired_api_keys();" >> /var/log/api_keys_cleanup.log 2>&1
```

### Rotation des clés

```bash
# Script pour régénérer toutes les clés d'un user
# (À exécuter via endpoint ou SQL)

# 1. Lister toutes les clés actives
curl -X GET http://localhost/cmem2_API/api-keys \
  -H "Authorization: Bearer $TOKEN"

# 2. Pour chaque clé, régénérer
for KEY_ID in 1 2 3; do
  curl -X POST http://localhost/cmem2_API/api-keys/$KEY_ID/regenerate \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"reason": "Rotation trimestrielle"}'
done
```

### Backup

```bash
# Backup de la table api_keys seule
mysqldump -u root -p cmem2_db api_keys > api_keys_backup_$(date +%Y%m%d).sql

# Restauration
mysql -u root -p cmem2_db < api_keys_backup_YYYYMMDD.sql
```

---

## 🐛 Dépannage

### Reset complet (développement uniquement)

```bash
# ATTENTION : Supprime TOUTES les clés !
mysql -u root -p cmem2_db -e "
  TRUNCATE TABLE api_keys;
"
```

### Réparer les indexes

```sql
-- Si problèmes de performance
ANALYZE TABLE api_keys;
OPTIMIZE TABLE api_keys;

-- Reconstruire les indexes
ALTER TABLE api_keys ENGINE=InnoDB;
```

### Vérifier les permissions

```bash
# Permissions fichiers PHP
chmod 644 src/auth_groups/Models/ApiKey.php
chmod 644 src/auth_groups/Controllers/ApiKeyController.php
chmod 644 src/auth_groups/Middleware/ApiKeyAuthMiddleware.php
chmod 644 src/auth_groups/Routing/RouteHandlers/ApiKeyRouteHandler.php

# Permissions dossiers
chmod 755 src/auth_groups/Models/
chmod 755 src/auth_groups/Controllers/
chmod 755 src/auth_groups/Middleware/
chmod 755 src/auth_groups/Routing/RouteHandlers/
```

---

## 📝 Exemples de code

### JavaScript

```javascript
// Configuration
const API_KEY = 'ag_live_a1b2c3d4e5f6g7h8...';
const API_URL = 'http://localhost/cmem2_API';

// Fonction utilitaire
async function apiCall(endpoint, method = 'GET', body = null) {
  const options = {
    method,
    headers: {
      'X-API-Key': API_KEY,
      'Content-Type': 'application/json'
    }
  };
  
  if (body) {
    options.body = JSON.stringify(body);
  }
  
  const response = await fetch(`${API_URL}${endpoint}`, options);
  return await response.json();
}

// Utilisation
const groups = await apiCall('/groups');
const newGroup = await apiCall('/groups', 'POST', {
  name: 'New Group',
  description: 'Created via API key'
});
```

### Python

```python
import requests

API_KEY = 'ag_live_a1b2c3d4e5f6g7h8...'
API_URL = 'http://localhost/cmem2_API'

headers = {
    'X-API-Key': API_KEY,
    'Content-Type': 'application/json'
}

# GET request
response = requests.get(f'{API_URL}/groups', headers=headers)
groups = response.json()

# POST request
new_group = {
    'name': 'New Group',
    'description': 'Created via API key'
}
response = requests.post(f'{API_URL}/groups', json=new_group, headers=headers)
result = response.json()
```

### PHP

```php
<?php
$apiKey = 'ag_live_a1b2c3d4e5f6g7h8...';
$apiUrl = 'http://localhost/cmem2_API';

function apiCall($endpoint, $method = 'GET', $data = null) {
    global $apiKey, $apiUrl;
    
    $ch = curl_init($apiUrl . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key: ' . $apiKey,
        'Content-Type: application/json'
    ]);
    
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Utilisation
$groups = apiCall('/groups');
$newGroup = apiCall('/groups', 'POST', [
    'name' => 'New Group',
    'description' => 'Created via API key'
]);
```

---

## 🔐 Sécurité

### Variables d'environnement

```bash
# .env (ne JAMAIS commiter)
API_KEY_PRODUCTION=ag_live_a1b2c3d4e5f6g7h8...
API_KEY_TEST=ag_test_x1y2z3w4v5u6...

# Charger dans votre code
# JavaScript (Node.js)
require('dotenv').config();
const apiKey = process.env.API_KEY_PRODUCTION;

# Python
import os
from dotenv import load_dotenv
load_dotenv()
api_key = os.getenv('API_KEY_PRODUCTION')

# PHP
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$apiKey = $_ENV['API_KEY_PRODUCTION'];
```

### .gitignore

```bash
# Ajouter au .gitignore
echo ".env" >> .gitignore
echo "*.key" >> .gitignore
echo "api_keys_backup_*.sql" >> .gitignore
```

---

## 📚 Documentation

### Liens rapides

```bash
# Voir la doc locale
open docs/ENDPOINTS_API_KEYS.md
open docs/API_KEYS_IMPLEMENTATION.md
open docs/API_KEYS_ARCHITECTURE.md

# Ou avec navigateur
firefox docs/ENDPOINTS_API_KEYS.md
```

### Générer un PDF de la doc (optionnel)

```bash
# Installer pandoc
sudo apt-get install pandoc

# Générer PDF
pandoc docs/ENDPOINTS_API_KEYS.md -o API_Keys_Documentation.pdf
```

---

**AuthGroups API v1.3.0** - Commandes rapides  
**Référence pratique pour développeurs**  
**Dernière mise à jour** : 7 octobre 2025
