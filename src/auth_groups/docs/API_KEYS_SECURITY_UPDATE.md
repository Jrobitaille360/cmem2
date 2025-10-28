# 🔐 Système de Gestion des API Keys Renforcé

## 📋 Vue d'ensemble

Suite aux exigences de sécurité renforcée, le système de gestion des API keys a été complètement modifié :

1. **Gestion des clés** : Création, renouvellement, révocation désormais **UNIQUEMENT** via `secretadminkey`
2. **Login obligatoire** : Tous les logins nécessitent maintenant une **API key valide**

## 🚨 Changements de Sécurité

### Avant (Système Ancien)

- ❌ API keys gérables via JWT standard
- ❌ Login possible sans API key
- ❌ Endpoint `/api-keys` accessible aux utilisateurs

### Maintenant (Système Renforcé)

- ✅ API keys gérables UNIQUEMENT via `secretadminkey`
- ✅ Login IMPOSSIBLE sans API key valide
- ✅ Endpoint `/api-keys` désactivé (retourne HTTP 410)
- ✅ Nouvelles routes secrètes `/secret-admin/api-keys`

## 🔑 Gestion des API Keys (Administrateurs Uniquement)

### Prérequis

- Token JWT avec rôle `ADMINISTRATEUR`
- Variable d'environnement `ADMIN_SECRET_KEY` configurée
- La clé secrète admin dans chaque requête

### Endpoints Disponibles

#### 1. Créer une API Key

```http
POST /secret-admin/api-keys
Authorization: Bearer {JWT_ADMIN_TOKEN}
Content-Type: application/json

{
  "admin_secret": "votre_admin_secret_key",
  "user_id": 123,
  "name": "Clé pour application mobile",
  "scopes": ["read", "write"],
  "environment": "production",
  "expires_in_days": 90,
  "rate_limit_per_minute": 60,
  "rate_limit_per_hour": 3600,
  "notes": "Clé pour l'app mobile v2.0"
}
```

**Réponse :**

```json
{
  "success": true,
  "message": "API Key créée avec succès via système secret admin",
  "data": {
    "api_key": {
      "id": 45,
      "name": "Clé pour application mobile",
      "key": "ag_live_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
      "prefix": "ag_live",
      "last_4": "o5p6",
      "environment": "production",
      "scopes": ["read", "write"],
      "rate_limit_per_minute": 60,
      "rate_limit_per_hour": 3600,
      "expires_at": "2026-01-27 15:30:00",
      "created_at": "2025-10-27 15:30:00"
    },
    "warning": "⚠️ IMPORTANT: Sauvegardez cette clé maintenant - elle ne sera plus jamais affichée!",
    "admin_info": {
      "created_by": "admin@example.com",
      "admin_user_id": 1
    }
  }
}
```

#### 2. Lister les API Keys

```http
GET /secret-admin/api-keys?admin_secret=votre_admin_secret_key&user_id=123
Authorization: Bearer {JWT_ADMIN_TOKEN}
```

#### 3. Obtenir les détails d'une clé

```http
GET /secret-admin/api-keys/45?admin_secret=votre_admin_secret_key
Authorization: Bearer {JWT_ADMIN_TOKEN}
```

#### 4. Révoquer une API Key

```http
DELETE /secret-admin/api-keys/45
Authorization: Bearer {JWT_ADMIN_TOKEN}
Content-Type: application/json

{
  "admin_secret": "votre_admin_secret_key",
  "reason": "Clé compromise"
}
```

#### 5. Régénérer une API Key

```http
POST /secret-admin/api-keys/45/regenerate
Authorization: Bearer {JWT_ADMIN_TOKEN}
Content-Type: application/json

{
  "admin_secret": "votre_admin_secret_key"
}
```

## 🔒 Nouveau Système de Login

### Exigence d'API Key Obligatoire

Depuis la mise à jour de sécurité, **TOUS** les logins nécessitent une API key valide.

#### Exemple de Login

```http
POST /users/login
X-API-Key: ag_live_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "motdepasse123"
}
```

#### Sans API Key (Échec)

```http
POST /users/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "motdepasse123"
}
```

**Réponse d'erreur :**

```json
{
  "success": false,
  "message": "API key obligatoire",
  "data": {
    "error": "API_KEY_REQUIRED",
    "message": "Une API key valide est obligatoire pour accéder à cette fonctionnalité",
    "details": "Utilisez le header X-API-Key ou Authorization: Bearer <key>",
    "security_notice": "Cette restriction a été mise en place pour renforcer la sécurité"
  }
}
```

## 🛠️ Configuration Environnement

### Variables Requises

```bash
# Clé secrète admin (OBLIGATOIRE)
ADMIN_SECRET_KEY=ultra_secret_admin_token_change_this_immediately_in_production

# Configuration JWT (existant)
JWT_SECRET=votre_jwt_secret_tres_long
JWT_EXPIRATION=86400

# Base de données (existant)
DB_HOST=localhost
DB_NAME=cmem2_db
DB_USER=votre_user
DB_PASS=votre_password
```

## 📊 Flux d'Authentification Modifié

```text
1. Client fait une requête de login
   └── POST /users/login
   
2. Vérification API Key OBLIGATOIRE
   ├── ✅ API Key valide → Continue
   └── ❌ Pas d'API Key ou invalide → ERREUR 401

3. Si API Key valide
   └── Procède au login standard (email/password)
   
4. Si login réussi
   └── Retourne JWT Token + données utilisateur
```

## 🚨 Messages d'Erreur Importants

### Login sans API Key

- **Code:** 401
- **Message:** "API key obligatoire"
- **Action:** Ajouter header `X-API-Key` avec une clé valide

### API Key invalide/expirée

- **Code:** 401  
- **Message:** "API key invalide"
- **Action:** Vérifier la clé ou en demander une nouvelle

### Tentative d'accès ancien endpoint

- **Code:** 410
- **Message:** "Endpoint déplacé"
- **Action:** Utiliser les nouveaux endpoints `/secret-admin/api-keys`

## ⚡ Migration pour les Clients Existants

### 1. Obtenir une API Key

Contactez votre administrateur système pour obtenir une API key via le système secret admin.

### 2. Modifier le Code Client

```javascript
// AVANT
fetch('/users/login', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    email: 'user@example.com',
    password: 'password'
  })
});

// MAINTENANT
fetch('/users/login', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-API-Key': 'ag_live_votre_api_key_ici'  // NOUVEAU
  },
  body: JSON.stringify({
    email: 'user@example.com',
    password: 'password'
  })
});
```

### 3. Stocker la Clé Sécurisément

```bash
# Variables d'environnement (recommandé)
API_KEY=ag_live_votre_api_key_ici

# Gestionnaires de secrets cloud
# AWS Secrets Manager, Azure Key Vault, etc.
```

## 🔍 Surveillance et Logs

Le système génère maintenant des logs de sécurité détaillés :

### Événements Loggés

- ✅ Tentatives de login avec/sans API key
- ✅ Utilisation d'API keys invalides
- ✅ Création/révocation d'API keys via secret admin
- ✅ Tentatives d'accès aux anciens endpoints
- ✅ Violations de rate limiting

### Exemples de Logs

```text
[INFO] API key validée avec succès - user_id: 123, api_key_id: 45
[WARNING] Tentative de login sans API key - IP: 192.168.1.100
[WARNING] Tentative d'accès aux API keys via endpoint public désactivé
[INFO] API Key créée via système secret admin - target_user: 123
```

## 🆘 Dépannage

### Problème : "API key obligatoire"

**Solution :** Ajouter le header `X-API-Key` à votre requête de login

### Problème : "API key invalide"

**Solutions :**

1. Vérifier que la clé est complète (`ag_live_...` ou `ag_test_...`)
2. Vérifier que la clé n'est pas expirée
3. Vérifier que la clé n'a pas été révoquée
4. Demander une nouvelle clé à l'administrateur

### Problème : "Endpoint déplacé" (HTTP 410)

**Solution :** Utiliser les nouveaux endpoints `/secret-admin/api-keys` avec authentification renforcée

### Problème : Accès refusé aux routes secret-admin

**Solutions :**

1. Vérifier que l'utilisateur a le rôle `ADMINISTRATEUR`
2. Vérifier que `ADMIN_SECRET_KEY` est correcte
3. Inclure `admin_secret` dans la requête

## 📞 Support

Pour toute question ou problème avec ce nouveau système :

1. **Administrateurs** : Consultez les logs système pour les détails des erreurs
2. **Développeurs** : Utilisez les exemples de code ci-dessus
3. **Urgences** : Vérifiez la configuration des variables d'environnement

---

**⚠️ IMPORTANT:** Ce changement de sécurité est **PERMANENT** et **NON-RÉVERSIBLE**. Assurez-vous que tous vos clients ont été mis à jour avec des API keys valides avant de déployer en production.
