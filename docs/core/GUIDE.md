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

### 2. Connexion par code (OTP)

```txt
POST /auth/send-code         → code OTP envoyé par email (15 min)
                               ↳ si email inconnu : compte auto-créé silencieusement (Option A)
POST /auth/verify-code       → { token, user, subscriptions }
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
| POST | `/auth/login` | Non | Email + mot de passe → JWT + subscriptions |
| POST | `/auth/send-code` | Non | Demander code OTP (auto-register si email inconnu) |
| POST | `/auth/verify-code` | Non | Vérifier OTP → JWT + subscriptions |
| POST | `/auth/refresh` | Non | Renouveler via device token (rate-limited par `device_id`) |
| GET | `/auth/me` | JWT | Infos utilisateur courant |
| POST | `/auth/logout` | JWT | Invalider le JWT courant |
| GET | `/auth/devices` | JWT | Lister les appareils de confiance |
| DELETE | `/auth/devices/{device_id}` | JWT | Révoquer un appareil |
| GET | `/auth/sessions` | JWT | Vue unifiée sessions + appareils |
| DELETE | `/auth/sessions` | JWT | Déconnexion globale tous appareils |

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
  "subscriptions": { "puzzle": { "is_premium": true, "show_ads": false, "is_trial": false, "trial_end": null, "expires_at": "2027-04-20 12:00:00", "provider": "stripe", "plan": "yearly" } },
  "device_token": "abc123...",
  "device_id": "550e8400..."
}
```

> `subscriptions` est aussi présent dans la réponse de `POST /auth/verify-code`. Objet vide `{}` si aucun abonnement actif.

### POST /auth/send-code

```json
{ "email": "alice@example.com" }
```

Réponse générique `200` (même si email inconnu) :

```json
{ "message": "Si cet email est enregistré, un code de connexion vous a été envoyé." }
```

**Auto-register Option A** : si l'email est inconnu du système, un compte est créé silencieusement (`email_verified=1`, mot de passe aléatoire inutilisable) et un code OTP est envoyé. L'utilisateur peut se connecter sans avoir inscrit de mot de passe.

`403` retourné uniquement si le compte **existe** mais que l'email n'est pas vérifié.

### POST /auth/refresh

```json
{
  "device_id": "550e8400-e29b-41d4-a716-446655440000",
  "device_token": "abc123..."
}
```

Retourne un nouveau `token` + un nouveau `device_token` (l'ancien est révoqué — **remplacer côté client impérativement**).

**Refresh token rotatif** : chaque appel révoque l'ancien `device_token` et émet un nouveau token appartenant à la même famille (`family_id` interne). Si un token déjà révoqué est présenté à nouveau (replay attack), **tous les tokens de la famille sont révoqués immédiatement** et le log `CRITICAL` est émis — l'utilisateur devra se reconnecter.

Cet endpoint est protégé par rate limiting : trop d'échecs consécutifs avec le même `device_id` retournent `429 RATE_LIMIT_EXCEEDED`. Le compteur est réinitialisé après un refresh réussi.

### GET /auth/sessions

Retourne la liste de toutes les sessions JWT actives et de tous les appareils de confiance de l'utilisateur connecté.

Réponse `200` :

```json
{
  "sessions": [
    { "session_id": 1, "created_at": "2026-04-13 10:00:00", "last_activity_at": "2026-04-13 11:30:00" }
  ],
  "sessions_count": 1,
  "devices": [
    { "device_id": "550e8400...", "device_name": "iPhone Alice", "last_used_at": "2026-04-13 11:30:00", "last_ip": "1.2.3.4", "expires_at": "2027-04-13 10:00:00" }
  ],
  "devices_count": 1
}
```

### DELETE /auth/sessions

Déconnexion globale : révoque immédiatement le JWT courant (blacklist), termine toutes les sessions actives et révoque tous les appareils de confiance. L'utilisateur devra se reconnecter sur tous ses appareils.

Réponse `200` :

```json
{ "message": "Toutes vos sessions et appareils de confiance ont été révoqués." }
```

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
| GET | `/files` | JWT ADMIN | Lister les fichiers d'un dossier (`?folder=<slug>`) |
| POST | `/files` | JWT | Uploader un fichier |
| GET | `/files/{id}` | JWT | Télécharger le contenu binaire |
| GET | `/files/{id}/info` | JWT | Métadonnées d'un fichier |
| PATCH | `/files/{id}/accessibility` | JWT propriétaire/admin | Changer l'accessibilité |
| DELETE | `/files/{id}` | JWT | Soft delete (`force_delete: true` pour suppression physique) |
| POST | `/files/{id}/restore` | JWT | Restaurer un fichier soft-deleted |
| GET | `/files/user/{user_id}` | JWT | Lister les fichiers d'un utilisateur (paginé) |

### Types MIME acceptés

| Catégorie | Extensions | Taille max |
| --- | --- | --- |
| Image | jpeg, png, gif, webp | 5 MB |
| Document | pdf, txt, doc, docx, xls, xlsx | 10 MB |
| Audio | mp3, wav, ogg | 20 MB |
| Vidéo | mp4, avi, mov | 50 MB |
| Exécutable / Archive | **exe, msi, zip, 7z** | **200 MB** |

Validation côté serveur : type MIME + taille. Retourne `400` si le type est refusé ou la taille dépassée.

### Paramètre `accessibility` (POST /files)

Le champ FormData `accessibility` est optionnel. Valeurs acceptées : `public`, `private` (défaut) ou `grand-public`.

| Valeur | JWT requis | Qui peut télécharger / consulter les métadonnées |
| - | - | - |
| `grand-public` | Non | N'importe qui, sans authentification |
| `public` | Oui | Tout utilisateur authentifié |
| `private` | Oui | Uniquement le déposant ou un administrateur |

L'accessibilité s'applique à `GET /files/{id}` (download) et `GET /files/{id}/info`. Pour `grand-public`, ces deux routes acceptent les requêtes sans en-tête `Authorization`.

### PATCH /files/{id}/accessibility

Permet au propriétaire du fichier ou à un administrateur de changer l'accessibilité après l'upload.

```http
PATCH /files/{id}/accessibility
Authorization: Bearer {jwt_token}
Content-Type: application/json

{ "accessibility": "private" }
```

Réponse `200` :

```json
{ "file_id": 42, "accessibility": "private" }
```

Retourne `403` si l'appelant n'est ni propriétaire ni administrateur.
Retourne `422` si la valeur n'est pas `public` ou `private`.

### Paramètre `folder` (POST /files)

Le champ FormData `folder` est optionnel. S'il est absent ou vide, le fichier est déposé dans `uploads/files/` (comportement par défaut).

```
folder: string — a-z, 0-9, - et _ uniquement ; max 80 caractères
```

Exemples valides : `mon-app`, `setup_v2`, `jdb-windows`
Exemples invalides : `../secret`, `mon dossier`, `MonApp`, `app/sub`

Le champ `url` de la réponse reflète le chemin réel :

```json
{ "url": "/uploads/mon-app/setup.exe" }
```

### GET /files?folder=`<slug>`

Route réservée aux **ADMINISTRATEURS**. Retourne tous les fichiers dont le chemin en base commence par `/uploads/<folder>/`. Retourne un tableau vide (pas 404) si le dossier n'a aucun fichier.

```http
GET /files?folder=mon-app
Authorization: Bearer {jwt_token}
```

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
| POST | `/subscription/verify` | JWT | Valider un achat provider et activer le Premium |
| POST | `/subscription/checkout` | JWT | Créer une session Stripe Checkout (paiement web) |
| POST | `/subscription/portal` | JWT | Ouvrir le portail Stripe pour gérer l'abonnement |
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

| `is_trial` | boolean | `true` si l'abonnement est en période d'essai |
| `trial_end` | datetime\|null | Fin de la période d'essai UTC, ou `null` |

> Utiliser `show_ads` (et non `is_premium`) pour décider d'afficher les publicités.

### POST /subscription/checkout

Crée une session Stripe Checkout avec essai gratuit de 7 jours. Retourne une URL à ouvrir dans le navigateur.

```json
{ "app_id": "puzzle", "plan": "monthly" }
```

Réponse `200` :

```json
{
  "checkout_url": "https://checkout.stripe.com/c/pay/cs_test_...",
  "session_id": "cs_test_..."
}
```

### POST /subscription/portal

Ouvre le Stripe Billing Portal pour qu'un utilisateur puisse gérer (modifier, annuler) son abonnement existant.

```json
{ "app_id": "puzzle" }
```

Réponse `200` :

```json
{ "portal_url": "https://billing.stripe.com/p/session/..." }
```

Retourne `404` avec `errors.error = "NO_SUBSCRIPTION"` si aucun `stripe_customer_id` n'est trouvé en base pour cet utilisateur + `app_id`.

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
