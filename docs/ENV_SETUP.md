# ⚙️ Configuration Modulaire - Setup Rapide

## 🚀 Démarrage Rapide

### **1. Configuration minimale (auth_groups seulement)**
```bash
# Copier le template
cp .env.auth_groups.example .env.auth_groups

# Éditer les variables essentielles
nano .env.auth_groups

# Variables obligatoires à modifier :
APP_ENV=development
DB_NAME=votre_base_de_donnees
DB_USER=votre_utilisateur
DB_PASS=votre_mot_de_passe
JWT_SECRET=votre_cle_secrete_tres_longue_minimum_64_caracteres
```

### **2. Configuration complète (avec mémoires)**
```bash
# Ajouter le module mémoires
cp .env.memories_elements.example .env.memories_elements

# Ajuster selon vos besoins
nano .env.memories_elements
```

### **3. Vérification**
```bash
# Accéder à votre API
curl http://localhost/cmem2_API/

# Les logs de validation apparaîtront si APP_DEBUG=true
```

## 📁 Structure des Fichiers

```
.env.auth_groups         ← Infrastructure + authentification (OBLIGATOIRE)
.env.memories_elements   ← Mémoires et multimédia (optionnel)
.env                     ← Ancien format (fallback)
```

## 🔧 Variables Critiques

### **auth_groups (obligatoire) :**
```bash
# Base de données
DB_HOST=localhost
DB_NAME=cmem1_db
DB_USER=root
DB_PASS=

# Sécurité
JWT_SECRET=changez_cette_cle_en_production_minimum_64_caracteres
ALLOWED_ORIGINS=http://localhost:3000

# Logs
LOG_LEVEL=debug
LOG_DIR=logs/
```

### **memories_elements (optionnel) :**
```bash
# Uploads
MAX_MEMORY_IMAGE_SIZE=10485760
ALLOWED_MEMORY_IMAGE_TYPES=image/jpeg,image/png,image/webp

# Pagination
MEMORIES_DEFAULT_PAGE_SIZE=12
```

## ⚠️ Important

- **JWT_SECRET** doit être changé en production (64+ caractères)
- **ALLOWED_ORIGINS** doit être restreint en production
- **DB_PASS** doit être défini pour votre base de données

## 📖 Documentation Complète

- [`CONFIGURATION_ENV_MODULAIRE.md`](./CONFIGURATION_ENV_MODULAIRE.md) - Guide complet
- [`CONFIGURATION_MODULAIRE.md`](./CONFIGURATION_MODULAIRE.md) - Structure des configs PHP

## 🆘 Problèmes Courants

### **Erreur "Configuration error"**
- Vérifiez que `.env.auth_groups` existe
- Contrôlez que `JWT_SECRET` est défini et sécurisé
- Vérifiez les paramètres de base de données

### **Fichiers non chargés**
- Vérifiez les chemins des fichiers .env
- Contrôlez les permissions de lecture
- Regardez les logs si `LOG_LEVEL=debug`