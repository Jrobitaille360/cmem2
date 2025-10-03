# Configuration de l'environnement CMem1 API

Ce guide vous explique comment configurer correctement l'environnement de développement et de production pour l'API CMem1.

## 📋 Fichiers de configuration

### `.env` - Variables d'environnement
Le fichier `.env` contient toutes les variables d'environnement spécifiques à votre installation. **Ce fichier ne doit jamais être commité dans le dépôt Git** car il contient des informations sensibles.

### `.env.example` - Modèle de configuration
Le fichier `.env.example` sert de modèle et montre toutes les variables disponibles avec des valeurs d'exemple.

### `config/config.php` - Configuration principale
Ce fichier charge les variables d'environnement et définit les constantes utilisées par l'application.

### `config/database.php` - Configuration de la base de données
Gère la connexion à la base de données MySQL avec un pattern Singleton.

## 🚀 Installation initiale

### 1. Copier le fichier d'environnement
```bash
cp .env.example .env
```

### 2. Éditer les variables d'environnement
Ouvrez le fichier `.env` et modifiez les valeurs selon votre environnement :

```bash
# Environnement (development, production, testing)
APP_ENV=development
APP_DEBUG=true

# URLs de base
APP_URL=http://localhost/cmem1_API
BASE_URL=http://localhost:8000

# Configuration JWT - IMPORTANT: Changez cette clé !
JWT_SECRET=votre_clé_secrète_très_longue_et_sécurisée_pour_jwt

# Base de données
DB_HOST=localhost
DB_NAME=cmem1_db
DB_USER=votre_utilisateur
DB_PASS=votre_mot_de_passe
```

### 3. Vérifier la configuration
Exécutez le script de validation :
```bash
php validate_config.php
```

## ⚙️ Variables d'environnement importantes

### Application
- `APP_ENV` : Environnement (development/production/testing)
- `APP_DEBUG` : Active le mode debug (true/false)
- `APP_URL` : URL de l'application
- `BASE_URL` : URL de base de l'API

### Sécurité JWT
- `JWT_SECRET` : Clé secrète pour signer les tokens JWT (**CHANGEZ-LA !**)
- `JWT_ALGORITHM` : Algorithme de signature (HS256)
- `JWT_EXPIRATION` : Durée de vie des tokens en secondes (86400 = 24h)

### Base de données
- `DB_HOST` : Serveur de base de données
- `DB_NAME` : Nom de la base de données
- `DB_USER` : Utilisateur de la base de données
- `DB_PASS` : Mot de passe de la base de données

### Upload de fichiers
- `MAX_FILE_SIZE` : Taille max des fichiers (10485760 = 10MB)
- `MAX_IMAGE_SIZE` : Taille max des images (5242880 = 5MB)
- `UPLOAD_DIR` : Dossier des uploads (uploads/)
- `TMP_ASSETS_DIR` : Dossier temporaire (tmp_assets/)

### Types de fichiers autorisés
- `ALLOWED_IMAGE_TYPES` : Types d'images (image/jpeg,image/png,...)
- `ALLOWED_VIDEO_TYPES` : Types de vidéos (video/mp4,video/webm,...)
- `ALLOWED_DOCUMENT_TYPES` : Types de documents (application/pdf,...)
- `ALLOWED_AUDIO_TYPES` : Types audio (audio/mpeg,audio/wav,...)

### Logging
- `LOG_ENABLED` : Active les logs (true/false)
- `LOG_LEVEL` : Niveau des logs (debug/info/warning/error)
- `LOG_DIR` : Dossier des logs (logs/)

### CORS
- `ALLOWED_ORIGINS` : Domaines autorisés (séparés par des virgules)

## 🔒 Configuration de production

Pour un environnement de production, suivez ces bonnes pratiques :

### 1. Variables critiques
```bash
APP_ENV=production
APP_DEBUG=false
JWT_SECRET=une_clé_très_longue_aléatoire_et_sécurisée_minimum_64_caractères
```

### 2. Base de données sécurisée
```bash
DB_HOST=votre_serveur_db_production
DB_NAME=cmem1_production
DB_USER=utilisateur_limité_non_root
DB_PASS=mot_de_passe_très_sécurisé
```

### 3. URLs de production
```bash
APP_URL=https://votre-domaine.com
BASE_URL=https://api.votre-domaine.com
ALLOWED_ORIGINS=https://votre-domaine.com,https://www.votre-domaine.com
```

### 4. Configuration des logs
```bash
LOG_LEVEL=warning
LOG_ARCHIVE_AFTER_DAYS=30
LOG_DELETE_AFTER_WEEKS=52
```

## 🛠️ Dépannage

### Erreur de connexion à la base de données
1. Vérifiez les variables `DB_*` dans `.env`
2. Assurez-vous que MySQL est démarré
3. Vérifiez que l'utilisateur a les permissions sur la base

### Erreur de permissions sur les dossiers
```bash
chmod 755 uploads/ logs/ tmp_assets/ -R
```

### JWT Secret non sécurisé
Le script de validation vous avertira si votre `JWT_SECRET` :
- Utilise une valeur par défaut
- Est trop court (< 32 caractères)

### Validation automatique
Le script `validate_config.php` vérifie automatiquement :
- ✅ Présence de tous les fichiers de configuration
- ✅ Définition de toutes les constantes
- ✅ Sécurité du JWT_SECRET
- ✅ Permissions des dossiers
- ✅ Connexion à la base de données
- ✅ Configuration des types de fichiers

## 📚 Liens utiles

- [Documentation API complète](docs/README.md)
- [Spécifications techniques](docs/SPECIFICATIONS.md)
- [Guide de déploiement](docs/DEPLOYMENT.md)

---

> 💡 **Conseil** : Exécutez `php validate_config.php` après chaque modification de configuration pour vous assurer que tout fonctionne correctement.
