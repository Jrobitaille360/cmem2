# Référence Rapide API - AuthGroups

Guide de référence rapide pour les développeurs.

## 🔗 URLs de Base

```text
Development: http://localhost/cmem2_API/
Production:  https://your-domain.com/api/
```

## 🔑 Authentification

L'API supporte deux méthodes d'authentification :

### 1. JWT Token (pour utilisateurs)

#### Headers requis

```http
Authorization: Bearer {jwt_token}
Content-Type: application/json
```

#### Obtenir un token

```bash
POST /users/login
{
  "email": "user@example.com",
  "password": "password"
}
```

### 2. API Keys (pour machines/intégrations)

```http
X-API-Key: {api_key}
Content-Type: application/json
```

#### Créer une API key

```bash
POST /api-keys
Authorization: Bearer {jwt_token}
{
  "name": "My Integration",
  "scopes": ["read", "write"],
  "environment": "production"
}
```

Voir [ENDPOINTS_API_KEYS.md](./ENDPOINTS_API_KEYS.md) pour plus de détails.

## 📋 Endpoints Essentiels

### Utilisateurs

| Action | Méthode | Endpoint | Auth |
|--------|---------|----------|:----:|
| Inscription | POST | `/users/register` | ❌ |
| Connexion | POST | `/users/login` | ❌ |
| Mon profil | GET | `/users/me` | ✅ |
| Modifier profil | PUT | `/users/me` | ✅ |
| Upload avatar | POST | `/users/avatar` | ✅ |
| Liste utilisateurs | GET | `/users` | ✅ |
| Détails utilisateur | GET | `/users/{id}` | ✅ |

### Groupes

| Action | Méthode | Endpoint | Auth |
|--------|---------|----------|:----:|
| Liste mes groupes | GET | `/groups` | ✅ |
| Créer groupe | POST | `/groups` | ✅ |
| Détails groupe | GET | `/groups/{id}` | ✅ |
| Modifier groupe | PUT | `/groups/{id}` | ✅ |
| Supprimer groupe | DELETE | `/groups/{id}` | ✅ |
| Inviter membre | POST | `/groups/{id}/invite` | ✅ |
| Liste membres | GET | `/groups/{id}/members` | ✅ |
| Rechercher | GET | `/groups/search?q={query}` | ✅ |

### Fichiers

| Action | Méthode | Endpoint | Auth |
|--------|---------|----------|:----:|
| Upload fichier(s) | POST | `/files/upload` | ✅ |
| Liste fichiers | GET | `/files` | ✅ |
| Détails fichier | GET | `/files/{id}` | ✅ |
| Supprimer | DELETE | `/files/{id}` | ✅ |
| Restaurer | PUT | `/files/{id}/restore` | ✅ |

### Tags

| Action | Méthode | Endpoint | Auth |
|--------|---------|----------|:----:|
| Liste tags | GET | `/tags` | ✅ |
| Créer tag | POST | `/tags` | ✅ |
| Détails tag | GET | `/tags/{id}` | ✅ |
| Modifier tag | PUT | `/tags/{id}` | ✅ |
| Tags par table | GET | `/tags/by-table/{table}` | ✅ |
| Plus utilisés | GET | `/tags/most-used` | ✅ |
| Associer | POST | `/tags/{tag_id}/associate/{item_id}` | ✅ |

### 🆕 API Keys

| Action | Méthode | Endpoint | Auth |
|--------|---------|----------|:----:|
| Créer clé | POST | `/api-keys` | ✅ JWT |
| Liste clés | GET | `/api-keys` | ✅ JWT |
| Détails clé | GET | `/api-keys/{id}` | ✅ JWT |
| Révoquer | DELETE | `/api-keys/{id}` | ✅ JWT |
| Régénérer | POST | `/api-keys/{id}/regenerate` | ✅ JWT |

### Stats

| Action | Méthode | Endpoint | Auth |
|--------|---------|----------|:----:|
| Stats utilisateur | GET | `/stats/user/{id}` | ✅ |
| Utilisateurs en ligne | GET | `/stats/online` | ✅ |

### Public

| Action | Méthode | Endpoint | Auth |
|--------|---------|----------|:----:|
| Info API | GET | `/` | ❌ |
| Aide | GET | `/help` | ❌ |
| Santé | GET | `/health` | ❌ |

## 🎨 Codes de Statut HTTP

| Code | Signification |
|------|---------------|
| 200 | ✅ Succès |
| 201 | ✅ Créé |
| 400 | ❌ Requête invalide |
| 401 | 🔒 Non authentifié |
| 403 | 🚫 Accès refusé |
| 404 | 🔍 Non trouvé |
| 409 | ⚠️ Conflit |
| 500 | 💥 Erreur serveur |

## 📦 Format des Réponses

### Succès

```json
{
  "success": true,
  "data": { ... },
  "message": "Operation successful"
}
```

### Erreur

```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Description",
    "details": { ... }
  }
}
```

## 🎯 Exemples Rapides

### JavaScript

```javascript
// Configuration de base
const API_URL = 'http://localhost/cmem2_API';
let token = localStorage.getItem('token');
let apiKey = localStorage.getItem('apiKey'); // For machine integrations

const api = {
  async call(endpoint, options = {}) {
    const headers = {
      'Content-Type': 'application/json'
    };
    
    // Auth: JWT token or API key
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    } else if (apiKey) {
      headers['X-API-Key'] = apiKey;
    }
    
    const response = await fetch(`${API_URL}${endpoint}`, {
      ...options,
      headers: { ...headers, ...options.headers }
    });
    
    return await response.json();
  },
  
  // Utilisateurs
  register: (data) => api.call('/users/register', {
    method: 'POST',
    body: JSON.stringify(data)
  }),
  
  login: async (email, password) => {
    const result = await api.call('/users/login', {
      method: 'POST',
      body: JSON.stringify({ email, password })
    });
    if (result.success) {
      token = result.data.token;
      localStorage.setItem('token', token);
    }
    return result;
  },
  
  getProfile: () => api.call('/users/me'),
  
  updateProfile: (data) => api.call('/users/me', {
    method: 'PUT',
    body: JSON.stringify(data)
  }),
  
  // API Keys
  createApiKey: (data) => api.call('/api-keys', {
    method: 'POST',
    body: JSON.stringify(data)
  }),
  
  listApiKeys: () => api.call('/api-keys'),
  
  // Groupes
  getGroups: () => api.call('/groups'),
  
  createGroup: (data) => api.call('/groups', {
    method: 'POST',
    body: JSON.stringify(data)
  }),
  
  getGroup: (id) => api.call(`/groups/${id}`),
  
  inviteToGroup: (groupId, email, role = 'MEMBRE') => 
    api.call(`/groups/${groupId}/invite`, {
      method: 'POST',
      body: JSON.stringify({ email, role })
    }),
  
  // Tags
  getTags: (table = 'all') => api.call(`/tags?table_associate=${table}`),
  
  createTag: (name, table, color = '#3498db') => 
    api.call('/tags', {
      method: 'POST',
      body: JSON.stringify({ name, table_associate: table, color })
    }),
  
  // Fichiers
  uploadFile: async (file) => {
    const formData = new FormData();
    formData.append('files[]', file);
    
    return await fetch(`${API_URL}/files/upload`, {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${token}` },
      body: formData
    }).then(r => r.json());
  }
};

// Utilisation
await api.register({
  name: 'John Doe',
  email: 'john@example.com',
  password: 'SecurePass123'
});

await api.login('john@example.com', 'SecurePass123');

const profile = await api.getProfile();
console.log(profile);

const groups = await api.getGroups();
console.log(groups);
```

### Python

```python
import requests
import json

class AuthGroupsAPI:
    def __init__(self, base_url='http://localhost/cmem2_API'):
        self.base_url = base_url
        self.token = None
    
    def _headers(self):
        headers = {'Content-Type': 'application/json'}
        if self.token:
            headers['Authorization'] = f'Bearer {self.token}'
        return headers
    
    # Utilisateurs
    def register(self, name, email, password):
        return requests.post(
            f'{self.base_url}/users/register',
            json={'name': name, 'email': email, 'password': password},
            headers=self._headers()
        ).json()
    
    def login(self, email, password):
        response = requests.post(
            f'{self.base_url}/users/login',
            json={'email': email, 'password': password},
            headers=self._headers()
        ).json()
        
        if response.get('success'):
            self.token = response['data']['token']
        return response
    
    def get_profile(self):
        return requests.get(
            f'{self.base_url}/users/me',
            headers=self._headers()
        ).json()
    
    # Groupes
    def get_groups(self):
        return requests.get(
            f'{self.base_url}/groups',
            headers=self._headers()
        ).json()
    
    def create_group(self, name, description='', visibility='PUBLIC'):
        return requests.post(
            f'{self.base_url}/groups',
            json={'name': name, 'description': description, 'visibility': visibility},
            headers=self._headers()
        ).json()
    
    # Tags
    def create_tag(self, name, table='groups', color='#3498db'):
        return requests.post(
            f'{self.base_url}/tags',
            json={'name': name, 'table_associate': table, 'color': color},
            headers=self._headers()
        ).json()
    
    # Fichiers
    def upload_file(self, file_path):
        with open(file_path, 'rb') as f:
            files = {'files[]': f}
            headers = {'Authorization': f'Bearer {self.token}'} if self.token else {}
            return requests.post(
                f'{self.base_url}/files/upload',
                files=files,
                headers=headers
            ).json()

# Utilisation
api = AuthGroupsAPI()

# Inscription
api.register('John Doe', 'john@example.com', 'SecurePass123')

# Connexion
api.login('john@example.com', 'SecurePass123')

# Profil
profile = api.get_profile()
print(profile)

# Groupes
groups = api.get_groups()
print(groups)
```

### PHP

```php
<?php

class AuthGroupsAPI {
    private $baseUrl;
    private $token;
    
    public function __construct($baseUrl = 'http://localhost/cmem2_API') {
        $this->baseUrl = $baseUrl;
    }
    
    private function request($method, $endpoint, $data = null) {
        $ch = curl_init($this->baseUrl . $endpoint);
        
        $headers = ['Content-Type: application/json'];
        if ($this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }
    
    // Utilisateurs
    public function register($name, $email, $password) {
        return $this->request('POST', '/users/register', [
            'name' => $name,
            'email' => $email,
            'password' => $password
        ]);
    }
    
    public function login($email, $password) {
        $response = $this->request('POST', '/users/login', [
            'email' => $email,
            'password' => $password
        ]);
        
        if ($response['success']) {
            $this->token = $response['data']['token'];
        }
        
        return $response;
    }
    
    public function getProfile() {
        return $this->request('GET', '/users/me');
    }
    
    // Groupes
    public function getGroups() {
        return $this->request('GET', '/groups');
    }
    
    public function createGroup($name, $description = '', $visibility = 'PUBLIC') {
        return $this->request('POST', '/groups', [
            'name' => $name,
            'description' => $description,
            'visibility' => $visibility
        ]);
    }
    
    // Tags
    public function createTag($name, $table = 'groups', $color = '#3498db') {
        return $this->request('POST', '/tags', [
            'name' => $name,
            'table_associate' => $table,
            'color' => $color
        ]);
    }
}

// Utilisation
$api = new AuthGroupsAPI();

// Inscription
$api->register('John Doe', 'john@example.com', 'SecurePass123');

// Connexion
$api->login('john@example.com', 'SecurePass123');

// Profil
$profile = $api->getProfile();
print_r($profile);
```

## 🔧 Variables d'Environnement Clés

```php
// Base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'cmem2_db');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');

// JWT
define('JWT_SECRET_KEY', 'your-secret-key');
define('JWT_EXPIRATION', 86400); // 24h

// Uploads
define('UPLOAD_DIR', __DIR__ . '/uploads/');
```

## 📏 Limites

| Type | Limite |
|------|--------|
| Images | 5 MB |
| Vidéos | 50 MB |
| Documents | 10 MB |
| Audio | 10 MB |
| Avatars | 2 MB |
| Pagination | 100/page |

## 🏷️ Tags - Tables Valides

- `groups` - Groupes
- `files` - Fichiers
- `all` - Toutes les tables

## 👥 Rôles Utilisateurs

- `UTILISATEUR` - Accès standard
- `MODERATEUR` - Permissions étendues
- `ADMINISTRATEUR` - Accès complet

---

**Plus d'infos:** [Documentation complète](../README.md) | [Guide démarrage](./QUICKSTART.md)
