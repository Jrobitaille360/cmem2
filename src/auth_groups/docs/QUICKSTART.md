# Guide de Démarrage Rapide - AuthGroups API

Bienvenue! Ce guide vous aidera à démarrer rapidement avec AuthGroups API.

## 🚀 Installation Express (5 minutes)

### 1. Téléchargement

```bash
git clone https://github.com/Jrobitaille360/cmem2.git
cd cmem2_API
```

### 2. Installation des dépendances

```bash
composer install
```

### 3. Configuration base de données

Créez la base de données:

```bash
mysql -u root -p < docs/create_database.sql
```

### 4. Configuration

```bash
cp config/environment.example.php config/environment.php
nano config/environment.php
```

Configuration minimale:

```php
<?php
// Base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'cmem2_db');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');

// JWT Secret (générez une clé aléatoire forte)
define('JWT_SECRET_KEY', 'votre-cle-secrete-tres-longue-et-aleatoire');
define('JWT_EXPIRATION', 86400); // 24 heures

// Uploads
define('UPLOAD_DIR', __DIR__ . '/uploads/');
```

### 5. Permissions

```bash
chmod -R 755 config/uploads/
```

### 6. Configuration des API Keys (OBLIGATOIRE v1.3.0)

⚠️ **IMPORTANT** : Depuis la v1.3.0, les API keys sont **obligatoires** pour tous les logins.

Créez votre première API key avec le script bootstrap :

```bash
# Configuration dans environment.php (ajoutez cette ligne)
echo "define('ADMIN_SECRET_KEY', 'votre-cle-secrete-admin-super-longue-et-aleatoire');" >> config/environment.php

# Créer la première API key
php bootstrap_create_first_api_key.php
```

Le script vous donnera une API key comme : `ag_live_abc123...`

**⚠️ Sauvegardez cette clé** - elle est nécessaire pour tous les appels API !

### 7. Test

Ouvrez dans votre navigateur:

```text
http://localhost/cmem2_API/
```

Vous devriez voir:

```json
{
  "success": true,
  "data": {
    "name": "AuthGroups API",
    "version": "1.3.0",
    "description": "API REST pour la gestion d'authentification et de groupes",
    "security_notice": "API Keys obligatoires pour tous les appels"
  }
}
```

## 📱 Premiers pas avec l'API

### ⚠️ Prérequis v1.3.0

**IMPORTANT** : Toutes les requêtes nécessitent maintenant une API key valide !

Utilisez l'API key créée précédemment dans toutes vos requêtes.

### 1. Créer un utilisateur

**Requête** (avec API key obligatoire) :

```bash
curl -X POST http://localhost/cmem2_API/users/register \
  -H "Content-Type: application/json" \
  -H "X-API-Key: ag_live_votre_cle_ici" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "SecurePass123"
  }'
```

**Réponse:**

```json
{
  "success": true,
  "data": {
    "user": {
      "user_id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "role": "UTILISATEUR"
    }
  },
  "message": "Utilisateur créé avec succès"
}
```

### 2. Se connecter

**Requête** (API key obligatoire) :

```bash
curl -X POST http://localhost/cmem2_API/users/login \
  -H "Content-Type: application/json" \
  -H "X-API-Key: ag_live_votre_cle_ici" \
  -d '{
    "email": "john@example.com",
    "password": "SecurePass123"
  }'
```

**Réponse:**

```json
{
  "success": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "user": {
      "user_id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "role": "UTILISATEUR"
    }
  }
}
```

**💡 Sauvegardez le token!** Il sera nécessaire pour toutes les requêtes authentifiées.

### 3. Récupérer votre profil

**Requête** (JWT + API key requis) :

```bash
curl -X GET http://localhost/cmem2_API/users/me \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc..." \
  -H "X-API-Key: ag_live_votre_cle_ici"
```

**Réponse:**

```json
{
  "success": true,
  "data": {
    "user": {
      "user_id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "role": "UTILISATEUR",
      "created_at": "2025-10-07 10:30:00"
    }
  }
}
```

### 4. Créer un groupe

**Requête** (JWT + API key requis) :

```bash
curl -X POST http://localhost/cmem2_API/groups \
  -H "Authorization: Bearer VOTRE_TOKEN" \
  -H "X-API-Key: ag_live_votre_cle_ici" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Mon Premier Groupe",
    "description": "Un groupe de test",
    "visibility": "PUBLIC"
  }'
```

**Réponse:**

```json
{
  "success": true,
  "data": {
    "group": {
      "group_id": 1,
      "name": "Mon Premier Groupe",
      "description": "Un groupe de test",
      "visibility": "PUBLIC",
      "owner_id": 1
    }
  }
}
```

### 5. Créer un tag

**Requête:**

```bash
curl -X POST http://localhost/cmem2_API/tags \
  -H "Authorization: Bearer VOTRE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Important",
    "table_associate": "groups",
    "color": "#e74c3c"
  }'
```

### 6. Upload un fichier

**Requête:**

```bash
curl -X POST http://localhost/cmem2_API/files/upload \
  -H "Authorization: Bearer VOTRE_TOKEN" \
  -F "files[]=@/path/to/file.jpg" \
  -F "description=Ma première image"
```

## 🛠️ Configuration Email (Optionnelle)

Pour activer les emails (invitations, notifications):

```php
// Dans config/environment.php
$_ENV['MAIL_HOST'] = 'smtp.gmail.com';
$_ENV['MAIL_PORT'] = 587;
$_ENV['MAIL_USERNAME'] = 'votre-email@gmail.com';
$_ENV['MAIL_PASSWORD'] = 'votre-mot-de-passe-application';
$_ENV['MAIL_ENCRYPTION'] = 'tls';
$_ENV['MAIL_FROM_ADDRESS'] = 'noreply@authgroups.local';
$_ENV['MAIL_FROM_NAME'] = 'AuthGroups API';
```

**Note Gmail:** Utilisez un [mot de passe d'application](https://support.google.com/accounts/answer/185833).

## 📊 Vérifier les statistiques

```bash
curl -X GET http://localhost/cmem2_API/stats/user/1 \
  -H "Authorization: Bearer VOTRE_TOKEN"
```

## 🔍 Explorer l'API

### Endpoints disponibles

```bash
curl http://localhost/cmem2_API/help
```

### Santé de l'API

```bash
curl http://localhost/cmem2_API/health
```

## 📚 Ressources Utiles

- **Documentation complète**: [README.md](../README.md)
- **Vue d'ensemble API**: [docs/API_OVERVIEW.md](./API_OVERVIEW.md)
- **Endpoints utilisateurs**: [docs/ENDPOINTS_USERS.md](./ENDPOINTS_USERS.md)
- **Endpoints groupes**: [docs/ENDPOINTS_GROUPS.md](./ENDPOINTS_GROUPS.md)
- **Endpoints fichiers**: [docs/ENDPOINTS_FILES.md](./ENDPOINTS_FILES.md)
- **Endpoints tags**: [docs/ENDPOINTS_TAGS.md](./ENDPOINTS_TAGS.md)
- **🆕 Endpoints API Keys**: [docs/ENDPOINTS_API_KEYS.md](./ENDPOINTS_API_KEYS.md)

## 🧪 Tests

Exécuter les tests:

```bash
# Tous les tests
composer test

# Test spécifique
php tests/test_users_entrypoints.php
```

## ⚠️ Problèmes Courants

### "API Key required" ou HTTP 401

- Vérifiez que vous incluez l'API key dans toutes vos requêtes
- Format: `X-API-Key: ag_live_...` ou `Authorization: Bearer ag_live_...`
- Utilisez le script bootstrap si vous n'avez pas d'API key

### "Admin secret key required" (gestion API keys)

- Définissez `ADMIN_SECRET_KEY` dans `config/environment.php`
- Utilisez une clé très longue et aléatoire
- Requis pour accéder aux endpoints `/secret-admin/api-keys/*`

### "Database connection failed"

- Vérifiez les credentials dans `config/environment.php`
- Assurez-vous que MySQL est démarré
- Vérifiez que la base de données existe

### "JWT Secret not configured"

- Définissez `JWT_SECRET_KEY` dans `config/environment.php`
- Utilisez une clé longue et aléatoire

### "Permission denied" sur uploads

```bash
chmod -R 755 config/uploads/
chown -R www-data:www-data config/uploads/
```

### "Composer not found"

```bash
# Installation Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

## 🎯 Prochaines Étapes

1. ✅ **Explorez** les différents endpoints
2. 📖 **Lisez** la documentation détaillée
3. 🧪 **Testez** avec Postman ou votre client préféré
4. 🔧 **Personnalisez** selon vos besoins
5. 🚀 **Déployez** en production

## 💡 Exemples de Code Client

### JavaScript (Fetch API)

```javascript
// Login
const login = async () => {
  const response = await fetch('http://localhost/cmem2_API/users/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: 'john@example.com',
      password: 'SecurePass123'
    })
  });
  const data = await response.json();
  localStorage.setItem('token', data.data.token);
};

// Requête authentifiée
const getProfile = async () => {
  const token = localStorage.getItem('token');
  const response = await fetch('http://localhost/cmem2_API/users/me', {
    headers: { 'Authorization': `Bearer ${token}` }
  });
  return await response.json();
};
```

### Python (Requests)

```python
import requests

# Login
response = requests.post('http://localhost/cmem2_API/users/login', json={
    'email': 'john@example.com',
    'password': 'SecurePass123'
})
token = response.json()['data']['token']

# Requête authentifiée
headers = {'Authorization': f'Bearer {token}'}
profile = requests.get('http://localhost/cmem2_API/users/me', headers=headers)
print(profile.json())
```

### PHP (cURL)

```php
<?php
// Login
$ch = curl_init('http://localhost/cmem2_API/users/login');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'john@example.com',
    'password' => 'SecurePass123'
]));

$response = json_decode(curl_exec($ch), true);
$token = $response['data']['token'];

// Requête authentifiée
curl_setopt($ch, CURLOPT_URL, 'http://localhost/cmem2_API/users/me');
curl_setopt($ch, CURLOPT_HTTPGET, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token
]);

$profile = json_decode(curl_exec($ch), true);
curl_close($ch);
```

## 📞 Support

Besoin d'aide?

- 📧 Email: <support@authgroups.local>
- 🐛 Issues: [GitHub Issues](https://github.com/Jrobitaille360/cmem2/issues)
- 📖 Documentation: [README.md](../README.md)

---

**Prêt à développer?** Consultez la [documentation complète](../README.md) pour aller plus loin!
