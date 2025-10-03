# Configuration Modulaire .env - Guide d'Utilisation

## 🎯 Nouveau Système .env Modulaire

La configuration des variables d'environnement a été divisée selon la même logique modulaire que le reste de l'application.

## 📁 Structure des Fichiers .env

### 🔄 **Nouveaux fichiers modulaires**
```
.env.auth_groups         # Infrastructure obligatoire + authentification
.env.memories_elements   # Variables spécifiques aux mémoires (optionnel)
```

### 📋 **Fichiers d'exemple**
```
.env.auth_groups.example         # Template pour auth_groups
.env.memories_elements.example   # Template pour memories_elements
.env.example                     # Ancien template (déprécié)
```

## 🚀 Migration et Utilisation

### **Étape 1 : Créer les fichiers .env modulaires**

```bash
# Copier les templates
cp .env.auth_groups.example .env.auth_groups
cp .env.memories_elements.example .env.memories_elements

# Configurer selon votre environnement
nano .env.auth_groups
nano .env.memories_elements
```

### **Étape 2 : Configuration minimale (auth_groups seulement)**

Pour une API d'authentification de base, seul `.env.auth_groups` est requis :

```bash
# Variables obligatoires dans .env.auth_groups
APP_ENV=development
DB_HOST=localhost
DB_NAME=cmem1_db
DB_USER=root
DB_PASS=votre_mot_de_passe
JWT_SECRET=votre_cle_secrete_minimum_64_caracteres
```

### **Étape 3 : Configuration complète (avec mémoires)**

Pour l'API complète, ajoutez `.env.memories_elements` :

```bash
# Variables dans .env.memories_elements
MAX_MEMORY_IMAGE_SIZE=10485760
ALLOWED_MEMORY_IMAGE_TYPES=image/jpeg,image/png,image/webp
MEMORIES_DEFAULT_PAGE_SIZE=12
```

## 🔐 Module auth_groups (.env.auth_groups)

### **Infrastructure obligatoire :**
- **Environnement** : `APP_ENV`, `APP_DEBUG`, `BASE_URL`
- **Sécurité** : `ALLOWED_ORIGINS`, `CORS`, Rate limiting
- **Base de données** : `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- **Logs** : `LOG_LEVEL`, `LOG_DIR`, Rotation
- **JWT** : `JWT_SECRET`, `JWT_EXPIRATION`

### **Authentification et utilisateurs :**
- **Auth** : `AUTH_AUTO_LOGOUT_*`, Sessions
- **Utilisateurs** : `MAX_USERNAME_LENGTH`, `MIN_PASSWORD_LENGTH`
- **Groupes** : `MAX_GROUP_MEMBERS`, `GROUP_INVITATION_EXPIRATION`
- **Avatars** : `MAX_AVATAR_SIZE`, `ALLOWED_AVATAR_TYPES`
- **Tags** : `MAX_TAGS_PER_ITEM`, `DEFAULT_TAG_COLORS`

## 💾 Module memories_elements (.env.memories_elements)

### **Mémoires et contenu :**
- **Limites** : `MAX_MEMORIES_PER_USER`, `MAX_ELEMENTS_PER_MEMORY`
- **Visibilité** : `DEFAULT_MEMORY_VISIBILITY`, `ALLOW_PUBLIC_MEMORIES`
- **Géolocalisation** : `ENABLE_MEMORY_GEOLOCATION`

### **Uploads multimédia :**
- **Images** : `MAX_MEMORY_IMAGE_SIZE`, `ALLOWED_MEMORY_IMAGE_TYPES`
- **Vidéos** : `MAX_MEMORY_VIDEO_SIZE`, `ALLOWED_MEMORY_VIDEO_TYPES`
- **Audio** : `MAX_MEMORY_AUDIO_SIZE`, `ALLOWED_MEMORY_AUDIO_TYPES`
- **Documents** : `MAX_MEMORY_DOCUMENT_SIZE`

### **Affichage et pagination :**
- **Pagination** : `MEMORIES_DEFAULT_PAGE_SIZE`, `ELEMENTS_DEFAULT_PAGE_SIZE`
- **Vues** : `AVAILABLE_MEMORY_VIEWS`, `DEFAULT_MEMORY_SORT`
- **Performance** : `ENABLE_LAZY_LOADING`, `PAGINATION_CACHE_DURATION`

## 🔄 Chargement Automatique

Le système charge automatiquement les variables dans cet ordre :

1. **`.env.auth_groups`** (infrastructure obligatoire)
2. **`.env.memories_elements`** (si le fichier existe)
3. **`.env`** (fallback pour compatibilité)

### **Priorité des variables :**
- Les variables des fichiers modulaires ont la priorité
- `.env` ne complète que les variables non définies
- Pas de conflit : ordre déterministe

## 🏗️ Déploiement Modulaire

### **Déploiement minimal (authentification seulement) :**
```bash
# Copier seulement
.env.auth_groups

# Contient :
# - Infrastructure (DB, logs, API)
# - Authentification (JWT, users, groups)
# - Sécurité (CORS, rate limiting)
```

### **Déploiement complet (avec mémoires) :**
```bash
# Copier les deux fichiers
.env.auth_groups
.env.memories_elements

# API complète avec toutes les fonctionnalités
```

## 🛠️ Outils et Validation

### **Vérification de la configuration :**
Le loader valide automatiquement les variables critiques :
- Variables obligatoires manquantes
- JWT_SECRET faible ou par défaut
- Répertoires inaccessibles
- Configuration incohérente

### **Variables de debug :**
```bash
# Dans .env.auth_groups
APP_DEBUG=true
LOG_LEVEL=debug

# Affiche les informations de chargement
```

## 🔄 Migration depuis .env unique

### **Migration automatique :**
1. Gardez votre `.env` existant (fallback)
2. Créez progressivement `.env.auth_groups` et `.env.memories_elements`
3. Migrez les variables une par une
4. Supprimez `.env` quand la migration est terminée

### **Script de migration :**
```bash
# Exemple de répartition des variables
grep -E '^(APP_|DB_|JWT_|AUTH_|LOG_)' .env > .env.auth_groups
grep -E '^(MEMORY_|MEMORIES_|MAX_MEMORY)' .env > .env.memories_elements
```

## 📊 Avantages

1. **Déploiement modulaire** : Installer seulement ce qui est nécessaire
2. **Sécurité** : Variables sensibles séparées par contexte
3. **Maintenance** : Configuration organisée par domaine fonctionnel
4. **Évolutivité** : Ajout facile de nouveaux modules
5. **Compatibilité** : Migration progressive depuis .env unique

## ⚠️ Bonnes Pratiques

### **Sécurité :**
- Utilisez des JWT_SECRET longs et complexes (64+ caractères)
- Limitez ALLOWED_ORIGINS en production
- Activez les logs de sécurité

### **Performance :**
- Ajustez les tailles d'upload selon vos besoins
- Configurez la pagination appropriée
- Activez la compression en production

### **Maintenance :**
- Versionez vos fichiers .env.example
- Documentez les changements de variables
- Testez les configurations avant déploiement