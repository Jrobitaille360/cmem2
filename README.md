# cmem2 API

![Version](https://img.shields.io/badge/version-2.16.1-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.0+-purple.svg)
![Status](https://img.shields.io/badge/status-production%20ready-green.svg)
![License](https://img.shields.io/badge/license-MIT-orange.svg)

API REST modulaire pour la plateforme **Memories v2**. Elle regroupe les modules : authentification/groupes (core), calendriers ICS/CalDAV, Pomodoro, Quiz interactif, gestionnaire générique Items, pilier Contacts (CRM), gestion de projet (Projets), puzzle collaboratif, jeu Traque, contrôle d'accès aux abonnements (Access), paiements Stripe, vérification Playstore, notifications push web VAPID (Push) et gestion des tokens push web (WebDevice).

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
- **ICS/CalDAV** : Calendriers iCalendar, tâches (VTODO), journaux (VJOURNAL), étiquettes par calendrier, export `.ics`, synchronisation CalDAV RFC 5545
- **Pomo** : Plugin Pomodoro — engagement waitlist/sondage, support, sync cloud
- **Quiz** : Quiz interactifs en temps réel (style Kahoot) — sessions, scoring dégressif, leaderboard
- **Items** : Gestionnaire générique d'items (private/public/share), catégories JSON, partages utilisateurs
- **Contacts** : Pilier Contacts (CRM) — fiches personnes/organisations owner-strict, vCard 4.0, cap `max_contacts`, envoi de courriel, historique d'interactions (appels/notes/rdv/sms)
- **Projets** : Gestion de projet — tâches, hiérarchie, dépendances (FS/SS/FF/SF), round-trip JSON, export `.ics`
- **Booking** : Réservation publique par lien — page hôte sans authentification, zones matérialisées, réservation atomique, annulation par jeton
- **Puzzle** : Puzzle collaboratif — pick/drop de pièces, sessions partagées
- **Traque** : Jeu type combat/exploration — monstres, biomes OSM, achievements, rôles (`gm`, `traque_admin`)
- **Access** : Contrôle d'accès aux abonnements — croisement Stripe / Playstore
- **Stripe** : Paiements et abonnements Stripe
- **Playstore** : Vérification des abonnements Google Play Store
- **WebDevice** : Gestion des tokens de notification push web
- **Push** : Notifications Web Push (VAPID) — abonnements par device, préférences par compte, envoi cron
- **Modules** : Registre de modules activables — gating par plan, toggle usager
- **IA** : Proxy IA — résumé d'agenda via `POST /ai/summarize`, gating par module `ia`

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
cp .env.example .env
# éditer .env avec vos valeurs
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

Toutes les variables sont dans `.env` (gitignored). Gabarit : [`.env.example`](.env.example).

### Variables dépendantes de l'environnement

Ces variables changent selon la cible de déploiement (marquées `↓ à ajuster` dans `.env`) :

| Variable | dev.local | dev.online | prod |
| - | - | - | - |
| `APP_ENV` | `development` | `development` | `production` |
| `APP_DEBUG` | `true` | `true` | `false` |
| `DB_HOST` | `localhost` | `nom de l'hôte de developpement` | `nom de l'hôte' de production` |
| `DB_NAME` | `nom de la base de donnée en développement local` | `nom de la base de donnée en développement online` | `nom de la base de donnée en production` |
| `APP_URL` / `BASE_URL` | `http://localhost/api` | `https://dev-votre_site.com` | `https://api.votre_site.com` |
| `BASE_PATH` | `/api` | `/` | `/` |
| `ALLOWED_ORIGINS` | origines localhost | domaine dev | domaine prod |
| `LOG_LEVEL` | `debug` | `debug` | `info` |
| `LOG_DIR` | `logs/` | `logs/` | `logs/` |
| `BACKUP_DIR` | chemin local | `/home/xxx/backups/` | `/home/xxx/backups/` |
| `STRIPE_SECRET_KEY` | `sk_test_…` | `sk_test_…` | `sk_live_…` |
| `PUZZLE_DEBUG_PREMIUM` | `false` | `false` | `false` |

`LOG_DIR` accepte un chemin relatif à la racine du projet (`logs/`) ou un chemin absolu
(`/home/xxx/logs/`, `C:\logs`). `BACKUP_DIR` attend un chemin absolu.

### Variables sensibles

À ne jamais committer. Générer des valeurs fortes en production :

| Variable | Description |
| - | - |
| `JWT_SECRET` | Clé HMAC HS256 — minimum 32 caractères |
| `ADMIN_SECRET_KEY` | Clé d'accès à l'API d'administration |
| `SECRET_ADMIN_ENDPOINT` | Segment d'URL de l'endpoint admin |
| `DB_PASS` | Mot de passe MySQL |
| `SMTP_PASSWORD` | Mot de passe SMTP |
| `BACKUP_PASSPHRASE` | Passphrase de chiffrement des backups |
| `STRIPE_SECRET_KEY` | Clé secrète Stripe (`sk_test_` ou `sk_live_`) |
| `STRIPE_WEBHOOK_SECRET` | Secret de signature webhook Stripe |

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
│   ├── items/                 # Gestionnaire générique d'items
│   ├── projets/                # Gestion de projet — tâches, hiérarchie, dépendances
│   │   ├── Controllers/
│   │   ├── Models/
│   │   ├── Services/           # GraphValidator, JsonRoundTrip
│   │   ├── Ical/                # VEventSerializer (export .ics)
│   │   └── Routing/
│   ├── puzzle/                # Puzzle collaboratif
│   ├── traque/                # Jeu combat/exploration
│   ├── access/                # Contrôle d'accès abonnements
│   ├── stripe/                # Paiements Stripe
│   ├── playstore/             # Vérification Playstore
│   ├── webdevice/             # Tokens push web
│   ├── notifications/         # Scripts notifications email (pas de routing)
│   ├── cron/                  # Scripts cron backup (pas de routing)
│   └── logs/
├── docs/
│   ├── core/                  # Documentation module core
│   ├── ics/                   # Documentation module ICS
│   ├── pomo/                  # Documentation plugin Pomo
│   └── quiz/                  # Documentation plugin Quiz
├── uploads/                   # Fichiers uploadés (avatars, groupes)
└── tmp_assets/                # Fichiers temporaires / exports
```

Namespaces PSR-4 :

- `AuthGroups\` → `src/auth_groups/`
- `ICS\` → `src/ics/`
- `Pomo\` → `src/pomo/`
- `Quiz\` → `src/quiz/`
- `Items\` → `src/items/`
- `Projets\` → `src/projets/`
- `Puzzle\` → `src/puzzle/`
- `Traque\` → `src/traque/`
- `Access\` → `src/access/`
- `Stripe\` → `src/stripe/`
- `Playstore\` → `src/playstore/`
- `WebDevice\` → `src/webdevice/`

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
| POST | `/users/verify-email` | Vérifier email (token à 8 chiffres reçu par courriel) | Non |
| POST | `/users/resend-verification-email` | Renvoyer le courriel de vérification | Non |
| POST | `/users/request-password-reset` | Demander un code de réinitialisation | Non |
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
| GET | `/files` | Lister les fichiers d'un dossier (`?folder=<slug>`) | JWT ADMIN |
| POST | `/files` | Uploader un fichier (images, docs, audio, vidéo, exe/zip jusqu'à 200 MB) | JWT |
| GET | `/files/{id}` | Télécharger le contenu binaire | JWT |
| GET | `/files/{id}/info` | Métadonnées d'un fichier | JWT |
| DELETE | `/files/{id}` | Supprimer (soft) | JWT |
| POST | `/files/{id}/restore` | Restaurer | JWT |
| GET | `/files/user/{user_id}` | Liste paginée des fichiers d'un utilisateur | JWT |
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
| GET/POST/PUT/DELETE | `/calendars/{id}/tags[/{tagId}]` | Étiquettes partagées par calendrier, cascade sur `categories[]` | JWT |
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

### Items

| Méthode | Endpoint | Description | Auth |
| --- | --- | --- | --- |
| GET | `/items/publics` | Liste les items publics sans JWT (filtres : category, limit, offset) | Aucune |
| GET | `/items` | Liste des items accessibles (filtres : owner, access, category, limit) | JWT |
| POST | `/items` | Créer un item | JWT |
| GET | `/items/categories` | Catégories distinctes accessibles | JWT |
| GET | `/items/categories/{name}` | Items d'une catégorie | JWT |
| GET | `/items/{id}` | Lire un item (sans JWT si `access=public`) | JWT / aucune |
| PUT | `/items/{id}` | Mettre à jour categories / json_item | JWT |
| DELETE | `/items/{id}` | Soft-delete (owner/admin) | JWT |
| PUT | `/items/{id}/access` | Changer le mode d'accès (owner/admin) | JWT |
| GET/POST | `/items/{id}/shares` | Lister / ajouter des invités | JWT |
| PUT/DELETE | `/items/{id}/shares/{user_id}` | Modifier / retirer un invité (owner/admin) | JWT |

Voir [docs/items/GUIDE.md](docs/items/GUIDE.md) pour la référence complète.

### Booking

| Méthode | Endpoint | Description | Auth |
| --- | --- | --- | --- |
| GET/PUT/DELETE | `/booking/page` | Configuration de la page de réservation de l'hôte | JWT |
| GET | `/booking/public/{slug}` | Nom d'affichage, durée, fuseau de l'hôte | Non |
| GET | `/booking/public/{slug}/slots` | Zones libres dans une plage (max 60 jours) | Non |
| POST | `/booking/public/{slug}/book` | Réserver une zone (atomique) | Non |
| POST | `/booking/public/cancel/{token}` | Annuler par lien à jeton | Non |

Voir [docs/booking/GUIDE.md](docs/booking/GUIDE.md) pour la référence complète.

### Public

| Méthode | Endpoint | Description |
| --- | --- | --- |
| GET | `/` | Informations API |
| GET | `/health` | Statut de l'API |
| GET | `/entrypoints` | Liste des modules avec documentation JSON des endpoints |
| GET | `/entrypoints/{module}` | Contenu JSON des endpoints du module |

## Documentation

| Module | Référence JSON | Guide |
| --- | --- | --- |
| Core (auth_groups) | [docs/core/API_ENDPOINTS.json](docs/core/API_ENDPOINTS.json) | [docs/core/GUIDE.md](docs/core/GUIDE.md) |
| ICS / CalDAV | [docs/ics/API_ICS_ENDPOINTS.json](docs/ics/API_ICS_ENDPOINTS.json) | [docs/ics/GUIDE.md](docs/ics/GUIDE.md) |
| Pomo | [docs/pomo/API_POMO_ENDPOINTS_v1_0_0.json](docs/pomo/API_POMO_ENDPOINTS_v1_0_0.json) | [docs/pomo/GUIDE.md](docs/pomo/GUIDE.md) |
| Quiz | [docs/quiz/API_QUIZ_ENDPOINTS_v1_0_0.json](docs/quiz/API_QUIZ_ENDPOINTS_v1_0_0.json) | [docs/quiz/GUIDE.md](docs/quiz/GUIDE.md) |
| Items | [docs/items/API_ITEMS_ENDPOINTS.json](docs/items/API_ITEMS_ENDPOINTS.json) | [docs/items/GUIDE.md](docs/items/GUIDE.md) |
| Contacts | [docs/contacts/API_CONTACTS_ENDPOINTS.json](docs/contacts/API_CONTACTS_ENDPOINTS.json) | [docs/contacts/GUIDE.md](docs/contacts/GUIDE.md) |
| Projets | [docs/projets/API_PROJETS_ENDPOINTS.json](docs/projets/API_PROJETS_ENDPOINTS.json) | — |
| Booking | [docs/booking/API_BOOKING_ENDPOINTS.json](docs/booking/API_BOOKING_ENDPOINTS.json) | [docs/booking/GUIDE.md](docs/booking/GUIDE.md) |
| Puzzle | [docs/puzzle/API_PUZZLE_ENDPOINTS.json](docs/puzzle/API_PUZZLE_ENDPOINTS.json) | [docs/puzzle/GUIDE.md](docs/puzzle/GUIDE.md) |
| Traque | [docs/traque/API_TRAQUE_ENDPOINTS.json](docs/traque/API_TRAQUE_ENDPOINTS.json) | [docs/traque/GUIDE.md](docs/traque/GUIDE.md) |
| Access | [docs/access/API_ACCESS_ENDPOINTS.json](docs/access/API_ACCESS_ENDPOINTS.json) | [docs/access/GUIDE.md](docs/access/GUIDE.md) |
| Stripe | [docs/stripe/API_STRIPE_ENDPOINTS.json](docs/stripe/API_STRIPE_ENDPOINTS.json) | [docs/stripe/GUIDE.md](docs/stripe/GUIDE.md) |
| Playstore | [docs/playstore/API_PLAYSTORE_ENDPOINTS.json](docs/playstore/API_PLAYSTORE_ENDPOINTS.json) | [docs/playstore/GUIDE.md](docs/playstore/GUIDE.md) |
| WebDevice | [docs/webdevice/API_WEBDEVICE_ENDPOINTS.json](docs/webdevice/API_WEBDEVICE_ENDPOINTS.json) | [docs/webdevice/GUIDE.md](docs/webdevice/GUIDE.md) |
| Push | [docs/push/API_PUSH_ENDPOINTS.json](docs/push/API_PUSH_ENDPOINTS.json) | [docs/push/GUIDE.md](docs/push/GUIDE.md) |
| Modules | [docs/modules/API_MODULES_ENDPOINTS.json](docs/modules/API_MODULES_ENDPOINTS.json) | [docs/modules/GUIDE.md](docs/modules/GUIDE.md) |
| IA | [docs/ai/API_AI_ENDPOINTS.json](docs/ai/API_AI_ENDPOINTS.json) | [docs/ai/GUIDE.md](docs/ai/GUIDE.md) |

Migrations : [docs/core/](docs/core/) · Schéma initial : [docs/build_cmem2_DB.sql](docs/build_cmem2_DB.sql)

## Tests

```bash
# Plugin Items
php private/tests/test_items.php

# Plugin Quiz
php private/tests/test_quiz.php

# Module ICS (calendriers, événements, récurrence, tags)
php private/tests/test_calendars.php

# Module Pomo
php private/tests/test_pomo.php

# Tous les tests
php private/tests/run_all_tests.php
```

Chaque module a son propre fichier `private/tests/test_<module>.php` (voir la liste complète dans `CLAUDE.md`). Les scripts utilisent les helpers de `private/tests/test_new_base.php` (`callNewApi`, `callApiWithJWT`, `testNewResult`, `printNewSection`).

## Conventions

- **Namespaces** : `AuthGroups\`, `Pomo\`, `Quiz\`, `Items\`
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
- [x] Plugin Items — gestionnaire générique private/public/share
- [x] Plugin Puzzle — collaboratif pick/drop
- [x] Plugin Traque — combat/exploration, biomes OSM, achievements
- [x] Access/Stripe/Playstore — abonnements croisés Stripe + Playstore
- [x] Module ICS — tâches (VTODO), journaux (VJOURNAL), étiquettes par calendrier, corbeille récupérable
- [x] Plugin Projets — CRUD + hiérarchie/dépendances (arbre/DAG), round-trip JSON, export `.ics` (backend ; frontend cmem-web hors dépôt)
- [ ] Plugin Quiz Ph2 — Variables dynamiques
- [ ] Plugin Quiz Ph3 — Moteur math (mossadal/math-executor)
- [ ] Plugin Quiz Ph4 — WebSocket Node.js (temps réel)
- [ ] Plugin Quiz Ph5 — Export CSV
- [x] Pilier Contacts (CRM) — CRUD, vCard 4.0, interactions, pipeline `/opportunites`
- [x] Push web (VAPID) — abonnements par device, préférences par compte, envoi cron
- [x] Registre de modules activables — gating par plan, toggle usager (v2.12.0)
- [x] Traque — socle des rôles de jeu (`gm`, `traque_admin`) (v2.15.0)
- [x] Proxy IA — résumé d'agenda (`POST /ai/summarize`) (v2.15.0)
- [x] Plan équipe — facturation Stripe portée par un groupe + modules de groupe (v2.16.0)
- [x] Plugin Booking — réservation publique par lien, zones matérialisées (v2.16.0)
- [ ] Rate limiting Redis

## Licence

MIT — voir [LICENSE](LICENSE). Dépendances tierces : [THIRD_PARTY_LICENSES.md](THIRD_PARTY_LICENSES.md).

---

**Version** : 2.16.0 · **Mis à jour** : 2026-08-14 · **Auteur** : [Jrobitaille360](https://github.com/Jrobitaille360)
