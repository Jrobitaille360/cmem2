# Changelog

Toutes les modifications notables de ce projet sont documentées ici.

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
Versioning : [Semantic Versioning](https://semver.org/lang/fr/)

---

## [2.2.2] — 2026-04-02

### Nouveau plugin — Pomo Phase 0 (prérequis système)

- **`src/Core/AbstractPlugin.php`** — classe de base pour tous les plugins
  - Centralise `safeLog()` (supprimé de `PluginManager` et `CalendarPlugin`)
  - Defaults : `deactivate(): void {}`, `getDependencies(): array { return []; }`
  - Hook `runMigrations(string $path): void` (vide — à surcharger)
- **`src/ics/CalendarPlugin.php`** — hérite désormais de `AbstractPlugin`
- **`src/Core/PluginManager.php`** — `scanPluginDirectories()` utilise uniquement la présence de `plugin.json` comme critère

### Nouveau plugin — Pomo Phase 1A (engagement MVP)

Endpoint public `POST /pomo/engagement` — waitlist (courriel) et sondage (5 questions).

- **`src/pomo/plugin.json`** — déclaration du plugin (namespace `Pomo`, main_class `Pomo\PomoPlugin`)
- **`src/pomo/autoloader.php`** — chargeur PSR-4 pour le namespace `Pomo\`
- **`composer.json`** — ajout `"Pomo\\": "src/pomo/"` dans l'autoload PSR-4
- **`src/pomo/PomoPlugin.php`** — hérite `AbstractPlugin`, enregistre `pomo` → `PomoRouteHandler`
- **`src/pomo/Routing/PomoRouteHandler.php`** — handler unique `/pomo/*`, auth conditionnelle par sous-route
- **`src/pomo/Controllers/EngagementController.php`** — dispatch interne par `type` (waitlist / survey)
- **`src/pomo/Models/Engagement.php`** — accès table `pomo_engagements` (emailExists, createWaitlist, createSurvey)
- **`src/pomo/Validators/EngagementValidator.php`** — validation courriel (waitlist) + 5 réponses yes|no|maybe (survey)
- **`src/pomo/migrations/001_pomo_engagement.sql`** — table `pomo_engagements`

#### Comportement

| Cas | HTTP |
| --- | ---- |
| `type=waitlist` — succès | 201 `{success: true, data: {reference_id}}` |
| `type=waitlist` — doublon courriel | 409 |
| `type=survey` — succès | 201 `{success: true, data: {reference_id}}` |
| Validation échouée | 422 `{success: false, errors: [{field, code, message}]}` |
| `GET /health` | 200 (non impacté — core) |

### Documentation

- **`docs/pomo/API_POMO_ENDPOINTS_v1_0_0.json`** — documentation complète des endpoints Pomo (Ph1A–Ph3)

---

## [2.2.1] — 2026-04-01

### Plugin ICS — Phase 2 (Propriétés VEVENT enrichies)

- **[2.1]** `CATEGORIES` — tableau de chaînes, sérialisé `CATEGORIES:Travail,Réunion` dans l'ICS
- **[2.2]** `PRIORITY` — entier 0–9 (0 = non défini, 1 = haute, 9 = basse), propriété RFC 5545 `PRIORITY`
- **[2.3]** `CLASS` — `PUBLIC` | `PRIVATE` | `CONFIDENTIAL`, propriété `CLASS`
- **[2.4]** `TRANSP` — `OPAQUE` | `TRANSPARENT`, propriété `TRANSP`
- **[2.5]** `GEO` — latitude/longitude WGS84 (`geo_lat`, `geo_lng`), propriété `GEO:lat;lng`
  - Les deux champs doivent être fournis ensemble — fournir l'un sans l'autre retourne `400`
- **[2.6]** `ATTACH` — tableau d'objets `{url}` ou `{data_base64}` avec `mime_type` optionnel,
  propriété `ATTACH;FMTTYPE=…:…` pour URL, `ATTACH;ENCODING=BASE64;…` pour données inline

Tous ces champs sont optionnels, rétrocompatibles, et disponibles sur :
`POST /calendars/{id}/events`, `PUT /calendars/{id}/events/{eventId}`,
`GET /calendars/{id}/events/{eventId}`, `GET /calendars/{id}/ics`, import ICS

### Plugin ICS — Phase 3 (ATTENDEE, ORGANIZER, iTIP)

- **[3.1]** `ATTENDEE` complet — champs `email`, `name`, `role`, `partstat`, `rsvp`, `cutype`
  - Export : `ATTENDEE;CN=…;ROLE=…;PARTSTAT=…;RSVP=…:mailto:…`
  - Import sabre/vobject
- **[3.2]** `ORGANIZER` — colonnes `organizer_email` / `organizer_name`
  - Export : `ORGANIZER;CN=Nom:mailto:email@ex.com`
- **[3.3]** iTIP — `METHOD:REQUEST` à la création, `METHOD:CANCEL` pour annulations
  - Endpoint `POST /notifications/attendee-reply` (PARTSTAT : ACCEPTED / DECLINED / TENTATIVE)
- **[3.4]** Email d'invitation avec pièce jointe `.ics` (PHPMailer multipart/mixed, `Content-Type: text/calendar; method=REQUEST`)

### Plugin ICS — Phase 4 (Récurrence avancée & VALARM)

- **[4.1]** `EXDATE` — exceptions de récurrence dérivées de `event_occurrences.is_cancelled = 1`
  - Export : `EXDATE;TZID=…:datetime,datetime`
  - Import : crée des occurrences annulées correspondantes
- **[4.2]** `RDATE` — dates additionnelles (colonne `rdate TEXT`, CSV de datetimes locales)
  - Export / import ; génère des `event_occurrences` supplémentaires
- **[4.3]** `RELATED-TO` — colonne `related_to VARCHAR(255)` (UID parent)
  - Export : `RELATED-TO;RELTYPE=PARENT:<uid>`
- **[4.4]** `VALARM` — export automatique depuis le champ `notifications` existant
  - `ACTION:DISPLAY` / `ACTION:EMAIL`, `TRIGGER:-PT{n}M`, `DESCRIPTION:Rappel`
  - Aucune colonne supplémentaire requise
- **[4.5]** `DURATION` vs `DTEND` — colonne `duration VARCHAR(20)` format ISO 8601 (ex. `PT1H30M`)
  - Si `duration` défini → export `DURATION:…` (sans `DTEND`)
  - Import : calcule `end_datetime` depuis `DTSTART + DURATION`
  - `duration` et `end_datetime` sont mutuellement exclusifs (retourne `400` si les deux sont fournis)

### Plugin ICS — Phase 5 (Composants CalDAV additionnels)

- **[5.1]** `VTODO` — nouvelle table `calendar_todos`
  - CRUD : `POST/GET/PUT/DELETE /calendars/{id}/todos[/{todoId}]`
  - Champs : `title`, `description`, `due`, `dtstart`, `status`, `priority`, `percent_complete`,
    `location`, `categories`, `url`, `timezone`
  - Export dans `GET /calendars/{id}/ics` comme composant `BEGIN:VTODO`
- **[5.2]** `VJOURNAL` — nouvelle table `calendar_journals`
  - CRUD : `POST/GET/PUT/DELETE /calendars/{id}/journals[/{journalId}]`
  - Champs : `summary`, `description`, `dtstart`, `status` (DRAFT/FINAL/CANCELLED), `categories`, `url`
  - Export dans `GET /calendars/{id}/ics` comme composant `BEGIN:VJOURNAL`
- **[5.3]** `VFREEBUSY` — endpoint `GET /calendars/{id}/freebusy?start=…&end=…`
  - Agrège les événements `TRANSP=OPAQUE` → retourne les plages occupées
  - Exposé également via `REPORT` CalDAV
  - Nécessite Phase 2.4 (`TRANSP`) complété

### Migrations DB

Exécuter dans l'ordre :

1. `docs/docs_ICS/migrations/20260331_ph2_vevent_props.sql` (Ph2 — 7 colonnes `calendar_events`)
2. `docs/docs_ICS/migrations/20260401_ph3_organizer.sql` (Ph3 — 2 colonnes `calendar_events`)
3. `docs/docs_ICS/migrations/20260401_ph4_recurrence.sql` (Ph4 — colonnes `rdate`, `related_to`, `duration`)
4. `docs/docs_ICS/migrations/20260401_ph5_components.sql` (Ph5 — tables `calendar_todos`, `calendar_journals`)

### Documentation

- `docs/2.2.1_CLIENT.md` — guide migration client (aucun changement cassant)
- `docs/2.2.1_PRODUCTION.md` — procédure déploiement production
- `docs/core/API_ENDPOINTS_v2_0_0.json` — mis à jour (Ph2–Ph5 : nouveaux champs + VTODO/VJOURNAL/VFREEBUSY)
- `docs/docs_ICS/API_ICS_ENDPOINTS_v1_0_0.json` — mis à jour (Ph3–Ph5 complets)

---

## [2.2.0] — 2026-03-30

### Sécurité

- **Anti-énumération** — `POST /auth/resend-verification` et `POST /users/password-change`
  retournent désormais `200` avec message générique quelle que soit l'existence ou l'état du compte
  (protège contre l'énumération d'adresses email)

### Nouvelles routes

- `GET /plans/{id}` — détails d'un plan spécifique (public, non authentifié)
- `GET /stats/my-stats` — statistiques de l'utilisateur connecté (tout rôle authentifié)
  → route existante, maintenant câblée sur une méthode dédiée (séparée de `GET /stats/users/{id}`)
- `GET /secret-admin/plugins` — liste des plugins chargés, maintenant sécurisée avec `admin_secret`
  (refactorisé : retiré de `PluginController` isolé, intégré dans `SecretAdminController`)

### Normalisation des réponses

- **Fichiers** — `POST /files` : réponse enveloppée dans `{ file: { id, name, … } }` (champ `file_id` → `id`)
  `GET /files/{id}/info` : clé `data` → `file`
  `GET /files/user/{id}` : champ `file_id` → `id` dans la liste
- **Utilisateurs** — `GET /users/me` et `GET /users/{id}` : clé `data` → `user`
  `DELETE /users/me`, `DELETE /users/{id}` : réponse `{ deleted: true }` (était message texte)
  `POST /users/{id}/restore` : réponse `{ restored: true }`
- **Groupes** — `PUT /groups/{id}`, `DELETE /groups/{id}`, `POST /groups/{id}/restore`,
  `POST /groups/{id}/leave` : réponses incluent maintenant `{ group_id }`
  `PUT /groups/{id}/members/{user_id}` : réponse inclut `{ group_id, user_id }`
- **Tags** — `DELETE /tags/{id}`, `POST /tags/{id}/restore` : réponses incluent `{ tag_id }`
  `PUT /tags/{tag_id}/{item_id}` : body `action` supprimé, paramètre renommé `table_associate` (était `table`)
- **Apps** — `DELETE /users/app/{app_id}` : réponse `{ deleted: true }`
- **Plans** — `GET /plans` : supporte maintenant pagination (`page`, `limit`) et filtre `active`

### Correctifs

- `GET /stats/users/{id}` — accès restreint aux administrateurs uniquement
  (auparavant, un utilisateur pouvait consulter ses propres stats via cette route — désormais via `my-stats`)
- `CalendarController` — `updateCalendar()` et `deleteCalendar()` retournent `404` si le calendrier n'existe pas,
  avant même la vérification des permissions (ordre corrigé)
- `CalendarController::hardDeleteCalendar()` — utilise `isOwner($id, $userId, includingDeleted: true)`
  pour distinguer correctement `404` (inexistant) de `403` (pas propriétaire), y compris sur soft-deleted
- `Calendar::create()` — le champ `title` est maintenant inclus dans la réponse de création

### Refactorisation

- `Calendar::isOwner()` — paramètre optionnel `$includingDeleted = false` remplace la méthode
  `isOwnerIncludingDeleted()` séparée (rétrocompatible)
- `StatsController::getMyStats()` — requête SQL simplifiée (`ORDER BY generated_at DESC LIMIT 1`
  remplace la sous-requête corélée avec double bind)
- `.gitignore` — remplace les multiples entrées `.env.*` par `private/` (répertoire de données privées)

### Documentation

- `docs/core/API_ENDPOINTS_v2_0_0.json` — mise à jour complète :
  réponses détaillées (schemas JSON), codes HTTP, contraintes de validation,
  champs `query` / `body` / `params` enrichis pour tous les modules

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
