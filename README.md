# cmem2 API

![Version](https://img.shields.io/badge/version-2.2.3-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.0+-purple.svg)
![Status](https://img.shields.io/badge/status-production%20ready-green.svg)
![License](https://img.shields.io/badge/license-MIT-orange.svg)

API REST modulaire pour la plateforme **Memories v2**. Elle regroupe quatre modules : authentification/groupes (core), calendriers ICS/CalDAV, Pomodoro et Quiz interactif.

Authentification **JWT** HS256 (Bearer, 15 jours). Deux méthodes de connexion : **email + mot de passe** ou **email + code OTP**.

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Technologies](#technologies)
- [Installation](#installation)
- [Configuration](#configuration)
- [Architecture](#architecture)
- [Authentification](#authentification)
- [Modules et endpoints](#modules-et-endpoints)
- [Documentation](#documentation)
- [Tests](#tests)
- [Conventions](#conventions)
- [Roadmap](#roadmap)
- [Licence](#licence)

## Vue d'ensemble

cmem2 API fournit :

- **Core (auth_groups)** : JWT, utilisateurs, groupes, fichiers, tags, statistiques, webhooks
- **ICS/CalDAV** : Calendriers iCalendar, export `.ics`, synchronisation CalDAV RFC 5545
- **Pomo** : Plugin Pomodoro — engagement waitlist/sondage, support, sync cloud
- **Quiz** : Quiz interactifs en temps réel (style Kahoot) — sessions, scoring dégressif, leaderboard

## Technologies

- **PHP 8.0+** — langage principal
- **MySQL / MariaDB** — base de données
- **PHPMailer** — envoi d'emails (inscription, OTP, support)
- **sabre/vobject** — génération et parsage iCalendar
- **simshaun/recurr** — moteur de récurrence RRULE
- **PHPUnit** — tests unitaires
- **Composer** — gestion des dépendances

## Installation

**Prérequis** : PHP >= 8.0, MySQL >= 5.7 ou MariaDB >= 10.3, Composer, extensions PDO / mbstring / openssl / fileinfo.

```bash
git clone https://github.com/Jrobitaille360/cmem2.git
cd cmem2_API
composer install
```

Créer la base de données :

```bash
mysql -u root -p < docs/build_cmem2_DB.sql
```

Configurer l'environnement :

```bash
cp .env.auth_groups.example .env.auth_groups
# éditer .env.auth_groups avec vos valeurs
```

Permissions :

```bash
chmod -R 755 uploads/ tmp_assets/
```

Lancer le serveur de développement :

```bash
composer serve
# écoute sur http://localhost:8080
```

## Configuration

Toutes les variables sont dans `.env.auth_groups` (ne pas versionner).

| Variable | Description | Exemple |
| --- | --- | --- |
| `DB_HOST` | Hôte MySQL | `localhost` |
| `DB_NAME` | Nom de la base | `cmem2_db` |
| `DB_USER` | Utilisateur | `root` |
| `DB_PASS` | Mot de passe | — |
| `JWT_SECRET` | Clé HMAC ≥ 32 chars **(obligatoire)** | — |
| `JWT_EXPIRY_DAYS` | Durée JWT | `15` |
| `OTP_EXPIRY_MINUTES` | Durée code OTP | `15` |
| `MAIL_HOST` | Serveur SMTP | `smtp.example.com` |
| `MAIL_PORT` | Port SMTP | `587` |
| `MAIL_USERNAME` | Courriel SMTP | — |
| `MAIL_PASSWORD` | Mot de passe SMTP | — |
| `MAIL_FROM` | Expéditeur | `no_reply@journauxdebord.com` |
| `MAINTENANCE_MODE` | Mode maintenance | `false` |

## Architecture

```text
cmem2_API/
├── index.php                  # Point d'entrée unique
├── composer.json
├── src/
│   ├── auth_groups/           # Module core (auth, groupes, fichiers, tags)
│   │   ├── Controllers/
│   │   ├── Models/
│   │   ├── Services/          # AuthService, MailService, LogService
│   │   ├── Routing/           # Router + RouteHandlers
│   │   ├── Middleware/        # LoggingMiddleware
│   │   ├── Utils/             # Response, Validator, helpers
│   │   ├── database.php
│   │   ├── environment.php
│   │   └── loader.php
│   ├── ics/                   # Module calendriers ICS/CalDAV
│   │   ├── Controllers/
│   │   ├── Models/
│   │   ├── Services/          # ICSService, CalDAVService, RecurrenceService
│   │   └── Routing/
│   ├── pomo/                  # Plugin Pomodoro
│   │   ├── Controllers/
│   │   ├── Models/
│   │   └── Routing/
│   ├── quiz/                  # Plugin Quiz interactif
│   │   ├── Controllers/       # QuizController, SessionController, ParticipantController
│   │   ├── Models/
│   │   ├── Validators/        # QuizValidator
│   │   └── Routing/
│   └── logs/
├── docs/
│   ├── core/                  # Documentation module core
│   ├── ics/                   # Documentation module ICS
│   ├── pomo/                  # Documentation plugin Pomo
│   └── quiz/                  # Documentation plugin Quiz
├── uploads/                   # Fichiers uploadés (avatars, groupes)
├── tmp_assets/                # Fichiers temporaires / exports
└── private/                   # Scripts maintenance (non exposés)
```

Namespaces PSR-4 :

- `AuthGroups\` → `src/auth_groups/`
- `Pomo\` → `src/pomo/`
- `Quiz\` → `src/quiz/`

## Authentification

Toutes les requêtes protégées utilisent `Authorization: Bearer <jwt_token>`.

### Obtenir un token — email + mot de passe

```http
POST /auth/login
Content-Type: application/json

{"email": "user@example.com", "password": "monMotDePasse"}
```

Réponse :

```json
{
  "token": "eyJhbGci...",
  "token_type": "Bearer",
  "expires_at": "2026-04-20 12:00:00",
  "user": {"id": 1, "name": "Alice", "email": "...", "role": "UTILISATEUR"}
}
```

### Obtenir un token — code OTP

```http
POST /auth/send-code
Content-Type: application/json
{"email": "user@example.com"}

POST /auth/verify-code
Content-Type: application/json
{"email": "user@example.com", "code": "482917"}
```

### Déconnexion

```http
POST /auth/logout
Authorization: Bearer eyJhbGci...
```

### Protection anti-brute-force

5 tentatives max toutes les 10 minutes par email+IP (table `login_attempts`). Retourne HTTP 429 au dépassement.

## Modules et endpoints

### Core — Auth

| Méthode | Endpoint | Description | Auth |
| --- | --- | --- | --- |
| POST | `/auth/login` | Connexion email + mot de passe | Non |
| POST | `/auth/send-code` | Demander un code OTP | Non |
| POST | `/auth/verify-code` | Vérifier OTP → JWT | Non |
| POST | `/auth/logout` | Invalider le JWT | JWT |
| POST | `/auth/refresh` | Renouveler via device token | Non |

### Core — Utilisateurs

| Méthode | Endpoint | Description | Auth |
| --- | --- | --- | --- |
| POST | `/users/register` | Inscription | Non |
| GET | `/users/me` | Profil courant | JWT |
| PUT | `/users/me` | Modifier profil | JWT |
| DELETE | `/users/me` | Supprimer compte | JWT |
| POST | `/users/avatar` | Upload avatar | JWT |
| GET | `/users/{id}` | Détails utilisateur | JWT |
| POST | `/users/verify-email` | Vérifier email | Non |
| POST | `/users/reset-password` | Réinitialiser mot de passe | Non |

### Core — Groupes

| Méthode | Endpoint | Description | Auth |
| --- | --- | --- | --- |
| GET | `/groups` | Liste des groupes | JWT |
| POST | `/groups` | Créer un groupe | JWT |
| GET | `/groups/{id}` | Détails d'un groupe | JWT |
| PUT | `/groups/{id}` | Modifier un groupe | JWT |
| DELETE | `/groups/{id}` | Supprimer un groupe | JWT |
| POST | `/groups/{id}/invite` | Inviter un membre | JWT |
| GET | `/groups/search` | Rechercher des groupes | JWT |

### Core — Fichiers, Tags, Stats, Webhooks

| Méthode | Endpoint | Description | Auth |
| --- | --- | --- | --- |
| POST | `/files/upload` | Upload fichier(s) | JWT |
| GET | `/files` | Liste des fichiers | JWT |
| DELETE | `/files/{id}` | Supprimer (soft) | JWT |
| PUT | `/files/{id}/restore` | Restaurer | JWT |
| GET/POST/PUT/DELETE | `/tags/*` | CRUD tags | JWT |
| GET | `/stats/user/{id}` | Stats utilisateur | JWT |
| GET | `/stats/online` | Utilisateurs en ligne | JWT |
| GET/POST/PUT/DELETE | `/webhooks/*` | CRUD webhooks | JWT |

### ICS / CalDAV

| Méthode | Endpoint | Description | Auth |
| --- | --- | --- | --- |
| GET | `/calendar/{token}.ics` | Téléchargement ICS public | Non |
| OPTIONS | `/caldav/` | Discovery CalDAV | Non |
| GET/POST/PUT/DELETE | `/calendars/*` | CRUD calendriers | JWT |
| GET/POST/PUT/DELETE | `/events/*` | CRUD événements | JWT |
| GET/POST/PUT/DELETE | `/attendees/*` | Participants (Ph3) | JWT |
| POST | `/calendars/import` | Import ICS (upsert par UID) | JWT |
| * | `/caldav/*` | Protocole CalDAV complet | JWT |

Voir [docs/ics/GUIDE.md](docs/ics/GUIDE.md) pour la référence complète.

### Pomo

| Méthode | Endpoint | Description | Auth |
| --- | --- | --- | --- |
| POST | `/pomo/engagement` | Waitlist ou sondage MVP | Non |
| POST | `/pomo/support` | Formulaire de support | JWT |
| POST | `/pomo/stripe/webhook` | Webhook Stripe (Ph3) | Signature Stripe |

Voir [docs/pomo/GUIDE.md](docs/pomo/GUIDE.md) pour la référence complète.

### Quiz

| Méthode | Endpoint | Description | Auth |
| --- | --- | --- | --- |
| POST | `/quiz/join` | Rejoindre une session (code) | Non |
| GET | `/quiz/session/{id}` | État de la session | participant_token |
| POST | `/quiz/session/{id}/answer` | Soumettre une réponse | participant_token |
| GET | `/quiz/session/{id}/leaderboard` | Classement en direct | participant_token |
| GET/POST | `/quiz` et `/quiz/{id}` | CRUD quiz | JWT |
| POST/PUT/DELETE | `/quiz/{id}/questions[/{q_id}]` | CRUD questions | JWT |
| POST | `/quiz/{id}/sessions` | Créer une session | JWT |
| POST | `/quiz/sessions/{sid}/next` | Question suivante | JWT |
| POST | `/quiz/sessions/{sid}/end` | Terminer la session | JWT |
| GET | `/quiz/sessions/{sid}/results` | Résultats finaux | JWT |
| GET | `/quiz/history` | Historique des sessions | JWT |

Voir [docs/quiz/GUIDE.md](docs/quiz/GUIDE.md) pour la référence complète.

### Public

| Méthode | Endpoint | Description |
| --- | --- | --- |
| GET | `/` | Informations API |
| GET | `/health` | Statut de l'API |

## Documentation

| Module | Référence JSON | Guide |
| --- | --- | --- |
| Core (auth_groups) | [docs/core/API_ENDPOINTS.json](docs/core/API_ENDPOINTS.json) | [docs/core/GUIDE.md](docs/core/GUIDE.md) |
| ICS / CalDAV | [docs/ics/API_ICS_ENDPOINTS.json](docs/ics/API_ICS_ENDPOINTS.json) | [docs/ics/GUIDE.md](docs/ics/GUIDE.md) |
| Pomo | [docs/pomo/API_POMO_ENDPOINTS_v1_0_0.json](docs/pomo/API_POMO_ENDPOINTS_v1_0_0.json) | [docs/pomo/GUIDE.md](docs/pomo/GUIDE.md) |
| Quiz | [docs/quiz/API_QUIZ_ENDPOINTS_v1_0_0.json](docs/quiz/API_QUIZ_ENDPOINTS_v1_0_0.json) | [docs/quiz/GUIDE.md](docs/quiz/GUIDE.md) |

Migrations : [docs/core/](docs/core/) · Schéma initial : [docs/build_cmem2_DB.sql](docs/build_cmem2_DB.sql)

## Tests

```bash
# Plugin Quiz (104 tests)
php private/tests_mine/test_quiz.php

# Module ICS
php private/tests_mine/test_new_calendar_entrypoints_1.php

# Module Pomo
php private/tests_mine/test_pomo.php
```

Les scripts de test utilisent les helpers de `private/tests_mine/test_new_base.php` (`callApiWithJWT`, `testNewResult`, `printNewSection`).

## Conventions

- **Namespaces** : `AuthGroups\`, `Pomo\`, `Quiz\`
- **Classes** : PascalCase
- **Méthodes** : camelCase
- **Colonnes DB** : snake_case
- **Réponses** : `{ success: bool, message?: string, data?: object, errors?: array }`
- **Codes HTTP** : 200 succès · 201 créé · 401 non authentifié · 403 interdit · 404 introuvable · 409 conflit · 422 validation · 429 rate limit

## Roadmap

- [x] Core auth/groupes/fichiers/tags (v2.0)
- [x] JWT + OTP + blacklist + anti-brute-force (v2.2)
- [x] Module ICS/CalDAV (Ph1–Ph5) (v2.2)
- [x] Plugin Pomo Ph1 (v2.2)
- [x] Plugin Quiz Ph1 — MVP REST (v2.2.3)
- [ ] Plugin Quiz Ph2 — Variables dynamiques
- [ ] Plugin Quiz Ph3 — Moteur math (mossadal/math-executor)
- [ ] Plugin Quiz Ph4 — WebSocket Node.js (temps réel)
- [ ] Plugin Quiz Ph5 — Export CSV
- [ ] Rate limiting Redis
- [ ] Notifications push

## Licence

MIT — voir [LICENSE](LICENSE). Dépendances tierces : [THIRD_PARTY_LICENSES.md](THIRD_PARTY_LICENSES.md).

---

**Version** : 2.2.3 · **Mis à jour** : 2026-04-05 · **Auteur** : [Jrobitaille360](https://github.com/Jrobitaille360)
