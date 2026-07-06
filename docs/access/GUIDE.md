# Guide — Module Access

Version 1.1.0 · Base URL : `/v2/access`

> Référence complète : [API_ACCESS_ENDPOINTS.json](API_ACCESS_ENDPOINTS.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [GET /v2/access/status](#get-v2accessstatus)
- [Matrice d'accès](#matrice-daccès)
- [Erreurs](#erreurs)

---

## Vue d'ensemble

Le module Access fournit un **endpoint d'accès unifié** : il agrège les abonnements Stripe
pour retourner la matrice d'accès premium par plateforme. Introduit en v2.7.0.

| Plateforme | Vérification d'accès |
| --- | --- |
| Web | `GET /v2/access/status` (JWT) |
| Windows | `GET /v2/access/status` (JWT) |
| Android | `GET /v2/subscriptions/playstore/status` (X-Device-Token) — **pas ce module** |

> Les abonnements Play Store ne sont pas inclus dans ce module : ils sont liés au
> `device_uuid` (anonyme) et non au `user_id`. Voir [../playstore/GUIDE.md](../playstore/GUIDE.md).

---

## Authentification

```http
Authorization: Bearer {jwt_token}
```

JWT obtenu via `POST /auth/login` (voir [../core/GUIDE.md](../core/GUIDE.md)).

---

## GET /v2/access/status

Retourne le statut premium consolidé pour l'utilisateur authentifié, une app et
optionnellement une plateforme. Source : Stripe uniquement.

```http
GET /v2/access/status?app_id=puzzle
Authorization: Bearer {jwt_token}
```

| Paramètre query | Type | Description |
| --- | --- | --- |
| `app_id` | string, requis | Identifiant de l'application |
| `platform` | string, optionnel | `android` \| `web` \| `windows` — filtre la réponse |

### Réponse sans filtre `platform` — matrice complète

```json
{
  "success": true,
  "data": {
    "is_premium": true,
    "platforms": {
      "android": true,
      "web": true,
      "windows": true
    },
    "sources": [
      { "provider": "stripe", "status": "active", "expires_at": "2027-06-01T00:00:00" }
    ]
  }
}
```

### Réponse avec filtre `platform`

```json
{
  "success": true,
  "data": {
    "is_premium": false,
    "platform": "web",
    "sources": []
  }
}
```

### Tableau `sources`

Abonnements actifs contribuant à l'accès :

| Champ | Type | Valeurs |
| --- | --- | --- |
| `provider` | string | `stripe` |
| `status` | string | `active` \| `trialing` \| `past_due` |
| `expires_at` | datetime \| null | Date d'expiration |

---

## Matrice d'accès

Règles de calcul par source (Stripe uniquement via JWT) :

| Source | android | web | windows |
| --- | --- | --- | --- |
| Stripe actif | true | true | true |
| Aucune | false | false | false |

---

## Erreurs

| Code | Signification |
| --- | --- |
| 401 | JWT absent ou invalide |
| 422 | `app_id` manquant, ou `platform` invalide (valeurs : `android`, `web`, `windows`) |
