# Changelog

Toutes les modifications notables de ce projet sont documentées ici.

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
Versioning : [Semantic Versioning](https://semver.org/lang/fr/)

---

## [2.1.1] — 2026-03-27

### Plugin ICS — Phase 1 (Fondations iCal)

- **[1.1]** Intégration `sabre/vobject` — remplacement des parseurs iCal manuels
  - Wrappers centralisés autour de `Sabre\VObject\Component\VCalendar`
  - Génération et parsing d'événements via la librairie de référence PHP CalDAV
  - Prérequis de toutes les phases ICS suivantes

- **[1.3]** UID stable RFC-conforme (UUID v4)
  - `uid` généré une seule fois à la création, jamais modifié lors des mises à jour
  - Garantit la fiabilité de la synchronisation CalDAV avec les clients externes

- **[1.4]** `DTSTART` avec paramètre `TZID`
  - Format `DTSTART;TZID=America/Montreal:20260101T090000` conforme RFC 5545
  - Les événements exportés incluent le fuseau horaire explicitement

- **[1.2]** Line folding RFC 5545 §3.1
  - `sabre/vobject` gère automatiquement le repliement à 75 octets/ligne
  - Vérifié compatible avec Google Calendar et Apple Calendar

### Nettoyage — Retrait du système de clés API

> Le système de clés API (`api_keys`) est entièrement retiré. L'authentification
> est désormais exclusivement par JWT (`POST /auth/login`, `POST /auth/verify-code`).

#### Fichiers supprimés

- `src/auth_groups/Middleware/ApiKeyAuthMiddleware.php`
- `src/auth_groups/Controllers/SecretApiKeyController.php`
- `src/auth_groups/Models/ApiKey.php`

#### Code retiré

- `UserManagerController::authenticate()` — login par API key (mort depuis v2.0.0)
- `UserController::authenticate()` et `UserController::logout()` — délégations orphelines
- `POST /users/logout` — route supprimée depuis v2.0.0 mais encore présente dans `UserRouteHandler`
- `UserSessionService::updateActivity()` — méthode morte (jamais appelée)
- `UserSessionService::endSession(?int $apiKeyId)` — paramètre `api_key_id` retiré

#### Base de données (`build_cmem2_DB.sql`)

- Table `api_keys` supprimée
- Colonne `api_key_id` retirée de `user_sessions`
- Vue `active_user_sessions` reconstruite sans JOIN `api_keys`
- Procédure `cleanup_expired_api_keys` retirée
- Contraintes FK `fk_api_keys_*` retirées

#### Divers

- `index.php` — `X-API-Key` retiré de `Access-Control-Allow-Headers`
- `restore_data.php` — `api_keys`, `login_codes`, `user_plan_history` retirés de la liste de restauration
- `GET /help` — section `api-keys` retirée

### Intégration routes — Sessions utilisateur et plugins

- `GET /users/{id}/sessions` — sessions actives d'un utilisateur (self ou admin)
- `DELETE /users/{id}/sessions` — terminer toutes les sessions (self ou admin)
- `GET /users/{id}/session-status` — vérifier si session active (self ou admin)
- `GET /stats/online` — statistiques sessions actives (admin)
- `POST /stats/cleanup-sessions` — purge des sessions expirées (admin)
- `GET /secret-admin/plugins` — liste des plugins chargés (admin)

> Ces routes existaient dans `UserSessionController` et `PluginController`
> mais n'étaient pas câblées dans les route handlers. Intégrées dans
> `UserRouteHandler`, `StatsRouteHandler` et `SecretAdminRouteHandler`.

### Migration DB (production existante)

```sql
-- Supprimer la colonne api_key_id de user_sessions
ALTER TABLE `user_sessions`
  DROP FOREIGN KEY `fk_user_sessions_api_key`,
  DROP KEY `idx_api_key_id`,
  DROP COLUMN `api_key_id`;

-- Supprimer la table api_keys
DROP TABLE IF EXISTS `api_keys`;
```

---

## [2.1.0] — 2026-03-26

> Plan complet : `docs/cmem2_Plan_Complet_Ph0-5.md`

### Sécurité

- **[A4]** Fix CORS — `Response::setCorsHeaders()` mis à jour
  - `Access-Control-Allow-Methods` : ajout de `PATCH` et `HEAD`
  - `Access-Control-Allow-Headers` : ajout de `X-API-Key`

- **[A3]** Rotation du device token à chaque `POST /auth/refresh` réussi
  - L'ancien token est révoqué, un nouveau est généré et retourné dans la réponse
  - Le client doit remplacer son `device_token` par la nouvelle valeur retournée
  - Intégration dans `AuthController::refresh()`

- **[A2]** Rate limiting — `POST /auth/login` et `POST /auth/send-code`
  - 5 tentatives max / 10 min par couple email+IP → `429 Too Many Requests`
  - Nouvelle table `login_attempts` — migration : `src/auth_groups/docs/20260325_A2_login_attempts.sql`
  - Nouveau service `RateLimitService` : `check()`, `record()`, `clear()`, `deleteExpired()`
  - Login réussi efface le compteur ; send-code enregistre chaque appel (anti-bombing)
  - Configurable : `RATE_LIMIT_AUTH_MAX_ATTEMPTS` (défaut 5), `RATE_LIMIT_AUTH_WINDOW_MINUTES` (défaut 10)

- **[A1]** Blacklist JWT — ajout du claim `jti` (UUID v4) dans chaque token généré
  - Nouvelle table `jwt_blacklist` — migration : `src/auth_groups/docs/20260325_A1_jwt_blacklist.sql`
  - `POST /auth/logout` révoque maintenant le token côté serveur (plus seulement côté client)
  - `JwtService::validate()` vérifie la blacklist à chaque requête authentifiée
  - Nouveau modèle `JwtBlacklist` : `add()`, `isBlacklisted()`, `deleteExpired()`

### Correctifs

- **[C1]** Fix contamination `self::$errors` entre appels dans `Validator`
  - Propriété statique `$errors` supprimée — variable locale `$errors` passée par référence
  - Chaque appel à `validate()` est désormais entièrement isolé
  - `applyRule()` et `addError()` reçoivent `array &$errors` en paramètre

- **[C2]** Fix règle `required` — remplace `empty()` par `!isset($value) || $value === ''`
  - `0`, `'0'`, `false` sont maintenant acceptés comme valeurs valides
  - Seuls `null` et `''` (chaîne vide) déclenchent l'erreur

- **[C3]** Fix `Response::error(array, 429)` dans `ApiKeyAuthMiddleware::authenticate()`
  - Premier argument corrigé : `array` → `string` (message)
  - Détails déplacés en second argument, `429` en troisième

### Refactoring

- **[B1]** `static $db` → propriété d'instance dans `BaseModel`
  - Supprime le slot statique partagé entre sous-classes
  - `__construct()` assigne directement `$this->db` via le singleton
  - `getDb()` retourne `$this->db` sans condition

- **[B2]** Refactor `User::findById()` / `findByEmail()` via `mapFromArray()`
  - 26 lignes d'affectation manuelle remplacées par `$this->mapFromArray($data)`

- **[B3]** Fusion `Group::create()` + `create2()` en transaction unique
  - `create()` intègre maintenant `beginTransaction()` / `commit()` / `rollBack()`
  - `create2()` assigne les propriétés depuis l'input et délègue à `create()`

- **[B4]** Retrait de `htmlspecialchars` des modèles `User` et `Group`
  - PDO prepared statements protègent déjà contre l'injection
  - Supprimé de `User::create()`, `User::update()`, `Group::create()`, `Group::update()`, `Group::updateGroup()`

- **[C4]** `countFiltered()` + pagination enrichie
  - `User::countFiltered(?string $email)` — total exact selon le filtre actif
  - `Group::countByUserId(int $userId)` et `Group::countPublic(string $search)`
  - Réponses paginées enrichies : `total`, `page`, `per_page`, `total_pages`
  - Contrôleurs mis à jour : `UserListController`, `GroupListController`

- **[E1]** Endpoint `GET /auth/me` — profil utilisateur (JWT requis)
  - `AuthController::me(int $userId)` — retourne le profil sans `password_hash`
  - Route ajoutée dans `AuthRouteHandler`

- **[E2]** Cron nettoyage — `src/cron/cleanup.php`
  - Purge : `otp_codes` (expirés/utilisés), `jwt_blacklist` (expirés), `login_attempts` (périmés)
  - CLI uniquement (`php_sapi_name() !== 'cli'` → 403)
  - Rapport horodaté sur stdout — compatible `crontab >> cron.log`

- **[D1]** Lazy-load des handlers (factory closures) dans `Router`
  - `routeHandlers` contient des `fn() => new XxxHandler()` au lieu d'instances
  - Le handler n'est instancié qu'à la réception d'une requête sur sa route

- **[D2]** `BASE_PATH` externalisé dans `environment.php`
  - Défaut `/cmem2_API`, surchargeable via `$_ENV['BASE_PATH']`
  - `Router::parseRequest()` utilise `BASE_PATH` au lieu de la chaîne littérale

- **[D3]** Suppression du fallback `$GLOBALS['pending_route_handlers']`
  - `loadPluginRouteHandlers()` ne supporte plus que `$GLOBALS['plugin_manager']`
  - Les factories de plugins sont aussi enveloppées en closures lazy

- **[D4]** Pipeline middleware dans `BaseRouteHandler`
  - `getMiddlewares()` : liste de callables surchargeable par les sous-classes
  - `runMiddleware()` : exécution séquentielle ; retourne `false` si interrompue
  - `handle()` délègue au pipeline puis à `handleRoute()`

### Infrastructure / maintenance

- Réorganisation `docs/` — migration de tous les documents dans `/docs/`
  - `cmem2_Plan_Complet_Ph0-5.md`, `2.1.0_PRODUCTION.md`, `2.1.0_CLIENT.md`
  - Sous-dossier `docs/docs_ICS/` pour la documentation du plugin ICS

- Fix chemin migrations ICS dans `CalendarPlugin::runMigrations()`
  - Chemin corrigé : `__DIR__ . '/docs_ICS/migrations/'` → `__DIR__ . '/../../docs/docs_ICS/migrations/'`

- Renommage `.env.auth_groups` → `.env` (fichier de configuration unifié)
  - `.env.example` mis à jour en conséquence
  - `environment.php` et `JwtService.php` mis à jour (`ADMIN_ENDPOINT` → `SECRET_ADMIN_ENDPOINT`)

- Séparation `docs/build_cmem2_DB.sql` — DDL pur uniquement
  - Suppression des vues inutilisées : `active_api_keys`, `api_keys_stats_by_user`,
    `group_statistics`, `v_active_users`, `v_group_dashboard`
  - Suppression des tables orphelines : `user_plan_history`, `login_codes`
  - Extraction des `INSERT users` sensibles dans `docs/seed_users.sql` (ignoré par git)
  - Purge de l'historique git (données sensibles) via `filter-branch`

---

## [2.0.0] — 2026-03-22

> Migration complète de l'authentification par API Key vers JWT Bearer.

### BREAKING CHANGES (depuis v1.x)

- `X-API-Key` supprimé — remplacé par `Authorization: Bearer {jwt}`
- `POST /users/login` supprimé — remplacé par `POST /auth/login`
- `POST /users/logout` supprimé — remplacé par `POST /auth/logout`
- La réponse de `POST /users/register` ne retourne plus `api_key`
- Les API keys ne sont plus créées à l'inscription

### Ajouté

- **Auth JWT** — `POST /auth/login` (email + password → JWT 15 jours)
- **Auth OTP** — `POST /auth/send-code` + `POST /auth/verify-code` (code 6 chiffres par email)
- **Device tokens** — `POST /auth/refresh` pour renouveler un JWT sans re-login
- **Gestion appareils** — `GET /auth/devices`, `DELETE /auth/devices/{device_id}`
- **Notifications email** — `POST /notifications/send-email`
- **Cron logs** — rotation quotidienne, 2 jours de rétention
- Table `otp_codes` — codes OTP hashés (bcrypt), 15 min, 5 tentatives max
- Table `device_tokens` — tokens longue durée associés à un appareil

### Modifié

- `user_sessions.api_key_id` rendu nullable (sessions JWT sans clé associée)
- Algorithme JWT : HS256 (HMAC-SHA256), implémentation pure PHP sans dépendance externe

### Migration DB

Exécuter : `src/auth_groups/docs/MIGRATION_JWT.sql`

---

## [1.x] — Historique

Voir les commits git antérieurs au `2024-06-17`.
