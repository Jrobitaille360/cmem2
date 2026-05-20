# Guide — Module Stripe (v2.7.0)

Version 1.0.0 · Base URL : `/v2`

> Référence complète : [API_STRIPE_ENDPOINTS.json](API_STRIPE_ENDPOINTS.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [Billing — Checkout et Portail](#billing--checkout-et-portail)
- [Webhook Stripe](#webhook-stripe)
- [Abonnements Stripe](#abonnements-stripe)
- [Mapping plateforme](#mapping-plateforme)
- [Codes d'erreur](#codes-derreur)
- [Exemples complets](#exemples-complets)

---

## Vue d'ensemble

Ce module gère les abonnements Stripe pour les plateformes web et Windows.

- `POST /v2/billing/checkout` — crée une session Stripe Checkout
- `POST /v2/billing/portal` — crée une session Stripe Billing Portal
- `POST /v2/billing/webhook` — reçoit les événements Stripe (public, signature Stripe)
- `GET /v2/subscriptions/stripe/status` — statut de l'abonnement
- `DELETE /v2/subscriptions/stripe` — annule à la fin de la période en cours

Chaque abonnement est lié à `(user_id, app_id)`. L'identifiant Stripe est l'email du compte.

---

## Authentification

Tous les endpoints JWT exigent :

```http
Authorization: Bearer <jwt_token>
```

L'endpoint `POST /v2/billing/webhook` est **public** — protégé par signature Stripe uniquement.

---

## Billing — Checkout et Portail

### POST /v2/billing/checkout

Crée une session Stripe Checkout. L'utilisateur est redirigé vers l'URL retournée pour compléter
son paiement. Stripe enverra un webhook `checkout.session.completed` à l'issue.

**Corps :**

| Champ | Type | Requis | Description |
| - | - | - | - |
| `app_id` | string | oui | Identifiant de l'application (ex. `puzzle`) |
| `plan` | string | oui | `monthly` ou `yearly` |

**Réponse 200 :**

```json
{
  "success": true,
  "data": {
    "checkout_url": "https://checkout.stripe.com/c/pay/...",
    "session_id": "cs_test_..."
  }
}
```

**Erreurs :**

| Code | Cause |
| - | - |
| 401 | JWT absent ou invalide |
| 422 | `app_id` ou `plan` manquant, `plan` invalide (doit être `monthly` ou `yearly`) |
| 500 | Clé Stripe non configurée ou erreur API Stripe |

**Notes client :** Rediriger l'utilisateur vers `checkout_url`. Après paiement, Stripe redirige
vers l'URL de succès configurée dans `.env` (`STRIPE_SUCCESS_URL_{APP_ID}`).

### POST /v2/billing/portal

Crée une session Stripe Billing Portal permettant à l'utilisateur de gérer son abonnement
(annuler, mettre à jour la carte, voir les factures).

**Corps :**

| Champ | Type | Requis | Description |
| - | - | - | - |
| `app_id` | string | oui | Identifiant de l'application |

**Réponse 200 :**

```json
{
  "success": true,
  "data": {
    "portal_url": "https://billing.stripe.com/p/session/..."
  }
}
```

**Erreurs :**

| Code | Cause |
| - | - |
| 401 | JWT absent ou invalide |
| 404 | Aucun `stripe_customer_id` en base pour cet utilisateur + `app_id` |
| 422 | `app_id` manquant |
| 500 | Erreur API Stripe |

---

## Webhook Stripe

### POST /v2/billing/webhook

Endpoint public qui reçoit les événements Stripe. Protégé par signature HMAC-SHA256
(`Stripe-Signature` header). Idempotent — un événement déjà traité est ignoré.

**Headers requis :**

```http
Content-Type: application/json
Stripe-Signature: t=...,v1=...
```

**Événements traités :**

| Événement | Action |
| - | - |
| `checkout.session.completed` | Active l'abonnement, crée `stripe_subscriptions` |
| `customer.subscription.updated` | Met à jour status, expires_at, cancel_at_period_end |
| `invoice.payment_succeeded` | Renouvelle expires_at |
| `invoice.payment_failed` | Passe status à `past_due` |
| `customer.subscription.deleted` | Passe status à `cancelled` |

**Réponses :**

| Code | Cause |
| - | - |
| 200 | Événement traité (ou déjà traité — idempotent) |
| 400 | Header `Stripe-Signature` absent, HMAC invalide ou timestamp expiré (>300 s) |

**Configuration requise dans `.env` :**

```
STRIPE_WEBHOOK_SECRET=whsec_...
```

---

## Abonnements Stripe

### GET /v2/subscriptions/stripe/status

Retourne le statut de l'abonnement Stripe pour `(user_id, app_id)`.

**Query params :** `app_id` (requis)

**Réponse 200 :**

```json
{
  "success": true,
  "data": {
    "is_premium": false,
    "status": null,
    "expires_at": null,
    "plan": null,
    "cancel_at_period_end": false
  }
}
```

Quand actif :

```json
{
  "success": true,
  "data": {
    "is_premium": true,
    "status": "active",
    "expires_at": "2027-06-01T00:00:00",
    "plan": "monthly",
    "cancel_at_period_end": false
  }
}
```

### DELETE /v2/subscriptions/stripe

Annule l'abonnement Stripe à la fin de la période en cours (`cancel_at_period_end = true`
via l'API Stripe). L'accès reste actif jusqu'à `expires_at`.

**Body :** `app_id` (requis)

**Erreurs :**

| Code | Cause |
| - | - |
| 401 | JWT absent ou invalide |
| 422 | `app_id` manquant ou aucun abonnement Stripe actif |
| 500 | Erreur API Stripe |

---

## Mapping plateforme

Un abonnement Stripe actif déverrouille uniquement les plateformes web et Windows.
Android n'est accessible que via Play Store.

| Plateforme | Accès Stripe |
| - | - |
| android | non |
| web | oui |
| windows | oui |

Pour vérifier l'accès consolidé (Play Store + Stripe), utiliser `GET /v2/access/status`.

---

## Codes d'erreur

| HTTP | Signification |
| - | - |
| 400 | Signature webhook invalide ou expirée |
| 401 | JWT absent ou expiré |
| 404 | Customer Stripe introuvable |
| 422 | Paramètre manquant ou invalide |
| 500 | Erreur API Stripe (clé manquante ou rejet) |

---

## Exemples complets

### Lancer un checkout mensuel

```http
POST /v2/billing/checkout
Authorization: Bearer {jwt}
Content-Type: application/json

{ "app_id": "puzzle", "plan": "monthly" }
```

```json
{
  "success": true,
  "data": {
    "checkout_url": "https://checkout.stripe.com/c/pay/cs_test_...",
    "session_id": "cs_test_..."
  }
}
```

### Vérifier le statut Stripe

```http
GET /v2/subscriptions/stripe/status?app_id=puzzle
Authorization: Bearer {jwt}
```

### Annuler à la fin de la période

```http
DELETE /v2/subscriptions/stripe
Authorization: Bearer {jwt}
Content-Type: application/json

{ "app_id": "puzzle" }
```
