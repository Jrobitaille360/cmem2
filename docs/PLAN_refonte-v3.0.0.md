# Plan de refonte — v3.0.0

## Objectif

Refonte complète de la base de code à partir de zéro. Architecture moderne (PSR-7/11/15, DI, OpenAPI), conventions REST strictes, versioning d'URL, et documentation comme source de vérité.

Portée : code source uniquement. Domaine métier et schéma DB conservés à 90 %.

---

## Phase 0 — Révision de la documentation de l'état actuel

### Ce qui est en place

- `docs/{module}/GUIDE.md` — un guide par module, qualité inégale
- `docs/{module}/API_*_ENDPOINTS.json` — endpoints JSON par module, non synchronisés avec le code
- `docs/v-2-15-0/build_DB-v-2.15.0.sql` — DDL complet v2.15.0 (82 tables)
- Aucune migration pendante dans `docs/` (dernière intégrée : `20260804_traque_roles.sql`, v2.15.0)

### Améliorations à faire

1. **Synchronisation docs ↔ code** — vérifier chaque GUIDE.md et API_*.json face au code v2.15.0 :

   | Module | Fichier doc | À vérifier |
   | - | - | - |
   | core | `docs/docs-api/core/API_ENDPOINTS.json` | 110+ routes totales |
   | ics | `docs/docs-api/ics/API_ICS_ENDPOINTS.json` | Routes CalDAV (PROPFIND, REPORT) |
   | quiz | `docs/docs-api/quiz/API_QUIZ_ENDPOINTS_v1_0_0.json` | Sessions/participants |
   | puzzle | `docs/docs-api/puzzle/API_PUZZLE_ENDPOINTS.json` | Google Play, admin |
   | items | `docs/docs-api/items/API_ITEMS_ENDPOINTS.json` | Access control |
   | pomo | `docs/docs-api/pomo/API_POMO_ENDPOINTS_v1_0_0.json` | Statut actuel du module |
   | contacts | `docs/docs-api/contacts/API_CONTACTS_ENDPOINTS.json` | CRUD + vCard + CRM (interactions, opportunités) |
   | projets | `docs/docs-api/projets/API_PROJETS_ENDPOINTS.json` | Tâches, hiérarchie, export JSON/.ics |

2. **Cartographie DB** — produire `docs/v-3-0-0/SCHEMA_DB.md` :
   - ERD des 82 tables avec relations FK explicites
   - Liste des enums implicites (status, role, visibility, billing_period)
   - Colonnes soft-delete par table

3. **Inventaire des ~121 clés .env** — créer `docs/v-3-0-0/ENV_REFERENCE.md` :
   - Regrouper par domaine fonctionnel
   - Marquer les clés obsolètes ou redondantes (cible : réduction à ~80 clés)

4. **Vérifier `docs/` racine avant le gel** de `build_DB-v-3.0.0.sql` — s'assurer qu'aucune migration `YYYYMMDD_*.sql` pendante n'a été oubliée entre-temps

### Conditions de complétion

- [ ] Chaque `API_*.json` reflète exactement les routes implémentées en v2.15.0
- [ ] `SCHEMA_DB.md` couvre les 82 tables avec relations
- [ ] `ENV_REFERENCE.md` identifie les clés à supprimer
- [ ] Aucune migration SQL orpheline dans `docs/`

---

## Phase 1 — Analyse des entry points

### Inventaire des routes v2.15.0

**Auth publique (10 routes)**

| Méthode | URL | Décision v3 |
| - | - | - |
| GET | / | → GET /v3/ |
| GET | /help | Supprimer — remplacé par OpenAPI spec |
| GET | /health | → GET /v3/health |
| GET | /groups | → GET /v3/groups?visibility=public |
| POST | /groups/join | → POST /v3/groups/join |
| POST | /users/register | → POST /v3/users |
| POST | /users/request-password-reset | → POST /v3/auth/password-reset |
| POST | /users/reset-password | → PUT /v3/auth/password-reset |
| POST | /users/verify-email | → POST /v3/auth/verify-email |
| POST | /users/resend-verification-email | → POST /v3/auth/verify-email/resend |

**Auth (10 routes)**

| Méthode | URL | Décision v3 |
| - | - | - |
| POST | /auth/login | → POST /v3/auth/login |
| POST | /auth/send-code | → POST /v3/auth/otp |
| POST | /auth/verify-code | → PUT /v3/auth/otp |
| POST | /auth/refresh | → POST /v3/auth/token/refresh |
| GET | /auth/me | Supprimer — fusionner avec GET /v3/users/me |
| GET | /auth/devices | → GET /v3/users/me/devices |
| DELETE | /auth/devices/{id} | → DELETE /v3/users/me/devices/{id} |
| GET | /auth/sessions | → GET /v3/users/me/sessions |
| DELETE | /auth/sessions | → DELETE /v3/users/me/sessions |
| POST | /auth/logout | → POST /v3/auth/logout |

**Utilisateurs (9 routes)**

| Méthode | URL | Décision v3 |
| - | - | - |
| GET | /users | → GET /v3/users |
| GET | /users/{id} | → GET /v3/users/{id} |
| POST | /users/{id} | → PATCH /v3/users/{id} |
| DELETE | /users/{id} | → DELETE /v3/users/{id} |
| GET | /users/{id}/avatar | → GET /v3/users/{id}/avatar |
| POST | /users/{id}/avatar | → PUT /v3/users/{id}/avatar |
| DELETE | /users/{id}/avatar | → DELETE /v3/users/{id}/avatar |
| POST | /users/{id}/password | → PUT /v3/users/{id}/password |
| POST | /users/{id}/restore | → POST /v3/users/{id}/restore |

**Groupes (12 routes)**

| Méthode | URL | Décision v3 |
| - | - | - |
| GET | /groups | → GET /v3/groups |
| POST | /groups | → POST /v3/groups |
| GET | /groups/{id} | → GET /v3/groups/{id} |
| POST | /groups/{id} | → PATCH /v3/groups/{id} |
| DELETE | /groups/{id} | → DELETE /v3/groups/{id} |
| GET | /groups/{id}/members | → GET /v3/groups/{id}/members |
| POST | /groups/{id}/members | → POST /v3/groups/{id}/members |
| DELETE | /groups/{id}/members/{uid} | → DELETE /v3/groups/{id}/members/{uid} |
| GET | /groups/{id}/invitations | → GET /v3/groups/{id}/invitations |
| POST | /groups/{id}/invitations | → POST /v3/groups/{id}/invitations |
| DELETE | /groups/{id}/invitations/{iid} | → DELETE /v3/groups/{id}/invitations/{iid} |
| POST | /groups/invitations/{code}/accept | → POST /v3/invitations/{code}/accept |

**Tags (8 routes)**

| Méthode | URL | Décision v3 |
| - | - | - |
| GET | /tags | → GET /v3/tags |
| POST | /tags | → POST /v3/tags |
| POST | /tags/search | → GET /v3/tags?q={term} |
| GET | /tags/{id} | → GET /v3/tags/{id} |
| POST | /tags/{id} | → PATCH /v3/tags/{id} |
| DELETE | /tags/{id} | → DELETE /v3/tags/{id} |
| GET | /tags/most-used | → GET /v3/tags?sort=popular |
| GET | /tags/suggestions | → GET /v3/tags?suggest=1&q={term} |

**Fichiers (8 routes)**

| Méthode | URL | Décision v3 |
| - | - | - |
| POST | /files/upload | → POST /v3/files |
| GET | /files | → GET /v3/files |
| GET | /files/{id} | → GET /v3/files/{id} |
| GET | /files/{id}/metadata | Fusionner dans GET /v3/files/{id} |
| POST | /files/{id} | → PATCH /v3/files/{id} |
| DELETE | /files/{id} | → DELETE /v3/files/{id} |
| POST | /files/{id}/restore | → POST /v3/files/{id}/restore |
| GET | /files/{id}/versions | → GET /v3/files/{id}/versions |

**Facturation (8 routes) — renommé de /stripe/**

| Méthode | URL actuelle | Décision v3 |
| - | - | - |
| GET | /plans | → GET /v3/plans (public) |
| GET | /plans/{id} | → GET /v3/plans/{id} |
| POST | /plans | → POST /v3/plans (admin) |
| POST | /plans/{id} | → PATCH /v3/plans/{id} (admin) |
| DELETE | /plans/{id} | → DELETE /v3/plans/{id} (admin) |
| POST | /stripe/checkout | → POST /v3/billing/checkout |
| POST | /stripe/portal | → POST /v3/billing/portal |
| POST | /stripe/webhook | → POST /v3/billing/webhook |

**Abonnements (5 routes)**

| Méthode | URL | Décision v3 |
| - | - | - |
| GET | /subscriptions | → GET /v3/subscriptions |
| POST | /subscriptions/{plan_id} | → POST /v3/subscriptions |
| POST | /subscriptions/{sub_id}/upgrade | → PUT /v3/subscriptions/{id} |
| DELETE | /subscriptions/{sub_id} | → DELETE /v3/subscriptions/{id} |
| GET | /subscriptions/{sub_id}/billing | → GET /v3/subscriptions/{id}/invoices |

**Stats (4 routes)**

| Méthode | URL | Décision v3 |
| - | - | - |
| GET | /stats/platform | → GET /v3/stats/platform (admin) |
| GET | /stats/user | → GET /v3/stats/me |
| GET | /stats/groups | → GET /v3/stats/groups |
| GET | /stats/usage | → GET /v3/stats/usage |

**Contacts + CRM (13 routes)**

| Méthode | URL | Décision v3 |
| - | - | - |
| GET | /contacts | → GET /v3/contacts |
| POST | /contacts | → POST /v3/contacts |
| POST | /contacts/import | → POST /v3/contacts/import |
| GET | /contacts/{id} | → GET /v3/contacts/{id} |
| GET | /contacts/{id}.vcf | → GET /v3/contacts/{id}/vcard (extension d'URL → sous-ressource) |
| PUT | /contacts/{id} | → PATCH /v3/contacts/{id} (mise à jour partielle — PUT mal nommé, convention #2) |
| DELETE | /contacts/{id} | → DELETE /v3/contacts/{id} |
| GET | /contacts/{id}/messages | → GET /v3/contacts/{id}/messages |
| POST | /contacts/{id}/messages | → POST /v3/contacts/{id}/messages |
| GET | /contacts/{id}/interactions | → GET /v3/contacts/{id}/interactions |
| POST | /contacts/{id}/interactions | → POST /v3/contacts/{id}/interactions |
| DELETE | /contacts/{id}/interactions/{iid} | → DELETE /v3/contacts/{id}/interactions/{iid} |
| GET | /contacts/{id}/opportunites | → GET /v3/contacts/{id}/opportunities |
| POST | /contacts/{id}/opportunites | → POST /v3/contacts/{id}/opportunities |

**Opportunités — pipeline CRM (4 routes)**

| Méthode | URL | Décision v3 |
| - | - | - |
| GET | /opportunites | → GET /v3/opportunities (board Kanban global) |
| PUT | /opportunites/{id} | Supprimer — doublon de PATCH (convention #2) |
| PATCH | /opportunites/{id} | → PATCH /v3/opportunities/{id} |
| DELETE | /opportunites/{id} | → DELETE /v3/opportunities/{id} |

**Projets — tâches (13 routes) — aplatir le préfixe /projets/**

| Méthode | URL | Décision v3 |
| - | - | - |
| GET | /projets/projects | → GET /v3/projects |
| POST | /projets/projects | → POST /v3/projects |
| GET | /projets/projects/{id} | → GET /v3/projects/{id} |
| PATCH | /projets/projects/{id} | → PATCH /v3/projects/{id} |
| DELETE | /projets/projects/{id} | → DELETE /v3/projects/{id} |
| GET | /projets/projects/{id}/tasks | → GET /v3/projects/{id}/tasks |
| POST | /projets/projects/{id}/tasks | → POST /v3/projects/{id}/tasks |
| GET | /projets/projects/{id}/export.json | → GET /v3/projects/{id}/export?format=json |
| POST | /projets/projects/{id}/import.json | → POST /v3/projects/{id}/import?format=json (dry-run) |
| POST | /projets/projects/{id}/import.json/confirm | → PUT /v3/projects/{id}/import?format=json (écriture confirmée) |
| GET | /projets/projects/{id}/export.ics | → GET /v3/projects/{id}/export?format=ics |
| GET, PATCH, DELETE | /projets/tasks/{id} | → GET/PATCH/DELETE /v3/tasks/{id} |

> Module `projets` empile aujourd'hui deux segments (`/projets/projects/...`) — le préfixe français `projets`
> et la ressource anglaise `projects` font doublon. v3 aplatit en `/v3/projects/**` et `/v3/tasks/**` top-level.

**Admin (6 routes) — renommé de /secret-admin/**

| Méthode | URL actuelle | Décision v3 |
| - | - | - |
| GET | /secret-admin/users | → GET /v3/admin/users |
| GET | /secret-admin/users/{id} | → GET /v3/admin/users/{id} |
| POST | /secret-admin/users/{id}/unlock | → POST /v3/admin/users/{id}/unlock |
| GET | /secret-admin/system/config | → GET /v3/admin/system/config |
| GET | /secret-admin/system/health | → GET /v3/admin/system/health |
| GET | /secret-admin/logs | → GET /v3/admin/logs |

**Plugins — Conserver, préfixer /v3/**

- Calendriers ICS : GET/POST/PATCH/DELETE /v3/calendars/** — migre depuis `/calendars/**` (non versionné en v2.7.0)
- CalDAV : PROPFIND/GET/PUT/DELETE/REPORT /caldav/** — **pas de préfixe /v3/** (protocole WebDAV, URL fixée côté clients externes iOS/Thunderbird — règle absolue, jamais versionner)
- Notifications : /v3/notifications/** — migre depuis `/notifications/**`
- Quiz : GET/POST/PATCH/DELETE /v3/quizzes/** — conserver
- Items : GET/POST/PATCH/DELETE /v3/items/** — conserver
- Puzzle : /v3/puzzle/** — migre depuis `/v2/puzzle/**` (versionné en v2.7.0)
- Pomo : À évaluer — si toujours actif, conserver sous /v3/waitlist/** ; sinon archiver

> **Note v2.7.0 → v3.0** : ICS (`/calendars`, `/calendar`, `/notifications`) reste sur routes non versionnées en v2.7.0. La migration vers `/v3/` en v3.0 nécessite directive inter-projet vers tous les clients calendrier.

### Décisions transversales

1. **Préfixe /v3/** sur toutes les routes API (sauf CalDAV — protocole)
2. **HTTP sémantique** — POST=créer, PUT=remplacer, PATCH=modifier, DELETE=supprimer
3. **/stripe/ → /billing/** — agnostique du provider
4. **/secret-admin/ → /admin/** — naming standard
5. **/auth/me → /users/me** — évite la duplication de profil
6. **POST /tags/search → GET /tags?q=** — idempotent, cacheable
7. **GET /help supprimé** — remplacé par GET /v3/openapi.json
8. **Nouvelles routes utilitaires** : GET /v3/openapi.json, GET /v3/version
9. **Extensions dans l'URL supprimées** (`.vcf`, `.json`, `.ics`) — remplacées par `?format=` ou une sous-ressource dédiée
10. **PUT/PATCH doublons supprimés** — chaque ressource n'expose qu'une seule route de mise à jour partielle (`PATCH`)
11. **/projets/projects → /projects, /projets/tasks → /tasks** — aplatir le préfixe module redondant

### Conditions de complétion

- [ ] Table complète des routes v2→v3 approuvée
- [ ] Aucune route abandonnée sans justification documentée
- [ ] Conventions HTTP cohérentes sur 100 % des routes

---

## Phase 2 — Architecture cible

### Ce qui est en place

| Composant | État actuel | Évaluation |
| - | - | - |
| Routeur | Custom match-based (12 RouteHandlers) | Fragile, pas de paramètres typés |
| Middleware | BaseRouteHandler + pipeline manuel | Pas PSR-15, pas composable |
| DI | Aucun — instanciation manuelle | Couplage fort |
| Config | ~121 clés .env brutes | Non typé, non validé à la compilation |
| Logging | LogService custom | Pas PSR-3 |
| DB Migrations | Fichiers .sql manuels | Pas versionné ni rollbackable |
| Tests | cURL intégration uniquement | Aucun test unitaire |
| Documentation | Fichiers JSON manuels | Pas OpenAPI |

### Dépendances cibles

```json
{
  "require": {
    "php": ">=8.2",
    "nikic/fast-route": "^1.3",
    "php-di/php-di": "^7.0",
    "monolog/monolog": "^3.0",
    "nyholm/psr7": "^1.8",
    "middlewares/utils": "^3.3",
    "robmorgan/phinx": "^0.16",
    "phpmailer/phpmailer": "^6.10",
    "simshaun/recurr": "^5.0",
    "sabre/vobject": "^4.5",
    "stripe/stripe-php": "^13.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.0",
    "phpstan/phpstan": "^1.11"
  }
}
```

### Structure cible

```
src/
  App/
    Kernel.php                     # Bootstrap, container, router
    Container.php                  # PHP-DI definitions
    Http/
      Request.php                  # PSR-7 wrapper + helpers
      Response.php                 # PSR-7 + JSON envelope
      ResponseFactory.php          # success/error/paginated
    Middleware/
      Pipeline.php                 # PSR-15 dispatcher
      AuthMiddleware.php
      RateLimitMiddleware.php
      LoggingMiddleware.php
      CorsMiddleware.php
      MaintenanceMiddleware.php
    Router/
      Router.php                   # FastRoute wrapper
      RouteDefinition.php          # Annotation/attribute-based
      RouteGroup.php

  Core/
    Auth/
      JwtService.php
      OtpService.php
      DeviceTokenService.php
    Config/
      AppConfig.php                # Typed readonly config
      EnvLoader.php
    Database/
      Connection.php
      BaseModel.php
      SoftDeleteTrait.php
    Logging/
      Logger.php                   # Monolog PSR-3
    Plugin/
      PluginInterface.php
      PluginManager.php
      AbstractPlugin.php

  Modules/                         # Modules natifs (core)
    Auth/
      Controllers/
      Services/
      Models/
      Routes.php
    Users/
    Groups/
    Files/
    Tags/
    Plans/
    Subscriptions/
    Billing/                       # Ex-Stripe — agnostique provider
    Stats/
    Admin/                         # Ex-SecretAdmin
    Contacts/                      # CRUD + vCard + interactions + opportunités
    Projects/                      # Ex-Projets — tâches, hiérarchie, export JSON/.ics

  Plugins/                         # Modules optionnels chargés dynamiquement
    Calendar/                      # Ex-ICS
    Quiz/
    Items/
    Puzzle/
    Waitlist/                      # Ex-Pomo (si conservé)

  Utils/
    Validator.php
    Pagination.php

index.php                          # Entrypoint (15 lignes max)
```

### Patterns à adopter

1. **Actions** — chaque endpoint = une classe `Action` (pas de controllers-dieux)
2. **DTOs** — request/response typés avec readonly properties PHP 8.2
3. **Repository pattern** — Models deviennent des Repositories, injectables
4. **Exception handling** — hiérarchie d'exceptions métier → middleware global les attrape
5. **Route attributes** — `#[Route('GET', '/v3/users')]` sur les Actions
6. **OpenAPI first** — spec générée depuis les attributes PHP

### Conditions de complétion

- [ ] composer.json avec nouvelles dépendances
- [ ] Kernel + Container + Router bootstrapent sans erreur
- [ ] Middleware pipeline PSR-15 opérationnel
- [ ] Structure de dossiers créée

---

## Phase 3 — Implantation par sous-système

### 3.1 Bootstrap + DI + Routeur

**Actions à poser :**

- Créer `src/App/Kernel.php` — init Container, Router, Pipeline
- Créer `src/App/Container.php` — définitions PHP-DI (DB, Logger, Services)
- Créer `src/App/Router/Router.php` — wrap FastRoute, supporte les attributes PHP
- Créer `src/App/Middleware/Pipeline.php` — PSR-15 dispatcher
- Créer `src/App/Http/Request.php` et `Response.php` (nyholm/psr7)
- Réécrire `index.php` — 15 lignes max, délègue tout au Kernel
- Réécrire `src/Core/Config/EnvLoader.php` — valide + type les 80 clés .env cibles

**Enjeux :**

- Compatibilité CalDAV — les requêtes PROPFIND/REPORT sortent du flux normal
- Performance — PHP-DI compile en prod (pas lazy-load par requête)

**Tests :**

- `GET /v3/health` retourne `{"success":true}` avec HTTP 200
- Requête sans JWT sur route protégée → HTTP 401
- Variable .env manquante → exception claire au boot

**Conditions de complétion :**

- [ ] `composer serve` démarre sans erreur
- [ ] Route /v3/health répond
- [ ] Middleware auth bloque les routes protégées
- [ ] Aucune variable globale ni singleton non-injecté

---

### 3.2 Auth (JWT + OTP + Device Token)

**Actions à poser :**

- Migrer `JwtService`, `OtpService`, `DeviceTokenService` dans `src/Core/Auth/`
- Adapter `AuthMiddleware` en PSR-15 (`process(Request, Handler)`)
- Créer Actions : `LoginAction`, `OtpSendAction`, `OtpVerifyAction`, `RefreshAction`, `LogoutAction`
- Fusionner `/auth/me` et `/users/me` — une seule source de profil
- Adapter `JwtBlacklist` model → Repository injecté

**Enjeux :**

- Blacklist JWT doit survivre à un redémarrage (DB, pas mémoire)
- OTP rate limit doit survivre à un redémarrage (DB ou Redis)

**Tests :**

- Login valide → JWT signé, expiry correct
- Login invalide (5x) → HTTP 429
- OTP expiré → HTTP 401
- Token blacklisté → HTTP 401
- Refresh device token → nouveau JWT sans mot de passe

**Conditions de complétion :**

- [ ] Tests `test_auth_otp.php` passent sans modification
- [ ] Aucune session PHP utilisée (stateless)
- [ ] Blacklist purgée automatiquement (cron ou lazy)

---

### 3.3 Utilisateurs + Groupes

**Actions à poser :**

- Créer `UserRepository`, `GroupRepository` (injectables)
- Extraire `SoftDeleteTrait` → `src/Core/Database/SoftDeleteTrait.php`
- Actions par endpoint : `ListUsersAction`, `GetUserAction`, `PatchUserAction`, etc.
- Fusionner UserController (23 méthodes) → 9 Actions distinctes
- Créer `GroupMembershipService` pour logique d'invitation
- Renommer `/secret-admin/` → `/admin/` dans les routes

**Enjeux :**

- Permissions : seul l'owner ou un admin peut PATCH/DELETE un user
- Cascade soft-delete : supprimer un user → gérer memberships/fichiers

**Tests :**

- CRUD utilisateur complet
- Invitation groupe : envoi → acceptation → vérification membership
- Soft delete → 404 sur les ressources du user supprimé

**Conditions de complétion :**

- [ ] Tests `test_users.php` et `test_groups.php` passent
- [ ] Aucune vérification de permission dans les controllers

---

### 3.4 Fichiers + Tags

**Actions à poser :**

- `FileRepository` + `TagRepository`
- Normaliser upload : POST /v3/files (multipart) → une seule route
- Fusionner `GET /files/{id}` et `GET /files/{id}/metadata` — retourner les deux
- Query string pour les filtres tags : GET /v3/tags?q=&sort=popular&suggest=1

**Enjeux :**

- Upload volumineux — streaming vers disque, pas en mémoire
- Versionning fichiers — conserver la logique actuelle (file_versions table)

**Tests :**

- Upload, download, metadata, versioning
- Recherche tags avec autocomplete
- Tags trending

**Conditions de complétion :**

- [ ] Tests `test_files.php` et `test_tags.php` passent
- [ ] Upload > 10 MB ne sature pas la RAM

---

### 3.4 bis Contacts + CRM + Projets

**Actions à poser :**

- `ContactRepository`, `InteractionRepository`, `OpportuniteRepository`, `ProjectRepository`, `TaskRepository`
- Aplatir `/projets/projects/**` → `/v3/projects/**` et `/projets/tasks/{id}` → `/v3/tasks/{id}`
- Renommer `/opportunites` → `/v3/opportunities`, `/contacts/{id}/opportunites` → `/v3/contacts/{id}/opportunities`
- Supprimer les routes à extension d'URL (`{id}.vcf`) — export vCard en sous-ressource `/v3/contacts/{id}/vcard`
- Unifier PUT/PATCH sur `/contacts/{id}` et `/opportunites/{id}` en une seule route `PATCH`
- Conserver la logique `max_contacts` (cap plan) et le pipeline CRM (étapes d'opportunité) inchangés

**Enjeux :**

- Import vCard/CSV — valider les gros lots sans bloquer la requête (queue ou traitement par lots)
- Export `.ics` des tâches de projet doit rester compatible avec le générateur ICS partagé (`src/ics/`)
- Cascade soft-delete contact → interactions, opportunités, messages liés

**Tests :**

- CRUD contact, import vCard/CSV, export vCard
- Historique interactions + envoi de courriel
- Board Kanban opportunités + changement d'étape
- CRUD projet/tâche, hiérarchie, export JSON round-trip, export `.ics`

**Conditions de complétion :**

- [ ] Tests `test_contacts.php`, `test_contacts_e2e.php`, `test_contacts_messages.php`, `test_contacts_interactions.php`, `test_contacts_opportunites.php` et `test_projets.php` passent
- [ ] Aucune route avec extension d'URL restante
- [ ] Aucune route `/projets/projects/**` restante

---

### 3.5 Plans + Abonnements + Facturation

**Actions à poser :**

- Renommer module Stripe → Billing dans routes et code
- `BillingService` isole Stripe derrière une interface
- Séparer les routes publiques (`/v3/plans`) des routes admin
- Webhook Stripe — vérification signature dans `BillingMiddleware` (pas dans le controller)
- Mettre à jour les clés .env : `STRIPE_*` restent, routes deviennent `/v3/billing/`

**Enjeux :**

- Idempotence des webhooks Stripe (migration `20260508_stripe_idempotency.sql` à intégrer)
- Si le provider change un jour, seul `BillingService` est touché

**Tests :**

- Création abonnement → status `active`
- Upgrade plan → nouveau `stripe_price_id` actif
- Webhook `invoice.payment_failed` → status `past_due`
- Replay du même webhook → pas de doublon (idempotence)

**Conditions de complétion :**

- [ ] Tests `test_subscriptions.php` et `test_stripe_webhooks.php` passent
- [ ] Migration idempotence intégrée dans build_DB-v-3.0.0.sql

---

### 3.6 Admin

**Actions à poser :**

- Renommer routes `/secret-admin/` → `/admin/`
- `AdminMiddleware` — vérifie `X-Admin-Key` header (logique inchangée)
- Actions : `ListUsersAdminAction`, `UnlockUserAction`, `SystemConfigAction`, `LogsAction`

**Enjeux :**

- La clé admin (`ADMIN_SECRET_KEY`) doit être dans les headers, jamais dans l'URL
- Logs admin doivent être paginés (pas de dump complet)

**Tests :**

- Requête sans clé → HTTP 403
- Unlock user bloqué → login redevient possible
- Logs paginés → format correct

**Conditions de complétion :**

- [ ] Tests `test_secret_admin.php` passent avec nouvelles URLs
- [ ] Aucune route `/secret-admin/` restante

---

### 3.7 Plugins (ICS, Quiz, Items, Puzzle, Pomo/Waitlist)

**Actions à poser :**

- Mettre à jour `PluginInterface` pour PSR-11 (injecter Container)
- Chaque plugin reçoit le Container → ses services sont résolus par DI
- Supprimer les autoloaders individuels (`autoloader.php` par plugin) — tout via Composer
- CalendarPlugin : migrer dans `src/Plugins/Calendar/`
- Puzzle : déplacer `google-service-account.json` hors de `src/` → `private/`
- Évaluer Pomo : si archivé → `src/Plugins/Waitlist/` avec statut `deprecated`

**Enjeux :**

- CalDAV — PROPFIND/REPORT hors du flux PSR-15 standard ; conserver caldav_proxy.php ou intégrer sabre/dav
- Puzzle Google Play validation — credentials hors dépôt

**Tests :**

- Tous les tests de plugins existants passent avec nouvelles URLs (/v3/calendars/**, etc.)
- CalDAV PROPFIND retourne XML valide

**Conditions de complétion :**

- [ ] Tests `test_calendars.php`, `test_quiz.php`, `test_items.php`, `test_puzzle_*.php` passent
- [ ] `google-service-account.json` hors de `src/`
- [ ] Aucun autoloader individuel par plugin

---

### 3.8 Migrations DB (Phinx)

**Actions à poser :**

- Intégrer Phinx : `vendor/bin/phinx init`
- Créer `db/migrations/` avec une migration par groupe de changements
- Migration initiale = `build_DB-v-2.4.1.sql` comme baseline
- Migration 001 = `20260508_stripe_idempotency.sql`
- Créer `docs/v-3-0-0/build_DB-v-3.0.0.sql` comme DDL complet
- Supprimer les fichiers `*.sql` de `docs/` racine après intégration

**Enjeux :**

- Rollback des migrations en production (Phinx supporte down())
- Pas de modification des build_DB des versions antérieures

**Conditions de complétion :**

- [ ] `vendor/bin/phinx migrate` reconstruit la DB complète
- [ ] `vendor/bin/phinx rollback` n'y laisse pas de données orphelines
- [ ] Aucun `*.sql` orphelin dans `docs/`

---

### 3.9 Tests

**Actions à poser :**

- Conserver les tests cURL existants (intégration) — adapter les URLs (/v3/*)
- Ajouter tests unitaires PHPUnit pour : JwtService, OtpService, Validator, Pagination
- Ajouter PHPStan niveau 6 dans CI
- Créer `private/tests/run_all_tests.php` — rapport consolidé avec temps d'exécution

**Enjeux :**

- Tests cURL nécessitent un serveur en marche → documenter la procédure de setup
- PHPStan peut bloquer le CI sur du code legacy migré

**Conditions de complétion :**

- [ ] `php private/tests/run_all_tests.php` → 0 échec
- [ ] PHPStan niveau 6 → 0 erreur
- [ ] Couverture unitaire JwtService ≥ 90 %

---

### 3.10 Documentation OpenAPI 3.2.0

**Cible :** [OAS 3.2.0](https://spec.openapis.org/oas/v3.2.0.html)

**Actions à poser :**

- Intégrer `zircote/swagger-php` ^5.x — vérifier support OAS 3.2.0 ; si insuffisant, écrire la spec YAML manuellement et valider via `spectral lint`
- Ajouter attributs `#[OA\...]` sur chaque Action (ou annotations YAML directes si swagger-php limité sur 3.2)
- Exploiter les features OAS 3.2.0 :
  - `webhooks` top-level — documenter `POST /v3/billing/webhook` (Stripe) sous `webhooks:`
  - JSON Schema 2020-12 complet — remplacer `nullable: true` par `type: [T, "null"]` (array de types)
  - `pathItem` réutilisable via `$ref` dans `components/pathItems` pour les routes admin partagées
- Maintenir `docs/openapi.yaml` (YAML préféré à JSON pour lisibilité et diff Git)
- Exposer `GET /v3/openapi.json` et `GET /v3/openapi.yaml` (lecture seule, pas d'UI Swagger en prod)
- Valider la spec : `npx @stoplight/spectral-cli lint docs/openapi.yaml --ruleset spectral:oas`
- Remplacer tous les `API_*_ENDPOINTS.json` par la spec générée

**Enjeux :**

- Support tooling 3.2.0 encore partiel (swagger-php ciblait 3.1 en 2025) — prévoir fallback spec YAML manuelle + validation Spectral
- `nullable` est une propriété OAS 3.0 dépréciée — à bannir en 3.2.0 ; utiliser `oneOf: [{type: T}, {type: "null"}]` ou l'array de types
- Spec générée doit rester dans le dépôt (pas runtime-only) — commit obligatoire après chaque changement d'API
- CalDAV restera hors OpenAPI (protocole WebDAV, URL fixée côté clients)

**Conditions de complétion :**

- [ ] `spectral lint docs/openapi.yaml` → 0 erreur, 0 warning sur ruleset OAS
- [ ] `GET /v3/openapi.yaml` retourne une spec valide OAS 3.2.0
- [ ] Webhooks Stripe documentés sous `webhooks:` top-level (pas sous `paths:`)
- [ ] Aucune propriété `nullable` dans la spec (toutes migrées vers array de types JSON Schema 2020-12)
- [ ] Anciens `API_*_ENDPOINTS.json` supprimés

---

### 3.11 Release v3.0.0

- Suivre la procédure globale `ancrer version 3.0.0`
- `docs/v-3-0-0/3.0.0_CLIENT.md` — liste des breaking changes (nouvelles URLs, PATCH vs POST)
- `docs/v-3-0-0/3.0.0_PRODUCTION.md` — checklist : migrations Phinx, .env réduit, cron
- Guide de migration client : `docs/v-3-0-0/MIGRATION_v2_vers_v3.md`

---

## Récapitulatif des phases

| Phase | Livrable principal | Priorité | Bloquant pour |
| - | - | - | - |
| 0 — Docs état actuel | Docs synchronized + ERD | P0 | Phase 1 |
| 1 — Entry points | Table routes v2→v3 approuvée | P0 | Phase 3 |
| 2 — Architecture cible | composer.json + structure dossiers | P1 | Phase 3 |
| 3.1 — Bootstrap | Kernel + DI + Router opérationnels | P1 | Tout le reste |
| 3.2 — Auth | JWT/OTP/Device tokens migrés | P1 | 3.3–3.7 |
| 3.3 — Users/Groups | CRUD + permissions | P1 | 3.5, 3.6 |
| 3.4 — Files/Tags | Upload + search | P2 | 3.7 (items) |
| 3.4 bis — Contacts/CRM/Projets | Routes aplaties, CRUD + import/export migrés | P2 | 3.11 |
| 3.5 — Billing | Stripe renommé, idempotence | P2 | 3.11 |
| 3.6 — Admin | Routes renommées | P2 | 3.11 |
| 3.7 — Plugins | DI injecté, autoloaders unifiés | P2 | 3.11 |
| 3.8 — Migrations | Phinx opérationnel | P1 | 3.11 |
| 3.9 — Tests | 0 échec, PHPStan P6 | P1 | 3.11 |
| 3.10 — OpenAPI 3.2.0 | Spec OAS 3.2.0, /v3/openapi.yaml, Spectral 0 erreur | P2 | 3.11 |
| 3.11 — Release | Tag v3.0.0, guides de migration | P3 | — |

---

## Décisions à confirmer avant de coder

Approuver ou modifier avant le démarrage de la Phase 2 :

1. **Préfixe /v3/** — accepté ou autres options (ex : `/api/v3/`, header `Accept-Version`) ?
2. **Pomo/Waitlist** — conserver ou archiver ?
3. **CalDAV** — garder `caldav_proxy.php` ou migrer vers `sabre/dav` ?
4. **DI container** — PHP-DI 7 ou autre (Pimple, Laravel Container standalone) ?
5. **Logging** — Monolog 3 ou conserver le LogService custom ?
6. **Route definitions** — Attributes PHP 8.2 ou fichiers de config YAML/PHP ?
7. **OpenAPI 3.2.0 tooling** — `zircote/swagger-php` avec attributes PHP si support 3.2.0 confirmé, ou spec YAML manuelle + Spectral (`@stoplight/spectral-cli`) comme seul validateur ?
