# 🌟 Collective Memories API

> API REST pour la gestion de mémoires collectives - Version 1.1.0

Une plateforme collaborative permettant aux utilisateurs de créer, partager et organiser leurs souvenirs et documents au sein de groupes communautaires.

---

## 🚀 Fonctionnalités principales

### 👥 **Gestion des utilisateurs**
- Inscription et authentification JWT
- Profils utilisateurs personnalisables
- Système de rôles (Utilisateur, Admin)
- Vérification par email et réinitialisation de mot de passe

### 💾 **Mémoires collectives**
- Création et modification de mémoires
- Support multimédia (texte, images, documents)
- Système de visibilité (public/privé)
- Géolocalisation et dates
- Recherche avancée

### 👥 **Groupes collaboratifs**
- Création de groupes publics/privés
- Système d'invitations
- Gestion des rôles (membre, modérateur, admin)
- Limitation du nombre de membres

### 📁 **Gestion des fichiers**
- Upload multiformat (images, documents, audio, vidéo)
- Validation et limites de taille
- Organisation par utilisateur
- Système de restauration

### 🏷️ **Tags et organisation**
- Système d'étiquetage flexible
- Association multi-tables (mémoires, fichiers, groupes)
- Tags populaires et recherche
- Couleurs personnalisables

### 📊 **Statistiques et analytics**
- Tableaux de bord administrateur
- Statistiques utilisateurs
- Métriques par groupe
- Rapports d'activité

---

## 🛠️ Technologies utilisées

- **Backend** : PHP 8.x
- **Base de données** : MySQL/PostgreSQL
- **Architecture** : API REST
- **Authentification** : JWT Bearer Token
- **Email** : SMTP avec TLS
- **Upload** : Multipart form-data
- **Validation** : Validation personnalisée PHP
- **Logging** : Système de logs intégré

---

## 📋 Prérequis

- **PHP** 8.0+
- **MySQL** 5.7+ ou **PostgreSQL** 12+
- **Composer** (gestionnaire de dépendances PHP)
- **Serveur web** (Apache/Nginx)
- **Extensions PHP** : PDO, JSON, mbstring, fileinfo

---

## ⚡ Installation rapide

### 1. Cloner le projet
```bash
git clone https://github.com/Jrobitaille360/cmem1.git
cd cmem1_API
```

### 2. Installer les dépendances
```bash
composer install
```

### 3. Configuration
```bash
# Copier le fichier de configuration
cp config/config.example.php config/config.php
cp config/database.example.php config/database.php

# Modifier les paramètres de base de données et SMTP
```

### 4. Base de données
```bash
# Créer la base de données avec le schéma fourni
mysql -u username -p < docs/schema_CMem1_mysql.sql

# Optionnel : Insérer des données de test
mysql -u username -p < docs/test_donnees.sql
```

### 5. Permissions
```bash
# Donner les permissions d'écriture
chmod -R 755 uploads/
chmod -R 755 logs/
```

---

## 📚 Documentation

### 📖 **Guide complet**
- **[Documentation API](docs/README.md)** - Guide principal et navigation
- **[Configuration](docs/CONFIGURATION.md)** - Guide de configuration de l'environnement
- **[Spécifications techniques](docs/SPECIFICATIONS.md)** - Configuration système et limites

### 🔗 **Endpoints par catégorie**
- **[📡 Public](docs/ENDPOINTS_PUBLIC.md)** - Accès libre (8 endpoints)
- **[👥 Users](docs/ENDPOINTS_USERS.md)** - Gestion utilisateurs (15 endpoints)
- **[💾 Memories](docs/ENDPOINTS_MEMORIES.md)** - Mémoires (10 endpoints)
- **[🧩 Elements](docs/ENDPOINTS_ELEMENTS.md)** - Éléments multimédia (6 endpoints)
- **[🏷️ Tags](docs/ENDPOINTS_TAGS.md)** - Étiquetage (13 endpoints)
- **[📁 Files](docs/ENDPOINTS_FILES.md)** - Fichiers (6 endpoints)
- **[👥 Groups](docs/ENDPOINTS_GROUPS.md)** - Groupes (14 endpoints)
- **[📊 Stats](docs/ENDPOINTS_STATS.md)** - Statistiques (6 endpoints)

### 🧪 **Tests et développement**
- **[Tests unitaires](tests/)** - Suite de tests complète
- **[Schéma base de données](docs/schema_CMem1_mysql.sql)** - Structure MySQL
- **[Données de test](docs/test_donnees.sql)** - Jeu de données d'exemple

---

## 🚦 Status du projet

- ✅ **API fonctionnelle** - Version 1.1.0 stable
- ✅ **Documentation complète** - 78 endpoints documentés  
- ✅ **Tests intégrés** - Suite de tests unitaires
- ✅ **Authentification sécurisée** - JWT + validation email
- ✅ **Multi-format** - Support images, documents, audio, vidéo
- ✅ **Système de logs** - Traçabilité complète
- ✅ **Rate limiting** - 100 req/hour/IP
- ✅ **CORS activé** - Compatible applications frontend

---

## 🔒 Sécurité

- **Authentification JWT** avec expiration
- **Hashage bcrypt** des mots de passe
- **Validation stricte** des entrées utilisateur
- **Protection CSRF** intégrée
- **Limitation du taux de requêtes**
- **Validation des types de fichiers**
- **Soft delete** pour la récupération de données

---

## 📊 Limites système

| Type | Limite | Description |
|------|--------|-------------|
| **Images** | 5MB | JPEG, PNG, GIF, WebP |
| **Documents** | 10MB | PDF, TXT, DOC, DOCX |
| **Audio** | 20MB | MP3, WAV, OGG |
| **Vidéo** | 50MB | MP4, AVI, MOV |
| **Avatars** | 2MB | Images utilisateurs |
| **Rate limiting** | 100/h | Requêtes par IP |

---

## 🌐 Endpoints principaux

```http
# Information API
GET /

# Authentification
POST /users/register
POST /users/login
POST /users/logout

# Mémoires
GET /memories
POST /memories
GET /memories/{id}

# Groupes
GET /groups/public
POST /groups
GET /groups/{id}

# Upload
POST /files
POST /memories/{id}/upload
```

---

## 🤝 Contribution

### Comment contribuer
1. **Fork** le projet
2. **Créer** une branche feature (`git checkout -b feature/nouvelle-fonctionnalite`)
3. **Commiter** les changements (`git commit -m 'Ajout nouvelle fonctionnalité'`)
4. **Push** vers la branche (`git push origin feature/nouvelle-fonctionnalite`)
5. **Ouvrir** une Pull Request

### Standards de code
- **PSR-4** pour l'autoloading
- **PSR-12** pour le style de code
- **Documentation** des nouvelles fonctionnalités
- **Tests unitaires** pour les nouvelles features

---

## 📞 Support et contact

### 🐛 **Signaler un bug**
Ouvrez une [issue GitHub](https://github.com/Jrobitaille360/cmem1/issues) avec :
- Description du problème
- Étapes pour reproduire
- Environnement (PHP, serveur, etc.)

### 💡 **Demander une fonctionnalité**
Utilisez les [discussions GitHub](https://github.com/Jrobitaille360/cmem1/discussions) pour proposer de nouvelles idées.

### 📧 **Contact direct**
Pour les questions techniques ou partenariats.

---

## 📄 Licence

Ce projet est sous licence **MIT** - voir le fichier [LICENSE](LICENSE) pour plus de détails.

---

## 🙏 Remerciements

Merci à tous les contributeurs qui ont participé au développement de cette API collaborative !

---

**Collective Memories API v1.1.0** - *Préservons nos souvenirs ensemble* 🌟
