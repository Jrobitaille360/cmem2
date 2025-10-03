# Configuration Modulaire - Guide d'Utilisation

## 📁 Structure des Configurations

La configuration a été réorganisée selon la logique modulaire avec les composants obligatoires regroupés dans auth_groups :

```
config/
├── loader.php                     # 🔄 Chargeur principal (utiliser celui-ci)
│
├── 🔐 auth_groups/                # Module obligatoire (authentification + infrastructure)
│   ├── shared/                    # 🔧 Infrastructure obligatoire
│   │   ├── environment.php        # Environnement, CORS, API
│   │   ├── database.php           # Connexion base de données
│   │   └── logs.php               # Système de logs
│   ├── auth.php                   # Configuration JWT et authentification
│   ├── uploads.php                # Uploads avatars et fichiers de groupes
│   └── tags.php                   # Configuration des tags et catégories
│
├── 💾 memories_elements/          # Module optionnel (mémoires et éléments)
│   ├── memories.php               # Configuration des mémoires
│   ├── uploads.php                # Uploads multimédia (images, vidéos, audio)
│   └── pagination.php             # Configuration de l'affichage
│
└── deprecated/                    # ⚠️ Anciens fichiers (à supprimer)
    ├── config.php
    └── database.php
```

## 🚀 Utilisation

### Migration depuis l'ancienne structure

**Avant (déprécié) :**
```php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
```

**Maintenant (recommandé) :**
```php
require_once __DIR__ . '/config/loader.php';
```

### Chargement modulaire spécifique

Si vous n'avez besoin que d'un module spécifique :

```php
// Infrastructure obligatoire (toujours requise)
require_once __DIR__ . '/config/auth_groups/shared/environment.php';
require_once __DIR__ . '/config/auth_groups/shared/database.php';
require_once __DIR__ . '/config/auth_groups/shared/logs.php';

// Module auth_groups complet
require_once __DIR__ . '/config/auth_groups/auth.php';
require_once __DIR__ . '/config/auth_groups/uploads.php';
require_once __DIR__ . '/config/auth_groups/tags.php';

// Module memories_elements (optionnel)
require_once __DIR__ . '/config/memories_elements/memories.php';
require_once __DIR__ . '/config/memories_elements/uploads.php';
require_once __DIR__ . '/config/memories_elements/pagination.php';
```

## 🔐 Module auth_groups (Obligatoire)

### shared/ - Infrastructure commune
- **environment.php** : Variables d'environnement, API, CORS, sécurité
- **database.php** : Connexion base de données singleton
- **logs.php** : Système de logs modulaire avec rotation

### Configurations spécifiques auth_groups
- **auth.php** : JWT, authentification, sessions, groupes
- **uploads.php** : Avatars, fichiers de groupes  
- **tags.php** : Étiquettes, catégories, couleurs

## 💾 Module memories_elements (Optionnel)

### memories.php
- Limites des mémoires (nombre, taille descriptions)
- Visibilité et permissions
- Géolocalisation
- Statistiques (vues, likes, commentaires)

### uploads.php
- Configuration multimédia complète
- Images, vidéos, audio, documents
- Compression et résolutions multiples
- Miniatures et aperçus

### pagination.php
- Pagination des mémoires et éléments
- Configuration de l'affichage (grille, liste, timeline)
- Lazy loading et cache
- Filtres et tri

## 🔧 Module shared (Intégré dans auth_groups)

### environment.php
- Variables d'environnement (.env)
- Configuration API et CORS
- Sécurité et rate limiting
- Mode maintenance

### database.php
- Classe Database singleton
- Gestion des connexions
- Transactions et reconnexion
- Configuration MySQL

### logs.php
- Système de logs modulaire
- Rotation et archivage
- Logs de sécurité et performance
- Envoi d'alertes critiques

## 🔄 Migration Progressive

### Étape 1 : Nouveau code
Utilisez `require_once __DIR__ . '/config/loader.php';` dans tout nouveau code.

### Étape 2 : Code existant
Remplacez progressivement les anciens includes par le nouveau loader.

### Étape 3 : Nettoyage
Une fois la migration terminée, supprimez `config.php` et `database.php`.

## ⚡ Constantes Disponibles

### Infrastructure obligatoire (auth_groups/shared/)
- `APP_ENV`, `APP_DEBUG`, `BASE_URL`
- `UPLOAD_DIR`, `TMP_ASSETS_DIR`, `LOG_DIR`
- `ALLOWED_ORIGINS`, `ALLOWED_METHODS`

### Auth Groups (obligatoire)
- `JWT_SECRET`, `JWT_EXPIRATION`
- `AVATAR_UPLOAD_DIR`, `MAX_AVATAR_SIZE`
- `MAX_TAGS_PER_ITEM`, `DEFAULT_TAG_COLORS`

### Memories Elements (optionnel)
- `MEMORY_UPLOAD_DIR`, `MAX_MEMORY_IMAGE_SIZE`
- `MEMORIES_DEFAULT_PAGE_SIZE`, `MAX_ELEMENTS_PER_MEMORY`
- `ALLOWED_MEMORY_FILE_TYPES`

## 🛠️ Validation et Debug

Le loader inclut des fonctions de validation :
- `validateConfiguration()` : Vérifie les constantes essentielles
- `initializeDirectories()` : Crée les répertoires requis
- `displayConfigurationInfo()` : Affiche les infos en mode debug

## 📝 Compatibilité

Des constantes de compatibilité sont maintenues pour faciliter la transition :
- `DEFAULT_PAGE_SIZE` → `MEMORIES_DEFAULT_PAGE_SIZE`
- `ALLOWED_FILE_TYPES` → `ALLOWED_MEMORY_FILE_TYPES`

Ces constantes seront supprimées dans une version future.