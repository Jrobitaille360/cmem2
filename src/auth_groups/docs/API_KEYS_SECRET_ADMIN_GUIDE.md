# Guide Secret-Admin API Keys v1.3.0

## 📋 Vue d'ensemble

Le système **Secret-Admin** est le nouveau point central de gestion des API Keys dans cmem2 v1.3.0. Il remplace complètement l'ancien système `/api-keys/*` avec une architecture de sécurité renforcée basée sur une double authentification.

### 🔒 Architecture de sécurité

```
Authentification Double Requise :
┌─────────────────────────┐
│ 1. JWT Admin Token      │ ← Authentification utilisateur admin
├─────────────────────────┤
│ 2. ADMIN_SECRET_KEY     │ ← Clé secrète serveur (variable env)
└─────────────────────────┘
```

### 🎯 Avantages v1.3.0

- **Sécurité renforcée** : Double authentification JWT + SECRET_KEY
- **Gestion centralisée** : Tous les endpoints dans `/secret-admin/api-keys/*`
- **Contrôle d'accès** : Restriction aux super-administrateurs uniquement
- **Audit complet** : Logs de toutes les opérations sensibles
- **API Keys obligatoires** : Requises pour tous les logins utilisateurs

---

## 🚀 Configuration initiale

### Variables d'environnement requises

```bash
# Clé secrète pour l'accès secret-admin (OBLIGATOIRE)
ADMIN_SECRET_KEY=your-ultra-secure-secret-key-here

# Configuration JWT (existante)
JWT_SECRET_KEY=your-jwt-secret
JWT_EXPIRATION_TIME=3600
```

### ⚠️ Sécurité de ADMIN_SECRET_KEY

```php
// ❌ INTERDIT : Clé faible
ADMIN_SECRET_KEY=admin123

// ✅ RECOMMANDÉ : Clé forte
ADMIN_SECRET_KEY=Ak9#mZ$7LpQ@vX2nR8sE!dF6gH4jK0uY
```

---

## 📚 Endpoints disponibles

### Base URL
```
POST /secret-admin/api-keys/*
```

| Endpoint | Action | Description |
|----------|---------|-------------|
| `/list` | Lister | Récupère toutes les API keys |
| `/create` | Créer | Génère une nouvelle API key |
| `/delete` | Supprimer | Révoque une API key existante |
| `/update` | Modifier | Met à jour les propriétés d'une API key |
| `/bulk-actions` | Actions groupées | Opérations sur plusieurs API keys |

---

## 🔧 Guide d'utilisation

### 1. Authentification administrative

**Étape 1 : Obtenir un JWT Admin**
```bash
curl -X POST "http://localhost/auth-groups/login" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "admin",
    "password": "admin_password",
    "api_key": "your-existing-api-key"
  }'
```

**Réponse :**
```json
{
  "success": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "user": {
      "username": "admin",
      "role": "admin"
    }
  }
}
```

### 2. Gestion des API Keys

#### 📋 Lister toutes les API Keys

```bash
curl -X POST "http://localhost/auth-groups/secret-admin/api-keys/list" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..." \
  -d '{
    "admin_secret_key": "Ak9#mZ$7LpQ@vX2nR8sE!dF6gH4jK0uY"
  }'
```

**Réponse :**
```json
{
  "success": true,
  "data": {
    "api_keys": [
      {
        "id": 1,
        "key_name": "Production API",
        "api_key": "ak_prod_...",
        "user_id": 1,
        "is_active": true,
        "created_at": "2024-01-15T10:30:00Z",
        "last_used_at": "2024-01-20T14:22:11Z",
        "usage_count": 247
      }
    ],
    "total_count": 5,
    "active_count": 4
  }
}
```

#### ➕ Créer une nouvelle API Key

```bash
curl -X POST "http://localhost/auth-groups/secret-admin/api-keys/create" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..." \
  -d '{
    "admin_secret_key": "Ak9#mZ$7LpQ@vX2nR8sE!dF6gH4jK0uY",
    "key_name": "Development API",
    "user_id": 2,
    "expires_at": "2024-12-31",
    "permissions": ["read", "write"]
  }'
```

**Réponse :**
```json
{
  "success": true,
  "data": {
    "api_key": {
      "id": 6,
      "key_name": "Development API",
      "api_key": "ak_dev_x9Y2mK8vQ3nR7sL5pF1gH4j",
      "user_id": 2,
      "is_active": true,
      "created_at": "2024-01-20T15:45:00Z",
      "expires_at": "2024-12-31T23:59:59Z"
    },
    "warning": "Conservez cette clé en lieu sûr, elle ne sera plus affichée"
  }
}
```

#### 🗑️ Supprimer une API Key

```bash
curl -X POST "http://localhost/auth-groups/secret-admin/api-keys/delete" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..." \
  -d '{
    "admin_secret_key": "Ak9#mZ$7LpQ@vX2nR8sE!dF6gH4jK0uY",
    "api_key_id": 6
  }'
```

#### ✏️ Mettre à jour une API Key

```bash
curl -X POST "http://localhost/auth-groups/secret-admin/api-keys/update" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..." \
  -d '{
    "admin_secret_key": "Ak9#mZ$7LpQ@vX2nR8sE!dF6gH4jK0uY",
    "api_key_id": 1,
    "key_name": "Production API v2",
    "is_active": false
  }'
```

#### 🔄 Actions groupées

```bash
curl -X POST "http://localhost/auth-groups/secret-admin/api-keys/bulk-actions" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..." \
  -d '{
    "admin_secret_key": "Ak9#mZ$7LpQ@vX2nR8sE!dF6gH4jK0uY",
    "action": "deactivate",
    "api_key_ids": [3, 4, 5]
  }'
```

---

## 🚨 Gestion des erreurs

### Codes d'erreur spécifiques

| Code | Description | Solution |
|------|-------------|----------|
| `401` | JWT invalide ou expiré | Renouveler le token JWT |
| `403` | ADMIN_SECRET_KEY incorrect | Vérifier la variable d'environnement |
| `404` | API Key introuvable | Vérifier l'ID de l'API key |
| `409` | Conflit (ex: nom existant) | Choisir un nom unique |
| `410` | Ancien endpoint utilisé | Migrer vers `/secret-admin/api-keys/*` |
| `429` | Rate limiting dépassé | Attendre avant de réessayer |

### Exemples de réponses d'erreur

**JWT manquant :**
```json
{
  "success": false,
  "error": "JWT_MISSING",
  "message": "Token d'authentification requis",
  "code": 401
}
```

**Secret key invalide :**
```json
{
  "success": false,
  "error": "ADMIN_SECRET_INVALID",
  "message": "Clé secrète administrateur incorrecte",
  "code": 403
}
```

**API Key introuvable :**
```json
{
  "success": false,
  "error": "API_KEY_NOT_FOUND",
  "message": "L'API key avec l'ID 999 n'existe pas",
  "code": 404
}
```

---

## 🔍 Monitoring et logs

### Logs automatiques

Le système enregistre automatiquement :
- Toutes les tentatives d'accès aux endpoints secret-admin
- Créations, modifications et suppressions d'API keys
- Échecs d'authentification avec détails
- Utilisations d'API keys avec horodatage

### Fichiers de logs

```
logs/
├── secret_admin_access.log      # Accès aux endpoints secret-admin
├── api_keys_operations.log      # Opérations CRUD sur les API keys
├── security_events.log          # Événements de sécurité suspects
└── authentication_failures.log  # Échecs d'authentification
```

### Exemple de log

```log
[2024-01-20 15:45:32] SECRET_ADMIN_ACCESS: {
  "action": "api_keys_create",
  "admin_user": "admin",
  "ip_address": "192.168.1.100",
  "user_agent": "curl/7.68.0",
  "api_key_created": "ak_dev_x9Y2mK8vQ3nR7sL5pF1gH4j",
  "target_user_id": 2,
  "success": true
}
```

---

## 🛡️ Bonnes pratiques de sécurité

### 1. Protection de ADMIN_SECRET_KEY

```bash
# ✅ Stockage sécurisé en production
export ADMIN_SECRET_KEY=$(cat /etc/cmem2/admin_secret.key)

# ✅ Rotation régulière (recommandé : tous les 90 jours)
ADMIN_SECRET_KEY_ROTATION_DATE=2024-04-20

# ❌ Éviter les fichiers de configuration Git
# Ne jamais commiter la clé dans .env ou config.php
```

### 2. Gestion des JWT Admin

```javascript
// ✅ Renouvellement automatique
const refreshJWT = async () => {
  if (isTokenExpiringSoon()) {
    await renewAdminToken();
  }
};

// ✅ Stockage sécurisé côté client
localStorage.removeItem('admin_jwt'); // Éviter localStorage
// Utiliser httpOnly cookies ou memory storage
```

### 3. Audit et surveillance

```bash
# ✅ Surveillance des logs suspects
tail -f logs/security_events.log | grep "FAILED_ADMIN_ACCESS"

# ✅ Alertes automatiques
grep -c "ADMIN_SECRET_INVALID" logs/security_events.log
```

---

## 📈 Migration depuis l'ancien système

### Étapes de migration

1. **Préparation**
   ```bash
   # Sauvegarder les API keys existantes
   curl -X GET "http://localhost/auth-groups/api-keys" > backup_api_keys.json
   ```

2. **Configuration**
   ```bash
   # Définir ADMIN_SECRET_KEY
   echo "ADMIN_SECRET_KEY=your-secret-key" >> .env
   ```

3. **Test de migration**
   ```bash
   # Tester l'accès secret-admin
   curl -X POST "http://localhost/auth-groups/secret-admin/api-keys/list" \
     -H "Authorization: Bearer $JWT_ADMIN" \
     -d '{"admin_secret_key": "$ADMIN_SECRET_KEY"}'
   ```

4. **Mise à jour des applications**
   ```javascript
   // Ancien code
   const response = await fetch('/auth-groups/api-keys', {
     method: 'GET',
     headers: { 'Authorization': `Bearer ${jwt}` }
   });

   // Nouveau code v1.3.0
   const response = await fetch('/auth-groups/secret-admin/api-keys/list', {
     method: 'POST',
     headers: { 
       'Authorization': `Bearer ${adminJWT}`,
       'Content-Type': 'application/json'
     },
     body: JSON.stringify({
       admin_secret_key: process.env.ADMIN_SECRET_KEY
     })
   });
   ```

### Vérification post-migration

```bash
# ✅ Vérifier que les anciens endpoints retournent 410
curl -X GET "http://localhost/auth-groups/api-keys"
# Attendu: HTTP 410 Gone

# ✅ Vérifier les nouveaux endpoints
curl -X POST "http://localhost/auth-groups/secret-admin/api-keys/list" \
  -H "Authorization: Bearer $JWT_ADMIN" \
  -d '{"admin_secret_key": "$ADMIN_SECRET_KEY"}'
# Attendu: HTTP 200 avec liste des API keys
```

---

## 🔗 Ressources connexes

- **[SECURITY_UPDATE_v1.3.0.md](./SECURITY_UPDATE_v1.3.0.md)** : Guide complet de migration
- **[API_KEYS_QUICK_REFERENCE.md](./API_KEYS_QUICK_REFERENCE.md)** : Référence rapide des commandes
- **[ENDPOINTS_API_KEYS.md](./ENDPOINTS_API_KEYS.md)** : Guide de dépréciation des anciens endpoints
- **[README.md](../README.md)** : Configuration générale du projet

---

## 📞 Support

En cas de problème avec le système Secret-Admin :

1. **Vérifier les logs** : `logs/security_events.log`
2. **Tester la configuration** : Variables d'environnement
3. **Valider l'authentification** : JWT Admin valide
4. **Contacter l'équipe technique** : Avec les logs d'erreur

---

*Document mis à jour pour cmem2 API v1.3.0 - Janvier 2024*