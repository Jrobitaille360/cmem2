# Plan d'amélioration globale — cmem2_API

## 1. Améliorations par ordre d'importance

### Critique (Sécurité, stabilité, dette technique)

- **Sécurisation JWT**
  - **En place :**
    - Blacklist des JWT (table dédiée, invalidation immédiate à la déconnexion ou changement critique)
    - Rotation du device token lors du refresh (empêche le vol de session persistante)
    - Rate limiting sur les endpoints sensibles (login, envoi de code OTP)
    - Expiration courte des tokens (15 jours max, configurable)
    - Algorithme HS256 avec clé secrète forte (stockée dans .env)
    - Vérification stricte de la signature et du scope à chaque requête
  - **Améliorations à faire :**
    - Surveillance active de la blacklist (purge automatique des tokens expirés)
    - Journalisation des tentatives d'accès avec JWT invalides ou expirés (alerte sécurité)
    - Ajout d'un endpoint pour lister/révoquer toutes les sessions actives d'un utilisateur
    - Support du refresh token rotatif (chaînage, invalidation automatique des anciens refresh)
    - Option de double authentification (2FA) sur les endpoints critiques
    - Tests automatisés de sécurité sur la gestion JWT (fuzzing, injection, replay)
    - Monitoring du taux d'échec d'authentification (alerte brute force)
  - **Maintenance à prévoir :**
    - Vérification régulière de la robustesse de la clé secrète (rotation annuelle recommandée)
    - Audit du code d'authentification à chaque release majeure
    - Mise à jour des dépendances liées à la gestion JWT (librairies, middlewares)
    - Documentation claire des flux d'authentification et des cas d'erreur
    - Nettoyage programmé de la table blacklist (cron, script dédié)
- **Validation centralisée**
  - **En place :**
    - Classe Validator centralisée pour la plupart des entrées utilisateurs (auth, création, update)
    - Gestion des erreurs de validation dans Response (retourne code 400, messages explicites)
    - Règles de validation standardisées (required, email, min/max, etc.)
    - Reset automatique des erreurs à chaque validation
    - Tests unitaires sur les cas de validation courants
  - **Améliorations à faire :**
    - Unifier la gestion des erreurs de validation sur tous les modules (éviter les bypass locaux)
    - Ajouter la validation côté modèle (niveau BaseModel) pour les entrées critiques
    - Enrichir les messages d'erreur (champ, règle violée, valeur reçue)
    - Support de la validation conditionnelle (ex : si champ A, alors champ B obligatoire)
    - Validation des types et formats complexes (dates, listes, objets imbriqués)
    - Ajout de schémas de validation JSON pour les endpoints publics
    - Centraliser la documentation des règles de validation (auto-génération possible)
    - Couvrir tous les cas de validation par des tests automatisés (y compris cas limites)
  - **Maintenance à prévoir :**
    - Audit régulier des règles de validation (nouveaux endpoints, évolutions métier)
    - Mise à jour des tests unitaires à chaque ajout de règle ou endpoint
    - Documentation à jour des règles et messages d'erreur
    - Vérification de la cohérence entre la doc, le code et les réponses API
- **Refactorisation des modèles**
  - **En place :**
    - Passage de `static $db` à une propriété d'instance dans BaseModel (évite les conflits multi-modèles)
    - Refactorisation de User::findById() et findByEmail() (utilisation de mapFromArray, moins de duplication)
    - Fusion de Group::create() et create2() (réduction de la dette technique)
    - Tests de régression sur les modèles principaux après refactoring
  - **Améliorations à faire :**
    - Supprimer toutes les propriétés statiques inutiles dans les modèles
    - Isoler les changements impactants dans des commits/test dédiés (ex : BaseModel)
    - Uniformiser la structure des modèles (constructeur, mapping, validation)
    - Factoriser les méthodes redondantes (ex : mapping, validation, save/update)
    - Ajouter des méthodes d'accès typées (getters/setters) pour chaque champ critique
    - Documenter chaque modèle (propriétés, méthodes, contraintes)
    - Préparer la compatibilité avec un ORM (Doctrine, Eloquent) si migration envisagée
    - Couvrir tous les modèles par des tests unitaires et de régression
  - **Maintenance à prévoir :**
    - Audit régulier de la cohérence des modèles (structure, héritage, duplication)
    - Mise à jour des tests à chaque refactorisation
    - Documentation à jour des modèles et de leurs usages
    - Vérification de la compatibilité avec les migrations SQL et la base de données
- **Suppression des exclusions hardcodées**
  - **En place :**
    - Système de plugins basé sur la détection de `plugin.json` dans chaque dossier
    - Exclusions manuelles de certains dossiers (ex : 'auth_groups', 'Core') dans `PluginManager`
    - Documentation des exclusions dans le code et les plans d'amélioration
  - **Améliorations à faire :**
    - Supprimer toutes les exclusions hardcodées dans `scanPluginDirectories()`
    - Se baser uniquement sur la présence de `plugin.json` pour détecter un plugin
    - Ajouter des tests pour garantir qu'aucun plugin valide n'est ignoré
    - Documenter la convention : tout dossier sans `plugin.json` est ignoré automatiquement
    - Nettoyer le code pour éviter toute confusion sur la logique d'exclusion
  - **Maintenance à prévoir :**
    - Vérification à chaque ajout de module/dossier que la logique d'inclusion/exclusion reste cohérente
    - Mise à jour de la documentation si la convention évolue
    - Audit régulier du système de plugins pour éviter les régressions
- **Centralisation des logs**
  - **En place :**
    - Service LogService centralisé (AuthGroups) pour les logs applicatifs (info, warning, error)
    - Méthode safeLog() présente dans plusieurs plugins et dans PluginManager
    - Utilisation de logs pour les événements critiques (auth, erreurs, plugins)
  - **Améliorations à faire :**
    - Factoriser safeLog() dans une classe de base abstraite (AbstractPlugin)
    - Uniformiser l'appel aux logs dans tous les modules/plugins (plus de duplication)
    - Ajouter des niveaux de logs (debug, notice, critical) si besoin
    - Permettre la configuration du niveau de log via .env ou config centrale
    - Ajouter la possibilité de loguer vers plusieurs cibles (fichier, stdout, syslog, Sentry…)
    - Documenter la politique de log (quoi, où, quand, niveau)
    - Ajouter des logs pour les actions d'administration et les changements critiques
  - **Maintenance à prévoir :**
    - Audit régulier de la couverture des logs (événements importants bien tracés)
    - Mise à jour de la doc à chaque évolution de la politique de log
    - Vérification de la cohérence des logs entre modules/plugins
    - Nettoyage périodique des fichiers de logs (rotation, archivage)

### Important (Performance, maintenabilité)

- **Lazy-load des handlers**
  - **En place :**
    - Utilisation de factory closures pour instancier les handlers à la demande (Router)
    - Réduction de la consommation mémoire au démarrage (seuls les handlers nécessaires sont instanciés)
    - Refactoring déjà appliqué sur certains modules (auth_groups, core)
  - **Améliorations à faire :**
    - Étendre le lazy-load à tous les modules/plugins (ics, pomo, quiz, puzzle…)
    - Vérifier qu'aucun handler n'est instancié inutilement au boot
    - Uniformiser la déclaration des handlers (tous via closure/factory, plus d'instance directe)
    - Ajouter des tests de performance (avant/après) pour valider le gain
    - Documenter la convention d'écriture des handlers (exemples, anti-patterns)
    - Gérer les dépendances entre handlers via injection ou factory
  - **Maintenance à prévoir :**
    - Audit régulier du code pour détecter les handlers instanciés trop tôt
    - Mise à jour de la doc à chaque ajout de module ou refactoring du router
    - Vérification de la compatibilité avec les middlewares et le pipeline
- **Externalisation des chemins**
  - **En place :**
    - Utilisation de variables d'environnement (.env) pour BASE_PATH, DB, clés secrètes, etc.
    - Fichier environment.php centralisant certains chemins critiques
    - Possibilité de surcharger les chemins via .env sans modifier le code
  - **Améliorations à faire :**
    - Externaliser tous les chemins critiques restants (uploads, logs, assets, etc.)
    - Uniformiser l'accès aux variables d'environnement dans tout le code (pas d'accès direct à $_ENV)
    - Documenter chaque variable d'environnement utilisée (nom, usage, valeur par défaut)
    - Ajouter des vérifications au démarrage pour détecter les chemins manquants ou incohérents
    - Permettre la configuration dynamique (ex : via interface admin ou script d'init)
    - Sécuriser l'accès aux chemins sensibles (droits, permissions, non-exposition publique)
  - **Maintenance à prévoir :**
    - Audit régulier des chemins utilisés dans le code (nouveaux modules, migrations…)
    - Mise à jour de la doc à chaque ajout/modification de variable d'environnement
    - Vérification de la cohérence entre .env, environment.php et la documentation
- **Pipeline middleware**
  - **En place :**
    - Système de middleware permettant d'intercepter les requêtes (auth, validation, logging…)
    - Exécution séquentielle des middlewares dans Router/runMiddleware()
    - Utilisation de middlewares pour la sécurité (auth, CORS, rate limiting)
  - **Améliorations à faire :**
    - Refactoriser le pipeline pour le rendre plus modulaire et extensible (pattern Pipeline/Chain of Responsibility)
    - Permettre l'ajout/retrait dynamique de middlewares (ex : via config ou plugins)
    - Uniformiser l'interface des middlewares (méthode handle/request/next)
    - Ajouter la gestion des middlewares asynchrones ou conditionnels
    - Documenter la liste et l'ordre d'exécution des middlewares
    - Ajouter des tests unitaires et d'intégration sur le pipeline complet
    - Gérer les erreurs et exceptions de façon centralisée dans le pipeline
  - **Maintenance à prévoir :**
    - Audit régulier de la cohérence et de la couverture des middlewares
    - Mise à jour de la doc à chaque ajout ou modification de middleware
    - Vérification de la compatibilité avec les nouveaux modules/plugins
- **Nettoyage des migrations SQL**
  - **En place :**
    - Dossiers de migrations par module (core, ics, pomo, quiz, puzzle)
    - Fichiers de migration nommés avec date et description (ex : 20260405_quiz_settings.sql)
    - Procédures de création de tables centralisées pour certains modules
    - Documentation partielle des migrations dans les guides de module
  - **Améliorations à faire :**
    - Unifier le format des fichiers de migration (en-tête, structure, commentaires)
    - Documenter chaque migration dans le guide du module correspondant (but, impact, rollback)
    - Ajouter un script d'exécution centralisé pour appliquer les migrations dans l'ordre
    - Générer un fichier build complet de la base à chaque release majeure
    - Ajouter des tests d'intégrité après migration (vérification schéma, contraintes)
    - Mettre en place une convention stricte de nommage et de versionning
    - Gérer les migrations de rollback (down) pour chaque ajout critique
  - **Maintenance à prévoir :**
    - Audit régulier des migrations (cohérence, documentation, application réelle)
    - Mise à jour de la doc à chaque nouvelle migration
    - Vérification de la compatibilité ascendante/descendante lors des upgrades
    - Nettoyage des anciennes migrations inutiles après consolidation dans un build complet

### Améliorations secondaires

- **Pagination enrichie** : sur tous les endpoints listant des ressources
- **Normalisation des réponses** : format JSON, codes HTTP, schémas de validation
- **Tests de régression** : systématiser après chaque refactorisation majeure

---

## 2. Amélioration de la documentation

- **Compléter les guides de chaque module**
  - **En place :**
    - Guides présents pour chaque module principal (core, ics, pomo, quiz, puzzle)
    - Table des matières, description des endpoints, exemples d'appels pour certains modules
    - Documentation partielle des erreurs et des migrations
  - **Améliorations à faire :**
    - Compléter systématiquement chaque guide avec :
      - Structure du module (schéma, dépendances, flux)
      - Liste exhaustive des endpoints (méthode, route, description, paramètres, réponses)
      - Exemples d'appels (requête, réponse, cas d'erreur)
      - Tableaux d'erreurs (codes, signification, cas typiques)
      - Documentation de toutes les migrations associées
    - Uniformiser la présentation (titres, tables, exemples, navigation)
    - Ajouter des schémas ou diagrammes pour les flux complexes
    - Générer automatiquement une partie de la doc à partir du code (si possible)
    - Ajouter une section FAQ ou bonnes pratiques par module
  - **Maintenance à prévoir :**
    - Mise à jour des guides à chaque ajout ou modification d'endpoint/migration
    - Vérification de la cohérence entre la doc, le code et les réponses API
    - Audit régulier de la complétude et de la clarté des guides
- **Uniformiser les tables et titres**
  - **En place :**
    - Utilisation de tables Markdown pour les endpoints, erreurs, migrations dans la plupart des guides
    - Titres hiérarchisés (H1, H2, H3) dans les guides principaux
    - Application partielle des règles markdownlint (espaces, niveaux de titres…)
  - **Améliorations à faire :**
    - Appliquer strictement toutes les règles markdownlint (voir `/memories/markdown-rules.md`)
    - Uniformiser le format des tables :
      - Un espace avant/après chaque |
      - Titres, séparateurs et données alignés
      - Pas de colonnes superflues ou manquantes
    - Harmoniser la hiérarchie des titres (pas de saut de niveau, un seul H1 par doc)
    - Ajouter des exemples de tables et de titres corrects dans la doc de contribution
    - Mettre en place une vérification automatique (CI ou script) pour le lint Markdown
  - **Maintenance à prévoir :**
    - Audit régulier des guides pour détecter les écarts de format
    - Correction immédiate à chaque ajout ou modification de table/titre
    - Mise à jour de la doc de contribution si les règles évoluent
- **Ajouter des exemples d'intégration** : pour chaque endpoint critique (auth, création, suppression)
  - **En place :**
    - Quelques exemples d'appels API dans certains guides (auth, création de ressource)
    - Exemples de requêtes et réponses pour certains endpoints critiques
    - Documentation partielle des cas d'erreur
  - **Améliorations à faire :**
    - Ajouter systématiquement des exemples d'intégration pour chaque endpoint critique (auth, création, suppression, update)
    - Couvrir les cas de succès, d'erreur courante et d'erreur inattendue
    - Utiliser un format uniforme pour les exemples (requête HTTP, payload, réponse JSON)
    - Ajouter des exemples d'intégration multi-modules (ex : création + ajout à un groupe)
    - Documenter les prérequis pour chaque exemple (auth, permissions, données attendues)
    - Ajouter des exemples d'intégration côté client (curl, Postman, JS, PHP…)
    - Mettre en avant les bonnes pratiques et pièges à éviter
  - **Maintenance à prévoir :**
    - Mise à jour des exemples à chaque évolution d'endpoint ou de schéma de réponse
    - Vérification de la cohérence entre les exemples, la doc et le code réel
    - Audit régulier pour garantir la pertinence et l'actualité des exemples
- **Documenter les migrations** : chaque fichier SQL doit être référencé dans le guide du module
  - **En place :**
    - Documentation partielle des migrations dans certains guides de module
    - Fichiers de migration nommés avec date et description
  - **Améliorations à faire :**
    - Référencer systématiquement chaque fichier SQL de migration dans le guide du module concerné
    - Ajouter une table récapitulative (nom, date, description, impact, rollback)
    - Documenter le lien entre la migration et la version applicative
    - Ajouter des exemples d'application et de rollback de migration
    - Uniformiser la présentation des migrations dans tous les guides
  - **Maintenance à prévoir :**
    - Mise à jour de la doc à chaque nouvelle migration ou modification
    - Vérification de la cohérence entre les fichiers SQL, la doc et la base réelle
    - Audit régulier de la complétude de la documentation des migrations
- **Roadmap et conventions** : maintenir à jour dans le README et les guides principaux
  - **En place :**
    - Roadmap présente dans le README et certains guides
    - Conventions de code et de doc partiellement documentées
  - **Améliorations à faire :**
    - Maintenir une roadmap à jour dans le README et les guides principaux (objectifs, jalons, évolutions majeures)
    - Documenter toutes les conventions de code, de nommage, de commit, de doc, de tests
    - Ajouter des exemples concrets pour chaque convention
    - Mettre en place une section "contribution" claire pour les nouveaux arrivants
    - Synchroniser la roadmap entre README, guides et tickets de suivi
  - **Maintenance à prévoir :**
    - Mise à jour de la roadmap à chaque release ou changement de cap
    - Vérification de la cohérence entre conventions, code et doc
    - Audit régulier de la clarté et de l'accessibilité des conventions

---

## 3. Amélioration de la structure

### Créer une classe de base pour les plugins : `AbstractPlugin` dans `src/Core/`

- **En place :**
  - Plugins actuels héritent d'une structure commune mais sans classe de base formelle.
  - Quelques méthodes utilitaires (log, accès config) dupliquées dans plusieurs plugins.
  - Convention implicite sur certaines méthodes (init, register, etc.).
- **Améliorations à faire :**
  - Créer une classe abstraite `AbstractPlugin` dans `src/Core/` centralisant les méthodes communes (log, accès config, hooks de cycle de vie).
  - Faire hériter tous les plugins de cette classe pour garantir la cohérence et réduire la duplication.
  - Définir des méthodes abstraites obligatoires (ex : `registerRoutes`, `getMetadata`).
  - Documenter l'usage et les conventions de la classe de base dans les guides de contribution.
  - Ajouter des tests unitaires sur le comportement de base (log, hooks, erreurs).
- **Maintenance à prévoir :**
  - Mettre à jour la classe de base à chaque évolution du cycle de vie des plugins.
  - Vérifier la compatibilité des plugins existants à chaque refactoring.
  - Documenter toute nouvelle méthode ou convention dans la doc technique.

### Supprimer les exclusions manuelles dans PluginManager : se baser uniquement sur la présence de `plugin.json`

- **En place :**
  - Système de détection des plugins basé sur la présence de `plugin.json` dans chaque dossier.
  - Exclusions manuelles de certains dossiers (auth_groups, Core) encore présentes dans `PluginManager`.
  - Documentation partielle de la logique d'exclusion dans le code.
- **Améliorations à faire :**
  - Supprimer toutes les exclusions hardcodées dans la méthode de scan des plugins.
  - Se baser uniquement sur la présence de `plugin.json` pour inclure un dossier comme plugin.
  - Ajouter des tests pour garantir qu'aucun plugin valide n'est ignoré et qu'aucun faux positif n'est inclus.
  - Documenter la convention : tout dossier sans `plugin.json` est ignoré automatiquement.
  - Nettoyer le code pour éviter toute confusion sur la logique d'exclusion.
- **Maintenance à prévoir :**
  - Vérification à chaque ajout de module/dossier que la logique d'inclusion/exclusion reste cohérente.
  - Mise à jour de la documentation si la convention évolue.
  - Audit régulier du système de plugins pour éviter les régressions.

### Factoriser les handlers de routes : éviter la duplication dans les plugins

- **En place :**
  - Certains plugins définissent leurs propres handlers de routes, parfois avec duplication de logique.
  - Quelques handlers factorisés dans des classes utilitaires ou des traits.
  - Convention partielle sur la déclaration des routes dans les plugins.
- **Améliorations à faire :**
  - Identifier et extraire les handlers redondants dans une base commune (classe ou trait partagé).
  - Uniformiser la déclaration des routes dans tous les plugins (ex : méthode `registerRoutes`).
  - Documenter les patterns recommandés pour la factorisation des handlers.
  - Ajouter des exemples de factorisation dans les guides de contribution.
  - Mettre en place des tests de non-régression sur les handlers factorisés.
- **Maintenance à prévoir :**
  - Audit régulier pour détecter toute nouvelle duplication de handler.
  - Mise à jour de la base commune à chaque évolution des besoins métiers.
  - Documentation à jour des patterns de factorisation.

### Séparer les migrations par module : chaque module doit avoir son dossier de migrations

- **En place :**
  - Dossiers de migrations déjà présents pour la plupart des modules (core, ics, pomo, quiz, puzzle).
  - Fichiers de migration nommés avec date et description.
  - Procédures de création de tables centralisées pour certains modules.
- **Améliorations à faire :**
  - Vérifier que chaque module dispose bien de son propre dossier de migrations.
  - Uniformiser le format et le nommage des fichiers de migration.
  - Documenter chaque migration dans le guide du module correspondant.
  - Générer un fichier build complet de la base à chaque release majeure.
  - Ajouter des tests d'intégrité après migration (vérification schéma, contraintes).
  - Mettre en place une convention stricte de versionning des migrations.
- **Maintenance à prévoir :**
  - Audit régulier des migrations (cohérence, documentation, application réelle).
  - Mise à jour de la doc à chaque nouvelle migration.
  - Nettoyage des anciennes migrations inutiles après consolidation dans un build complet.

### Centraliser la configuration : `.env`, `environment.php`, composer.json

- **En place :**
  - Utilisation de `.env` pour les variables d'environnement critiques (chemins, clés, DB).
  - Fichier `environment.php` centralisant certains chemins et paramètres.
  - `composer.json` utilisé pour la gestion des dépendances et l'autoloading.
- **Améliorations à faire :**
  - Centraliser toutes les variables de configuration dans `.env` et `environment.php` (plus d'accès direct à $_ENV dans le code).
  - Documenter chaque variable de configuration (nom, usage, valeur par défaut) dans un guide dédié.
  - Ajouter des vérifications au démarrage pour détecter les variables manquantes ou incohérentes.
  - Permettre la configuration dynamique via une interface admin ou un script d'init.
  - Sécuriser l'accès aux variables sensibles (droits, permissions, non-exposition publique).
  - Synchroniser la documentation entre `.env`, `environment.php` et `composer.json`.
- **Maintenance à prévoir :**
  - Audit régulier des variables utilisées dans le code (nouveaux modules, migrations…).
  - Mise à jour de la doc à chaque ajout/modification de variable.
  - Vérification de la cohérence entre `.env`, `environment.php`, `composer.json` et la documentation.

---

## 4. Modules open source recommandés

### sabre/vobject : gestion avancée des calendriers ICS/CalDAV

- **En place :**
  - Utilisation de sabre/vobject pour le parsing, la génération et la validation des fichiers ICS (calendriers, événements, tâches).
  - Intégration dans les modules liés à l'import/export de calendriers (ics, core).
  - Support des formats iCalendar (VCALENDAR, VEVENT, VTODO) via l'API sabre/vobject.
  - Quelques helpers pour convertir les objets sabre/vobject en tableaux PHP ou objets métier.
  - Tests unitaires sur certains cas d'import/export ICS.
- **Améliorations à faire :**
  - Uniformiser l'utilisation de sabre/vobject dans tous les modules manipulant des calendriers (éviter le code custom ou legacy).
  - Ajouter des helpers/documentation pour la création, la modification et la validation d'événements complexes (récurrences, exceptions, alarmes).
  - Couvrir tous les cas d'erreur et de compatibilité (timezone, encodage, propriétés non standard).
  - Mettre en place des tests d'intégration sur les flux complets (import/export, synchronisation CalDAV).
  - Documenter les bonnes pratiques d'utilisation de sabre/vobject dans les guides techniques (exemples, pièges à éviter).
  - Prévoir une abstraction pour faciliter le remplacement ou l'évolution de la librairie si besoin.
- **Maintenance à prévoir :**
  - Suivi des mises à jour de sabre/vobject (corrections de sécurité, évolutions de standard iCalendar).
  - Mise à jour des helpers et de la doc à chaque évolution de la librairie ou du format ICS.
  - Audit régulier de la couverture des tests sur les cas calendaires complexes.
  - Vérification de la compatibilité avec les clients externes (Google, Outlook, Apple Calendar…).

### phpunit/phpunit : tests unitaires et de régression

- **En place :**
  - Utilisation de phpunit pour les tests unitaires sur les modèles principaux, la validation et certains endpoints critiques.
  - Fichier phpunit.xml configuré à la racine du projet.
  - Quelques suites de tests automatisés lancées avant les releases majeures.
  - Intégration partielle dans le workflow de développement (tests manuels ou via scripts).
- **Améliorations à faire :**
  - Étendre la couverture des tests à tous les modules, plugins et endpoints (y compris cas limites et erreurs).
  - Automatiser l'exécution des tests (CI, pre-commit hook, scripts dédiés).
  - Ajouter des tests de non-régression systématiques après chaque refactoring ou ajout majeur.
  - Documenter la structure des tests et les conventions à suivre (nommage, organisation, mocks).
  - Générer des rapports de couverture et suivre leur évolution.
- **Maintenance à prévoir :**
  - Mise à jour des tests à chaque évolution du code ou ajout de fonctionnalité.
  - Audit régulier de la pertinence et de la clarté des tests.
  - Vérification de la compatibilité avec les nouvelles versions de phpunit.

### doctrine/orm ou illuminate/database : ORM pour simplifier la gestion des modèles (optionnel, selon roadmap)

- **En place :**
  - Utilisation actuelle de modèles maison (BaseModel, User, Group, etc.) sans ORM externe.
  - Quelques helpers pour l'accès à la base et le mapping objet-table.
  - Expérimentation ponctuelle de doctrine/orm sur des branches de test (non mergé en production).
- **Améliorations à faire :**
  - Évaluer l'intégration d'un ORM (doctrine/orm ou illuminate/database) pour les nouveaux modules ou lors d'une refonte majeure.
  - Préparer la migration progressive des modèles existants vers l'ORM choisi (mapping, entités, repository).
  - Adapter les migrations SQL et la gestion des schémas à l'ORM.
  - Former l'équipe aux bonnes pratiques de l'ORM retenu.
  - Documenter la stratégie de migration et les impacts sur le code existant.
- **Maintenance à prévoir :**
  - Suivi des mises à jour de l'ORM et de ses dépendances.
  - Audit régulier de la cohérence entre les entités ORM et la base réelle.
  - Mise à jour de la doc et des scripts de migration à chaque évolution.

### monolog/monolog : logging avancé, si besoin d'aller au-delà de LogService

- **En place :**
  - Logging centralisé via LogService maison (AuthGroups), logs applicatifs dans des fichiers ou stdout.
  - Utilisation ponctuelle de monolog/monolog sur des projets annexes ou en test.
  - Quelques hooks pour intégrer d'autres cibles de logs (syslog, Sentry).
- **Améliorations à faire :**
  - Intégrer monolog/monolog comme backend principal ou optionnel pour la gestion avancée des logs.
  - Uniformiser l'appel aux logs dans tous les modules/plugins via une interface commune.
  - Configurer plusieurs handlers (fichier, mail, syslog, Sentry, etc.) selon les besoins.
  - Documenter la configuration et l'utilisation de monolog dans le projet.
  - Ajouter des tests sur la bonne remontée des logs critiques.
- **Maintenance à prévoir :**
  - Suivi des mises à jour de monolog et de ses extensions.
  - Vérification régulière de la cohérence et de la pertinence des logs produits.
  - Mise à jour de la doc à chaque évolution de la politique de log.

### friendsofphp/php-cs-fixer : normalisation du code PHP

- **En place :**
  - Utilisation ponctuelle de php-cs-fixer pour le formatage du code avant certaines releases.
  - Fichier de configuration partiel ou par défaut dans le projet.
  - Application manuelle ou via script sur certains dossiers (src/, tests/).
- **Améliorations à faire :**
  - Mettre en place une configuration stricte et partagée de php-cs-fixer (règles, exclusions, conventions).
  - Automatiser le lint et le formatage du code (CI, pre-commit hook, scripts).
  - Documenter les règles de style et la procédure de formatage dans la doc de contribution.
  - Former l'équipe à l'utilisation de l'outil et à la résolution des conflits de style.
- **Maintenance à prévoir :**
  - Mise à jour de la config à chaque évolution des conventions de code.
  - Vérification régulière de la cohérence du code (audit, CI).
  - Suivi des mises à jour de php-cs-fixer et adaptation des règles si besoin.

### phpstan/phpstan ou vimeo/psalm : analyse statique pour détecter bugs et incohérences

- **En place :**
  - Utilisation ponctuelle de phpstan ou psalm sur certains modules pour détecter des bugs ou incohérences.
  - Quelques corrections appliquées suite aux rapports d'analyse statique.
  - Pas d'intégration systématique dans le workflow de développement.
- **Améliorations à faire :**
  - Intégrer phpstan ou psalm dans le pipeline CI pour une analyse régulière.
  - Augmenter progressivement le niveau de stricteté et la couverture des analyses.
  - Documenter les règles, les niveaux et les exceptions dans la doc technique.
  - Former l'équipe à l'interprétation des rapports et à la correction des erreurs signalées.
  - Suivre les évolutions des outils et adapter la configuration.
- **Maintenance à prévoir :**
  - Mise à jour régulière des outils et de leur configuration.
  - Audit périodique de la couverture et de la pertinence des analyses.
  - Correction continue des bugs et incohérences détectés.

---

## 5. Phases détaillées pour l'application du plan

### Phase 0 — Sécurité, validation et modèles critiques (PRIORITÉ ABSOLUE)

- **Sécurisation JWT et authentification**
  - Auditer la gestion actuelle des JWT (blacklist, refresh, device token, algorithme, stockage clé secrète)
  - Mettre en place la purge automatique de la blacklist (cron/script)
  - Ajouter la journalisation des accès refusés (JWT invalide/expiré) et alertes sécurité
  - Créer un endpoint pour lister/révoquer toutes les sessions d'un utilisateur
  - Implémenter le refresh token rotatif et l'invalidation automatique
  - Préparer l'option 2FA sur endpoints critiques (spécification, POC)
  - Écrire des tests automatisés de sécurité (fuzzing, injection, replay)
  - Mettre en place le monitoring du taux d'échec d'authentification
  - Documenter tous les flux d'authentification et cas d'erreur

- **Validation centralisée**
  - Unifier la gestion des erreurs de validation sur tous les modules (audit, refactoring)
  - Ajouter la validation côté modèle (BaseModel) pour les entrées critiques
  - Enrichir les messages d'erreur (champ, règle violée, valeur reçue)
  - Mettre en place la validation conditionnelle et des types complexes
  - Ajouter des schémas de validation JSON pour les endpoints publics
  - Centraliser la documentation des règles de validation (auto-génération si possible)
  - Couvrir tous les cas de validation par des tests automatisés

- **Refactorisation des modèles critiques**
  - Supprimer toutes les propriétés statiques inutiles dans les modèles
  - Uniformiser la structure des modèles (constructeur, mapping, validation)
  - Factoriser les méthodes redondantes (mapping, validation, save/update)
  - Ajouter des getters/setters typés pour chaque champ critique
  - Documenter chaque modèle (propriétés, méthodes, contraintes)
  - Préparer la compatibilité avec un ORM (doctrine/orm ou illuminate/database)
  - Couvrir tous les modèles par des tests unitaires et de régression

### Phase 1 — Refactoring structurel et plugins

- **Classe de base AbstractPlugin**
  - Créer la classe abstraite `AbstractPlugin` dans `src/Core/` (log, config, hooks)
  - Refactoriser tous les plugins pour hériter de cette classe
  - Définir les méthodes obligatoires (`registerRoutes`, `getMetadata`)
  - Ajouter des tests unitaires sur le comportement de base
  - Documenter l'usage et les conventions dans les guides de contribution

- **Suppression des exclusions manuelles dans PluginManager**
  - Supprimer toutes les exclusions hardcodées dans `scanPluginDirectories()`
  - Se baser uniquement sur la présence de `plugin.json` pour détecter un plugin
  - Ajouter des tests pour garantir qu'aucun plugin valide n'est ignoré
  - Documenter la convention d'inclusion/exclusion

- **Factorisation des handlers de routes**
  - Identifier et extraire les handlers redondants dans une base commune (classe ou trait partagé)
  - Uniformiser la déclaration des routes dans tous les plugins (`registerRoutes`)
  - Ajouter des exemples de factorisation dans les guides
  - Mettre en place des tests de non-régression sur les handlers factorisés

- **Centralisation des logs**
  - Intégrer la gestion des logs dans AbstractPlugin
  - Uniformiser l'appel aux logs dans tous les modules/plugins
  - Ajouter la configuration du niveau de log via .env/config
  - Prévoir la possibilité de loguer vers plusieurs cibles (fichier, stdout, syslog, Sentry)
  - Documenter la politique de log et les bonnes pratiques

### Phase 2 — Documentation, tests et migrations

- **Documentation complète et uniformisée**
  - Compléter chaque guide de module (structure, endpoints, exemples, erreurs, migrations)
  - Uniformiser la présentation (titres, tables, navigation, schémas)
  - Générer automatiquement une partie de la doc à partir du code si possible
  - Ajouter une section FAQ et bonnes pratiques par module
  - Mettre à jour la doc à chaque ajout ou modification d'endpoint/migration

- **Exemples d'intégration et de migration**
  - Ajouter des exemples d'intégration pour chaque endpoint critique (auth, création, suppression, update)
  - Couvrir les cas de succès, d'erreur courante et inattendue
  - Ajouter des exemples multi-modules (ex : création + ajout à un groupe)
  - Documenter les prérequis pour chaque exemple (auth, permissions, données)
  - Référencer chaque fichier SQL de migration dans le guide du module concerné
  - Ajouter une table récapitulative des migrations (nom, date, description, impact, rollback)

- **Tests de régression systématiques**
  - Étendre la couverture des tests à tous les modules, plugins et endpoints
  - Automatiser l'exécution des tests (CI, pre-commit hook, scripts)
  - Générer des rapports de couverture et suivre leur évolution
  - Mettre à jour les tests à chaque évolution du code

- **Nettoyage et gestion des migrations SQL**
  - Vérifier que chaque module dispose de son dossier de migrations
  - Uniformiser le format et le nommage des fichiers de migration
  - Générer un fichier build complet de la base à chaque release majeure
  - Ajouter des tests d'intégrité après migration
  - Mettre en place une convention stricte de versionning des migrations

### Phase 3 — Améliorations secondaires et optimisation

- **Pagination enrichie**
  - Implémenter la pagination sur tous les endpoints listant des ressources
  - Documenter les paramètres et le format de réponse

- **Normalisation des réponses**
  - Uniformiser le format JSON, les codes HTTP, les schémas de validation
  - Ajouter des tests de conformité sur les réponses API

- **Pipeline middleware**
  - Refactoriser le pipeline pour le rendre plus modulaire/extensible (pattern Pipeline/Chain of Responsibility)
  - Permettre l'ajout/retrait dynamique de middlewares
  - Uniformiser l'interface des middlewares
  - Ajouter la gestion des middlewares asynchrones/conditionnels
  - Documenter la liste et l'ordre d'exécution

- **Externalisation et centralisation de la configuration**
  - Centraliser toutes les variables de configuration dans `.env` et `environment.php`
  - Documenter chaque variable (nom, usage, valeur par défaut)
  - Ajouter des vérifications au démarrage pour détecter les variables manquantes/incohérentes

- **Intégration de modules open source complémentaires**
  - Intégrer et configurer phpunit, monolog, php-cs-fixer, phpstan/psalm, doctrine/orm selon les besoins
  - Documenter leur usage et leur configuration
  - Former l'équipe à leur utilisation

---
