# AuthGroups API

![Version](https://img.shields.io/badge/version-1.3.0-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.0+-purple.svg)
![Status](https://img.shields.io/badge/status-production%20ready-green.svg)
![Tests](https://img.shields.io/badge/tests-23%2F23%20passing-brightgreen.svg)
![License](https://img.shields.io/badge/license-MIT-orange.svg)

API REST moderne pour la gestion d'authentification, de groupes et de fichiers avec support de tags et statistiques.

**🆕 Nouveauté v1.3.0**: Système complet d'API Keys pour authentification machine-to-machine !

## 📋 Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Fonctionnalités](#fonctionnalités)
- [Technologies](#technologies)
- [Installation](#installation)
- [Configuration](#configuration)
- [Architecture](#architecture)
- [Endpoints API](#endpoints-api)
- [Authentification](#authentification)
- [Documentation](#documentation)
- [Tests](#tests)
- [Licence](#licence)

## 🎯 Vue d'ensemble

AuthGroups API est une solution complète pour gérer :
- **Authentification** : Système JWT avec gestion des sessions
- **Utilisateurs** : Inscription, connexion, profils, avatars
- **Groupes** : Création, gestion des membres, invitations
- **Fichiers** : Upload, stockage, gestion avec validation
- **Tags** : Système de catégorisation flexible
- **Statistiques** : Analytics et rapports d'utilisation
- **Synchronisation** : Support hors-ligne

## ✨ Fonctionnalités

### Gestion des utilisateurs
- 🔐 Inscription et authentification JWT
- 👤 Profils utilisateurs personnalisables
- 🖼️ Upload d'avatars
- 🔑 Réinitialisation de mot de passe
- 📧 Notifications par email
- 🔒 Gestion des rôles (UTILISATEUR, MODERATEUR, ADMINISTRATEUR)
- 🔑 **API Keys pour authentification machine-to-machine**

### Gestion des groupes
- 👥 Création et administration de groupes
- 📨 Système d'invitations par email
- 🏷️ Images de groupe
- 🔐 Gestion des permissions
- 🔍 Recherche avancée

### Système de fichiers
- 📁 Upload de fichiers multiples
- 🖼️ Support images, vidéos, documents, audio
- ✅ Validation et sécurité
- 🗑️ Soft delete avec restauration
- 📊 Gestion du stockage

### Tags et catégorisation
- 🏷️ Tags personnalisables avec couleurs
- 🔗 Association à groupes et fichiers
- 📊 Tags les plus utilisés
- 🔍 Recherche par tags

### Statistiques
- 📈 Statistiques utilisateurs
- 📊 Analytics groupes
- 💾 Utilisation du stockage
- 👥 Utilisateurs en ligne

## 🛠️ Technologies

- **PHP 8.x** - Langage principal
- **MySQL/MariaDB** - Base de données
- **JWT** - Authentification (firebase/php-jwt)
- **PHPMailer** - Envoi d'emails
- **Composer** - Gestion des dépendances
- **PHPUnit** - Tests unitaires

## 📦 Installation

### Prérequis

- PHP >= 8.0
- MySQL >= 5.7 ou MariaDB >= 10.3
- Composer
- Extension PHP : PDO, mbstring, openssl, fileinfo

### Installation

1. **Cloner le projet**
```bash
git clone https://github.com/Jrobitaille360/cmem2.git
cd cmem2_API
```

2. **Installer les dépendances**
```bash
composer install
```

3. **Créer la base de données**
```bash
mysql -u root -p < docs/create_database.sql
```

4. **Configurer l'environnement**
```bash
cp config/environment.example.php config/environment.php
```

Éditer `config/environment.php` avec vos paramètres :
```php
// Base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'cmem2_db');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');

// JWT
define('JWT_SECRET_KEY', 'your-secret-key-here');

// Emails
$_ENV['MAIL_HOST'] = 'smtp.example.com';
$_ENV['MAIL_PORT'] = 587;
$_ENV['MAIL_USERNAME'] = 'your-email@example.com';
$_ENV['MAIL_PASSWORD'] = 'your-password';
```

5. **Configurer les permissions**
```bash
chmod -R 755 config/uploads/
```

## ⚙️ Configuration

### Structure des fichiers de configuration

```
config/
├── database.php          # Configuration base de données
├── environment.php       # Variables d'environnement
├── loader.php           # Autoloader
└── uploads/             # Dossier uploads
    ├── avatars/         # Avatars utilisateurs
    ├── groups/          # Images de groupes
    └── temp/            # Fichiers temporaires
```

### Variables d'environnement

| Variable | Description | Défaut |
|----------|-------------|--------|
| `DB_HOST` | Hôte de la base de données | localhost |
| `DB_NAME` | Nom de la base de données | cmem2_db |
| `DB_USER` | Utilisateur de la base | - |
| `DB_PASS` | Mot de passe de la base | - |
| `JWT_SECRET_KEY` | Clé secrète JWT | - |
| `JWT_EXPIRATION` | Durée de validité JWT (secondes) | 86400 |
| `MAIL_HOST` | Serveur SMTP | - |
| `MAIL_PORT` | Port SMTP | 587 |
| `MAIL_USERNAME` | Email SMTP | - |
| `MAIL_PASSWORD` | Mot de passe SMTP | - |

## 🏗️ Architecture

### Structure du projet

```
cmem2_API/
├── config/              # Configuration
├── docs/                # Documentation
├── src/
│   └── auth_groups/     # Code source principal
│       ├── Controllers/ # Contrôleurs
│       ├── Models/      # Modèles de données
│       ├── Services/    # Services métier
│       ├── Routing/     # Routeur et handlers
│       ├── Middleware/  # Middlewares
│       └── Utils/       # Utilitaires
├── tests/               # Tests
├── vendor/              # Dépendances Composer
├── index.php            # Point d'entrée
└── composer.json        # Configuration Composer
```

### Architecture modulaire

L'API utilise une architecture modulaire avec séparation des responsabilités :

- **Controllers** : Gestion des requêtes HTTP
- **Models** : Logique métier et accès données
- **Services** : Services partagés (Auth, Email, Logs)
- **Routing** : Routage et handlers spécialisés
- **Middleware** : Logging et interception
- **Utils** : Validation, réponses, helpers

## 🔌 Endpoints API

### Public

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/` | Informations API |
| GET | `/help` | Liste des endpoints |
| GET | `/health` | Statut de l'API |

### Utilisateurs

| Méthode | Endpoint | Description | Auth |
|---------|----------|-------------|------|
| POST | `/users/register` | Inscription | Non |
| POST | `/users/login` | Connexion | Non |
| GET | `/users/me` | Profil actuel | Oui |
| PUT | `/users/me` | Modifier profil | Oui |
| DELETE | `/users/me` | Supprimer compte | Oui |
| POST | `/users/avatar` | Upload avatar | Oui |
| GET | `/users/{id}` | Détails utilisateur | Oui |

### Groupes

| Méthode | Endpoint | Description | Auth |
|---------|----------|-------------|------|
| GET | `/groups` | Liste des groupes | Oui |
| POST | `/groups` | Créer un groupe | Oui |
| GET | `/groups/{id}` | Détails d'un groupe | Oui |
| PUT | `/groups/{id}` | Modifier un groupe | Oui |
| DELETE | `/groups/{id}` | Supprimer un groupe | Oui |
| POST | `/groups/{id}/invite` | Inviter un membre | Oui |
| GET | `/groups/search` | Rechercher des groupes | Oui |

### Fichiers

| Méthode | Endpoint | Description | Auth |
|---------|----------|-------------|------|
| POST | `/files/upload` | Upload fichier(s) | Oui |
| GET | `/files` | Liste des fichiers | Oui |
| GET | `/files/{id}` | Détails d'un fichier | Oui |
| DELETE | `/files/{id}` | Supprimer un fichier | Oui |
| PUT | `/files/{id}/restore` | Restaurer un fichier | Oui |

### Tags

| Méthode | Endpoint | Description | Auth |
|---------|----------|-------------|------|
| GET | `/tags` | Liste des tags | Oui |
| POST | `/tags` | Créer un tag | Oui |
| GET | `/tags/{id}` | Détails d'un tag | Oui |
| PUT | `/tags/{id}` | Modifier un tag | Oui |
| DELETE | `/tags/{id}` | Supprimer un tag | Oui |
| GET | `/tags/by-table/{table}` | Tags par table | Oui |
| GET | `/tags/most-used` | Tags populaires | Oui |

### API Keys

| Méthode | Endpoint | Description | Auth |
|---------|----------|-------------|------|
| POST | `/api-keys` | Créer une clé API | JWT |
| GET | `/api-keys` | Liste des clés | JWT |
| GET | `/api-keys/{id}` | Détails d'une clé | JWT |
| DELETE | `/api-keys/{id}` | Révoquer une clé | JWT |
| POST | `/api-keys/{id}/regenerate` | Régénérer une clé | JWT |

### Statistiques

| Méthode | Endpoint | Description | Auth |
|---------|----------|-------------|------|
| GET | `/stats/user/{id}` | Stats utilisateur | Oui |
| GET | `/stats/online` | Utilisateurs en ligne | Oui |

Voir la [documentation complète des endpoints](docs/) pour plus de détails.

## 🔐 Authentification

L'API supporte deux méthodes d'authentification :

### 1. JWT (JSON Web Tokens)

Pour les applications web et mobiles avec utilisateurs.

**Obtenir un token**

```http
POST /users/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password123"
}
```

Réponse :
```json
{
  "success": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "user": {
      "user_id": 1,
      "name": "John Doe",
      "email": "user@example.com",
      "role": "UTILISATEUR"
    }
  }
}
```

**Utiliser le token**

Incluez le token dans l'en-tête `Authorization` :

```http
GET /users/me
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

**Durée de validité**
- Token valide pendant 24h par défaut
- Configurable via `JWT_EXPIRATION`
- Stockage des sessions actives en base de données

### 2. API Keys

Pour les intégrations serveur-à-serveur et scripts automatisés.

**Créer une clé API**

```http
POST /api-keys
Authorization: Bearer <jwt_token>
Content-Type: application/json

{
  "name": "Production Key",
  "scopes": ["read", "write"],
  "expires_in_days": 365
}
```

**Utiliser une clé API**

```http
GET /groups
X-API-Key: ag_live_a1b2c3d4e5f6...
```

**Avantages des API keys**
- ✅ Pas besoin de login/logout
- ✅ Idéal pour scripts et cron jobs
- ✅ Scopes granulaires (read, write, delete, admin)
- ✅ Rate limiting configurable
- ✅ Révocation instantanée
- ✅ Environnements séparés (production/test)

Voir [ENDPOINTS_API_KEYS.md](docs/ENDPOINTS_API_KEYS.md) pour plus de détails.

## 📚 Documentation

### Documentation des endpoints

- [Endpoints utilisateurs](docs/ENDPOINTS_USERS.md)
- [Endpoints groupes](docs/ENDPOINTS_GROUPS.md)
- [Endpoints fichiers](docs/ENDPOINTS_FILES.md)
- [Endpoints tags](docs/ENDPOINTS_TAGS.md)
- [Endpoints API Keys](docs/ENDPOINTS_API_KEYS.md) 🆕
- [Endpoints statistiques](docs/ENDPOINTS_STATS.md)
- [Endpoints publics](docs/ENDPOINTS_PUBLIC.md)

### Documentation technique

- [Endpoints admin secret](docs/ADMIN_SECRET_ENDPOINT.md)
- [Structure base de données](docs/create_database.sql)
- [Triggers et procédures](docs/create_triggers_auth_groups.sql)

## 🧪 Tests

### Exécuter les tests

```bash
# Tous les tests
composer test

# Tests spécifiques
php tests/test_users_entrypoints.php
php tests/test_group_entrypoints.php
php tests/test_files_entrypoints.php
php tests/test_tags_entrypoints.php
```

### Structure des tests

```
tests/
├── users/              # Tests utilisateurs
├── groups/             # Tests groupes
├── files/              # Tests fichiers
├── tags/               # Tests tags
└── public/             # Tests endpoints publics
```

## 🔧 Développement

### Logs

Les logs sont enregistrés dans `logs/` :
- `app.log` - Logs applicatifs
- `error.log` - Erreurs
- Rotation automatique quotidienne

### Base de données

Créer la table des API keys:
```sql
SOURCE docs/create_table_api_keys.sql
```

Réinitialiser les données de test :
```sql
CALL reset_auth_groups_data();
```

### Conventions

- **Namespaces** : `AuthGroups\{Module}`
- **Classes** : PascalCase
- **Méthodes** : camelCase
- **Variables** : snake_case (DB) / camelCase (PHP)
- **Constantes** : UPPER_CASE

## 📄 Licence

Ce projet utilise plusieurs dépendances open-source. Voir [THIRD_PARTY_LICENSES.md](THIRD_PARTY_LICENSES.md) pour les détails.

## 🤝 Contribution

Les contributions sont les bienvenues ! 

1. Fork le projet
2. Créer une branche (`git checkout -b feature/AmazingFeature`)
3. Commit les changements (`git commit -m 'Add some AmazingFeature'`)
4. Push vers la branche (`git push origin feature/AmazingFeature`)
5. Ouvrir une Pull Request

## 📞 Support

Pour toute question ou problème :
- Email : support@authgroups.local
- Issues : [GitHub Issues](https://github.com/Jrobitaille360/cmem2/issues)

## 🗺️ Roadmap

- [x] API key setup ✅
- [ ] Admin dynamic feature creation
  - [ ] Create tables via admin panel
  - [ ] Generate PHP endpoints
  - [ ] Examples: Calendar, Todo list
- [ ] Rate limiting
- [ ] Cache layer (Redis)
- [ ] WebSockets pour notifications temps réel
- [ ] Export de données
- [ ] Audit logs détaillés

---

**Version** : 1.2.0  
**Dernière mise à jour** : Octobre 2025  
**Auteur** : [Jrobitaille360](https://github.com/Jrobitaille360)
