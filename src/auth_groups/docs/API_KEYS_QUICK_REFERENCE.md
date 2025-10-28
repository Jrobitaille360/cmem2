# Commandes Rapides - API Keys v1.3.0 (Système Sécurisé)# Commandes Rapides - API Keys v1.3.0 (Système Sécurisé)



Référence rapide pour gérer le système API Keys renforcé avec gestion centralisée.Référence rapide pour gérer le système API Keys renforcé avec gestion centralisée.



## ⚠️ IMPORTANT - Nouvelle Sécurité v1.3.0## ⚠️ IMPORTANT - Nouvelle Sécurité v1.3.0



**CHANGEMENTS MAJEURS :****CHANGEMENTS MAJEURS :**

- ✅ API Keys OBLIGATOIRES pour tous les logins  

- ✅ API Keys OBLIGATOIRES pour tous les logins  - ✅ Gestion UNIQUEMENT via `/secret-admin/api-keys/*`

- ✅ Gestion UNIQUEMENT via `/secret-admin/api-keys/*`- ❌ Anciens endpoints `/api-keys/*` supprimés (HTTP 410)

- ❌ Anciens endpoints `/api-keys/*` supprimés (HTTP 410)- 🔑 Authentification renforcée : JWT Admin + ADMIN_SECRET_KEY

- 🔑 Authentification renforcée : JWT Admin + ADMIN_SECRET_KEY

---

---

## 🔧 Configuration Prérequis

## 🔧 Configuration Prérequis

### Variables d'environnement

### Variables d'environnement

```bash

```bash# Configuration dans .env ou environment.php

# Configuration dans .env ou environment.phpADMIN_SECRET_KEY=votre_cle_secrete_admin_ultra_forte_64_caracteres_minimum

ADMIN_SECRET_KEY=votre_cle_secrete_admin_ultra_forte_64_caracteres_minimumJWT_SECRET=votre_jwt_secret_existant

JWT_SECRET=votre_jwt_secret_existantDB_HOST=localhost

DB_HOST=localhostDB_NAME=cmem2_db

DB_NAME=cmem2_db# ... autres variables DB

# ... autres variables DB```

```

### Premier administrateur

### Premier administrateur

```sql

```sql-- Créer ou promouvoir un administrateur

-- Créer ou promouvoir un administrateurUPDATE users SET role = 'ADMINISTRATEUR' WHERE email = 'admin@votre-domaine.com';

UPDATE users SET role = 'ADMINISTRATEUR' WHERE email = 'admin@votre-domaine.com';```

```

---

---

## 🚀 Démarrage Rapide

## 🚀 Démarrage Rapide

### 1. Créer votre première API Key (Bootstrap)

### 1. Créer votre première API Key (Bootstrap)

```bash

```bash# Script bootstrap pour créer la première clé

# Script bootstrap pour créer la première cléphp bootstrap_create_first_api_key.php

php bootstrap_create_first_api_key.php

# Sauvegarder immédiatement la clé générée !

# Sauvegarder immédiatement la clé générée !# Exemple: ag_live_abc123def456...

# Exemple: ag_live_abc123def456...```

```

### 2. Obtenir un JWT Admin

### 2. Obtenir un JWT Admin

```bash

```bash# Se connecter avec un compte administrateur

# Se connecter avec un compte administrateurcurl -X POST http://localhost/cmem2_API/users/login \

curl -X POST http://localhost/cmem2_API/users/login \  -H "Content-Type: application/json" \

  -H "Content-Type: application/json" \  -H "X-API-Key: ag_live_votre_cle_bootstrap" \

  -H "X-API-Key: ag_live_votre_cle_bootstrap" \  -d '{

  -d '{    "email": "admin@votre-domaine.com",

    "email": "admin@votre-domaine.com",    "password": "votre_mot_de_passe"

    "password": "votre_mot_de_passe"  }'

  }'

# Sauvegarder le token JWT retourné

# Sauvegarder le token JWT retournéexport ADMIN_JWT="eyJ0eXAiOiJKV1QiLCJhbGc..."

export ADMIN_JWT="eyJ0eXAiOiJKV1QiLCJhbGc..."export ADMIN_SECRET="votre_admin_secret_key"

export ADMIN_SECRET="votre_admin_secret_key"```

```

## 🗄️ Base de données

---

### Installation

## 🔑 Gestion des API Keys (Administrateurs)

```bash

### Créer une nouvelle API Key# Créer la table api_keys

mysql -u root -p cmem2_db < docs/create_table_api_keys.sql

```bash

curl -X POST http://localhost/cmem2_API/secret-admin/api-keys \# Vérifier la création

  -H "Authorization: Bearer $ADMIN_JWT" \mysql -u root -p cmem2_db -e "DESCRIBE api_keys;"

  -H "X-Admin-Secret: $ADMIN_SECRET" \```

  -H "Content-Type: application/json" \

  -d '{### Requêtes utiles

    "name": "Clé Production Mobile",

    "user_id": 123,```sql

    "scopes": ["read", "write"],-- Connexion

    "environment": "production",mysql -u root -p cmem2_db

    "expires_in_days": 90,

    "rate_limit_per_minute": 60,-- Statistiques globales

    "rate_limit_per_hour": 3600,SELECT 

    "notes": "Clé pour application mobile v2.0"  COUNT(*) as total_keys,

  }'  COUNT(CASE WHEN revoked_at IS NULL THEN 1 END) as active,

  COUNT(CASE WHEN revoked_at IS NOT NULL THEN 1 END) as revoked,

# ⚠️ COPIER IMMÉDIATEMENT LA CLÉ RETOURNÉE !  COUNT(CASE WHEN expires_at < NOW() THEN 1 END) as expired

```FROM api_keys;



### Lister toutes les API Keys-- Clés actives

SELECT * FROM active_api_keys;

```bash

# Toutes les clés-- Stats par utilisateur

curl -X GET http://localhost/cmem2_API/secret-admin/api-keys \SELECT * FROM api_keys_stats_by_user;

  -H "Authorization: Bearer $ADMIN_JWT" \

  -H "X-Admin-Secret: $ADMIN_SECRET"-- Clés d'un utilisateur spécifique

SELECT id, name, key_prefix, last_4, scopes, total_requests, last_used_at

# Clés d'un utilisateur spécifiqueFROM api_keys

curl -X GET "http://localhost/cmem2_API/secret-admin/api-keys?user_id=123" \WHERE user_id = 1 AND revoked_at IS NULL;

  -H "Authorization: Bearer $ADMIN_JWT" \

  -H "X-Admin-Secret: $ADMIN_SECRET"-- Clés les plus utilisées (24h)

```SELECT name, total_requests, last_used_at

FROM api_keys

### Détails d'une API KeyWHERE last_used_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)

ORDER BY total_requests DESC

```bashLIMIT 10;

curl -X GET http://localhost/cmem2_API/secret-admin/api-keys/45 \

  -H "Authorization: Bearer $ADMIN_JWT" \-- Clés expirant bientôt (7 jours)

  -H "X-Admin-Secret: $ADMIN_SECRET"SELECT name, user_id, expires_at, DATEDIFF(expires_at, NOW()) as days_left

```FROM api_keys

WHERE expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)

### Révoquer une API KeyORDER BY expires_at ASC;



```bash-- Nettoyer les clés expirées

curl -X DELETE http://localhost/cmem2_API/secret-admin/api-keys/45 \CALL cleanup_expired_api_keys();

  -H "Authorization: Bearer $ADMIN_JWT" \

  -H "X-Admin-Secret: $ADMIN_SECRET" \-- Révoquer toutes les clés d'un utilisateur (admin)

  -H "Content-Type: application/json" \UPDATE api_keys 

  -d '{SET revoked_at = NOW(), revoked_reason = 'Account suspended'

    "reason": "Clé compromise - révocation de sécurité"WHERE user_id = 123;

  }'

```-- Supprimer définitivement les clés révoquées il y a plus de 30 jours

DELETE FROM api_keys

### Régénérer une API KeyWHERE revoked_at IS NOT NULL

  AND revoked_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

```bash```

curl -X PUT http://localhost/cmem2_API/secret-admin/api-keys/45/regenerate \

  -H "Authorization: Bearer $ADMIN_JWT" \---

  -H "X-Admin-Secret: $ADMIN_SECRET" \

  -H "Content-Type: application/json" \## 🧪 Tests

  -d '{

    "reason": "Rotation de sécurité planifiée"### Test automatisé complet

  }'

```bash

# ⚠️ COPIER IMMÉDIATEMENT LA NOUVELLE CLÉ !# Lancer tous les tests

```php tests/api_keys/test_api_keys_basic.php



---# Résultat attendu : tous les tests passent

```

## 📱 Utilisation des API Keys (Clients)

### Tests manuels avec curl

### Login avec API Key (Obligatoire)

#### 1. Login (obtenir JWT token)

```bash

# Tous les logins nécessitent maintenant une API key```bash

curl -X POST http://localhost/cmem2_API/users/login \# Se connecter

  -H "Content-Type: application/json" \curl -X POST http://localhost/cmem2_API/users/login \

  -H "X-API-Key: ag_live_votre_cle_ici" \  -H "Content-Type: application/json" \

  -d '{  -d '{

    "email": "user@example.com",    "email": "your_email@example.com",

    "password": "password123"    "password": "your_password"

  }'  }'

```

# Sauvegarder le token

### Utiliser une API Key pour les appels APITOKEN="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."

```

```bash

# Méthode 1 : Header X-API-Key (recommandé)#### 2. Créer une API key

curl -X GET http://localhost/cmem2_API/groups \

  -H "X-API-Key: ag_live_votre_cle_ici"```bash

# Clé de production

# Méthode 2 : Authorization Bearercurl -X POST http://localhost/cmem2_API/api-keys \

curl -X GET http://localhost/cmem2_API/groups \  -H "Authorization: Bearer $TOKEN" \

  -H "Authorization: Bearer ag_live_votre_cle_ici"  -H "Content-Type: application/json" \

  -d '{

# Avec JWT + API Key (double authentification)    "name": "Production Integration",

curl -X POST http://localhost/cmem2_API/groups \    "scopes": ["read", "write"],

  -H "Authorization: Bearer $JWT_USER_TOKEN" \    "environment": "production",

  -H "X-API-Key: ag_live_votre_cle_ici" \    "expires_in_days": 90,

  -H "Content-Type: application/json" \    "rate_limit_per_minute": 60,

  -d '{    "rate_limit_per_hour": 3600

    "name": "Nouveau Groupe",  }'

    "description": "Créé via API",

    "visibility": "PUBLIC"# Clé de test

  }'curl -X POST http://localhost/cmem2_API/api-keys \

```  -H "Authorization: Bearer $TOKEN" \

  -H "Content-Type: application/json" \

---  -d '{

    "name": "Test Integration",

## 🗄️ Base de données    "scopes": ["*"],

    "environment": "test",

### Requêtes utiles    "expires_in_days": 7

  }'

```sql

-- Connexion# ⚠️ COPIER LA CLÉ IMMÉDIATEMENT !

mysql -u root -p cmem2_dbAPI_KEY="ag_live_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8"

```

-- Statistiques globales

SELECT #### 3. Lister les clés

  COUNT(*) as total_keys,

  COUNT(CASE WHEN revoked_at IS NULL THEN 1 END) as active,```bash

  COUNT(CASE WHEN revoked_at IS NOT NULL THEN 1 END) as revoked,# Toutes vos clés

  COUNT(CASE WHEN expires_at < NOW() THEN 1 END) as expiredcurl -X GET http://localhost/cmem2_API/api-keys \

FROM api_keys;  -H "Authorization: Bearer $TOKEN"



-- Clés actives# Avec filtres (production uniquement)

SELECT * FROM active_api_keys;curl -X GET "http://localhost/cmem2_API/api-keys?environment=production" \

  -H "Authorization: Bearer $TOKEN"

-- Stats par utilisateur```

SELECT * FROM api_keys_stats_by_user;

#### 4. Détails d'une clé

-- Clés d'un utilisateur spécifique

SELECT id, name, key_prefix, last_4, scopes, total_requests, last_used_at```bash

FROM api_keys# Obtenir stats détaillées

WHERE user_id = 1 AND revoked_at IS NULL;curl -X GET http://localhost/cmem2_API/api-keys/1 \

  -H "Authorization: Bearer $TOKEN"

-- Clés les plus utilisées (24h)```

SELECT name, total_requests, last_used_at

FROM api_keys#### 5. Utiliser une API key

WHERE last_used_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)

ORDER BY total_requests DESC```bash

LIMIT 10;# Méthode 1 : Header X-API-Key (recommandé)

curl -X GET http://localhost/cmem2_API/groups \

-- Clés expirant bientôt (7 jours)  -H "X-API-Key: $API_KEY"

SELECT name, user_id, expires_at, DATEDIFF(expires_at, NOW()) as days_left

FROM api_keys# Méthode 2 : Authorization Bearer

WHERE expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)curl -X GET http://localhost/cmem2_API/groups \

ORDER BY expires_at ASC;  -H "Authorization: Bearer $API_KEY"



-- Nettoyer les clés expirées# Test avec création de ressource

CALL cleanup_expired_api_keys();curl -X POST http://localhost/cmem2_API/groups \

  -H "X-API-Key: $API_KEY" \

-- Révoquer toutes les clés d'un utilisateur (admin)  -H "Content-Type: application/json" \

UPDATE api_keys   -d '{

SET revoked_at = NOW(), revoked_reason = 'Account suspended'    "name": "Test Group",

WHERE user_id = 123;    "description": "Created with API key"

```  }'

```

---

#### 6. Régénérer une clé

## 🔍 Vérifications

```bash

### Santé de l'API# Régénérer (révoque l'ancienne, crée une nouvelle)

curl -X POST http://localhost/cmem2_API/api-keys/1/regenerate \

```bash  -H "Authorization: Bearer $TOKEN" \

# Health check  -H "Content-Type: application/json" \

curl http://localhost/cmem2_API/health  -d '{

    "reason": "Rotation de sécurité planifiée"

# Réponse attendue  }'

# {"success":true,"data":{"status":"healthy"}}

```# ⚠️ COPIER LA NOUVELLE CLÉ !

```

### Vérifier les endpoints

#### 7. Révoquer une clé

```bash

# Liste des routes disponibles```bash

curl http://localhost/cmem2_API/help# Révocation manuelle

curl -X DELETE http://localhost/cmem2_API/api-keys/1 \

# Vérifier que les anciens endpoints retournent 410  -H "Authorization: Bearer $TOKEN" \

curl -v http://localhost/cmem2_API/api-keys 2>&1 | grep "HTTP"  -H "Content-Type: application/json" \

# Devrait afficher : HTTP/1.1 410 Gone  -d '{

```    "reason": "Clé compromise - rotation immédiate"

  }'

### Vérifier les headers de rate limit```



```bash---

# Avec verbosité pour voir les headers

curl -v -X GET http://localhost/cmem2_API/groups \## 🔍 Vérifications

  -H "X-API-Key: $API_KEY" 2>&1 | grep -i "ratelimit"

### Santé de l'API

# Devrait afficher :

# X-RateLimit-Remaining: 59```bash

# X-RateLimit-Reset: 2025-10-27 15:32:00# Health check

```curl http://localhost/cmem2_API/health



---# Réponse attendue

# {"success":true,"data":{"status":"healthy"}}

## 🚨 Codes d'Erreur v1.3.0```



### Nouveaux codes de sécurité### Vérifier les endpoints



| Code | Message | Cause | Solution |```bash

|------|---------|-------|---------|# Liste des routes disponibles

| 401 | "API key required" | Aucune API key fournie | Ajouter header `X-API-Key` |curl http://localhost/cmem2_API/help

| 401 | "Invalid API key" | API key invalide/expirée | Utiliser une API key valide |

| 410 | "Gone" | Ancien endpoint `/api-keys` | Utiliser `/secret-admin/api-keys/*` |# Vérifier spécifiquement /api-keys

| 403 | "Admin secret required" | Clé admin manquante | Ajouter header `X-Admin-Secret` |curl http://localhost/cmem2_API/help | grep -i "api-keys"

| 403 | "Admin role required" | Pas de rôle admin | Utiliser un compte administrateur |```



### Tests d'erreur### Vérifier les headers de rate limit



```bash```bash

# Test sans API key (doit échouer avec 401)# Avec verbosité pour voir les headers

curl -X POST http://localhost/cmem2_API/users/login \curl -v -X GET http://localhost/cmem2_API/groups \

  -H "Content-Type: application/json" \  -H "X-API-Key: $API_KEY" 2>&1 | grep -i "ratelimit"

  -d '{"email":"user@example.com","password":"pass"}'

# Devrait afficher :

# Test ancien endpoint (doit échouer avec 410)# X-RateLimit-Remaining: 59

curl -X GET http://localhost/cmem2_API/api-keys \# X-RateLimit-Reset: 2025-10-07 15:32:00

  -H "Authorization: Bearer $JWT_TOKEN"```



# Test sans admin secret (doit échouer avec 403)---

curl -X GET http://localhost/cmem2_API/secret-admin/api-keys \

  -H "Authorization: Bearer $ADMIN_JWT"## 📊 Monitoring

```

### Logs en temps réel

---

```bash

## 📊 Monitoring# Logs Apache/PHP (Linux)

tail -f /var/log/apache2/error.log

### Logs de sécurité

# Logs XAMPP (Windows)

```bashtail -f C:/xampp/apache/logs/error.log

# Surveiller les tentatives sans API key

tail -f /var/log/apache2/error.log | grep "API_KEY_REQUIRED"# Filtrer pour API keys uniquement

tail -f /var/log/apache2/error.log | grep -i "apikey"

# Surveiller les tentatives sur anciens endpoints```

tail -f /var/log/apache2/error.log | grep "HTTP_410"

### Stats d'usage

# Activité d'administration

tail -f /var/log/apache2/error.log | grep "secret-admin"```bash

```# Via API (nécessite JWT)

curl -X GET http://localhost/cmem2_API/stats/api-keys \

### Stats d'usage  -H "Authorization: Bearer $TOKEN"



```bash# Ou via SQL

# Via SQLmysql -u root -p cmem2_db -e "

mysql -u root -p cmem2_db -e "  SELECT 

  SELECT     name,

    DATE(last_used_at) as date,    total_requests,

    COUNT(*) as requests,    last_used_at,

    COUNT(DISTINCT user_id) as unique_users    TIMESTAMPDIFF(HOUR, created_at, NOW()) as age_hours

  FROM api_keys   FROM api_keys

  WHERE last_used_at > DATE_SUB(NOW(), INTERVAL 7 DAY)  WHERE revoked_at IS NULL

  GROUP BY DATE(last_used_at)  ORDER BY total_requests DESC

  ORDER BY date DESC;  LIMIT 10;

""

``````



------



## 🔧 Maintenance## 🔧 Maintenance



### Nettoyage automatique### Nettoyage automatique



```bash```bash

# Script de nettoyage quotidien# Script de nettoyage manuel

mysql -u root -p cmem2_db -e "CALL cleanup_expired_api_keys();"mysql -u root -p cmem2_db -e "CALL cleanup_expired_api_keys();"



# Supprimer les clés révoquées anciennes (30+ jours)# Via cron (ajouter au crontab)

mysql -u root -p cmem2_db -e "crontab -e

  DELETE FROM api_keys# Ajouter :

  WHERE revoked_at IS NOT NULL# 0 2 * * * mysql -u root -pPASSWORD cmem2_db -e "CALL cleanup_expired_api_keys();" >> /var/log/api_keys_cleanup.log 2>&1

    AND revoked_at < DATE_SUB(NOW(), INTERVAL 30 DAY);```

"

```### Rotation des clés



### Rotation de sécurité```bash

# Script pour régénérer toutes les clés d'un user

```bash# (À exécuter via endpoint ou SQL)

# Script de rotation trimestrielle des clés

# 1. Lister les clés anciennes (90+ jours)# 1. Lister toutes les clés actives

curl -X GET "http://localhost/cmem2_API/secret-admin/api-keys?created_before=90_days" \curl -X GET http://localhost/cmem2_API/api-keys \

  -H "Authorization: Bearer $ADMIN_JWT" \  -H "Authorization: Bearer $TOKEN"

  -H "X-Admin-Secret: $ADMIN_SECRET"

# 2. Pour chaque clé, régénérer

# 2. Régénérer les clés critiquesfor KEY_ID in 1 2 3; do

# (À faire une par une avec notification aux équipes)  curl -X POST http://localhost/cmem2_API/api-keys/$KEY_ID/regenerate \

```    -H "Authorization: Bearer $TOKEN" \

    -H "Content-Type: application/json" \

---    -d '{"reason": "Rotation trimestrielle"}'

done

## 📞 Support```



### En cas de problème### Backup



1. **Vérifier les logs** : `/var/log/apache2/error.log````bash

2. **Tester la santé** : `curl http://localhost/cmem2_API/health`# Backup de la table api_keys seule

3. **Vérifier la configuration** : Variables d'environnementmysqldump -u root -p cmem2_db api_keys > api_keys_backup_$(date +%Y%m%d).sql

4. **Documentation** : [SECURITY_UPDATE_v1.3.0.md](SECURITY_UPDATE_v1.3.0.md)

# Restauration

### Récupération d'urgencemysql -u root -p cmem2_db < api_keys_backup_YYYYMMDD.sql

```

```bash

# Si perte de toutes les API keys---

php bootstrap_create_first_api_key.php

## 🐛 Dépannage

# Si problème avec ADMIN_SECRET_KEY

# 1. Modifier la variable d'environnement### Reset complet (développement uniquement)

# 2. Redémarrer l'application

# 3. Tester avec la nouvelle clé```bash

```# ATTENTION : Supprime TOUTES les clés !

mysql -u root -p cmem2_db -e "

---  TRUNCATE TABLE api_keys;

"

**🔒 Ce guide reflète le système de sécurité renforcé v1.3.0 avec gestion centralisée des API keys.**```

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
