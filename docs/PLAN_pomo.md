
# Plan : Plugin Pomodoro dans cmem2_API

> Basé sur la documentation client `C:\code\pomodoro\docs` — 1 avril 2026

## TL;DR

Le plugin Pomodoro est une API à 3 phases intégrée dans cmem2 via le système de plugins.
Phase 1A = 1 endpoint public (engagement MVP). Phase 1B = support (JWT). Phase 2 = sync cloud Premium.
Phase 3 = abonnement Stripe. L'auth réutilise entièrement auth_groups existant.

---

## Contexte client (Pomodoro Flutter)

**Phase 1A MVP (actuel)** : App 100% locale, Hive storage, analytics calculés en local.
Seuls besoins API : `GET /health` (déjà dans cmem2 core), `POST /pomo/engagement` (waitlist + sondage).

**Phase 2 Premium** : Sync cloud, formulaire support, abonnement 6$/mois ou 60$/an.
Auth : compte cmem2 standard (`/users/register`, `/auth/login`) + JWT Bearer.

**Phase 3** : Collaboration temps réel (WebSocket, hors scope cmem2 — futur Node.js).

---

## Phase 0 — Prérequis système de plugins *(bloquant)*

1. **Créer `src/Core/AbstractPlugin.php`**
   - Centraliser `safeLog()` (actuellement dupliqué dans PluginManager et CalendarPlugin)
   - Defaults : `deactivate(): void {}`, `getDependencies(): array { return []; }`
   - Hook `runMigrations(string $path): void` vide

2. **Refactorer `src/ics/CalendarPlugin.php`** *(dépend de #1)*
   - Hériter de `AbstractPlugin` au lieu d'implémenter `PluginInterface` directement
   - Dans `initialize()`, appeler `$this->getRouteHandlers()` au lieu de redéclarer les factories

3. **Nettoyer `src/Core/PluginManager.php`** *(dépend de #1)*
   - Supprimer les exclusions hardcodées `'auth_groups'`, `'Core'` dans `scanPluginDirectories()`
   - La présence de `plugin.json` est le seul critère suffisant

---

## Phase 1A — MVP Engagement *(public, sans auth)*

### Routes

| Method | Route | Auth | Description |
| -------- | ------- | ------ | ------------- |
| GET | `/health` | None | Déjà dans cmem2 `PublicRouteHandler` — rien à créer |
| POST | `/pomo/engagement` | None + `device_id` | `type=waitlist` (courriel) ou `type=survey` (5 questions) |

### Fichiers à créer

1. **`src/pomo/plugin.json`** — name, version, namespace `Pomo`, main_class `Pomo\PomoPlugin`, dependencies
2. **`src/pomo/autoloader.php`** + ajout `"Pomo\\": "src/pomo/"` dans `composer.json`
3. **`src/pomo/PomoPlugin.php`** — hérite `AbstractPlugin`, enregistre `pomo` → `PomoRouteHandler`
4. **`src/pomo/Routing/PomoRouteHandler.php`** — un seul handler, auth conditionnelle par sous-route
5. **`src/pomo/Controllers/EngagementController.php`** — routing interne par `type`
6. **`src/pomo/Models/Engagement.php`** — accès table `pomo_engagements`
7. **`src/pomo/Validators/EngagementValidator.php`** — validation courriel (waitlist) + 5 réponses yes|no|maybe (survey)
8. **`src/pomo/migrations/001_pomo_engagement.sql`**

### Schéma DB `pomo_engagements`

```sql
id               INT AUTO_INCREMENT PRIMARY KEY,
type             ENUM('waitlist', 'survey')                             NOT NULL,
device_id        VARCHAR(36)                                            NOT NULL,
email            VARCHAR(254)                                           NULL,
responses        JSON                                                   NULL,
suggestion       TEXT                                                   NULL,
platform         ENUM('android','ios','web','windows','macos','linux')  NULL,
language         VARCHAR(16)                                            NULL,
app_version      VARCHAR(32)                                            NULL,
build_number     VARCHAR(32)                                            NULL,
session_duration INT                                                    NULL,
network_status   ENUM('online','offline')                               NULL,
timestamp_utc    DATETIME                                               NOT NULL,
created_at       DATETIME                                               NOT NULL DEFAULT CURRENT_TIMESTAMP,
INDEX (type),
INDEX (device_id)
```

> Note : unicité courriel pour waitlist gérée en code (MySQL ne supporte pas partial unique).

### Logique métier `EngagementController`

- `type=waitlist` : valider courriel (format + 3–254 chars), vérifier doublon → HTTP 409 si existant
- `type=survey` : valider 5 champs `yes|no|maybe`, suggestion optionnelle, accepte multiples par device
- Succès : `{"success": true, "reference_id": 123}` (HTTP 201)
- Erreur validation : `{"success": false, "errors": [{"field": "...", "code": "...", "message": "..."}]}` (HTTP 422)

---

## Phase 1B — Support *(actif quand `SUPPORT_FORM_ENABLED=true`)*

### Routes

| Method | Route           | Auth       | Description                          |
| ------ | --------------- | ---------- | ------------------------------------ |
| POST   | `/pomo/support` | JWT Bearer | Formulaire support avec infos device |

### Fichiers à créer

1. **`src/pomo/Controllers/SupportController.php`**
   - Valider body : `email`, `message` (requis) + `infos` (device_id, platform, language, app_version, build_number, network_status, timestamp_utc)
   - Utiliser `EmailService` de `auth_groups` pour envoyer courriel + confirmation
   - Enregistrer en DB, retourner `reference_id`

2. **`src/pomo/migrations/002_pomo_support.sql`**

### Schéma DB `pomo_support_requests`

```sql
id                INT AUTO_INCREMENT PRIMARY KEY,
user_id           INT              NULL,
reference_id      VARCHAR(36)      NOT NULL,
email             VARCHAR(254)     NOT NULL,
message           TEXT             NOT NULL,
infos             JSON             NULL,
mail_sent         TINYINT(1)       NOT NULL DEFAULT 0,
confirmation_sent TINYINT(1)       NOT NULL DEFAULT 0,
created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
```

> `user_id` FK vers `users.id` — NULL prévu pour usage anonyme futur (device_id uniquement).

---

## Phase 2 — Sync Cloud *(Premium)*

Les utilisateurs s'authentifient via les endpoints cmem2 existants (`/users/register`, `/auth/login`).
Stratégie de résolution de conflits : **last-write-wins** sur `updated_at`.

### Routes

| Method            | Route                    | Auth | Description                        |
|-------------------|--------------------------|------|------------------------------------|
| GET / POST        | `/pomo/sync/sessions`    | JWT  | Sessions Pomodoro                  |
| GET / POST        | `/pomo/sync/tasks`       | JWT  | Tâches                             |
| GET / POST        | `/pomo/sync/projects`    | JWT  | Projets (Phase 1B client)          |
| GET / POST        | `/pomo/sync/stats`       | JWT  | DailyFocusStats (7 indicateurs)    |

### Fichiers à créer

1. **`src/pomo/Controllers/SyncController.php`** — dispatch par `$request['action']`
2. **`src/pomo/Services/SyncService.php`** — résolution conflits, delta sync via `?since=ISO`
3. **`src/pomo/Models/PomoSession.php`**, **`PomoTask.php`**, **`PomoProject.php`**, **`PomoDailyStats.php`**
4. **`src/pomo/migrations/003_pomo_sync.sql`**

### Schémas DB sync

```sql
-- pomo_sessions
id, uuid VARCHAR(36) UNIQUE, user_id INT, timestamp_utc DATETIME,
duration_sec INT, interruptions INT, task_uuid VARCHAR(36) NULL,
completed TINYINT(1), device_id VARCHAR(36), deleted_at DATETIME NULL,
updated_at DATETIME, synced_at DATETIME, created_at DATETIME

-- pomo_tasks
id, uuid VARCHAR(36) UNIQUE, user_id INT, title VARCHAR(255),
description TEXT NULL, priority TINYINT, status VARCHAR(32),
estimated_pomodoros INT NULL, project_uuid VARCHAR(36) NULL,
deadline DATETIME NULL, order_index INT, type VARCHAR(32),
device_id VARCHAR(36), deleted_at DATETIME NULL,
updated_at DATETIME, synced_at DATETIME, created_at DATETIME

-- pomo_projects
id, uuid VARCHAR(36) UNIQUE, user_id INT, title VARCHAR(255),
description TEXT NULL, deadline DATETIME NULL, device_id VARCHAR(36),
deleted_at DATETIME NULL, updated_at DATETIME, synced_at DATETIME, created_at DATETIME

-- pomo_daily_stats
id, user_id INT, date DATE, completion_rate FLOAT NULL,
duration_accuracy FLOAT NULL, regularity FLOAT NULL,
interruption_score FLOAT NULL, focus_score TINYINT NULL,
calculated_at DATETIME, device_id VARCHAR(36), synced_at DATETIME,
UNIQUE KEY (user_id, date, device_id)
```

---

## Phase 3 — Premium Stripe *(futur)*

1. **`src/pomo/Controllers/SubscriptionController.php`**
2. **`src/pomo/migrations/004_pomo_subscriptions.sql`** — table `pomo_subscriptions`
3. Webhook Stripe → `POST /pomo/stripe/webhook` (public, validation signature)

---

## Structure répertoire finale `src/pomo/`

```text
src/pomo/
├── PLAN_pomo.md
├── plugin.json
├── autoloader.php
├── PomoPlugin.php
├── Controllers/
│   ├── EngagementController.php      (Phase 1A)
│   ├── SupportController.php         (Phase 1B)
│   ├── SyncController.php            (Phase 2)
│   └── SubscriptionController.php    (Phase 3)
├── Models/
│   ├── Engagement.php                (Phase 1A)
│   ├── SupportRequest.php            (Phase 1B)
│   ├── PomoSession.php               (Phase 2)
│   ├── PomoTask.php                  (Phase 2)
│   ├── PomoProject.php               (Phase 2)
│   └── PomoDailyStats.php            (Phase 2)
├── Routing/
│   └── PomoRouteHandler.php
├── Validators/
│   └── EngagementValidator.php       (Phase 1A)
├── Services/
│   └── SyncService.php               (Phase 2)
└── migrations/
    ├── 001_pomo_engagement.sql        (Phase 1A)
    ├── 002_pomo_support.sql           (Phase 1B)
    ├── 003_pomo_sync.sql              (Phase 2)
    └── 004_pomo_subscriptions.sql     (Phase 3)
```

---

## Vérification par phase

**Phase 0 :**

- Charger l'API → plugin ICS se charge toujours, tests existants passent

**Phase 1A :**

- `POST /pomo/engagement` type=waitlist → 201, reference_id
- `POST /pomo/engagement` type=waitlist doublon courriel → 409
- `POST /pomo/engagement` type=survey → 201
- `POST /pomo/engagement` invalide → 422 avec erreurs par champ
- `GET /health` → toujours 200 (non impacté)

**Phase 1B :**

- `POST /pomo/support` sans JWT → 401
- `POST /pomo/support` avec JWT valide → 200, courriel envoyé, reference_id

**Phase 2 :**

- `POST /pomo/sync/sessions` → batch upload sessions
- `GET /pomo/sync/sessions?since=ISO` → delta sync
- Conflit même uuid, `updated_at` différent → last-write-wins
- `GET /health` liste les plugins chargés incluant `pomo`

---

## Décisions

- **Routes préfixées `/pomo/`** : évite toute collision avec les routes cmem2 core.
- **`GET /health` non créé** : déjà dans `PublicRouteHandler` cmem2 — le plugin n'y touche pas.
- **Auth réutilisée** : Pomo Phase 2 utilise `/users/register` + `/auth/login` existants. Aucune auth séparée.
- **`user_id` nullable dans support** : préparation pour un mode anonyme futur (device_id uniquement).
- **Pas d'EventDispatcher** : aucun use-case cross-plugin identifié — reporter.
- **Migrations déclarées dans `plugin.json`** : prévoir la clé `database.migrations` même si le runner automatique est implémenté en Phase 0.
