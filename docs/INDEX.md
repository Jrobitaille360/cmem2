# 📚 Index de la Documentation - AuthGroups API

Bienvenue dans la documentation complète de l'AuthGroups API v1.3.0

## 🚀 Pour commencer

### Démarrage rapide
1. **[README.md](../README.md)** - Vue d'ensemble du projet et installation
2. **[QUICKSTART.md](./QUICKSTART.md)** - Guide de démarrage rapide (15 min)
3. **[API_OVERVIEW.md](./API_OVERVIEW.md)** - Architecture générale de l'API

## 🔑 Système d'API Keys (v1.3.0)

### Documentation principale
- **[ENDPOINTS_API_KEYS.md](./ENDPOINTS_API_KEYS.md)** ⭐ - Documentation complète (520 lignes)
  - Tous les endpoints avec exemples détaillés
  - Codes d'erreur et réponses
  - Bonnes pratiques de sécurité
  
- **[API_KEYS_QUICK_REFERENCE.md](./API_KEYS_QUICK_REFERENCE.md)** 🚀 - Référence rapide (531 lignes)
  - Commandes rapides
  - Snippets de code prêts à l'emploi
  - Exemples cURL, JavaScript, Python, PHP
  
### Guides techniques
- **[API_KEYS_IMPLEMENTATION.md](./API_KEYS_IMPLEMENTATION.md)** 🔧 - Guide d'implémentation (405 lignes)
  - Comment intégrer les API keys dans votre projet
  - Exemples d'intégration frontend/backend
  - Gestion du cycle de vie des clés
  
- **[API_KEYS_ARCHITECTURE.md](./API_KEYS_ARCHITECTURE.md)** 🏗️ - Architecture technique (583 lignes)
  - Design patterns utilisés
  - Diagrammes de flux
  - Schéma de base de données
  - Décisions architecturales
  
### Migration et mise à jour
- **[MIGRATION_v1.3.0.md](./MIGRATION_v1.3.0.md)** 📦 - Migration depuis v1.2.x (478 lignes)
  - Étapes de migration détaillées
  - Scripts SQL fournis
  - Checklist de déploiement
  - Compatibilité ascendante garantie
  
- **[API_KEYS_COMPLETION_SUMMARY.md](./API_KEYS_COMPLETION_SUMMARY.md)** ✅ - Résumé d'implémentation
  - Liste complète des fichiers créés
  - Résultats des tests (23/23 ✓)
  - Métriques du projet

## 📡 Documentation des Endpoints

### Par module
- **[ENDPOINTS_PUBLIC.md](./ENDPOINTS_PUBLIC.md)** - Endpoints publics (santé, aide)
- **[ENDPOINTS_USERS.md](./ENDPOINTS_USERS.md)** - Gestion des utilisateurs
- **[ENDPOINTS_GROUPS.md](./ENDPOINTS_GROUPS.md)** - Gestion des groupes
- **[ENDPOINTS_FILES.md](./ENDPOINTS_FILES.md)** - Upload et gestion de fichiers
- **[ENDPOINTS_TAGS.md](./ENDPOINTS_TAGS.md)** - Système de tags
- **[ENDPOINTS_STATS.md](./ENDPOINTS_STATS.md)** - Statistiques et analytics
- **[ENDPOINTS_API_KEYS.md](./ENDPOINTS_API_KEYS.md)** 🆕 - Clés API

### Format JSON
- **[API_ENDPOINTS.json](./API_ENDPOINTS.json)** - Tous les endpoints en JSON (v1.3.0)
  - Format machine-readable
  - Idéal pour génération automatique de clients
  - Spécification OpenAPI-like

## 📖 Guides de référence

### Référence API
- **[API_REFERENCE.md](./API_REFERENCE.md)** - Référence rapide complète
  - Tous les endpoints en un coup d'œil
  - Exemples JavaScript, Python, PHP, Bash
  - Codes HTTP et formats de réponse
  
### Architecture
- **[API_OVERVIEW.md](./API_OVERVIEW.md)** - Vue d'ensemble technique
  - Architecture modulaire
  - Flux de requêtes
  - Services et composants
  - Diagrammes

## 🔐 Sécurité et authentification

### Authentification
- **JWT (JSON Web Tokens)**
  - Documentation: [ENDPOINTS_USERS.md](./ENDPOINTS_USERS.md#authentification)
  - Cas d'usage: Applications web/mobile avec utilisateurs
  - Durée: 24h par défaut
  
- **API Keys** 🆕
  - Documentation: [ENDPOINTS_API_KEYS.md](./ENDPOINTS_API_KEYS.md)
  - Cas d'usage: Intégrations serveur-à-serveur, scripts
  - Formats: `ag_live_*` (prod), `ag_test_*` (test)

### Administration
- **[ADMIN_SECRET_ENDPOINT.md](./ADMIN_SECRET_ENDPOINT.md)** - Endpoints d'administration
  - Reset de données
  - Gestion avancée
  - Accès restreint

## 🗄️ Base de données

### Scripts SQL
- **[create_database.sql](./create_database.sql)** - Création initiale de la base
- **[create_proc_reset_auth_groups.sql](./create_proc_reset_auth_groups.sql)** - Procédure de reset complète
  - Inclut la table `api_keys` (v1.3.0)
  - Soft delete avec `deleted_at`
  - Vues et statistiques
  
### Triggers
- **[create_triggers_auth_groups.sql](./create_triggers_auth_groups.sql)** - Triggers automatiques

## 📝 Gestion du projet

### Changelog et versions
- **[CHANGELOG.md](../CHANGELOG.md)** - Historique des versions
  - v1.3.0 (2025-10-08) - API Keys System ✨
  - v1.2.0 (2025-10-07) - Rebranding vers AuthGroups
  - v1.1.0 et antérieures
  
### Licences
- **[LICENSE](../LICENSE)** - Licence du projet
- **[THIRD_PARTY_LICENSES.md](./THIRD_PARTY_LICENSES.md)** - Licences des dépendances

### Todo et roadmap
- **[aaa TODO.txt](./aaa TODO.txt)** - Liste des tâches
- Roadmap dans [README.md](../README.md#roadmap)

## 🧪 Tests

### Scripts de test
Tous situés dans `/tests/api_keys/`:
- `test_api_keys_basic.php` - Suite de tests principale (23 tests)
- `check_table_exists.php` - Vérification de la table
- `add_deleted_at_remote.php` - Migration helper

### Autres tests
- `/tests/users/` - Tests utilisateurs
- `/tests/groups/` - Tests groupes
- `/tests/files/` - Tests fichiers
- `/tests/tags/` - Tests tags

## 🎯 Cas d'usage par besoin

### "Je veux créer une application web"
1. Lisez [README.md](../README.md) pour l'installation
2. Suivez [QUICKSTART.md](./QUICKSTART.md)
3. Utilisez l'authentification JWT: [ENDPOINTS_USERS.md](./ENDPOINTS_USERS.md)
4. Consultez [API_REFERENCE.md](./API_REFERENCE.md) pour les endpoints

### "Je veux intégrer l'API dans mes scripts/automations"
1. Lisez [API_KEYS_QUICK_REFERENCE.md](./API_KEYS_QUICK_REFERENCE.md)
2. Créez une clé API: [ENDPOINTS_API_KEYS.md](./ENDPOINTS_API_KEYS.md#créer-une-clé-api)
3. Utilisez les exemples cURL/Python/PHP fournis
4. Gérez vos clés: [API_KEYS_IMPLEMENTATION.md](./API_KEYS_IMPLEMENTATION.md)

### "Je veux comprendre l'architecture"
1. [API_OVERVIEW.md](./API_OVERVIEW.md) - Vue d'ensemble
2. [API_KEYS_ARCHITECTURE.md](./API_KEYS_ARCHITECTURE.md) - Architecture API Keys
3. Schémas SQL dans `/docs/*.sql`
4. Code source dans `/src/auth_groups/`

### "Je veux migrer depuis v1.2.x"
1. **[MIGRATION_v1.3.0.md](./MIGRATION_v1.3.0.md)** - Guide complet de migration
2. Exécutez les scripts SQL fournis
3. Testez avec la suite de tests
4. Déployez en suivant la checklist

### "Je cherche un endpoint spécifique"
1. [API_REFERENCE.md](./API_REFERENCE.md) - Vue rapide de tous les endpoints
2. [API_ENDPOINTS.json](./API_ENDPOINTS.json) - Format JSON recherchable
3. Ou consultez directement `ENDPOINTS_*.md` par module

## 📊 Statistiques de la documentation

| Type | Fichiers | Lignes totales |
|------|----------|----------------|
| Documentation API Keys | 6 | ~3,000 lignes |
| Documentation générale | 15 | ~2,500 lignes |
| Scripts SQL | 4 | ~1,000 lignes |
| **Total** | **25+** | **~6,500+ lignes** |

## 🆘 Support et contribution

### Besoin d'aide ?
1. Consultez d'abord cette documentation
2. Vérifiez [CHANGELOG.md](../CHANGELOG.md) pour les dernières modifications
3. Regardez les exemples dans `API_REFERENCE.md`
4. Testez avec les scripts fournis dans `/tests/`

### Rapporter un bug
1. Vérifiez qu'il n'est pas déjà résolu dans la dernière version
2. Consultez les issues connues
3. Fournissez un exemple reproductible
4. Incluez les logs pertinents

### Contribuer
1. Fork le projet
2. Créez une branche pour votre feature
3. Suivez le style de code existant
4. Ajoutez des tests
5. Mettez à jour la documentation
6. Soumettez une Pull Request

## 🔄 Mises à jour

Cette documentation est maintenue activement. Dernière mise à jour: **8 octobre 2025**

### Prochaines versions prévues
- v1.4.0 - Rate limiting avancé avec Redis
- v1.5.0 - Webhooks et événements
- v2.0.0 - GraphQL support

---

## 📱 Navigation rapide

### Par ordre alphabétique
- [ADMIN_SECRET_ENDPOINT.md](./ADMIN_SECRET_ENDPOINT.md)
- [API_ENDPOINTS.json](./API_ENDPOINTS.json)
- [API_KEYS_ARCHITECTURE.md](./API_KEYS_ARCHITECTURE.md) 🆕
- [API_KEYS_COMPLETION_SUMMARY.md](./API_KEYS_COMPLETION_SUMMARY.md) 🆕
- [API_KEYS_IMPLEMENTATION.md](./API_KEYS_IMPLEMENTATION.md) 🆕
- [API_KEYS_QUICK_REFERENCE.md](./API_KEYS_QUICK_REFERENCE.md) 🆕
- [API_OVERVIEW.md](./API_OVERVIEW.md)
- [API_REFERENCE.md](./API_REFERENCE.md)
- [CHANGELOG.md](../CHANGELOG.md)
- [ENDPOINTS_API_KEYS.md](./ENDPOINTS_API_KEYS.md) 🆕
- [ENDPOINTS_FILES.md](./ENDPOINTS_FILES.md)
- [ENDPOINTS_GROUPS.md](./ENDPOINTS_GROUPS.md)
- [ENDPOINTS_PUBLIC.md](./ENDPOINTS_PUBLIC.md)
- [ENDPOINTS_STATS.md](./ENDPOINTS_STATS.md)
- [ENDPOINTS_TAGS.md](./ENDPOINTS_TAGS.md)
- [ENDPOINTS_USERS.md](./ENDPOINTS_USERS.md)
- [MIGRATION_v1.3.0.md](./MIGRATION_v1.3.0.md) 🆕
- [QUICKSTART.md](./QUICKSTART.md) 🆕
- [README.md](../README.md)

### Par priorité (recommandé)
1. ⭐ [README.md](../README.md) - Commencez ici
2. 🚀 [QUICKSTART.md](./QUICKSTART.md) - Guide rapide
3. 📖 [API_REFERENCE.md](./API_REFERENCE.md) - Référence complète
4. 🔑 [ENDPOINTS_API_KEYS.md](./ENDPOINTS_API_KEYS.md) - API Keys (nouveau)
5. 📚 Les autres selon vos besoins

---

**AuthGroups API v1.3.0** - Documentation complète et à jour  
*Dernière révision: 8 octobre 2025*
