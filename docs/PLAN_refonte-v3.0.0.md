# Plan de refonte — v3.0.0

## Objectif

Refonte complète de la base de code à partir de zéro. Architecture moderne (PSR-7/11/15, DI, OpenAPI), conventions REST strictes, versioning d'URL, et documentation comme source de vérité.

Portée : code source uniquement. Domaine métier et schéma DB conservés à 90 %.

---

## Phase 0 — Révision de la documentation de l'état actuel

### Ce qui est en place

- `docs/{module}/GUIDE.md` — un guide par module, qualité inégale
- `docs/{module}/API_*_ENDPOINTS.json` — endpoints JSON par module, non synchronisés avec le code
- `docs/v-2-4-1/build_DB-v-2.4.1.sql` — DDL complet v2.4.1
- `docs/20260508_stripe_idempotency.sql` — migration pendante non intégrée

### Améliorations à faire

1. **Synchronisation docs ↔ code** — vérifier chaque GUIDE.md et API_*.json face au code v2.5.0 :

   | Module | Fichier doc | À vérifier |
   | - | - | - |
   | core | `docs/core/API_ENDPOINTS.json` | 110+ routes totales |
   | ics | `docs/ics/API_ICS_ENDPOINTS.json` | Routes CalDAV (PROPFIND, REPORT) |
   | quiz | `docs/quiz/API_QUIZ_ENDPOINTS_v1_0_0.json` | Sessions/participants |
   | puzzle | `docs/puzzle/API_PUZZLE_ENDPOINTS.json` | Google Play, admin |
   | items | `docs/items/API_ITEMS_ENDPOINTS.json` | Access control |
   | pomo | `docs/pomo/API_POMO_ENDPOINTS_v1_0_0.json` | Statut actuel du module |

2. **Cartographie DB** — produire `docs/v-3-0-0/SCHEMA_DB.md` :
   - ERD des 42 tables avec relations FK explicites
   - Liste des enums implicites (status, role, visibility, billing_period)
   - Colonnes soft-delete par table

3. **Inventaire des 218 clés .env** — créer `docs/v-3-0-0/ENV_REFERENCE.md` :
   - Regrouper par domaine fonctionnel
   - Marquer les clés obsolètes ou redondantes (cible : réduction à ~80 clés)

4. **Intégrer la migration pendante** dans `docs/v-3-0-0/build_DB-v-3.0.0.sql`

### Conditions de complétion

- [ ] Chaque `API_*.json` reflète exactement les routes implémentées en v2.5.0
- [ ] `SCHEMA_DB.md` couvre les 42 tables avec relations
- [ ] `ENV_REFERENCE.md` identifie les clés à supprimer
- [ ] Aucune migration SQL orpheline dans `docs/`

---

## Phase 1 — Analyse des entry points

### Inventaire des routes v2.5.0

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
| Config | 218 clés .env brutes | Non typé, non validé à la compilation |
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

### 3.10 Documentation OpenAPI

**Actions à poser :**

- Intégrer `zircote/swagger-php` (annotations PHP 8 → spec OpenAPI 3.1)
- Ajouter attributs `#[OA\...]` sur chaque Action
- Générer `docs/openapi.json` via script Composer
- Exposer `GET /v3/openapi.json` (lecture seule, pas d'UI Swagger en prod)
- Remplacer tous les `API_*_ENDPOINTS.json` par la spec générée

**Enjeux :**

- Spec générée doit rester dans le dépôt (pas runtime-only) — commit après chaque changement d'API
- CalDAV restera hors OpenAPI (protocole WebDAV)

**Conditions de complétion :**

- [ ] `composer generate-spec` produit un fichier valide OpenAPI 3.1
- [ ] GET /v3/openapi.json retourne la spec
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
| 3.5 — Billing | Stripe renommé, idempotence | P2 | 3.11 |
| 3.6 — Admin | Routes renommées | P2 | 3.11 |
| 3.7 — Plugins | DI injecté, autoloaders unifiés | P2 | 3.11 |
| 3.8 — Migrations | Phinx opérationnel | P1 | 3.11 |
| 3.9 — Tests | 0 échec, PHPStan P6 | P1 | 3.11 |
| 3.10 — OpenAPI | Spec générée, /v3/openapi.json | P2 | 3.11 |
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
