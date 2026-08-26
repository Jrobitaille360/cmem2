# Guide — Module Stripe (v2.7.0)

Version 1.2.0 · Base URL : `/v2`

> Référence complète : [API_STRIPE_ENDPOINTS.json](API_STRIPE_ENDPOINTS.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [Billing — Checkout et Portail](#billing--checkout-et-portail)
- [Webhook Stripe](#webhook-stripe)
- [Abonnements Stripe](#abonnements-stripe)
- [Plan effectif cmem (/auth/me)](#plan-effectif-cmem-authme)
- [Plan équipe (abonnement de groupe)](#plan-equipe-abonnement-de-groupe)
- [URLs de redirection](#urls-de-redirection)
- [Mapping plateforme](#mapping-plateforme)
- [Routes dépréciées](#routes-dépréciées)
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

Chaque abonnement est lié à `(user_id, app_id)` — ou à `(group_id, app_id)` depuis le plan équipe
(2026-08-13, voir [Plan équipe](#plan-equipe-abonnement-de-groupe)). L'identifiant Stripe est
l'email du compte (ou de l'admin qui initie le checkout pour un groupe).

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
| `plan` | string | oui | `monthly` ou `yearly` (sans `group_id`) ; `team` obligatoire si `group_id` fourni |
| `group_id` | int | non | Plan équipe — rattache l'abonnement au **groupe** plutôt qu'à l'usager. Requiert `role=admin` du groupe. Voir [Plan équipe](#plan-equipe-abonnement-de-groupe) |

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
| 403 | `group_id` fourni et l'usager n'est pas admin du groupe — `errors: { code: "GROUP_ADMIN_REQUIRED" }` |
| 422 | `app_id` ou `plan` manquant, `plan` invalide (`monthly`/`yearly` sans `group_id`, `team` obligatoire avec `group_id`) |
| 500 | Clé/prix Stripe non configuré ou erreur API Stripe |

**Notes client :** Rediriger l'utilisateur vers `checkout_url`. Après paiement, Stripe redirige
vers `https://journauxdebord.com/{app_id}/subscription/success?session_id={CHECKOUT_SESSION_ID}`.
En cas d'annulation : `https://journauxdebord.com/{app_id}/subscription/cancel`.

### POST /v2/billing/portal

Crée une session Stripe Billing Portal permettant à l'utilisateur de gérer son abonnement
(annuler, mettre à jour la carte, voir les factures).

**Corps :**

| Champ | Type | Requis | Description |
| - | - | - | - |
| `app_id` | string | oui | Identifiant de l'application |
| `group_id` | int | non | Plan équipe — gère l'abonnement du **groupe**. Requiert `role=admin` du groupe |

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
| 403 | `group_id` fourni et l'usager n'est pas admin du groupe — `errors: { code: "GROUP_ADMIN_REQUIRED" }` |
| 404 | Aucun `stripe_customer_id` en base pour cet utilisateur/groupe + `app_id` |
| 422 | `app_id` manquant |
| 500 | Erreur API Stripe |

**Notes client :** Après gestion, Stripe retourne vers
`https://journauxdebord.com/{app_id}/subscription/manage-return`.

---

## Webhook Stripe

### POST /v2/billing/webhook

Endpoint public qui reçoit les événements Stripe. Protégé par signature HMAC-SHA256
(`Stripe-Signature` header). Idempotent — un événement déjà traité est ignoré.

**Configuration Stripe Dashboard :** `https://{domain}/v2/billing/webhook`

**Headers requis :**

```http
Content-Type: application/json
Stripe-Signature: t=...,v1=...
```

**Événements traités :**

| Événement | Action |
| - | - |
| `checkout.session.completed` | Active l'abonnement, crée ligne dans `stripe_subscriptions` |
| `customer.subscription.updated` | Met à jour `status`, `expires_at`, `cancel_at_period_end` |
| `invoice.payment_succeeded` | Renouvelle `expires_at`, passe `is_trial` à 0 |
| `invoice.payment_failed` | Passe `status` à `past_due` |
| `customer.subscription.deleted` | Passe `status` à `cancelled` |

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

Retourne le statut de l'abonnement Stripe pour `(user_id, app_id)` — ou pour
`(group_id, app_id)` si `group_id` est fourni (plan équipe). Lecture seule : simple appartenance
au groupe suffit, `role=admin` non requis.

**Query params :** `app_id` (requis), `group_id` (non, plan équipe — requiert d'être membre du groupe, sinon `403 GROUP_MEMBERSHIP_REQUIRED`)

**Réponse 200 — aucun abonnement :**

```json
{
  "success": true,
  "data": {
    "is_premium": false,
    "status": null,
    "expires_at": null,
    "plan": null,
    "is_trial": false,
    "trial_end": null,
    "cancel_at_period_end": false,
    "provider": "stripe"
  }
}
```

**Réponse 200 — essai actif :**

```json
{
  "success": true,
  "data": {
    "is_premium": true,
    "status": "trialing",
    "expires_at": "2026-06-03T11:00:00",
    "plan": "monthly",
    "is_trial": true,
    "trial_end": "2026-06-03T11:00:00",
    "cancel_at_period_end": false,
    "provider": "stripe"
  }
}
```

**Réponse 200 — abonnement actif :**

```json
{
  "success": true,
  "data": {
    "is_premium": true,
    "status": "active",
    "expires_at": "2027-06-01T00:00:00",
    "plan": "monthly",
    "is_trial": false,
    "trial_end": null,
    "cancel_at_period_end": false,
    "provider": "stripe"
  }
}
```

**Valeurs possibles de `status` :** `trialing` · `active` · `past_due` · `cancelled` · `null`

### DELETE /v2/subscriptions/stripe

Annule l'abonnement Stripe à la fin de la période en cours (`cancel_at_period_end = true`
via l'API Stripe). L'accès reste actif jusqu'à `expires_at`. `group_id` optionnel (plan équipe) :
annule l'abonnement du **groupe**, réservé aux admins — même délai de grâce que l'individuel.

**Body :** `app_id` (requis), `group_id` (non, plan équipe — requiert `role=admin` du groupe)

**Erreurs :**

| Code | Cause |
| - | - |
| 401 | JWT absent ou invalide |
| 403 | `group_id` fourni et l'usager n'est pas admin du groupe — `errors: { code: "GROUP_ADMIN_REQUIRED" }` |
| 422 | `app_id` manquant ou aucun abonnement Stripe actif |
| 500 | Erreur API Stripe |

---

## Plan effectif cmem (/auth/me)

`GET /auth/me` retourne un champ `plan` calculé par `EntitlementService::getEffectivePlanForCmem()`
(pas un appel Stripe séparé — évite d'agréger 3 appels côté client) :

```json
"plan": {
  "code": "monthly",
  "source": "stripe",
  "status": "active",
  "features": {
    "max_calendars": 25,
    "max_journals": 2500,
    "max_tasks": 5000,
    "max_devices": 5,
    "max_storage_mb": 2000,
    "max_groups": 10,
    "max_group_members": 50
  }
}
```

Depuis le plan équipe (2026-08-13), le plan effectif est le **meilleur** entre le plan perso de
l'usager et celui de chacun de ses groupes actifs — `source` peut aussi valoir `"group"`, avec un
champ `group_id` en plus :

```json
"plan": {
  "code": "team",
  "source": "group",
  "status": "active",
  "group_id": 42,
  "features": { "...": "mêmes caps que monthly/yearly" }
}
```

Ordre de résolution — perso d'abord, puis comparaison avec chaque groupe actif via un classement
interne (`free` < `monthly`=`yearly` < `team` < `ami`) :

1. **`stripe_subscriptions`** actif pour `app_id='cmem'`/`'cmemweb'` (`status` ∈ `trialing`/`active`/`past_due`) → `source: "stripe"`, `code` = `plan` (`monthly`/`yearly`).
2. **`users.cmem_plan_override`** (override manuel, ex. `'ami'`, posé par un admin) → `source: "override"`.
3. Par défaut → `code: "free"`, `source: "default"`.
4. Pour chaque **groupe actif** dont l'usager est membre, l'abonnement `stripe_subscriptions`
   du groupe (`group_id`) est comparé au meilleur candidat trouvé jusque-là ; s'il est de rang
   supérieur → `source: "group"`, `code` = plan du groupe, `group_id` renseigné.

Égalité de rang : le plan perso gagne sur un plan de groupe ; entre deux groupes à égalité, le
plus petit `group_id` gagne (déterministe). Quitter un groupe (`POST /groups/{id}/leave`) ou en
être retiré fait retomber le plan effectif **immédiatement** (résolution live, pas de cache) —
sans perte des données existantes.

Caps par plan : config statique `src/stripe/Config/CmemPlans.php` (pas de table DB). Règle
verrouillée : `max_journals = max_tasks / 2`.

---

## Plan equipe (abonnement de groupe)

Directive `20260813_143000_cmem_web_vers_cmem2_API__plan-equipe.md`. Un groupe peut porter son
propre abonnement Stripe (tier **`team`**, mensuel seulement en v1) au lieu de facturer chaque
membre individuellement. Même principe XOR que `tenant_modules` sur la table
`stripe_subscriptions` : exactement un des deux porteurs par ligne (`user_id` **ou** `group_id`).

- **Initier/gérer l'abonnement** (`checkout`, `portal`, `cancel`) : réservé aux membres
  `role=admin` du groupe (ou rôle système `ADMINISTRATEUR`+) — sinon `403 GROUP_ADMIN_REQUIRED`.
- **Consulter le statut** (`GET status`) : ouvert à tout membre du groupe.
- **Prix** : `STRIPE_PRICE_CMEMWEB_TEAM`, placeholder **15 $ CAD/mois** (provisoire, documenté
  dans `docs/PLAN_plan-equipe.md`). Aucun plafond de sièges appliqué côté serveur en v1.
- **Modules de groupe** : `GET/PATCH /groups/{id}/modules[/{key}]` — voir
  [docs/docs-api/modules/GUIDE.md](../modules/GUIDE.md).
- **Annulation** : même délai de grâce que l'individuel (`cancel_at_period_end`, accès jusqu'à
  `expires_at`).

---

## URLs de redirection

Les URLs sont générées dynamiquement selon `app_id`. Aucune variable d'environnement
n'est requise côté API pour ces URLs — elles sont construites en code.

| Événement | URL |
| - | - |
| Paiement réussi | `https://journauxdebord.com/{app_id}/subscription/success?session_id={id}` |
| Paiement annulé | `https://journauxdebord.com/{app_id}/subscription/cancel` |
| Retour portail | `https://journauxdebord.com/{app_id}/subscription/manage-return` |

Ces pages doivent exister dans le frontend (`jdb`). Voir directive
`20260527_110753_cmem2_API_vers_jdb__pages-stripe-subscription.md`.

---

## Mapping plateforme

Un abonnement Stripe actif déverrouille uniquement les plateformes web et Windows.
Android n'est accessible que via Play Store.

| Plateforme | Accès Stripe |
| - | - |
| android | non |
| web | oui |
| windows | oui |

Pour vérifier l'accès Stripe (web/windows), utiliser `GET /v2/access/status` (JWT).
Android consulte son propre statut via `GET /v2/subscriptions/playstore/status` (X-Device-Token).

---

## Routes dépréciées

Ces routes existaient avant v2.7.0. Elles restent actives mais écrivent dans la table
`subscriptions` (générique) au lieu de `stripe_subscriptions`. Migrer vers les routes `/v2/`.

| Route dépréciée | Remplacée par |
| - | - |
| `POST /subscription/checkout` | `POST /v2/billing/checkout` |
| `POST /subscription/portal` | `POST /v2/billing/portal` |
| `POST /stripe/webhook` | `POST /v2/billing/webhook` |

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
