# Endpoints API Keys - AuthGroups API

Documentation complète des endpoints de gestion des clés API pour l'authentification machine-to-machine.

## 📋 Vue d'ensemble

Les clés API permettent une authentification alternative au JWT, idéale pour:
- Intégrations serveur-à-serveur
- Scripts automatisés
- Applications backend
- Services externes

## 🔐 Authentification

**Pour gérer les clés API**: Authentification JWT requise  
**Pour utiliser une clé API**: Header `X-API-Key` ou `Authorization: Bearer <api_key>`

### Format des clés

```
Production: ag_live_<64 caractères hexadécimaux>
Test:       ag_test_<64 caractères hexadécimaux>
```

## 📍 Endpoints

### 1. Créer une clé API

Génère une nouvelle clé API pour l'utilisateur authentifié.

```http
POST /api-keys
Authorization: Bearer <jwt_token>
Content-Type: application/json
```

**Body:**
```json
{
  "name": "Production API Key",
  "environment": "production",
  "scopes": ["read", "write"],
  "expires_in_days": 365,
  "rate_limit_per_minute": 60,
  "rate_limit_per_hour": 3600,
  "notes": "Clé pour l'application mobile",
  "metadata": {
    "app": "mobile-app",
    "version": "1.0.0"
  }
}
```

**Paramètres:**

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `name` | string | ✅ | Nom descriptif (max 255 caractères) |
| `environment` | string | ❌ | `production` ou `test` (défaut: `production`) |
| `scopes` | array | ❌ | Permissions (défaut: `["read", "write"]`) |
| `expires_in_days` | int | ❌ | Jours avant expiration (null = jamais) |
| `rate_limit_per_minute` | int | ❌ | Requêtes max/minute (défaut: 60) |
| `rate_limit_per_hour` | int | ❌ | Requêtes max/heure (défaut: 3600) |
| `notes` | string | ❌ | Notes internes |
| `metadata` | object | ❌ | Métadonnées personnalisées (JSON) |

**Scopes disponibles:**
- `read` - Lecture seule
- `write` - Création et modification
- `delete` - Suppression
- `admin` - Opérations administratives
- `*` - Tous les scopes (accès complet)

**Réponse succès (201):**
```json
{
  "success": true,
  "message": "Clé API créée avec succès",
  "data": {
    "api_key": "ag_live_a1b2c3d4e5f6...xyz",
    "key_data": {
      "id": 42,
      "name": "Production API Key",
      "prefix": "ag_live",
      "last_4": "...xyz",
      "environment": "production",
      "scopes": ["read", "write"],
      "rate_limits": {
        "per_minute": 60,
        "per_hour": 3600
      },
      "expires_at": "2026-10-07 12:00:00",
      "created_at": "2025-10-07 12:00:00"
    },
    "warning": "Sauvegardez cette clé maintenant! Elle ne sera plus jamais affichée."
  }
}
```

⚠️ **IMPORTANT**: La clé complète n'est affichée qu'UNE SEULE FOIS lors de la création!

**Erreurs:**
- `400` - Validation échouée
- `401` - Token JWT manquant ou invalide
- `500` - Erreur serveur

---

### 2. Lister les clés API

Récupère toutes les clés de l'utilisateur authentifié.

```http
GET /api-keys?active_only=true
Authorization: Bearer <jwt_token>
```

**Query Parameters:**

| Paramètre | Type | Description |
|-----------|------|-------------|
| `active_only` | boolean | Filtrer uniquement les clés actives (défaut: false) |

**Réponse succès (200):**
```json
{
  "success": true,
  "message": "Clés API récupérées",
  "data": {
    "count": 3,
    "keys": [
      {
        "id": 42,
        "name": "Production API Key",
        "prefix": "ag_live",
        "last_4": "...xyz",
        "environment": "production",
        "scopes": ["read", "write"],
        "status": "active",
        "rate_limits": {
          "per_minute": 60,
          "per_hour": 3600
        },
        "usage": {
          "total_requests": 15420,
          "last_used_at": "2025-10-07 11:30:00",
          "last_used_ip": "192.168.1.100"
        },
        "expires_at": "2026-10-07 12:00:00",
        "revoked_at": null,
        "revoked_reason": null,
        "created_at": "2025-10-07 12:00:00",
        "updated_at": "2025-10-07 12:00:00"
      },
      {
        "id": 43,
        "name": "Test API Key",
        "prefix": "ag_test",
        "last_4": "...abc",
        "environment": "test",
        "scopes": ["*"],
        "status": "active",
        "rate_limits": {
          "per_minute": 120,
          "per_hour": 7200
        },
        "usage": {
          "total_requests": 523,
          "last_used_at": "2025-10-06 18:45:00",
          "last_used_ip": "127.0.0.1"
        },
        "expires_at": null,
        "revoked_at": null,
        "revoked_reason": null,
        "created_at": "2025-09-15 09:30:00",
        "updated_at": "2025-09-15 09:30:00"
      },
      {
        "id": 41,
        "name": "Old API Key",
        "prefix": "ag_live",
        "last_4": "...def",
        "environment": "production",
        "scopes": ["read"],
        "status": "revoked",
        "rate_limits": {
          "per_minute": 30,
          "per_hour": 1800
        },
        "usage": {
          "total_requests": 8764,
          "last_used_at": "2025-09-30 23:59:59",
          "last_used_ip": "192.168.1.50"
        },
        "expires_at": null,
        "revoked_at": "2025-10-01 00:00:00",
        "revoked_reason": "Compromised key",
        "created_at": "2025-01-10 08:00:00",
        "updated_at": "2025-10-01 00:00:00"
      }
    ]
  }
}
```

**Statuts possibles:**
- `active` - Clé active et utilisable
- `expired` - Clé expirée
- `revoked` - Clé révoquée manuellement

**Erreurs:**
- `401` - Non authentifié
- `500` - Erreur serveur

---

### 3. Obtenir les détails d'une clé

Récupère les informations détaillées et statistiques d'une clé spécifique.

```http
GET /api-keys/{id}
Authorization: Bearer <jwt_token>
```

**Path Parameters:**

| Paramètre | Type | Description |
|-----------|------|-------------|
| `id` | int | ID de la clé API |

**Réponse succès (200):**
```json
{
  "success": true,
  "message": "Détails de la clé API",
  "data": {
    "key": {
      "id": 42,
      "name": "Production API Key",
      "prefix": "ag_live",
      "last_4": "...xyz",
      "environment": "production",
      "scopes": ["read", "write"],
      "status": "active",
      "rate_limits": {
        "per_minute": 60,
        "per_hour": 3600
      },
      "metadata": {
        "app": "mobile-app",
        "version": "1.0.0"
      },
      "notes": "Clé pour l'application mobile",
      "expires_at": "2026-10-07 12:00:00",
      "revoked_at": null,
      "revoked_reason": null,
      "created_at": "2025-10-07 12:00:00",
      "updated_at": "2025-10-07 12:00:00"
    },
    "stats": {
      "id": 42,
      "name": "Production API Key",
      "environment": "production",
      "total_requests": 15420,
      "last_used_at": "2025-10-07 11:30:00",
      "last_used_ip": "192.168.1.100",
      "created_at": "2025-10-07 12:00:00",
      "age_days": 0,
      "days_since_last_use": 0
    }
  }
}
```

**Erreurs:**
- `401` - Non authentifié
- `403` - Accès refusé (clé appartient à un autre utilisateur)
- `404` - Clé non trouvée
- `500` - Erreur serveur

---

### 4. Révoquer une clé API

Révoque définitivement une clé API. Cette action est irréversible.

```http
DELETE /api-keys/{id}
Authorization: Bearer <jwt_token>
Content-Type: application/json
```

**Path Parameters:**

| Paramètre | Type | Description |
|-----------|------|-------------|
| `id` | int | ID de la clé API à révoquer |

**Body (optionnel):**
```json
{
  "reason": "Clé compromise"
}
```

**Réponse succès (200):**
```json
{
  "success": true,
  "message": "Clé API révoquée avec succès",
  "data": {
    "key_id": 42,
    "reason": "Clé compromise",
    "revoked_at": "2025-10-07 12:15:00"
  }
}
```

**Erreurs:**
- `400` - Clé déjà révoquée
- `401` - Non authentifié
- `403` - Accès refusé
- `404` - Clé non trouvée
- `500` - Erreur serveur

---

### 5. Régénérer une clé API

Révoque l'ancienne clé et en génère une nouvelle avec les mêmes paramètres.

```http
POST /api-keys/{id}/regenerate
Authorization: Bearer <jwt_token>
```

**Path Parameters:**

| Paramètre | Type | Description |
|-----------|------|-------------|
| `id` | int | ID de la clé API à régénérer |

**Réponse succès (201):**
```json
{
  "success": true,
  "message": "Clé API régénérée avec succès",
  "data": {
    "old_key_id": 42,
    "new_api_key": "ag_live_f6e5d4c3b2a1...new",
    "new_key_data": {
      "id": 44,
      "name": "Production API Key",
      "prefix": "ag_live",
      "last_4": "...new",
      "environment": "production",
      "scopes": ["read", "write"],
      "created_at": "2025-10-07 12:30:00"
    },
    "warning": "Sauvegardez cette nouvelle clé maintenant! Elle ne sera plus jamais affichée."
  }
}
```

⚠️ **IMPORTANT**: L'ancienne clé est immédiatement révoquée. La nouvelle clé n'est affichée qu'une seule fois!

**Erreurs:**
- `401` - Non authentifié
- `403` - Accès refusé
- `404` - Clé non trouvée
- `500` - Erreur serveur

---

## 🔑 Utilisation des clés API

### Méthode 1: Header X-API-Key (Recommandé)

```bash
curl -H "X-API-Key: ag_live_a1b2c3..." \
     https://api.example.com/users
```

### Méthode 2: Authorization Bearer

```bash
curl -H "Authorization: Bearer ag_live_a1b2c3..." \
     https://api.example.com/groups
```

### Méthode 3: Query Parameter (Déconseillé)

⚠️ Seulement en mode debug/développement:

```bash
curl "https://api.example.com/tags?api_key=ag_test_xyz..."
```

## 📊 Rate Limiting

Les headers suivants sont inclus dans chaque réponse lors de l'utilisation d'une API key:

```
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1696680000
```

Si la limite est dépassée:

```http
HTTP/1.1 429 Too Many Requests
```

```json
{
  "success": false,
  "message": "Limite de taux dépassée pour cette clé API",
  "error": {
    "code": "RATE_LIMIT_EXCEEDED",
    "limit": 60,
    "reset_at": 1696680000
  }
}
```

## 🔒 Scopes et Permissions

Les scopes déterminent les actions autorisées avec une clé API:

| Scope | Permissions |
|-------|-------------|
| `read` | GET - Lecture seule |
| `write` | POST, PUT - Création et modification |
| `delete` | DELETE - Suppression de ressources |
| `admin` | Toutes opérations + administration |
| `*` | Accès complet à tous les scopes |

### Exemples de combinaisons

**Lecture seule:**
```json
{
  "scopes": ["read"]
}
```

**Lecture et écriture standard:**
```json
{
  "scopes": ["read", "write"]
}
```

**Accès complet:**
```json
{
  "scopes": ["*"]
}
```

## ⚡ Exemples d'utilisation

### JavaScript

```javascript
const API_KEY = 'ag_live_a1b2c3d4...';
const API_URL = 'https://api.example.com';

// Avec X-API-Key header
fetch(`${API_URL}/users`, {
  headers: {
    'X-API-Key': API_KEY,
    'Content-Type': 'application/json'
  }
})
.then(res => res.json())
.then(data => console.log(data));

// Créer une nouvelle clé via JWT
const jwt = 'eyJhbGciOiJIUzI1NiIs...';

fetch(`${API_URL}/api-keys`, {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${jwt}`,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    name: 'My API Key',
    scopes: ['read', 'write'],
    expires_in_days: 90
  })
})
.then(res => res.json())
.then(data => {
  console.log('Nouvelle clé:', data.data.api_key);
  // IMPORTANT: Sauvegarder immédiatement!
});
```

### Python

```python
import requests

API_KEY = 'ag_live_a1b2c3d4...'
API_URL = 'https://api.example.com'

# Utiliser une API key
headers = {
    'X-API-Key': API_KEY,
    'Content-Type': 'application/json'
}

response = requests.get(f'{API_URL}/groups', headers=headers)
print(response.json())

# Créer une nouvelle clé
jwt_token = 'eyJhbGciOiJIUzI1NiIs...'

response = requests.post(
    f'{API_URL}/api-keys',
    headers={'Authorization': f'Bearer {jwt_token}'},
    json={
        'name': 'Python Script Key',
        'scopes': ['read', 'write'],
        'rate_limit_per_minute': 120
    }
)

key_data = response.json()
api_key = key_data['data']['api_key']
print(f'Nouvelle clé: {api_key}')
# IMPORTANT: Sauvegarder immédiatement!
```

### PHP

```php
<?php

$apiKey = 'ag_live_a1b2c3d4...';
$apiUrl = 'https://api.example.com';

// Utiliser une API key
$ch = curl_init($apiUrl . '/files');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-API-Key: ' . $apiKey,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$data = json_decode($response, true);

print_r($data);
curl_close($ch);
```

## 🛡️ Bonnes pratiques

### Sécurité

1. ✅ **Stocker les clés de manière sécurisée**
   - Variables d'environnement
   - Gestionnaires de secrets (AWS Secrets Manager, Azure Key Vault)
   - Jamais dans le code source

2. ✅ **Utiliser des clés différentes par environnement**
   - Production: `ag_live_xxx`
   - Test/Développement: `ag_test_xxx`

3. ✅ **Rotation régulière**
   - Régénérer les clés périodiquement
   - Utiliser l'endpoint `/regenerate`

4. ✅ **Principe du moindre privilège**
   - N'accorder que les scopes nécessaires
   - Éviter `*` sauf si vraiment nécessaire

5. ✅ **Surveillance**
   - Vérifier `last_used_at` et `last_used_ip`
   - Révoquer immédiatement les clés suspectes

### Performance

1. ✅ **Réutiliser la même clé**
   - Pas besoin de régénérer à chaque requête
   - La clé reste valide jusqu'à expiration/révocation

2. ✅ **Respecter les rate limits**
   - Vérifier les headers `X-RateLimit-*`
   - Implémenter des retry avec backoff

3. ✅ **Utiliser l'environnement test**
   - Clés `ag_test_xxx` pour le développement
   - Peut avoir des limites différentes

## 🔍 Codes d'erreur spécifiques

| Code | Message | Solution |
|------|---------|----------|
| `MISSING_API_KEY` | Clé API manquante | Ajouter header X-API-Key |
| `INVALID_API_KEY` | Clé invalide/expirée/révoquée | Vérifier la clé ou en générer une nouvelle |
| `INSUFFICIENT_PERMISSIONS` | Scope requis manquant | Utiliser une clé avec les bons scopes |
| `RATE_LIMIT_EXCEEDED` | Limite dépassée | Attendre le reset ou augmenter la limite |

## 📝 Notes importantes

1. **La clé complète n'est montrée qu'une seule fois** lors de la création ou régénération
2. **Les clés révoquées ne peuvent pas être réactivées** - créer une nouvelle clé
3. **Les clés expirées sont automatiquement révoquées** par le système
4. **Seul le propriétaire peut voir/gérer ses clés** (pas d'accès admin cross-user)
5. **Les métadonnées sont flexibles** - utilisez-les pour identifier vos intégrations

---

**Voir aussi:**
- [Documentation principale](../README.md)
- [Référence API complète](./API_REFERENCE.md)
- [Guide sécurité](./API_OVERVIEW.md#sécurité)
