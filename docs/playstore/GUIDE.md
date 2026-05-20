# Guide — Module Play Store (v2.7.0)

Version 1.0.0 · Base URL : `/v2`

> Référence complète : [API_PLAYSTORE_ENDPOINTS.json](API_PLAYSTORE_ENDPOINTS.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [Devices Android](#devices-android)
- [Pseudonymes](#pseudonymes)
- [Abonnements Play Store](#abonnements-play-store)
- [Codes d'erreur](#codes-derreur)
- [Exemples complets](#exemples-complets)

---

## Vue d'ensemble

Ce module gère deux responsabilités distinctes :

1. **Enregistrement de device Android** — lier un `device_uuid` stable au compte de l'utilisateur
   (table `android_devices`). Le token de device est opaque et sert à l'authentification
   persistante côté mobile.
2. **Abonnements Google Play** — valider un `purchase_token` Google Play via l'API Google
   Play Developer et maintenir le statut premium dans `playstore_subscriptions`.

Le module est multi-app via `app_id` : un même compte peut avoir des devices et abonnements
pour des apps différentes.

---

## Authentification

Tous les endpoints exigent un JWT valide :

```http
Authorization: Bearer <jwt_token>
```

Obtenir un token → `POST /auth/login` (ou `POST /auth/send-code` + `POST /auth/verify-code`).

---

## Devices Android

### POST /v2/devices/android/register

Enregistre ou renouvelle un device Android pour un utilisateur. Si le `device_uuid` existe
déjà pour cet `app_id` et cet utilisateur, le `device_token` est renouvelé (upsert).

**Corps :**

| Champ | Type | Requis | Description |
| - | - | - | - |
| `app_id` | string | oui | Identifiant de l'application (ex. `puzzle`) |
| `device_uuid` | string | oui | UUID v4 stable — généré à l'installation, jamais changé |

**Réponse 200 :**

```json
{
  "success": true,
  "data": {
    "device_token": "string (64 chars hex)",
    "expires_at": "2027-05-19T00:00:00",
    "pseudonym": "MonPseudo | null"
  }
}
```

**Erreurs :**

| Code | Cause |
| - | - |
| 401 | JWT absent ou invalide |
| 422 | `app_id` ou `device_uuid` manquant, UUID format invalide |

**Notes client :** Appeler au premier démarrage et à chaque réinstallation avec le même
`device_uuid`. Le `device_token` retourné sert à l'authentification persistante Puzzle (via
`Authorization: Bearer {device_token}` sur les endpoints `/puzzle/*`).

---

## Pseudonymes

Le pseudonyme est lié à un `(user_id, app_id)` — il survit aux changements de device.
Contrainte unicité : un pseudonyme est unique par `app_id` sur tout le serveur.

### GET /v2/devices/android/pseudonym

Retourne le pseudonyme actuel pour cet utilisateur et cet `app_id`.

**Query params :** `app_id` (requis)

**Réponse 200 :**

```json
{ "success": true, "data": { "pseudonym": "MonPseudo | null" } }
```

### POST /v2/devices/android/pseudonym

Définit ou remplace le pseudonyme.

**Corps :**

| Champ | Type | Requis | Description |
| - | - | - | - |
| `app_id` | string | oui | Identifiant de l'application |
| `pseudonym` | string | oui | 2–64 chars, lettres/chiffres/`_`/`.`/`-` uniquement |

**Réponse 200 :**

```json
{ "success": true, "data": { "pseudonym": "MonPseudo" } }
```

**Erreurs :**

| Code | Cause |
| - | - |
| 401 | JWT absent ou invalide |
| 409 | Pseudonyme déjà pris par un autre utilisateur pour ce `app_id` |
| 422 | `app_id` ou `pseudonym` manquant, trop court/long, caractères invalides |

### DELETE /v2/devices/android/pseudonym

Supprime le pseudonyme (met à `NULL` dans `app_user_settings`).

**Body :** `app_id` (requis)

**Réponse 200 :** `{ "success": true, "message": "Pseudonyme supprimé" }`

### GET /v2/devices/android/pseudonym/check/{pseudo}

Vérifie la disponibilité d'un pseudonyme avant de le définir.

**Query params :** `app_id` (requis)

**Réponse 200 :**

```json
{ "success": true, "data": { "available": true } }
```

`available = false` si un **autre** utilisateur détient déjà ce pseudonyme pour cet `app_id`.
`available = true` si libre ou si le pseudonyme appartient à l'utilisateur courant.

---

## Abonnements Play Store

### POST /v2/subscriptions/playstore/verify

Valide un `purchase_token` Google Play via l'API Google Play Developer. Si valide,
l'abonnement est inséré ou mis à jour dans `playstore_subscriptions` avec `status=active`.

**Corps :**

| Champ | Type | Requis | Description |
| - | - | - | - |
| `purchase_token` | string | oui | Token reçu de Google Play après achat |
| `product_id` | string | oui | ID produit Google Play (ex. `puzzle_monthly`) |
| `app_id` | string | oui | Identifiant de l'application |

**Réponse 200 :**

```json
{
  "success": true,
  "data": {
    "is_premium": true,
    "status": "active",
    "expires_at": "2027-06-01T00:00:00"
  }
}
```

**Erreurs :**

| Code | Cause |
| - | - |
| 401 | JWT absent ou invalide |
| 422 | Champ manquant, token invalide ou rejeté par Google Play |

### GET /v2/subscriptions/playstore/status

Retourne le statut actuel de l'abonnement Play Store le plus récent actif pour
`(user_id, app_id)`. Si `expires_at < now`, une synchronisation en temps réel avec
Google Play est effectuée avant la réponse.

**Query params :** `app_id` (requis)

**Réponse 200 :**

```json
{
  "success": true,
  "data": {
    "is_premium": false,
    "status": null,
    "expires_at": null,
    "product_id": null
  }
}
```

### DELETE /v2/subscriptions/playstore

Marque l'abonnement Play Store comme `cancelled` côté API (ne touche pas Google Play).
Toujours 200, même si aucun abonnement actif n'existe.

**Body :** `app_id` (requis)

**Réponse 200 :** `{ "success": true, "message": "Abonnement annulé" }`

---

## Accès accordé par Play Store

Un abonnement Play Store actif déverrouille l'accès premium sur toutes les plateformes :

| Plateforme | Accès |
| - | - |
| android | oui |
| web | oui |
| windows | oui |

Pour vérifier l'accès consolidé (Play Store + Stripe), utiliser `GET /v2/access/status`.

---

## Codes d'erreur

| HTTP | Signification |
| - | - |
| 401 | JWT absent ou expiré |
| 409 | Conflit (pseudonyme pris) |
| 422 | Paramètre manquant ou invalide |

---

## Exemples complets

### Enregistrer un device et obtenir le pseudonyme

```http
POST /v2/devices/android/register
Authorization: Bearer {jwt}
Content-Type: application/json

{ "app_id": "puzzle", "device_uuid": "550e8400-e29b-41d4-a716-446655440000" }
```

```json
{
  "success": true,
  "data": {
    "device_token": "a3f8e2b1...",
    "expires_at": "2027-05-19T12:00:00",
    "pseudonym": null
  }
}
```

### Définir un pseudonyme

```http
POST /v2/devices/android/pseudonym
Authorization: Bearer {jwt}
Content-Type: application/json

{ "app_id": "puzzle", "pseudonym": "ZebraStar" }
```

### Vérifier un abonnement Google Play

```http
POST /v2/subscriptions/playstore/verify
Authorization: Bearer {jwt}
Content-Type: application/json

{
  "app_id": "puzzle",
  "product_id": "puzzle_monthly",
  "purchase_token": "ojhdfklsjdhf..."
}
```
