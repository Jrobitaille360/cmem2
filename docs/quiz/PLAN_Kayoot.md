# Plan : Plugin Quiz Interactif (type Kahoot) dans cmem2_API

> 1 avril 2026

## TL;DR

Créer un plugin PHP `src/quiz/` intégré au système de plugins cmem2 existant. Le backend gère le
CRUD des quiz, les sessions identifiées par code, la participation anonyme (pseudonyme + device_id),
la génération et validation des variables mathématiques côté serveur. Le temps réel est délégué à un
microservice Node.js en Phase 4 (hors scope cmem2 direct, même pattern que Pomo Phase 3). Les
Phases 1–3 fonctionnent en polling client.

---

## Phase 0 — Prérequis plugin *(bloquant — déjà planifié dans PLAN_pomo.md)*

1. `src/Core/AbstractPlugin.php` doit exister avec `safeLog()`, `deactivate()`, `getDependencies()`,
   hook `runMigrations()`
2. `src/Core/PluginManager.php` — exclusions hardcodées `'auth_groups'`, `'Core'` supprimées
3. `src/ics/CalendarPlugin.php` — hérite `AbstractPlugin` ; `initialize()` appelle
   `registerPluginRoutes('ics', $this->getRouteHandlers())` sans redéclarer les factories

> Si la Phase 0 du plan Pomo est déjà implémentée → aller directement en Phase 1.

---

## Phase 1 — MVP REST *(4–5 semaines)*

### Routes publiques *(préfixe `/quiz`, auth conditionnelle par sous-route)*

| Méthode | Route | Auth | Description |
| --------- | ------- | ------ | ------------- |
| GET | `/health` | None | Déjà dans cmem2 `PublicRouteHandler` — **rien à créer** |
| POST | `/quiz/join` | `device_id` + body | Rejoindre via code session → retourne `participant_token` |
| GET | `/quiz/session/{session_id}` | `participant_token` | État de la session (`status`, `current_question_idx`, question courante) |
| POST | `/quiz/session/{session_id}/answer` | `participant_token` | Soumettre réponse à la question courante |
| GET | `/quiz/session/{session_id}/leaderboard` | `participant_token` | Classement en direct |

### Routes authentifiées *(JWT Bearer, même préfixe `/quiz`)*

| Méthode | Route | Auth | Description |
| --------- | ------- | ------ | ------------- |
| GET | `/quiz` | JWT | Lister les quiz de l'hôte |
| POST | `/quiz` | JWT | Créer un quiz |
| GET | `/quiz/{id}` | JWT | Lire quiz + questions |
| PUT | `/quiz/{id}` | JWT | Modifier quiz |
| DELETE | `/quiz/{id}` | JWT | Supprimer quiz |
| POST | `/quiz/{id}/questions` | JWT | Ajouter une question |
| PUT | `/quiz/{id}/questions/{q_id}` | JWT | Modifier une question |
| DELETE | `/quiz/{id}/questions/{q_id}` | JWT | Supprimer une question |
| POST | `/quiz/{id}/sessions` | JWT | Lancer une session → génère `session_code` à 6 caractères |
| POST | `/quiz/sessions/{sid}/next` | JWT | Passer à la question suivante |
| POST | `/quiz/sessions/{sid}/end` | JWT | Fermer la session |
| GET | `/quiz/sessions/{sid}/results` | JWT | Résultats détaillés |
| GET | `/quiz/history` | JWT | Historique des sessions de l'hôte |

### Fichiers à créer

1. **`src/quiz/plugin.json`** — name `Quiz`, namespace `Quiz`, main_class `Quiz\QuizPlugin`,
   dépendance `cmem2_core >=1.3.0`
2. **`src/quiz/autoloader.php`** + entrée `"Quiz\\": "src/quiz/"` dans `composer.json`
3. **`src/quiz/QuizPlugin.php`** — hérite `AbstractPlugin` (pas de `safeLog()` à copier — règle Ph. 0-A)
   - `initialize()` → `PluginManager::getInstance()->registerPluginRoutes('quiz', $this->getRouteHandlers())`
   - `getRouteHandlers()` → retourne les lazy factories (clé `quiz`)
   - **Règle** : `initialize()` appelle `$this->getRouteHandlers()` — ne PAS redéclarer les factories (règle Ph. 0-B)
4. **`src/quiz/Routing/QuizRouteHandler.php`** — UN seul handler pour tout `/quiz` ;
   auth conditionnelle par sous-route (`participant_token` ou JWT selon chemin) — même pattern que `PomoRouteHandler`
5. **`src/quiz/Controllers/QuizController.php`** — CRUD quiz, CRUD questions/choix
6. **`src/quiz/Controllers/SessionController.php`** — lancement, avancement, fermeture session
7. **`src/quiz/Controllers/ParticipantController.php`** — join, answer, leaderboard
8. **`src/quiz/Services/SessionService.php`** — scoring (points + temps réponse), ranking,
   génération `session_code` unique
9. **`src/quiz/Validators/QuizValidator.php`** — validation CRUD quiz/questions
10. **`src/quiz/Validators/SessionValidator.php`** — validation join, answer
11. **`src/quiz/Models/Quiz.php`**
12. **`src/quiz/Models/Question.php`**
13. **`src/quiz/Models/Choice.php`**
14. **`src/quiz/Models/Session.php`**
15. **`src/quiz/Models/Participant.php`**
16. **`src/quiz/Models/ParticipantAnswer.php`**
17. **`src/quiz/migrations/001_quiz_base.sql`**

### Schéma `plugin.json`

```json
{
    "name": "Quiz",
    "version": "1.0.0",
    "description": "Module de quiz interactifs en temps réel (type Kahoot)",
    "author": "CMEM Team",
    "namespace": "Quiz",
    "main_class": "Quiz\\QuizPlugin",
    "min_cmem_version": "1.3.0",
    "status": "active",
    "dependencies": {
        "cmem2_core": ">=1.3.0"
    },
    "routes": {
        "prefix": "/quiz"
    },
    "route_handlers": {
        "quiz": "Quiz\\Routing\\QuizRouteHandler"
    },
    "database": {
        "tables": [
            "quiz_quizzes",
            "quiz_questions",
            "quiz_choices",
            "quiz_sessions",
            "quiz_participants",
            "quiz_participant_answers"
        ],
        "migrations_path": "quiz/migrations/"
    }
}
```

### Schéma DB — Migration 001

```sql
-- quiz_quizzes
id              INT AUTO_INCREMENT PRIMARY KEY,
user_id         INT           NOT NULL,                          -- FK users.id (hôte)
title           VARCHAR(255)  NOT NULL,
description     TEXT          NULL,
status          ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
INDEX (user_id)

-- quiz_questions
id              INT AUTO_INCREMENT PRIMARY KEY,
quiz_id         INT NOT NULL,                                    -- FK quiz_quizzes.id
position        SMALLINT NOT NULL DEFAULT 0,
type            ENUM('mcq','truefalse','numerical') NOT NULL,
content         JSON NOT NULL,                                   -- {text, latex, image_url}
points          INT NOT NULL DEFAULT 100,
time_limit_sec  INT NOT NULL DEFAULT 30,
created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
INDEX (quiz_id)

-- quiz_choices
id              INT AUTO_INCREMENT PRIMARY KEY,
question_id     INT NOT NULL,                                    -- FK quiz_questions.id
position        SMALLINT NOT NULL DEFAULT 0,
content         JSON NOT NULL,                                   -- {text, latex}
is_correct      TINYINT(1) NOT NULL DEFAULT 0,
INDEX (question_id)

-- quiz_sessions
id              INT AUTO_INCREMENT PRIMARY KEY,
quiz_id         INT NOT NULL,                                    -- FK quiz_quizzes.id
host_user_id    INT NOT NULL,                                    -- FK users.id
session_code    VARCHAR(8) NOT NULL,
status          ENUM('waiting','active','reviewing','ended') NOT NULL DEFAULT 'waiting',
current_question_idx INT NOT NULL DEFAULT -1,
created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
started_at      DATETIME NULL,
ended_at        DATETIME NULL,
UNIQUE (session_code),
INDEX (quiz_id),
INDEX (host_user_id)

-- quiz_participants
id                  INT AUTO_INCREMENT PRIMARY KEY,
session_id          INT NOT NULL,                                -- FK quiz_sessions.id
display_name        VARCHAR(64) NOT NULL,
device_id           VARCHAR(36) NOT NULL,
participant_token   VARCHAR(64) NOT NULL,
score               INT NOT NULL DEFAULT 0,
rank                INT NULL,
joined_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
UNIQUE (participant_token),
INDEX (session_id)

-- quiz_participant_answers
id                INT AUTO_INCREMENT PRIMARY KEY,
participant_id    INT NOT NULL,                                  -- FK quiz_participants.id
session_id        INT NOT NULL,
question_id       INT NOT NULL,
value             TEXT NOT NULL,                                 -- choice_id ou valeur numérique
is_correct        TINYINT(1) NOT NULL DEFAULT 0,
points_earned     INT NOT NULL DEFAULT 0,
response_time_ms  INT NOT NULL DEFAULT 0,
created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
UNIQUE (participant_id, question_id),                           -- une seule réponse par question
INDEX (session_id, question_id)
```

### Logique métier clé

- **`session_code`** : 6 chiffres alphanumériques, unicité vérifiée en DB, réservé jusqu'à
  `status = 'ended'`
- **`participant_token`** : `HMAC-SHA256(session_id|participant_id|device_id, APP_SECRET)` —
  valide uniquement pour la durée de la session ; renvoie HTTP 403 après `status = 'ended'`
- **Scoring** : `floor(points * max(0, 1 - elapsed_ms / (time_limit_sec * 1000)))` — décroissant
  selon la vitesse de réponse
- **`POST /answer`** : bloqué si `session.status != 'active'` ou si une réponse existe déjà
  pour (`participant_id`, `question_id`) → HTTP 409
- **Polling client** : `GET /quiz/session/{id}` toutes les 2 s ; champ `current_question_idx`
  change quand l'hôte appelle `POST /next`
- **Réponses API** : utiliser `Response::success()` / `Response::error()` de `AuthGroups\Utils\Response`
  — mêmes codes que le reste de cmem2 :
  - Succès : `{"success": true, "data": {...}}` HTTP 200/201
  - Erreur validation : `{"success": false, "errors": [...]}` HTTP 422
  - Non autorisé : HTTP 401/403

---

## Phase 2 — Contenu enrichi *(2–3 semaines) — dépend Phase 1*

1. Documenter et stabiliser la structure `content JSON` :
   `{text: string, latex: string|null, image_url: string|null}`
2. Ajouter à `quiz_questions` :
   - `has_variables TINYINT(1) NOT NULL DEFAULT 0`
   - `variables_config JSON NULL` — définition des variables `{a: {min, max, step}, b: {min, max, step}}`
   - `expression JSON NULL` — expression à évaluer `{formula: "a*x + b", tolerance: 0.01}`
3. **`src/quiz/migrations/002_quiz_variables.sql`** — `ALTER TABLE quiz_questions ADD COLUMN ...`
4. Créer table **`quiz_session_questions`** — snapshot par participant des variables résolues :

```sql
id                  INT AUTO_INCREMENT PRIMARY KEY,
session_id          INT NOT NULL,
question_id         INT NOT NULL,
participant_id      INT NULL,                   -- NULL = partagé entre tous
resolved_variables  JSON NOT NULL,              -- {a: 3, b: 7}
correct_answer      TEXT NOT NULL,              -- pré-calculé lors du snapshot
created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
UNIQUE (session_id, question_id, participant_id),
INDEX (session_id)
```

1. **`src/quiz/migrations/003_quiz_session_questions.sql`**
2. **`src/quiz/Models/SessionQuestion.php`**
3. Mettre à jour `plugin.json` → ajouter `quiz_session_questions` dans `database.tables`

---

## Phase 3 — Moteur dynamique *(3–4 semaines) — dépend Phase 2*

1. Ajouter dans [composer.json](../composer.json) :

   ```json
   "mossadal/math-executor": "^2.0"
   ```

2. **`src/quiz/Services/VariableService.php`**
   - Génère les valeurs aléatoires selon `variables_config` (min/max/step)
   - Substitue dans `expression.formula`
   - Calcule `correct_answer` via `mossadal/math-executor` (liste blanche d'opérateurs — pas `eval`)
   - Retourne le snapshot `resolved_variables` + `correct_answer`
3. **À `POST /quiz/sessions/{sid}/next`** : si `has_variables = true`, appeler `VariableService`
   pour générer un `quiz_session_questions` par participant (si `per_player = true`) ou un
   snapshot partagé
4. **`GET /quiz/session/{id}`** : retourner dans la question courante le champ `rendered_content`
   avec les variables substituées + `latex_source` brut pour rendu KaTeX côté client —
   la `correct_answer` n'est **jamais** transmise avant la fermeture de la question
5. **`POST /quiz/session/{id}/answer`** pour type `numerical` :
   - Récupérer `correct_answer` depuis `quiz_session_questions`
   - Valider `|submitted - correct_answer| <= expression.tolerance`
   - Pour MCQ/V-F : comparer le `choice_id` soumis
6. Stocker `participant_id` + `resolved_variables` dans `quiz_participant_answers` pour audit/replay

### Sécurité moteur math

- Utiliser `math-executor` avec liste blanche explicite : `+`, `-`, `*`, `/`, `^`, `sqrt`, `abs`,
  `round`, `floor`, `ceil`, `sin`, `cos`, `tan`, `log`, `exp` — **pas de `eval()` PHP**
- Les formules sont définies par des utilisateurs authentifiés (JWT hôte) et pré-validées à
  l'enregistrement de la question pour détecter toute syntaxe invalide

---

## Phase 4 — Temps réel Node.js *(hors scope cmem2 direct)*

Même pattern que Pomo Phase 3 — microservice Node.js/WebSocket séparé.

- cmem2 expose un **endpoint webhook interne** `POST /quiz/internal/session-event` :
  - Signé HMAC, IP restreinte au microservice
  - Appelé par `SessionController` lors de `next()` et `end()`
  - Payload : `{event: "question_started"|"session_ended", session_id, data: {...}}`
- Le microservice Node.js :
  - Reçoit les événements cmem2 et les broadcast aux clients WebSocket connectés
  - Les clients s'authentifient avec leur `participant_token` ou JWT hôte
  - Remplace le polling sans modifier les contrats REST existants

> Ce microservice est planifié mais hors scope de ce plan — les Phases 1–3 fonctionnent
> en polling client (GET toutes les 2 s).

---

## Phase 5 — Résultats & export *(1–2 semaines) — dépend Phase 1*

1. **`GET /quiz/sessions/{sid}/results`** : agrégation par question — taux de bonnes réponses,
   temps moyen, distribution des choix
2. **`src/quiz/Services/ExportService.php`** — export CSV des résultats
3. **`GET /quiz/sessions/{sid}/export?format=csv`** — téléchargement direct
4. **`src/quiz/migrations/004_quiz_indexes.sql`** — index perf :

   ```sql
   ALTER TABLE quiz_participant_answers ADD INDEX idx_session_question (session_id, question_id);
   ALTER TABLE quiz_sessions ADD INDEX idx_status (status);
   ```

---

## Récapitulatif des fichiers

| Fichier | Action | Phase |
| --------- | -------- | ------- |
| `composer.json` | Modifier — namespace `Quiz\\` + `mossadal/math-executor` | 1 + 3 |
| `src/quiz/plugin.json` | Créer | 1 |
| `src/quiz/autoloader.php` | Créer | 1 |
| `src/quiz/QuizPlugin.php` | Créer | 1 |
| `src/quiz/Routing/QuizRouteHandler.php` | Créer | 1 |
| `src/quiz/Controllers/QuizController.php` | Créer | 1 |
| `src/quiz/Controllers/SessionController.php` | Créer | 1 |
| `src/quiz/Controllers/ParticipantController.php` | Créer | 1 |
| `src/quiz/Services/SessionService.php` | Créer | 1 |
| `src/quiz/Services/VariableService.php` | Créer | 3 |
| `src/quiz/Services/ExportService.php` | Créer | 5 |
| `src/quiz/Validators/QuizValidator.php` | Créer | 1 |
| `src/quiz/Validators/SessionValidator.php` | Créer | 1 |
| `src/quiz/Models/Quiz.php` | Créer | 1 |
| `src/quiz/Models/Question.php` | Créer | 1 |
| `src/quiz/Models/Choice.php` | Créer | 1 |
| `src/quiz/Models/Session.php` | Créer | 1 |
| `src/quiz/Models/Participant.php` | Créer | 1 |
| `src/quiz/Models/ParticipantAnswer.php` | Créer | 1 |
| `src/quiz/Models/SessionQuestion.php` | Créer | 2 |
| `src/quiz/migrations/001_quiz_base.sql` | Créer | 1 |
| `src/quiz/migrations/002_quiz_variables.sql` | Créer | 2 |
| `src/quiz/migrations/003_quiz_session_questions.sql` | Créer | 2 |
| `src/quiz/migrations/004_quiz_indexes.sql` | Créer | 5 |

**Patterns de référence :**

- [src/ics/CalendarPlugin.php](../src/ics/CalendarPlugin.php) — structure `initialize()`, lazy factories
- [src/ics/plugin.json](../src/ics/plugin.json) — format `plugin.json` avec `route_handlers`
- [src/ics/autoloader.php](../src/ics/autoloader.php) — `spl_autoload_register` par namespace
- [docs/PLAN_pomo.md](PLAN_pomo.md) — Phase 0 prérequis `AbstractPlugin` + pattern handler conditionnel (single handler)

---

## Structure répertoire finale `src/quiz/`

```text
src/quiz/
├── plugin.json
├── autoloader.php
├── QuizPlugin.php
├── Controllers/
│   ├── QuizController.php            (Phase 1)
│   ├── SessionController.php         (Phase 1)
│   └── ParticipantController.php     (Phase 1)
├── Models/
│   ├── Quiz.php                      (Phase 1)
│   ├── Question.php                  (Phase 1)
│   ├── Choice.php                    (Phase 1)
│   ├── Session.php                   (Phase 1)
│   ├── Participant.php               (Phase 1)
│   ├── ParticipantAnswer.php         (Phase 1)
│   └── SessionQuestion.php           (Phase 2)
├── Routing/
│   └── QuizRouteHandler.php          (Phase 1 — auth conditionnelle par sous-route)
├── Validators/
│   ├── QuizValidator.php             (Phase 1)
│   └── SessionValidator.php          (Phase 1)
├── Services/
│   ├── SessionService.php            (Phase 1)
│   ├── VariableService.php           (Phase 3)
│   └── ExportService.php             (Phase 5)
└── migrations/
    ├── 001_quiz_base.sql              (Phase 1)
    ├── 002_quiz_variables.sql         (Phase 2)
    ├── 003_quiz_session_questions.sql (Phase 2)
    └── 004_quiz_indexes.sql           (Phase 5)
```

---

## Décisions

| Sujet | Décision |
| ------- | ---------- |
| Routes préfixées | `/quiz` — préfixe unique toutes routes public + JWT ; évite toute collision cmem2 core |
| `GET /health` | **Non créé** — déjà dans `PublicRouteHandler` cmem2 core, le plugin n'y touche pas |
| Handler unique | UN seul `QuizRouteHandler` (auth conditionnelle par sous-route) — même pattern que `PomoRouteHandler` ; pas deux handlers séparés sur le même préfixe |
| `initialize()` | Appelle `registerPluginRoutes('quiz', $this->getRouteHandlers())` — ne redéclare PAS les factories (règle Ph. 0-B) |
| `safeLog()` | **Non copié dans `QuizPlugin`** — hérité de `AbstractPlugin` (règle Ph. 0-A) |
| Auth participants | Pseudonyme + `device_id` uniquement — pas de compte cmem2 requis (comme Kahoot classique) |
| `participant_token` | HMAC-SHA256 signé, scope session, HTTP 403 après `ended` |
| Temps réel Phase 1–3 | Polling `GET /session/{id}` côté client — Node.js WS en Phase 4 remplace sans changer les contrats REST |
| Moteur math | `mossadal/math-executor` PHP côté serveur — liste blanche d'opérateurs, pas de `eval()` |
| Rendu LaTeX | `latex_source` transmis brut au client → rendu KaTeX côté navigateur |
| `correct_answer` | Jamais transmise avant fermeture de la question (`POST /next` ou `POST /end`) |
| Unicité réponse | `UNIQUE (participant_id, question_id)` en DB + HTTP 409 en code |
| Anti-triche | Variables différentes par joueur si `per_player = true` + `correct_answer` calculée côté serveur uniquement |

---

## Estimation

| Phase | Contenu cmem2 | Durée estimée |
| ------- | -------------- | --------------- |
| Phase 0 | Prérequis AbstractPlugin (commun Pomo) | — (voir PLAN_pomo.md) |
| Phase 1 | MVP REST plugin | ~4–5 semaines |
| Phase 2 | Champs variables + table snapshot | ~2–3 semaines |
| Phase 3 | Moteur math PHP | ~3–4 semaines |
| Phase 4 | Webhook + microservice Node.js WS | ~4 semaines (hors cmem2) |
| Phase 5 | Export CSV + agrégations | ~1–2 semaines |

- **Total backend cmem2 (Ph. 1–3 + 5) : ~10–14 semaines**

---

## Vérification par phase

**Phase 0 :**

- `GET /health` renvoie 200, plugin ICS toujours actif — aucune régression

**Phase 1 :**

- `POST /quiz` (JWT) → 201, quiz créé
- `POST /quiz/{id}/questions` (JWT) → 201, question ajoutée
- `POST /quiz/{id}/sessions` (JWT) → `session_code` à 6 caractères retourné
- `POST /quiz/join` (device_id + body) → `participant_token` HMAC retourné
- `GET /quiz/session/{id}` (participant_token) → statut + question courante
- `POST /quiz/session/{id}/answer` (participant_token) → 200 ou 409 si doublon
- Scénario complet : 2 participants rejoignent → hôte `POST /next` → `GET /leaderboard` mis à jour
- `participant_token` d'une session `ended` → HTTP 403
- `GET /health` → toujours 200 (non impacté)

**Phase 2 :**

- Question avec `has_variables = true` acceptée → `variables_config` + `expression` persistés

**Phase 3 :**

- `POST /quiz/sessions/{sid}/next` sur question dynamique → `quiz_session_questions` généré par participant
- `GET /quiz/session/{id}` → `rendered_content` avec valeurs substituées, `latex_source` brut
- Réponse numérique dans la tolérance → `is_correct = true` ; hors tolérance → `is_correct = false`
- 2 participants reçoivent des `rendered_content` différents si `per_player = true`
- Formule invalide à l'enregistrement → HTTP 422

**Phase 5 :**

- `GET /quiz/sessions/{sid}/results` → agrégation correcte par question
- `GET /quiz/sessions/{sid}/export?format=csv` → fichier téléchargeable

---

## Évolutions hors scope actuel

- Mode asynchrone (quiz hors live)
- Génération de questions par IA
- Analyse de performance des étudiants / tableaux de bord
- Intégration LMS (Moodle, Canvas)
- Application mobile
- Export PDF des résultats
