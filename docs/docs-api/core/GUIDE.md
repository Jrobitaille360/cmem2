# Guide — Module Core (auth_groups)

Version 2.2.4 · Base URL : `/`

> Référence complète : [API_ENDPOINTS.json](API_ENDPOINTS.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [Flux typiques](#flux-typiques)
- [Endpoints — Auth](#endpoints--auth)
- [Endpoints — Utilisateurs](#endpoints--utilisateurs)
- [Suppression de compte et restauration](#suppression-de-compte-et-restauration)
- [Endpoints — Groupes](#endpoints--groupes)
- [Endpoints — Fichiers](#endpoints--fichiers)
- [Endpoints — Tags](#endpoints--tags)
- [Endpoints — Statistiques](#endpoints--statistiques)
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

5 tentatives maximum par email+IP toutes les 10 minutes sur `/auth/login`, `/auth/send-code`, `/users/request-password-reset` et `/users/reset-password`. HTTP 429 au dépassement.

### Politique de mot de passe

Une seule politique, appliquée à **tous** les points d'entrée acceptant un mot de passe
(`POST /users/register`, `POST /users/reset-password`, `PUT /users/{id}/password`). Un mot de passe doit contenir :

- au minimum **8 caractères** ;
- au moins une lettre **minuscule** ;
- au moins une lettre **majuscule** ;
- au moins un **chiffre** ;
- au moins un **caractère spécial** (tout caractère non alphanumérique).

Les exigences non satisfaites sont renvoyées en `400`, sous forme de tableau de messages prêts à afficher (`errors.password` ou `errors.new_password`). Source unique : `Validator::passwordErrors()`.

> **BREAKING (2026-07-28)** — tous les `password_hash` existants ont été invalidés sur dev et en production. Aucun mot de passe antérieur n'authentifie plus : chaque usager doit passer par « mot de passe oublié » (code par courriel) ou par connexion OTP. Script : `private/utilitaires/invalidate_passwords.php`.

### CORS

L'API répond aux préflights `OPTIONS` sans authentification (`204`, `Access-Control-Max-Age: 86400`). Si l'en-tête `Origin` figure dans la liste blanche du serveur (`ALLOWED_ORIGINS`), il est renvoyé tel quel dans `Access-Control-Allow-Origin` (+ `Vary: Origin`) ; sinon la réponse porte `Access-Control-Allow-Origin: *`. `Access-Control-Allow-Headers` inclut `Authorization` et `Content-Type` ; `Access-Control-Allow-Methods` inclut `GET, POST, PUT, PATCH, DELETE, OPTIONS`.

### Compte de test E2E (dev seulement)

Sur `dev-cmem2` uniquement, un compte de test à code OTP fixe est disponible pour les tests automatisés (Playwright) : `send-code` sur cet email n'envoie **aucun** email, stocke un code fixe et est exempt du rate limit ; `verify-code` émet un JWT + device token normaux. Activé par les variables d'environnement `OTP_TEST_ACCOUNT_EMAIL` / `OTP_TEST_ACCOUNT_CODE`, absentes en production (le compte s'y comporte comme un compte ordinaire).

---

## Flux typiques

### 1. Inscription → connexion

```txt
POST /users/register         → token à 8 chiffres envoyé par courriel (valide 24 h)
POST /users/verify-email     → compte activé
POST /auth/login             → { token, user }
```

Le token de vérification n'est **jamais** renvoyé dans une réponse HTTP : il ne sort que par
courriel. `POST /users/verify-email` accepte un champ facultatif `email` qui permet de compter
les tentatives sur le token de l'usager : au-delà de 5 essais (ou 5 tentatives en 10 minutes
par couple email + IP), le token est invalidé et l'API répond `429` — il faut en redemander un
via `POST /users/resend-verification-email`, elle-même limitée à 5 demandes par 10 minutes.

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
POST /users/request-password-reset   → code à 6 chiffres envoyé par courriel (valide 60 min)
POST /users/reset-password           → mot de passe mis à jour
```

Le code n'apparaît **jamais** dans la réponse HTTP : il part uniquement par courriel. La réponse est identique que le courriel existe ou non (anti-énumération de comptes).

- `request-password-reset` : 5 demandes par 10 minutes par couple (email + IP) → `429` au-delà.
- `reset-password` : body `{ token, new_password, password_policy?, email? }`.
  - `password_policy` — **sans effet**, il n'existe plus qu'une seule politique (voir « Politique de mot de passe » ci-dessous). Seule la valeur `strong` est tolérée par compatibilité ; `any` et toute autre valeur renvoient `400`.
  - `email` (facultatif) — permet de compter les tentatives sur le code de cet usager : au-delà de 5 essais, le code est supprimé et l'API répond `429`.
  - Le code est à usage unique : il est supprimé dès que le mot de passe est changé.

Sur `dev-cmem2` uniquement, `PASSWORD_RESET_TEST_CODE=654321` force un code fixe, sans envoi de courriel et sans rate limit. La variable est ignorée si `APP_ENV=production`.

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

`409` retourné si le compte est **en attente de purge** (supprimé, délai de grâce en cours) — aucun code n'est envoyé :

```json
{
  "success": false,
  "message": "Compte en attente de suppression définitive",
  "errors": {
    "error_code": "ACCOUNT_PENDING_DELETION",
    "purge_scheduled_at": "2026-08-28T02:14:07Z"
  }
}
```

Voir [Suppression de compte](#suppression-de-compte-et-restauration) pour la restauration.

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
| PUT | `/users/me` | JWT | Modifier profil ; accepte `timezone` (identifiant IANA, `null` = repli) |
| DELETE | `/users/me` | JWT | Supprimer compte |
| POST | `/users/avatar` | JWT | Upload avatar (multipart) |
| PUT | `/users/password` | JWT | Changer son mot de passe (`old_password` + `new_password`) |
| GET | `/users` | JWT (ADMIN+) | Liste des utilisateurs (`?email=`, `?include_deleted=1` pour inclure les comptes soft-deleted) |
| GET | `/users/{id}` | JWT | Profil d'un utilisateur |
| PUT | `/users/{id}` | JWT (ADMIN+) | Modifier un utilisateur ; accepte `role` selon la matrice d'autorité (voir Rôles) |
| DELETE | `/users/{id}` | JWT (ADMIN+) | Supprimer (soft) selon la matrice d'autorité |
| POST | `/users/{id}/restore` | JWT (ADMIN+) | Restaurer un compte soft-deleted |
| POST | `/users/{id}/avatar` | JWT (ADMIN+) | Upload avatar d'un utilisateur |
| PUT | `/users/{id}/password` | JWT (ADMIN+) | Changer le mot de passe d'un utilisateur |
| GET | `/users/{id}/sessions` | JWT (ADMIN+ ou soi-même) | Sessions actives d'un utilisateur |
| DELETE | `/users/{id}/sessions` | JWT (ADMIN+ ou soi-même) | Terminer toutes les sessions |
| GET | `/users/{id}/session-status` | JWT (ADMIN+ ou soi-même) | Présence d'une session active |
| PUT | `/users/{id}/plan-override` | JWT (SUPERADMINISTRATEUR) | Poser/retirer l'assignation manuelle du plan cmem (`cmem_plan_override`, ex. `'ami'`, ou `null` pour retirer) |
| GET | `/users/choose-plan` | JWT | Plans disponibles pour l'utilisateur |
| POST | `/users/choose-plan` | JWT | Choisir un plan |
| POST | `/users/app` | JWT | Créer une configuration d'application (`app_id` + `json_data`) |
| GET | `/users/app` | JWT | Lister ses configurations d'applications |
| GET | `/users/app/{app_id}` | JWT | Lire une configuration |
| PUT | `/users/app/{app_id}` | JWT | Modifier une configuration |
| DELETE | `/users/app/{app_id}` | JWT | Supprimer une configuration |
| GET | `/users/me/notification-preferences` | JWT | Lire ses préférences de notification |
| PUT | `/users/me/notification-preferences` | JWT | Modifier ses préférences de notification |

`ADMIN+` = `ADMINISTRATEUR` ou `SUPERADMINISTRATEUR`.

### POST /users/register

```json
{
  "name": "Alice Tremblay",
  "email": "alice@example.com",
  "password": "motDePasse123"
}
```

Un email de vérification est envoyé. La connexion est bloquée (403) tant que l'email n'est pas vérifié.

### Lecture de l'avatar (pas d'endpoint GET)

Il n'existe pas de route `GET /users/avatar`. L'upload (`POST /users/avatar`) retourne
`avatar_url` (ex. `/uploads/avatars/avatar_42_xxx.png`) et le profil (`GET /users/me`,
`GET /users/{id}`) expose `profile_image`. Ce chemin est un **fichier statique** servi
directement par le serveur web, sans JWT — le client le charge tel quel
(`{base_url}/uploads/avatars/...`).

### Fuseau horaire du compte (`timezone`)

`GET /users/me` expose `timezone` : un identifiant IANA (`Europe/Paris`) ou `null` tant que
le client ne l'a jamais posé. `PUT /users/me` l'accepte en écriture ; une valeur absente de
la base IANA du serveur est refusée en `422`, et `null` remet l'usager sur le repli.

Le client est la seule source fiable de cette valeur
(`Intl.DateTimeFormat().resolvedOptions().timeZone`) : il lui revient de la poser à
l'inscription, et de la corriger si elle change.

Le champ sert au cron des notifications push, qui s'exécute hors requête HTTP et ne peut
donc pas déduire l'heure locale de l'usager au moment de l'envoi : échéances sans heure
fixées à 00:00 locales, et plage « ne pas déranger » (`quiet_from` / `quiet_to` de
`/push/preferences`) évaluée dans ce fuseau. Ordre de priorité :
`users.timezone` → fuseau du premier calendrier → `America/Montreal`.

### Rôles utilisateur

| Rôle | Accès |
| --- | --- |
| `UTILISATEUR` | Accès standard |
| `ADMINISTRATEUR` | Administration : gestion utilisateurs et contenu |
| `SUPERADMINISTRATEUR` | Autorité maximale ; jamais attribué via l'API (DB seulement) |

Hiérarchie : `UTILISATEUR < ADMINISTRATEUR < SUPERADMINISTRATEUR`.

Matrice d'autorité (`PUT /users/{id}` champ `role`, `DELETE /users/{id}`) :

- `ADMINISTRATEUR` : peut seulement promouvoir `UTILISATEUR → ADMINISTRATEUR` ; ne touche jamais un pair `ADMINISTRATEUR`/`SUPERADMINISTRATEUR` ; révoque un `UTILISATEUR` seulement.
- `SUPERADMINISTRATEUR` : toute transition `UTILISATEUR ↔ ADMINISTRATEUR` ; révoque `UTILISATEUR` ou `ADMINISTRATEUR`.
- Personne ne peut attribuer ni révoquer `SUPERADMINISTRATEUR` via l'API.

---

## Suppression de compte et restauration

Conformité Loi 25 (Québec). L'accès est coupé immédiatement, les données sont effacées
définitivement après un **délai de grâce de 30 jours** (`ACCOUNT_PURGE_GRACE_DAYS`).

### Parcours

1. `DELETE /users/me` — le serveur annule d'abord tout abonnement Stripe encore actif, puis pose
   `deleted_at`. Réponse :

   ```json
   { "deleted": true, "purge_scheduled_at": "2026-08-28T02:14:07Z" }
   ```

   `purge_scheduled_at` est informatif : son absence ne remet pas en cause le succès.

2. Pendant le délai de grâce : toute connexion est refusée, un JWT déjà émis cesse d'être accepté,
   et `POST /auth/send-code` répond `409 ACCOUNT_PENDING_DELETION` avec la date de purge.

3. Restauration, deux voies :
   - `POST /auth/restore-account` `{ email }` → code envoyé, réponse générique ;
     puis `POST /auth/restore-account/verify` `{ email, code }` → compte restauré + JWT ;
   - `POST /auth/login` avec le mot de passe du compte — une connexion réussie restaure le compte.
   - Voie admin : `POST /users/{id}/restore` (`ADMINISTRATEUR` et plus).

4. Après 30 jours, le cron de maintenance efface physiquement le compte. L'adresse redevient
   disponible pour une inscription neuve, sans aucun reliquat.

### Si l'annulation Stripe échoue

`DELETE /users/me` renvoie `409` et **ne supprime pas** le compte :

```json
{
  "success": false,
  "errors": {
    "error_code": "STRIPE_CANCEL_FAILED",
    "app_id": "cmemweb"
  }
}
```

Aucun compte supprimé ne reste facturé. Réessayer plus tard, ou annuler l'abonnement d'abord via
`DELETE /v2/subscriptions/stripe`.

### Ce qui est effacé, ce qui est conservé

| Donnée | Sort |
| - | - |
| Calendriers, événements, occurrences, journaux, tâches | Effacé |
| Projets et dépendances de tâches | Effacé |
| Contacts, interactions CRM, opportunités | Effacé |
| Fichiers téléversés (base **et** disque) | Effacé |
| Liens `/links`, étiquettes | Effacé |
| Compte, profil, préférences, sessions, jetons d'appareil | Effacé |
| Abonnements Web Push et préférences de notification | Effacé |
| Données de jeu traque | Effacé |
| Adhésion aux groupes partagés | Effacée — le groupe des autres membres survit |
| Groupe dont l'usager est le seul membre | Effacé |
| Propriété d'un groupe partagé | Transférée au membre le plus ancien |
| Partie de casse-tête partagée | Conservée chez le partenaire, sans trace de l'usager parti |
| Registres de facturation Stripe | Conservés **anonymisés** (`billing_archive`) — obligation fiscale, aucun identifiant d'usager |

### Cron

La purge tourne dans `src/cron/maintenance.php` (tâche `auth_groups`). Elle peut être déclenchée
seule :

```bash
php src/cron/purge_accounts.php --dry-run   # compte sans rien supprimer
php src/cron/purge_accounts.php             # exécution réelle
php src/cron/purge_accounts.php --json      # rapport machine
```

Le traitement est idempotent et journalise un décompte par usager purgé.

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
| GET | `/files/png-from-svg` | JWT* | Convertir un SVG en PNG à la demande (`?id=`) |
| GET | `/files/{id}/info` | JWT | Métadonnées d'un fichier |
| GET | `/files/{id}/tags` | JWT | Étiquettes associées au fichier |
| PATCH | `/files/{id}` | JWT propriétaire/admin | Renommer (`original_name`) / modifier `description` |
| PATCH | `/files/{id}/accessibility` | JWT propriétaire/admin | Changer l'accessibilité |
| DELETE | `/files/{id}` | JWT | Soft delete (`force_delete: true` pour suppression physique) |
| POST | `/files/{id}/restore` | JWT | Restaurer un fichier soft-deleted |
| GET | `/files/user/{user_id}` | JWT | Lister les fichiers d'un utilisateur (paginé, `?deleted=exclude\|include\|only`) |

### Corbeille : `GET /files/user/{user_id}?deleted=`

| Valeur | Effet |
| - | - |
| `exclude` | Défaut — fichiers actifs seulement (comportement historique) |
| `include` | Actifs + soft-deleted |
| `only` | Soft-deleted seulement (vue corbeille) |

Chaque ligne expose `deleted_at` (`null` si le fichier est actif). Une valeur hors liste retourne `422`.
`pagination` et `statistics` suivent le même filtre. Restauration via `POST /files/{id}/restore`.

### Types acceptés

| Catégorie | Extensions | Taille max |
| --- | --- | --- |
| Image | jpg, jpeg, png, gif, webp, svg, heic, heif, avif, tiff, tif | 10 MB |
| Document | pdf, txt, csv, md, rtf, doc, docx, xls, xlsx, pptx, odt, ods, odp, gpx | 10 MB |
| Audio | mp3, wav, ogg | 20 MB |
| Vidéo | mp4, avi, mov | 50 MB |
| Exécutable / Archive | **exe, msi, zip, 7z** | **200 MB** |

Plafond dur toutes catégories : **100 MB** (`FILES_MAX_UPLOAD_MB`), aligné sur `upload_max_filesize` / `post_max_size`.

La validation croise l'**extension** et la **signature réelle** du fichier (`mime_content_type`) :
les deux doivent correspondre. Renommer un `.zip` en `.png` ne le fait pas passer, et un MIME
générique (`application/zip`, `text/plain`, `application/octet-stream`) n'est accepté que pour les
extensions dont c'est la signature attendue — `.ods`/`.pptx` pour zip, `.csv`/`.md` pour texte,
`.exe`/`.msi`/`.zip`/`.7z` pour octet-stream.

Le `mime_type` enregistré est celui de la signature, **pas** le `Content-Type` déclaré par le client ;
`media_type` en découle.

Refus (`400`) avec code applicatif :

| Motif | `errors.code` | Données jointes |
| - | - | - |
| Type non autorisé | `FILE_TYPE_REFUSED` | `detected_type`, `extension` |
| Taille dépassée | `FILE_TOO_LARGE` | `max_size_bytes`, `file_size_bytes` |

### Sécurité — SVG

`GET /files/{id}` sert les images bitmap `inline` avec un cache immuable, mais un `image/svg+xml`
est **toujours** servi `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff` : un SVG
est du XML exécutable, et l'afficher inline exécuterait son script sur l'origine de l'API.
Pour l'affichage, utiliser `GET /files/png-from-svg`.

### PATCH /files/{id}

Renomme le fichier (libellé affiché et nom proposé au téléchargement) sans toucher au stockage :
`file_name` et `file_path` restent inchangés.

```http
PATCH /files/42
Authorization: Bearer {jwt_token}
Content-Type: application/json

{ "original_name": "Devis 2026 révisé.pdf", "description": "Version signée" }
```

Les deux champs sont optionnels, mais au moins un doit être fourni (sinon `400`).
`original_name` doit être non vide, de 255 caractères au plus, et ne contenir ni `/`, ni `\`, ni `..`
— sinon `400` avec `errors.code = FILE_NAME_INVALID`. Propriétaire ou administrateur uniquement.

### Étiquettes d'un fichier

Association / dissociation par `PUT /tags/{tag_id}/{file_id}` avec `table_associate: files`.

Lecture :

- `GET /files/{id}/tags` → `{ "file_id": 42, "tags": [{ "id": 7, "name": "Contrats", "color": "#33aa55" }] }`
- chaque ligne de `GET /files/user/{user_id}` porte un champ `tags` (une seule requête pour toute la
  page — pas de fan-out côté client)

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

### GET /files/png-from-svg

Convertit un fichier SVG stocké en PNG, côté serveur (rsvg-convert > inkscape > convert, auto-détecté). Mêmes règles d'accessibilité que le téléchargement — JWT optionnel (`grand-public` accessible sans authentification).

```http
GET /files/png-from-svg?id=42&width=400&dpi=192&bg=ffffff
```

| Paramètre | Type | Défaut | Description |
| - | - | - | - |
| `id` | int (requis) | — | ID du fichier SVG |
| `width` | int | taille naturelle | 1-4096 px |
| `height` | int | proportionnel | 1-4096 px |
| `dpi` | int | 96 | 1-600 |
| `bg` | string | transparent | hex sans `#`, ex. `ffffff` |
| `scale` | float | 1.0 | 0.01-10 ; ignoré si width/height fourni |

Réponse `200` : octets PNG, `Content-Type: image/png`, `Cache-Control: public, max-age=86400`.
Retourne `404` si le fichier est introuvable, `422` si ce n'est pas un SVG ou si un paramètre est hors limites, `500` si aucun convertisseur n'est disponible sur le serveur.

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
| POST | `/stats/build` | JWT | Construire/rafraîchir les statistiques |
| GET | `/stats/platform` | JWT | Stats globales de la plateforme |
| GET | `/stats/groups` | JWT | Stats des groupes |
| GET | `/stats/users` | JWT | Stats de tous les utilisateurs |
| GET | `/stats/users/{id}` | JWT | Stats d'un utilisateur |
| GET | `/stats/my-stats` | JWT | Stats de l'utilisateur courant |
| GET | `/stats/online` | JWT | Utilisateurs en ligne |
| POST | `/stats/cleanup-sessions` | JWT | Nettoyer les sessions expirées |

---

## Endpoints — Public

| Méthode | Endpoint | Description |
| --- | --- | --- |
| GET | `/` | Infos API |
| GET | `/health` | Statut (DB, fichiers, plugins) |
| GET | `/entrypoints` | Liste des modules avec documentation JSON des endpoints |
| GET | `/entrypoints/{module}` | Contenu JSON des endpoints du module (404 si inconnu) |

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
| [../20260505_subscriptions_purchase_token_unique.sql](../20260505_subscriptions_purchase_token_unique.sql) | Contrainte `uq_purchase_token_app` + migration devices Google Play (v2.5.0) |

---

## Abonnements Premium

Les endpoints `/subscription/*` permettent de gérer les abonnements Premium **par application** (`app_id`). L'accès Premium est indépendant pour chaque app : un utilisateur peut être Premium pour `puzzle` mais pas pour `quiz`.

La table `subscriptions` est la **source unique de vérité** pour tous les providers. Pour Google Play (plugin Puzzle), le lookup s'effectue par `purchase_token + app_id` — l'abonnement est donc lié à l'achat, pas à l'appareil, et survit à une réinstallation.

### Endpoints

| Méthode | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| GET | `/subscription/status` | JWT | Statut Premium de toutes les apps |
| GET | `/subscription/status?app_id={app}` | JWT | Statut Premium d'une app spécifique |
| POST | `/subscription/verify` | JWT | Valider un achat provider et activer le Premium |
| DELETE | `/subscription/cancel` | JWT | Annuler un abonnement |

> **Dépréciées depuis v2.7.0** : `POST /subscription/checkout` et `POST /subscription/portal`
> retournent `410 Gone`. Utiliser `POST /v2/billing/checkout` et `POST /v2/billing/portal`
> — voir [../stripe/GUIDE.md](../stripe/GUIDE.md).

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

### Checkout et portail Stripe

Le paiement web (Checkout) et le portail de gestion Stripe sont gérés par le module Stripe :
`POST /v2/billing/checkout` et `POST /v2/billing/portal`.
Voir [../stripe/GUIDE.md](../stripe/GUIDE.md).

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
