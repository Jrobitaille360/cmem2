# Guide — Module Access

Version 1.2.0 · Base URL : `/v2/access` (accès sur approbation : `/users/{id}/access-status`, `/invitations`)

> Référence complète : [API_ACCESS_ENDPOINTS.json](API_ACCESS_ENDPOINTS.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [GET /v2/access/status](#get-v2accessstatus)
- [Matrice d'accès](#matrice-daccès)
- [Erreurs](#erreurs)
- [Accès sur approbation](#accès-sur-approbation)

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

---

## Accès sur approbation

Depuis le plan accès sur approbation (Phase 2, après v2.18.0), tout compte a un
statut `users.access_status` :

| Statut | Effet |
| - | - |
| `pending` | Défaut des nouveaux comptes (`users/register`, OTP `auth/send-code`). Connexion permise, routes métier refusées. |
| `approved` | Accès normal. Comptes existants à la migration. |
| `suspended` | Connexion permise, routes métier refusées. |

Un compte `pending` ou `suspended` obtient son JWT (`data.user.access_status`
dans la réponse de connexion et dans `GET /auth/me`), mais toute route JWT
répond `403` sauf `GET /auth/me`, `POST /auth/logout` et `DELETE /users/me` :

```json
{
  "success": false,
  "message": "Compte en attente d'approbation",
  "errors": { "code": "ACCOUNT_PENDING", "message": "..." }
}
```

`errors.code` vaut `ACCOUNT_PENDING` ou `ACCOUNT_SUSPENDED`. Le client affiche
un écran d'attente et propose la déconnexion.

### Endpoints admin (ADMINISTRATEUR ou plus)

| Méthode | Route | Description |
| - | - | - |
| PUT | `/users/{id}/access-status` | `{"status": "pending" \| "approved" \| "suspended"}` |
| POST | `/invitations` | `{"email", "modules"?, "ai_budget_usd"?, "expires_in_days"?}` → 201 |
| GET | `/invitations` | Liste, `status` = `pending` / `accepted` / `expired` |
| DELETE | `/invitations/{id}` | Supprime une invitation |

Un ADMINISTRATEUR ne peut pas modifier le statut d'un autre ADMINISTRATEUR
(SUPERADMINISTRATEUR requis) ; personne ne modifie un SUPERADMINISTRATEUR.

### Invitations

Une invitation non expirée et non acceptée approuve le compte du même courriel :
à la création du compte, à la connexion, ou tout de suite si le compte existe
déjà en attente. Le budget `ai_budget_usd` est posé dans
`users.ai_budget_usd_monthly`. Les `modules` sont validés et stockés ; ils
seront appliqués avec les modules cochés par personne (Phase 3).

Erreurs : `409` invitation active déjà existante, `422` courriel, module,
budget ou durée invalide.

### Courriels

- Admin : à chaque inscription en attente, vers `ACCESS_ADMIN_NOTIFY_EMAIL`
  (`.env`, vide = aucun envoi).
- Usager : quand un admin le fait passer de `pending` à `approved`.
