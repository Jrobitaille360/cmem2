# Guide — Module Play Store (v2.7.0)

Version 1.1.0 · Base URL : `/v2`

> Référence complète : [API_PLAYSTORE_ENDPOINTS.json](API_PLAYSTORE_ENDPOINTS.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [Devices Android](#devices-android)
- [Pseudonymes](#pseudonymes)
- [Abonnements Play Store](#abonnements-play-store)
- [Restauration cross-device](#restauration-cross-device)
- [Codes d'erreur](#codes-derreur)
- [Exemples complets](#exemples-complets)

---

## Vue d'ensemble

Ce module gère deux responsabilités distinctes :

1. **Enregistrement de device Android** — lier un `device_uuid` stable au serveur
   (table `android_devices`). Le `device_token` retourné sert d'identité persistante côté mobile.
2. **Abonnements Google Play** — valider un `purchase_token` Google Play via l'API Google
   Play Developer et maintenir le statut premium dans `playstore_subscriptions`.

Le module est multi-app via `app_id` : un même device peut avoir des abonnements pour des apps
différentes.

> **Android est entièrement anonyme.** Aucun email, aucun JWT n'est requis. L'identité repose
> sur `device_uuid` + `device_token`.

---

## Authentification

L'authentification varie selon le groupe d'endpoints :

### Endpoints `/v2/devices/android/*`

```http
Authorization: Bearer {jwt_token}
```

JWT **optionnel** pour `POST /v2/devices/android/register` (anonyme si absent).
JWT **requis** pour toutes les autres routes devices (pseudonyme, check).

### Endpoints `/v2/subscriptions/playstore/*`

```http
X-Device-Token: {device_token}
```

Obtenu à l'enregistrement du device (`POST /v2/devices/android/register`). **Jamais de JWT.**

---

## Devices Android

### POST /v2/devices/android/register

Enregistre ou renouvelle un device Android. Si le `device_uuid` existe déjà pour cet `app_id`,
le `device_token` est renouvelé (upsert).

**JWT optionnel.** Sans JWT : device anonyme (pas de pseudonyme, pas de `user_id`).
Avec JWT : device lié au compte (pseudonyme disponible).

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
| 422 | `app_id` ou `device_uuid` manquant, UUID format invalide |

**Notes client :**

- Appeler au premier démarrage et à chaque réinstallation avec le **même** `device_uuid`.
- Stocker `device_token` localement — il expire après 365 jours.
- **Important :** passer `device_uuid` comme `obfuscatedExternalAccountId` à la
  [BillingClient](https://developer.android.com/reference/com/google/android/billingclient/api/BillingFlowParams.Builder#setObfuscatedAccountId(java.lang.String))
  avant tout achat. Ceci permet de retrouver l'abonnement d'un device à l'autre.

---

## Pseudonymes

Le pseudonyme est lié à un `(user_id, app_id)` — il requiert un JWT valide.
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

> **Auth : `X-Device-Token`** — pas de JWT. Le `device_token` obtenu à l'enregistrement
> identifie le device et donne accès à son abonnement.

### POST /v2/subscriptions/playstore/verify

Valide un `purchase_token` Google Play via l'API Google Play Developer. Si valide,
l'abonnement est inséré ou mis à jour dans `playstore_subscriptions` pour ce `device_uuid`.
Un seul enregistrement par `(device_uuid, app_id)` — upsert.

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
| 401 | `X-Device-Token` absent ou invalide |
| 422 | Champ manquant, token invalide ou rejeté par Google Play |

**Notes client :**

- Appeler immédiatement après un achat réussi.
- Appeler aussi au démarrage si un `purchase_token` local non encore vérifié existe.
- L'abonnement est lié au `device_uuid`, non à un compte utilisateur.

### GET /v2/subscriptions/playstore/status

Retourne le statut actuel de l'abonnement Play Store pour ce `(device_uuid, app_id)`.

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

**Erreurs :**

| Code | Cause |
| - | - |
| 401 | `X-Device-Token` absent ou invalide |
| 422 | `app_id` manquant |

### DELETE /v2/subscriptions/playstore

Marque l'abonnement Play Store comme `cancelled` côté API (ne touche pas Google Play).
Toujours 200, même si aucun abonnement actif n'existe.

**Body :** `app_id` (requis)

**Réponse 200 :** `{ "success": true, "message": "Abonnement annulé" }`

**Erreurs :**

| Code | Cause |
| - | - |
| 401 | `X-Device-Token` absent ou invalide |
| 422 | `app_id` manquant |

---

## Restauration cross-device

Quand un utilisateur installe l'app sur un **nouvel appareil** :

1. Nouveau device génère un nouveau `device_uuid`.
2. `POST /v2/devices/android/register` → nouveau `device_token`.
3. L'app récupère le `purchase_token` depuis Google Play (via `queryPurchasesAsync`).
4. `POST /v2/subscriptions/playstore/verify` avec le nouveau `device_token`.
5. Google Play retourne l'`obfuscatedExternalAccountId` original (= `device_uuid` du premier device).
6. L'API retrouve l'abonnement existant et l'associe au nouveau device.

> **Condition :** l'app doit avoir défini `obfuscatedExternalAccountId = device_uuid`
> lors de l'achat original (via `BillingFlowParams.Builder.setObfuscatedAccountId()`).

---

## Codes d'erreur

| HTTP | Contexte | Signification |
| - | - | - |
| 401 | Routes devices | JWT absent ou expiré |
| 401 | Routes subscriptions | `X-Device-Token` absent ou invalide |
| 409 | Pseudonyme | Pseudonyme déjà pris |
| 422 | Tous | Paramètre manquant ou invalide |

---

## Exemples complets

### Enregistrer un device (anonyme)

```http
POST /v2/devices/android/register
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

### Définir un pseudonyme (nécessite JWT)

```http
POST /v2/devices/android/pseudonym
Authorization: Bearer {jwt}
Content-Type: application/json

{ "app_id": "puzzle", "pseudonym": "ZebraStar" }
```

### Vérifier un abonnement Google Play

```http
POST /v2/subscriptions/playstore/verify
X-Device-Token: a3f8e2b1...
Content-Type: application/json

{
  "app_id": "puzzle",
  "product_id": "puzzle_monthly",
  "purchase_token": "ojhdfklsjdhf..."
}
```

### Consulter le statut de l'abonnement

```http
GET /v2/subscriptions/playstore/status?app_id=puzzle
X-Device-Token: a3f8e2b1...
```

```json
{
  "success": true,
  "data": {
    "is_premium": true,
    "status": "active",
    "expires_at": "2027-06-01T00:00:00",
    "product_id": "puzzle_monthly"
  }
}
```
