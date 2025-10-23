# AuthGroups API

![Version](https://img.shields.io/badge/version-1.3.0-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.0+-purple.svg)
![Status](https://img.shields.io/badge/status-production%20ready-green.svg)
![Tests](https://img.shields.io/badge/tests-23%2F23%20passing-brightgreen.svg)
![License](https://img.shields.io/badge/license-MIT-orange.svg)

API REST moderne pour la gestion d'authentification, de groupes et de fichiers avec support de tags et statistiques.

**🆕 Nouveauté v1.3.0**: Système complet d'API Keys pour authentification machine-to-machine !

## 📋 Table des matières

- [Vue d'ensemble](#-vue-densemble)
- [Fonctionnalités](#-fonctionnalités)
- [Technologies](#%EF%B8%8F-technologies)
- [Installation](#-installation)
- [Configuration](#%EF%B8%8F-configuration)
- [Architecture](#%EF%B8%8F-architecture)
- [Endpoints API](#-endpoints-api)
- [Authentification](#-authentification)
- [Documentation](#-documentation)
- [Tests](#-tests)
- [Licence](#-licence)

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

1. **Installer les dépendances**

```bash
composer install
```

1. **Créer la base de données**

```bash
mysql -u root -p < src/auth_groups/docs/create_database.sql
```

1. **Configurer l'environnement**

```bash
cp .env.auth_groups.example .env.auth_groups
```

Éditer `.env.auth_groups` avec vos paramètres :

```env
# Base de données
DB_HOST=localhost
DB_NAME=cmem2_db
DB_USER=your_user
DB_PASS=your_password

# JWT
JWT_SECRET=your-secret-key-here-minimum-32-characters
JWT_EXPIRATION=86400

# Emails
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-email@example.com
MAIL_PASSWORD=your-password
```

1. **Configurer les permissions**

```bash
chmod -R 755 uploads/
chmod -R 755 tmp_assets/
```

## ⚙️ Configuration

### Structure des fichiers de configuration

```text
src/auth_groups/
├── database.php          # Configuration base de données
├── environment.php       # Variables d'environnement
├── loader.php           # Chargeur de configuration
└── uploads/             # Dossier uploads
    ├── avatars/         # Avatars utilisateurs
    ├── groups/          # Images de groupes
    └── temp/            # Fichiers temporaires
```

### Variables d'environnement

Le fichier `.env.auth_groups` à la racine contient toutes les variables d'environnement.

| Variable | Description | Défaut |
|----------|-------------|--------|
| `DB_HOST` | Hôte de la base de données | localhost |
| `DB_NAME` | Nom de la base de données | cmem2_db |
| `DB_USER` | Utilisateur de la base | - |
| `DB_PASS` | Mot de passe de la base | - |
| `JWT_SECRET` | Clé secrète JWT (min 32 caractères) | - |
| `JWT_EXPIRATION` | Durée de validité JWT (secondes) | 86400 |
| `MAIL_HOST` | Serveur SMTP | - |
| `MAIL_PORT` | Port SMTP | 587 |
| `MAIL_USERNAME` | Email SMTP | - |
| `MAIL_PASSWORD` | Mot de passe SMTP | - |

## 🏗️ Architecture

### Structure du projet

```text
cmem2_API/
├── src/
│   ├── auth_groups/     # Module principal d'authentification
│   │   ├── Controllers/ # Contrôleurs
│   │   ├── Models/      # Modèles de données
│   │   ├── Services/    # Services métier
│   │   ├── Routing/     # Routeur et handlers
│   │   ├── Middleware/  # Middlewares
│   │   ├── Utils/       # Utilitaires
│   │   ├── docs/        # Documentation API
│   │   ├── database.php # Configuration DB
│   │   ├── environment.php # Chargeur .env
│   │   └── loader.php   # Chargeur principal
│   ├── ics/             # Module calendrier ICS/CalDAV
│   │   ├── Controllers/ # Contrôleurs calendrier
│   │   ├── Models/      # Modèles calendrier
│   │   ├── Services/    # Services CalDAV
│   │   └── docs_ICS/    # Documentation calendrier
│   └── logs/            # Logs applicatifs
├── tests/               # Tests unitaires et d'intégration
├── uploads/             # Fichiers uploadés
├── tmp_assets/          # Fichiers temporaires
├── vendor/              # Dépendances Composer
├── .env.auth_groups     # Configuration (ne pas versionner)
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

### Calendriers

| Méthode | Endpoint | Description | Auth |
|---------|----------|-------------|------|
| GET | `/calendars` | Liste des calendriers | Oui |
| POST | `/calendars` | Créer un calendrier | Oui |
| GET | `/calendars/{id}` | Détails d'un calendrier | Oui |
| PUT | `/calendars/{id}` | Modifier un calendrier | Oui |
| DELETE | `/calendars/{id}` | Supprimer un calendrier | Oui |
| GET | `/calendar/{token}.ics` | Export ICS public | Non |
| * | `/caldav/` | Support CalDAV | Oui |

### Webhooks

| Méthode | Endpoint | Description | Auth |
|---------|----------|-------------|------|
| POST | `/webhooks` | Créer un webhook | Oui |
| GET | `/webhooks` | Liste des webhooks | Oui |
| PUT | `/webhooks/{id}` | Modifier un webhook | Oui |
| DELETE | `/webhooks/{id}` | Supprimer un webhook | Oui |

### API Keys

| Méthode | Endpoint | Description | Auth |
|---------|----------|-------------|------|
| POST | `/api-keys` | Créer une clé API | JWT |
| GET | `/api-keys` | Liste des clés | JWT |
| GET | `/api-keys/{id}` | Détails d'une clé | JWT |
| DELETE | `/api-keys/{id}` | Révoquer une clé | JWT |
| POST | `/api-keys/{id}/regenerate` | Régénérer une clé | JWT |

### Analytics et statistiques

| Méthode | Endpoint | Description | Auth |
|---------|----------|-------------|------|
| GET | `/stats/user/{id}` | Stats utilisateur | Oui |
| GET | `/stats/online` | Utilisateurs en ligne | Oui |

Voir la [documentation complète](src/auth_groups/docs/README.md) pour plus de détails.

## 🔐 Authentification

L'API supporte deux méthodes d'authentification :

### 1. JWT (JSON Web Tokens)

Pour les applications web et mobiles avec utilisateurs.

#### Obtenir un token

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

#### Utiliser le token

Incluez le token dans l'en-tête `Authorization` :

```http
GET /users/me
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

#### Durée de validité

- Token valide pendant 24h par défaut
- Configurable via `JWT_EXPIRATION`
- Stockage des sessions actives en base de données

### 2. API Keys

Pour les intégrations serveur-à-serveur et scripts automatisés.

#### Créer une clé API

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

#### Utiliser une clé API

```http
GET /groups
X-API-Key: ag_live_a1b2c3d4e5f6...
```

#### Avantages des API keys

- ✅ Pas besoin de login/logout
- ✅ Idéal pour scripts et cron jobs
- ✅ Scopes granulaires (read, write, delete, admin)
- ✅ Rate limiting configurable
- ✅ Révocation instantanée
- ✅ Environnements séparés (production/test)

Voir [ENDPOINTS_API_KEYS.md](src/auth_groups/docs/ENDPOINTS_API_KEYS.md) pour plus de détails.

## 📚 Documentation

### Documentation complète

➡️ **[Documentation principale](src/auth_groups/docs/README.md)** - Point d'entrée de toute la documentation

### Documentation des endpoints

- [Endpoints utilisateurs](src/auth_groups/docs/ENDPOINTS_USERS.md)
- [Endpoints groupes](src/auth_groups/docs/ENDPOINTS_GROUPS.md)
- [Endpoints fichiers](src/auth_groups/docs/ENDPOINTS_FILES.md)
- [Endpoints tags](src/auth_groups/docs/ENDPOINTS_TAGS.md)
- [Endpoints API Keys](src/auth_groups/docs/ENDPOINTS_API_KEYS.md) 🆕
- [Endpoints statistiques](src/auth_groups/docs/ENDPOINTS_STATS.md)
- [Endpoints publics](src/auth_groups/docs/ENDPOINTS_PUBLIC.md)

### Modules spécialisés

- [Module ICS/CalDAV](src/ics/docs_ICS/README.md) - Calendriers et synchronisation CalDAV
- [Webhooks](src/auth_groups/docs/WEBHOOKS_README.md) - Configuration et utilisation des webhooks
- [Système de licences](src/auth_groups/docs/FLUTTER_LICENSE_SYSTEM.md) - Gestion des abonnements

### Guides techniques

- [Démarrage rapide](src/auth_groups/docs/QUICKSTART.md)
- [Vue d'ensemble de l'API](src/auth_groups/docs/API_OVERVIEW.md)
- [Référence API complète](src/auth_groups/docs/API_REFERENCE.md)
- [Structure base de données](src/auth_groups/docs/create_database.sql)
- [Migrations](src/auth_groups/docs/MIGRATION_v1.3.0.md)

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

```text
tests/
├── users/              # Tests utilisateurs
├── groups/             # Tests groupes
├── files/              # Tests fichiers
├── tags/               # Tests tags
├── webhooks/           # Tests webhooks
└── test_base.php       # Fonctions communes
```

## 🔧 Développement

### Logs

Les logs sont enregistrés dans `src/logs/` :

- `app.log` - Logs applicatifs
- `error.log` - Erreurs
- Rotation automatique quotidienne

### Base de données

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

- Email : <support@authgroups.local>
- Issues : [GitHub Issues](https://github.com/Jrobitaille360/cmem2/issues)

## 🗺️ Roadmap

- [x] Système d'API Keys ✅
- [x] Module calendrier ICS/CalDAV ✅
- [x] Webhooks ✅
- [x] Système de licences ✅
- [ ] Administration dynamique
  - [ ] Création de tables via admin
  - [ ] Génération d'endpoints PHP
- [ ] Rate limiting avancé
- [ ] Cache layer (Redis)
- [ ] WebSockets pour notifications temps réel
- [ ] Export de données (CSV, JSON)
- [ ] Audit logs détaillés
- [ ] Support multi-tenant

---

**Version** : 1.3.0  
**Dernière mise à jour** : Octobre 2025  
**Auteur** : [Jrobitaille360](https://github.com/Jrobitaille360)
