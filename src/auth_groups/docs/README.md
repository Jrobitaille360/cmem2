# 📚 Documentation AuthGroups API

Documentation complète de l'API AuthGroups v1.3.0 - Système d'authentification, gestion d'utilisateurs, groupes, fichiers, et API Keys.

---

## 🚀 Démarrage rapide

### Pour les nouveaux utilisateurs

1. **[QUICKSTART.md](./QUICKSTART.md)** - Installation et configuration en 15 minutes
   - Installation des dépendances
   - Configuration de la base de données
   - Configuration de l'environnement
   - Premiers pas avec l'API

2. **[API_OVERVIEW.md](./API_OVERVIEW.md)** - Vue d'ensemble de l'architecture
   - Architecture générale
   - Composants principaux (Router, Handlers, Controllers, Models)
   - Flux de requêtes
   - Conventions de réponse

---

## 📖 Documentation par fonctionnalité

### 👤 Utilisateurs

**[ENDPOINTS_USERS.md](./ENDPOINTS_USERS.md)** - Gestion des utilisateurs

- Inscription et authentification (login/logout)
- Gestion du profil utilisateur
- Upload d'avatar
- Vérification d'email
- Réinitialisation de mot de passe
- Gestion des rôles et permissions

### 👥 Groupes

**[ENDPOINTS_GROUPS.md](./ENDPOINTS_GROUPS.md)** - Gestion des groupes

- Création et modification de groupes
- Ajout/retrait de membres
- Gestion des permissions de groupe
- Hiérarchie des groupes
- Groupes publics et privés

### 📁 Fichiers

**[ENDPOINTS_FILES.md](./ENDPOINTS_FILES.md)** - Gestion des fichiers

- Upload de fichiers
- Téléchargement de fichiers
- Partage de fichiers (public/privé)
- Gestion des permissions
- Métadonnées et tags

### 🏷️ Tags

**[ENDPOINTS_TAGS.md](./ENDPOINTS_TAGS.md)** - Système de tags

- Création et gestion de tags
- Association tags-ressources
- Recherche par tags
- Tags personnalisés par utilisateur

### 📊 Statistiques

**[ENDPOINTS_STATS.md](./ENDPOINTS_STATS.md)** - Statistiques et analytics

- Statistiques d'utilisation
- Métriques par utilisateur
- Métriques par groupe
- Rapports d'activité

### 🌐 Endpoints publics

**[ENDPOINTS_PUBLIC.md](./ENDPOINTS_PUBLIC.md)** - Routes publiques

- Accès sans authentification
- Partages publics
- Calendriers publics (format ICS)
- Webhooks

---

## 🔑 Système d'API Keys

Le système d'API Keys permet l'authentification sans JWT pour les intégrations machine-to-machine.

### Documentation principale

**[ENDPOINTS_API_KEYS.md](./ENDPOINTS_API_KEYS.md)** ⭐ - Documentation complète

- Tous les endpoints CRUD
- Exemples de requêtes et réponses
- Codes d'erreur détaillés
- Bonnes pratiques de sécurité

**[API_KEYS_QUICK_REFERENCE.md](./API_KEYS_QUICK_REFERENCE.md)** 🚀 - Référence rapide

- Commandes cURL prêtes à l'emploi
- Snippets JavaScript, Python, PHP
- Tests et validation
- Cas d'usage courants

### Guides techniques

**[API_KEYS_IMPLEMENTATION.md](./API_KEYS_IMPLEMENTATION.md)** - Guide d'implémentation

- Intégration dans vos applications
- Gestion du cycle de vie des clés
- Rotation des clés
- Meilleures pratiques

**[API_KEYS_ARCHITECTURE.md](./API_KEYS_ARCHITECTURE.md)** - Architecture technique

- Structure de la base de données
- Middleware d'authentification
- Flux de validation
- Diagrammes UML

**[API_KEYS_TABLE_STRUCTURE.md](./API_KEYS_TABLE_STRUCTURE.md)** - Structure de la table

- Schéma SQL détaillé
- Colonnes et index
- Relations avec autres tables

**[API_KEYS_ADMIN_FEATURES.md](./API_KEYS_ADMIN_FEATURES.md)** - Fonctionnalités admin

- Gestion centralisée des clés
- Révocation globale
- Monitoring et logs
- Quotas et limitations

---

## 📅 Module Calendrier

**[CALENDAR_PUBLIC_ROUTE.md](./CALENDAR_PUBLIC_ROUTE.md)** - Routes publiques calendrier

- Export de calendriers au format ICS
- Synchronisation avec Google Calendar, Outlook, Apple Calendar
- Partage de calendriers via token
- Intégration CalDAV (voir `/src/ics/docs_ICS/`)

Pour la documentation complète du module CalDAV, voir **[/src/ics/docs_ICS/README.md](../../ics/docs_ICS/README.md)**

---

## 🔔 Webhooks

### Configuration

**[WEBHOOKS_CONFIGURATION.md](./WEBHOOKS_CONFIGURATION.md)** - Configuration des webhooks

- Configuration des endpoints
- Événements disponibles
- Payload des webhooks
- Retry et gestion d'erreurs

**[WEBHOOKS_README.md](./WEBHOOKS_README.md)** - Guide d'utilisation

- Créer et tester des webhooks
- Sécurité (signatures)
- Exemples d'intégration
- Debugging

---

## 💳 Système de licences

**[FLUTTER_LICENSE_SYSTEM.md](./FLUTTER_LICENSE_SYSTEM.md)** - Système de licences et paiements

- Gestion des abonnements
- Plans de paiement (Free, Pro, Enterprise)
- Validation des licences côté Flutter
- Endpoints de licence

---

## 🗄️ Base de données

### Scripts SQL

**[create_database.sql](./create_database.sql)** - Création de la base de données

- Schéma complet de la base
- Tables principales
- Indexes et contraintes

**[create_proc_reset_auth_groups.sql](./create_proc_reset_auth_groups.sql)** - Procédure de reset

- Réinitialisation des données de test
- Recréation des tables
- Données de seed

**[create_proc_reset_auth_groups_data.sql](./create_proc_reset_auth_groups_data.sql)** - Reset données uniquement

- Conservation de la structure
- Suppression des données
- Réinsertion des données de test

**[create_triggers_auth_groups.sql](./create_triggers_auth_groups.sql)** - Triggers

- Triggers automatiques
- Mise à jour des timestamps
- Validation des données
- Logs d'audit

### Migrations

**[MIGRATION_v1.3.0.md](./MIGRATION_v1.3.0.md)** - Migration vers v1.3.0

- Nouveautés de la version 1.3.0
- Instructions de migration
- Breaking changes
- Étapes de mise à jour

**[migrate_api_keys_add_deleted_at.sql](./migrate_api_keys_add_deleted_at.sql)** - Soft delete API keys

- Ajout de la colonne `deleted_at`
- Modification des requêtes

**[migrate_license_system.sql](./migrate_license_system.sql)** - Système de licences

- Tables de licences
- Colonnes de paiement utilisateurs
- Indexes

---

## 📋 Référence API

**[API_REFERENCE.md](./API_REFERENCE.md)** - Référence complète de l'API

- Tous les endpoints disponibles
- Format des requêtes et réponses
- Codes d'erreur
- Exemples détaillés

**[API_ENDPOINTS_v2.json](./API_ENDPOINTS_v2.json)** - Spécification JSON (OpenAPI)

- Format machine-readable
- Import dans Postman/Insomnia
- Génération de clients

---

## 🔐 Sécurité

### Authentification

L'API supporte deux méthodes d'authentification :

1. **JWT Bearer Token** (recommandé pour les applications web/mobile)

   ```text
   Authorization: Bearer <token>
   ```

2. **API Keys** (pour les intégrations machine-to-machine)

   ```text
   X-API-Key: <api_key>
   ```

### Bonnes pratiques

- ✅ Utilisez HTTPS en production
- ✅ Stockez les secrets dans des variables d'environnement (`.env.auth_groups`)
- ✅ Rotation régulière des API Keys
- ✅ Utilisez des scopes/permissions limités
- ✅ Loggez les accès aux ressources sensibles
- ✅ Validez et sanitisez toutes les entrées

---

## 🧪 Tests

Tous les tests sont disponibles dans `/tests/` :

```bash
# Tester tous les endpoints utilisateurs
php tests/test_users_entrypoints.php

# Tester les webhooks
php tests/test_webhooks.php

# Tester les API keys
php tests/test_api_keys.php
```

---

## 📞 Support et contribution

### Structure du projet

```text
src/auth_groups/
├── Controllers/        # Contrôleurs métier
├── Models/            # Modèles de données
├── Routing/           # Gestion du routage
│   └── RouteHandlers/ # Handlers spécialisés
├── Middleware/        # Middlewares (Auth, CORS, etc.)
├── Services/          # Services métier
├── Utils/             # Utilitaires
├── docs/              # Cette documentation
├── database.php       # Connexion DB
├── environment.php    # Configuration
└── loader.php         # Chargeur principal
```

### Conventions de code

- **PSR-4** pour l'autoloading
- **Namespace** : `AuthGroups\`
- **Réponses JSON** standardisées :

  ```json
  {
    "success": true,
    "message": "Opération réussie",
    "data": { ... }
  }
  ```

### Contribuer

1. Fork le projet
2. Créer une branche (`git checkout -b feature/AmazingFeature`)
3. Commiter les changements (`git commit -m 'Add AmazingFeature'`)
4. Push vers la branche (`git push origin feature/AmazingFeature`)
5. Ouvrir une Pull Request

---

## 📜 License

Ce projet est sous licence MIT. Voir le fichier `LICENSE` à la racine du projet.

---

**Version** : 1.3.0  
**Dernière mise à jour** : Octobre 2025  
**Auteur** : CMEM Team
