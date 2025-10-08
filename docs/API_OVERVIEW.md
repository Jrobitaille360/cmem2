# Vue d'ensemble de l'API AuthGroups

## Introduction

AuthGroups API est une API REST complète conçue pour gérer l'authentification, les groupes, les fichiers et bien plus encore. Elle offre une architecture modulaire, sécurisée et performante.

## Architecture générale

### Flux de requête

```
Client → index.php → Router → RouteHandler → Controller → Model → Database
                                                     ↓
                                                 Response
```

### Composants principaux

#### 1. Router (`src/auth_groups/Routing/Router.php`)
- Point d'entrée principal du routage
- Analyse les URLs et dirige vers les handlers appropriés
- Gestion centralisée des erreurs

#### 2. Route Handlers
Handlers spécialisés par module :
- `UserRouteHandler` - Gestion des utilisateurs
- `GroupRouteHandler` - Gestion des groupes
- `FileRouteHandler` - Gestion des fichiers
- `TagRouteHandler` - Gestion des tags
- `StatsRouteHandler` - Statistiques
- `DataRouteHandler` - Synchronisation
- `PublicRouteHandler` - Endpoints publics
- `SecretAdminRouteHandler` - Administration
- `ApiKeyRouteHandler` - 🆕 Gestion des clés API

#### 3. Controllers
Contrôleurs par fonctionnalité :
- `UserController` - Opérations utilisateurs
- `GroupController` - Opérations groupes
- `FileController` - Opérations fichiers
- `TagController` - Opérations tags
- `ApiKeyController` - 🆕 Opérations clés API
- etc.

#### 4. Models
Modèles de données :
- `User` - Utilisateur
- `Group` - Groupe
- `File` - Fichier
- `Tag` - Tag
- `ApiKey` - 🆕 Clé API
- `BaseModel` - Classe de base avec CRUD

#### 5. Services
Services partagés :
- `AuthService` - Authentification JWT
- `EmailService` - Envoi d'emails
- `LogService` - Logging avancé
- `ValidTokenService` - Gestion des sessions

#### 6. Middleware
Middleware d'authentification :
- `JWTAuthMiddleware` - Authentification par JWT tokens
- `ApiKeyAuthMiddleware` - 🆕 Authentification par API keys

#### 7. Utils
Utilitaires :
- `Response` - Formatage des réponses
- `Validator` - Validation des données
- `FileValidator` - Validation de fichiers
- `Database` - Accès base de données

## Format des réponses

### Réponse de succès

```json
{
  "success": true,
  "data": {
    "resource_key": { ... }
  },
  "message": "Operation successful"
}
```

### Réponse d'erreur

```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Error description",
    "details": { ... }
  }
}
```

## Codes HTTP utilisés

| Code | Signification | Utilisation |
|------|---------------|-------------|
| 200 | OK | Succès général |
| 201 | Created | Ressource créée |
| 400 | Bad Request | Données invalides |
| 401 | Unauthorized | Non authentifié |
| 403 | Forbidden | Non autorisé |
| 404 | Not Found | Ressource introuvable |
| 409 | Conflict | Conflit (ex: email existe) |
| 500 | Internal Error | Erreur serveur |

## Authentification et autorisation

### Méthodes d'authentification

L'API supporte deux méthodes d'authentification :

#### 1. JWT Tokens (pour utilisateurs)
- **Usage** : Applications web, mobiles, authentification utilisateur
- **Durée** : 24 heures par défaut
- **Header** : `Authorization: Bearer {token}`
- **Obtention** : Via `/users/login`

```php
// Exemple de vérification JWT
$authMiddleware = new JWTAuthMiddleware();
$user = $authMiddleware->authenticate($request);
```

#### 2. API Keys (pour machines/intégrations)
- **Usage** : Intégrations machine-to-machine, automatisations, services externes
- **Durée** : Configurable (jours ou jamais)
- **Header** : `X-API-Key: {key}` ou `Authorization: Bearer {key}`
- **Obtention** : Via `/api-keys` (nécessite JWT)
- **Scopes** : `read`, `write`, `delete`, `admin`, `*`
- **Rate Limiting** : Configurable par minute et par heure
- **Environnements** : `production` (`ag_live_*`) et `test` (`ag_test_*`)

```php
// Exemple de vérification API Key
$apiKeyAuth = new ApiKeyAuthMiddleware();
$user = $apiKeyAuth->authenticate($request);
```

#### 3. Authentification flexible (JWT ou API Key)
```php
// Accepte JWT ou API Key
$apiKeyAuth = new ApiKeyAuthMiddleware();
$user = $apiKeyAuth->authenticateFlexible($request);
```

Voir [ENDPOINTS_API_KEYS.md](./ENDPOINTS_API_KEYS.md) pour la documentation complète des API keys.

### Niveaux d'accès

1. **Public** - Pas d'authentification requise
   - `/help`, `/health`
   - Certains endpoints `/users` (login, register)

2. **Authentifié** - Token JWT ou API Key requis
   - Tous les autres endpoints

3. **Rôles utilisateurs**
   - `UTILISATEUR` - Accès standard
   - `MODERATEUR` - Permissions étendues
   - `ADMINISTRATEUR` - Accès complet

### Vérification des permissions

```php
// Dans un contrôleur
if ($user['role'] !== 'ADMINISTRATEUR') {
    return Response::error('Accès refusé', null, 403);
}

// Vérification de scope API Key
$apiKeyAuth = new ApiKeyAuthMiddleware();
if (!$apiKeyAuth->hasScope('write')) {
    return Response::error('Scope insuffisant', null, 403);
}
```

## Validation des données

### Règles de validation

```php
$rules = [
    'email' => 'required|email',
    'password' => 'required|min:8',
    'name' => 'required|string|min:2|max:100',
    'age' => 'integer|min:18|max:120'
];

$validator = new Validator();
$validation = $validator->validate($input, $rules);
```

### Règles disponibles

- `required` - Champ requis
- `email` - Format email valide
- `string` - Type chaîne
- `integer` - Type entier
- `min:x` - Longueur/valeur minimale
- `max:x` - Longueur/valeur maximale
- `in:a,b,c` - Valeur dans liste
- `regex:pattern` - Expression régulière

## Gestion des fichiers

### Types de fichiers supportés

**Images**
- JPG, JPEG, PNG, GIF, WEBP
- Taille max : 5 MB
- Dimensions max : 4096x4096 px

**Vidéos**
- MP4, WEBM, OGG, AVI, MOV
- Taille max : 50 MB

**Documents**
- PDF, DOC, DOCX, TXT, XLS, XLSX
- Taille max : 10 MB

**Audio**
- MP3, WAV, OGG
- Taille max : 10 MB

### Structure de stockage

```
config/uploads/
├── avatars/           # Avatars utilisateurs
│   └── {user_id}.{ext}
├── groups/            # Images de groupes
│   └── {group_id}.{ext}
└── temp/              # Fichiers temporaires
```

## Système de tags

### Tables associables

Les tags peuvent être associés à :
- `groups` - Groupes
- `files` - Fichiers
- `all` - Toutes les tables

### Couleurs des tags

Format hexadécimal : `#RRGGBB`
Exemples : `#3498db`, `#e74c3c`, `#2ecc71`

## Logging

### Niveaux de log

1. **DEBUG** - Informations de débogage
2. **INFO** - Informations générales
3. **WARNING** - Avertissements
4. **ERROR** - Erreurs
5. **CRITICAL** - Erreurs critiques

### Utilisation

```php
use AuthGroups\Services\LogService;

LogService::info('User logged in', ['user_id' => 123]);
LogService::warning('Invalid attempt', ['ip' => $ip]);
LogService::error('Database error', ['error' => $e->getMessage()]);
```

### Rotation des logs

- Rotation quotidienne automatique
- Conservation 30 jours
- Compression des anciens logs

## Gestion des emails

### Types d'emails

1. **Bienvenue** - Nouvel utilisateur
2. **Réinitialisation** - Mot de passe oublié
3. **Invitation** - Invitation à un groupe
4. **Vérification** - Vérification email
5. **Digest** - Résumé d'activité
6. **Alerte** - Notification de sécurité

### Configuration SMTP

```php
$_ENV['MAIL_HOST'] = 'smtp.gmail.com';
$_ENV['MAIL_PORT'] = 587;
$_ENV['MAIL_USERNAME'] = 'your-email@gmail.com';
$_ENV['MAIL_PASSWORD'] = 'your-app-password';
$_ENV['MAIL_ENCRYPTION'] = 'tls';
```

## Statistiques

### Données collectées

**Par utilisateur**
- Nombre de groupes
- Stockage utilisé
- Date de dernière activité

**Système**
- Utilisateurs en ligne
- Activité globale
- Utilisation ressources

### Génération

Les statistiques sont générées :
- À la demande via API
- Périodiquement (tâche planifiée recommandée)

## Sécurité

### Mesures de sécurité

1. **Authentification**
   - JWT avec expiration
   - Sessions stockées en DB
   - Révocation possible

2. **Validation**
   - Validation stricte des entrées
   - Sanitization des données
   - Protection XSS/SQL injection

3. **Fichiers**
   - Validation type MIME
   - Vérification taille
   - Nom sécurisé
   - .htaccess protection

4. **Passwords**
   - Hash bcrypt
   - Coût adaptatif
   - Politique de complexité

5. **Rate Limiting**
   - Protection brute force
   - Logging tentatives

## Base de données

### Tables principales

- `auth_users` - Utilisateurs
- `auth_groups` - Groupes
- `auth_group_members` - Membres des groupes
- `auth_group_invitations` - Invitations
- `auth_files` - Fichiers
- `auth_tags` - Tags
- `auth_tag_associations` - Associations tags
- `auth_valid_tokens` - Tokens valides
- `auth_user_stats` - Statistiques

### Triggers

- Audit automatique des modifications
- Mise à jour timestamps
- Gestion des suppressions

### Procédures stockées

- `reset_auth_groups_data()` - Reset données test
- Plus dans `docs/create_proc_*.sql`

## Performances

### Optimisations

1. **Base de données**
   - Index appropriés
   - Requêtes optimisées
   - Pagination systématique

2. **Code**
   - Chargement sélectif
   - Cache (à implémenter)
   - Compression gzip

3. **Fichiers**
   - Validation côté serveur
   - Chunked upload (à implémenter)
   - CDN (recommandé)

## Maintenance

### Tâches recommandées

**Quotidien**
- Vérifier les logs d'erreur
- Monitorer l'espace disque

**Hebdomadaire**
- Nettoyer fichiers temporaires
- Archiver vieux logs
- Backup base de données

**Mensuel**
- Audit sécurité
- Mise à jour dépendances
- Optimisation DB

## Extensions futures

### Roadmap

1. **API Keys**
   - Authentification par clé API
   - Gestion des quotas

2. **Admin dynamique**
   - Création tables via interface
   - Génération endpoints PHP
   - Templates (Calendar, Todo, etc.)

3. **Fonctionnalités avancées**
   - WebSockets temps réel
   - Cache Redis
   - Queue système
   - Export données

## Ressources

- [README](../README.md)
- [Documentation endpoints](./ENDPOINTS_*.md)
- [Code source](../src/auth_groups/)
- [Tests](../tests/)

---

Pour toute question : support@authgroups.local
