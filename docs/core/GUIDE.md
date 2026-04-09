# Guide — Module Core (auth_groups)

Version 2.2.4 · Base URL : `/`

> Référence complète : [API_ENDPOINTS.json](API_ENDPOINTS.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [Flux typiques](#flux-typiques)
- [Endpoints — Auth](#endpoints--auth)
- [Endpoints — Utilisateurs](#endpoints--utilisateurs)
- [Endpoints — Groupes](#endpoints--groupes)
- [Endpoints — Fichiers](#endpoints--fichiers)
- [Endpoints — Tags](#endpoints--tags)
- [Endpoints — Statistiques](#endpoints--statistiques)
- [Endpoints — Webhooks](#endpoints--webhooks)
- [Endpoints — Public](#endpoints--public)
- [Abonnements Premium](#abonnements-premium)
- [Erreurs](#erreurs)
- [Migrations](#migrations)

---

## Vue d'ensemble

Le module core gère :

- **Authentification JWT** : login email/password ou OTP, device tokens longue durée, blacklist, anti-brute-force
- **Utilisateurs** : inscription, profils, avatars, réinitialisation mot de passe
- **Groupes** : création, membres, invitations
- **Fichiers** : upload, soft delete, restauration
- **Tags** : catégorisation flexible avec couleurs
- **Statistiques** : analytics utilisateurs et groupes
- **Webhooks** : notifications HTTP sur événements

---

## Authentification

Toutes les routes protégées exigent :

```http
Authorization: Bearer <jwt_token>
```

Le JWT est valide **15 jours** (HS256). Il peut être révoqué via `POST /auth/logout` (blacklist en base).

### 401 vs 403

| Code | Signification |
| --- | --- |
| 401 | Token absent ou mal formé |
| 403 | Email non vérifié |

### Device tokens

En passant `device_id` (UUID stable côté client) lors du login ou de la vérification OTP, vous recevez un `device_token` longue durée. Utilisez `POST /auth/refresh` pour obtenir un nouveau JWT sans re-login (utile pour les applications mobiles).

### Anti-brute-force

5 tentatives maximum par email+IP toutes les 10 minutes sur `/auth/login` et `/auth/send-code`. HTTP 429 au dépassement.

---

## Flux typiques

### 1. Inscription → connexion

```txt
POST /users/register         → email de vérification envoyé
POST /users/verify-email     → compte activé
POST /auth/login             → { token, user }
```

### 2. Connexion par OTP

```txt
POST /auth/send-code         → code OTP envoyé par email (15 min)
POST /auth/verify-code       → { token, user }
```

### 3. Renouvellement sans re-login (mobile)

```txt
POST /auth/login  (avec device_id)   → { token, device_token }
...expiration JWT...
POST /auth/refresh (device_id + device_token) → { token, device_token }
```

### 4. Réinitialisation mot de passe

```txt
POST /users/request-password-reset   → lien envoyé par email
POST /users/reset-password           → mot de passe mis à jour
```

---

## Endpoints — Auth

| Méthode | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| POST | `/auth/login` | Non | Email + mot de passe → JWT |
| POST | `/auth/send-code` | Non | Demander code OTP |
| POST | `/auth/verify-code` | Non | Vérifier OTP → JWT |
| POST | `/auth/refresh` | Non | Renouveler via device token |
| GET | `/auth/me` | JWT | Infos utilisateur courant |
| POST | `/auth/logout` | JWT | Invalider le JWT |
| GET | `/auth/devices` | JWT | Lister les appareils de confiance |
| DELETE | `/auth/devices/{device_id}` | JWT | Révoquer un appareil |

### POST /auth/login

```json
{
  "email": "alice@example.com",
  "password": "motDePasse123",
  "device_id": "550e8400-e29b-41d4-a716-446655440000",
  "device_name": "iPhone Alice"
}
```

Réponse `200` :

```json
{
  "token": "eyJhbGci...",
  "token_type": "Bearer",
  "expires_at": "2026-04-20 12:00:00",
  "user": { "id": 1, "name": "Alice", "email": "alice@example.com", "role": "UTILISATEUR" },
  "device_token": "abc123...",
  "device_id": "550e8400..."
}
```

### POST /auth/refresh

```json
{
  "device_id": "550e8400-e29b-41d4-a716-446655440000",
  "device_token": "abc123..."
}
```

Retourne un nouveau `token` + un nouveau `device_token` (l'ancien est révoqué — remplacer côté client).

---

## Endpoints — Utilisateurs

| Méthode | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| POST | `/users/register` | Non | Inscription |
| POST | `/users/verify-email` | Non | Activer le compte |
| POST | `/users/resend-verification-email` | Non | Renvoyer l'email |
| POST | `/users/request-password-reset` | Non | Demander réinitialisation |
| POST | `/users/reset-password` | Non | Nouveau mot de passe |
| GET | `/users/me` | JWT | Profil courant |
| PUT | `/users/me` | JWT | Modifier profil |
| DELETE | `/users/me` | JWT | Supprimer compte |
| POST | `/users/avatar` | JWT | Upload avatar (multipart) |
| GET | `/users/{id}` | JWT | Profil d'un utilisateur |

### POST /users/register

```json
{
  "name": "Alice Tremblay",
  "email": "alice@example.com",
  "password": "motDePasse123"
}
```

Un email de vérification est envoyé. La connexion est bloquée (403) tant que l'email n'est pas vérifié.

### Rôles utilisateur

| Rôle | Accès |
| --- | --- |
| `UTILISATEUR` | Accès standard |
| `MODERATEUR` | Modération contenu |
| `ADMINISTRATEUR` | Administration complète |

---

## Endpoints — Groupes

| Méthode | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| GET | `/groups` | JWT | Liste des groupes |
| POST | `/groups` | JWT | Créer un groupe |
| GET | `/groups/{id}` | JWT | Détails d'un groupe |
| PUT | `/groups/{id}` | JWT | Modifier un groupe |
| DELETE | `/groups/{id}` | JWT | Supprimer un groupe |
| POST | `/groups/{id}/invite` | JWT | Inviter par email |
| GET | `/groups/search` | JWT | Recherche fulltext |

### POST /groups

```json
{
  "name": "Équipe Projet",
  "description": "Groupe de travail",
  "visibility": "private",
  "max_members": 50
}
```

---

## Endpoints — Fichiers

| Méthode | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| POST | `/files/upload` | JWT | Upload un ou plusieurs fichiers |
| GET | `/files` | JWT | Liste des fichiers |
| GET | `/files/{id}` | JWT | Détails d'un fichier |
| DELETE | `/files/{id}` | JWT | Soft delete |
| PUT | `/files/{id}/restore` | JWT | Restaurer |

Types supportés : images, vidéos, audio, documents. Validation côté serveur (type MIME + taille).

---

## Endpoints — Tags

| Méthode | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| GET | `/tags` | JWT | Liste des tags |
| POST | `/tags` | JWT | Créer un tag |
| GET | `/tags/{id}` | JWT | Détails |
| PUT | `/tags/{id}` | JWT | Modifier |
| DELETE | `/tags/{id}` | JWT | Supprimer |
| GET | `/tags/by-table/{table}` | JWT | Tags d'une entité |
| GET | `/tags/most-used` | JWT | Tags populaires |

Tags avec couleurs (nom ou `#RRGGBB`), associables à groupes et fichiers.

---

## Endpoints — Statistiques

| Méthode | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| GET | `/stats/user/{id}` | JWT | Stats d'un utilisateur |
| GET | `/stats/online` | JWT | Utilisateurs en ligne |

---

## Endpoints — Webhooks

| Méthode | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| POST | `/webhooks` | JWT | Créer un webhook |
| GET | `/webhooks` | JWT | Liste |
| PUT | `/webhooks/{id}` | JWT | Modifier |
| DELETE | `/webhooks/{id}` | JWT | Supprimer |

---

## Endpoints — Public

| Méthode | Endpoint | Description |
| --- | --- | --- |
| GET | `/` | Infos API |
| GET | `/health` | Statut (DB, fichiers, plugins) |

---

## Erreurs

Format standard de toutes les réponses d'erreur :

```json
{
  "success": false,
  "message": "Description courte",
  "errors": [
    { "field": "email", "code": "invalid_format", "message": "Format email invalide" }
  ]
}
```

| Code | Signification |
| --- | --- |
| 400 | Requête malformée |
| 401 | Token absent ou invalide |
| 403 | Email non vérifié ou accès refusé |
| 404 | Ressource introuvable |
| 409 | Conflit (doublon email, etc.) |
| 422 | Validation échouée — détail dans `errors[]` |
| 429 | Trop de tentatives (anti-brute-force) |
| 500 | Erreur serveur |

---

## Migrations

| Fichier | Description |
| --- | --- |
| [20260325_A1_jwt_blacklist.sql](20260325_A1_jwt_blacklist.sql) | Table de blacklist JWT |
| [20260325_A2_login_attempts.sql](20260325_A2_login_attempts.sql) | Table anti-brute-force |
| [MIGRATION_JWT.sql](MIGRATION_JWT.sql) | Migration API Keys → JWT (v2.0) |
| [MIGRATION_CLIENT_v2_0_0.md](MIGRATION_CLIENT_v2_0_0.md) | Guide client v2.0 |
| [create_proc_reset_auth_groups.sql](create_proc_reset_auth_groups.sql) | Procédure reset données test |
| [migrations/20260409_subscriptions.sql](migrations/20260409_subscriptions.sql) | Table `subscriptions` (v2.2.4) |

---

## Abonnements Premium

Les endpoints `/subscription/*` permettent de gérer les abonnements Premium **par application** (`app_id`). L'accès Premium est indépendant pour chaque app : un utilisateur peut être Premium pour `puzzle` mais pas pour `quiz`.

### Endpoints

| Méthode | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| GET | `/subscription/status` | JWT | Statut Premium de toutes les apps |
| GET | `/subscription/status?app_id={app}` | JWT | Statut Premium d'une app spécifique |
| POST | `/subscription/verify` | JWT | Valider un achat et activer le Premium |
| DELETE | `/subscription/cancel` | JWT | Annuler un abonnement |

### Structure de réponse

Chaque entrée `app_id` dans `subscriptions{}` retourne :

| Champ | Type | Description |
| --- | --- | --- |
| `is_premium` | boolean | `true` si l'abonnement est actif |
| `show_ads` | boolean | `true` si les publicités doivent être affichées (= `!is_premium`) |
| `expires_at` | datetime\|null | Date d'expiration UTC (`Y-m-d H:i:s`) |
| `provider` | string\|null | `stripe`, `google_play`, `apple`, `microsoft` |
| `plan` | string\|null | `monthly` (+31 j) ou `yearly` (+365 j) |

> Utiliser `show_ads` (et non `is_premium`) pour décider d'afficher les publicités.

### Providers supportés

| Provider | Plateforme |
| --- | --- |
| `stripe` | Web, Windows |
| `google_play` | Android |
| `apple` | iOS / macOS |
| `microsoft` | Microsoft Store |

### CRON

Le script `src/cron/expire_subscriptions.php` (planifié à 03:00) expire automatiquement les abonnements dépassés et envoie un email de notification aux utilisateurs concernés.

> Documentation complète : [docs/v 2.2.4/2.2.4_CLIENT.md](../v%202.2.4/2.2.4_CLIENT.md)
