# Plan — Consolidation module Stripe

Créé : 2026-05-27 · Dernière mise à jour : 2026-05-27

## Contexte

Deux implémentations Stripe coexistent depuis v2.7.0. Le module `src/stripe/` est canonique ;
`src/auth_groups/` conserve des reliquats (`StripeService`, routes legacy) qui causaient des
divergences (success_url hardcodé → 404, tables différentes, comportement divergent).

---

## Architecture avant/après

### Module canonique `src/stripe/` (v2) — à conserver

| Route | Contrôleur | Table |
| - | - | - |
| `POST /v2/billing/checkout` | `BillingController` | `stripe_subscriptions` |
| `POST /v2/billing/portal` | `BillingController` | `stripe_subscriptions` |
| `POST /v2/billing/webhook` | `BillingController` | `stripe_subscriptions` |
| `GET /v2/subscriptions/stripe/status` | `Stripe\SubscriptionController` | `stripe_subscriptions` |
| `DELETE /v2/subscriptions/stripe` | `Stripe\SubscriptionController` | `stripe_subscriptions` |

### Module legacy `src/auth_groups/` — parties Stripe à éliminer

| Route | Contrôleur | Table | Statut |
| - | - | - | - |
| `POST /subscription/checkout` | `AuthGroups\SubscriptionController` | `subscriptions` | Retourne 410 ✓ |
| `POST /subscription/portal` | `AuthGroups\SubscriptionController` | `subscriptions` | Retourne 410 ✓ |
| `POST /stripe/webhook` | `AuthGroups\StripeController` | `subscriptions` | Encore actif — prod webhook pointe ici |

### Parties NON-Stripe dans `src/auth_groups/` (à conserver définitivement)

| Route | Utilité |
| - | - |
| `GET /subscription/status` | Sync Google Play live + statut multi-provider |
| `POST /subscription/verify` | Activation multi-provider (google_play, apple, microsoft, stripe) |
| `DELETE /subscription/cancel` | Annulation générique (non Stripe-spécifique) |

---

## État d'avancement

| Phase | Description | Statut |
| - | - | - |
| 1 | Fix `success_url`/`cancel_url` dynamiques dans service legacy | ✅ Complété |
| 2 | Webhook Dashboard — dev migré, prod bloqué post-release | 🔶 En cours |
| 3 | Déprécier routes legacy checkout/portal (→ 410) | ✅ Complété |
| 4 | Suppression StripeController + router + StripeService | 🔒 Bloqué (attend Phase 2 prod) |
| 5 | Docs GUIDE.md + API_STRIPE_ENDPOINTS.json | ✅ Complété |
| M | Maintenance — tests + jdb route check | 🔶 Partiel |

---

## Phases

### Phase 1 — Fix success_url dynamique dans le service legacy ✅

**Pourquoi :** clients sur `POST /subscription/checkout` recevaient URL hardcodée
`/puzzle/subscription/success` → 404 frontend.

**Actions :**

- [x] `src/auth_groups/Services/StripeService.php` : `success_url` et `cancel_url`
      remplacés par `'https://journauxdebord.com/' . $appId . '/subscription/...'`

**Condition de complétion :** ✅ plus de 404 sur flux legacy.

---

### Phase 2 — Migrer webhook Stripe Dashboard 🔶

**Pourquoi :** deux endpoints webhook actifs écrivent dans des tables différentes.
`/v2/billing/webhook` → `stripe_subscriptions` (correct).
`/stripe/webhook` → `subscriptions` (legacy, table à supprimer).

**Actions :**

- [x] **dev-cmem2** → `https://dev-cmem2.journauxdebord.com/v2/billing/webhook` ✓ (2026-05-27)
- [ ] **prod cmem2 API** → encore `https://cmem2.journauxdebord.com/stripe/webhook` — migrer après release v2.7.0
- [ ] Confirmer événements prod dans `logs/app-YYYY-MM-DD.log` via `/v2/billing/webhook`

**Condition de complétion :** un seul webhook actif par environnement, table = `stripe_subscriptions`.

---

### Phase 3 — Déprécier routes legacy checkout/portal ✅

**Pourquoi :** `POST /subscription/checkout` et `POST /subscription/portal` dupliquaient
la logique en écrivant dans `subscriptions` au lieu de `stripe_subscriptions`.
Puzzle web/windows confirmé migré vers `/v2/billing/*` (directives v2.7.0 complétées).

**Actions :**

- [x] `AuthGroups\SubscriptionController::checkout()` → retourne 410 avec message de migration
- [x] `AuthGroups\SubscriptionController::portal()` → retourne 410 avec message de migration
- [x] `use AuthGroups\Services\StripeService` retiré de `SubscriptionController`
- [x] Routes router-handler conservées (`/subscription/status`, `/subscription/verify`,
      `/subscription/cancel` toujours actives)

**Condition de complétion :** ✅ zéro écriture Stripe dans `subscriptions` via ces routes.

---

### Phase 4 — Supprimer StripeController + router entry + StripeService 🔒

**Prérequis :** Phase 2 prod complétée (webhook prod migré vers `/v2/billing/webhook`).

**Pourquoi :** `AuthGroups\Services\StripeService` est le seul fichier encore utilisé par
`StripeController`. Une fois le webhook prod migré, ces trois fichiers sont orphelins.

**Actions :**

- [x] Vérifier usages — seul `StripeController.php` importe encore `AuthGroups\Services\StripeService`
- [ ] Supprimer `src/auth_groups/Controllers/StripeController.php`
- [ ] Retirer entrée `'stripe' => fn() => new StripeRouteHandler()` de `src/auth_groups/Routing/Router.php`
- [ ] Retirer `use AuthGroups\Routing\RouteHandlers\StripeRouteHandler` de `Router.php`
- [ ] Supprimer `src/auth_groups/Routing/RouteHandlers/StripeRouteHandler.php`
- [ ] Supprimer `src/auth_groups/Services/StripeService.php`

**Tests :**

- `composer dump-autoload` sans erreur
- `php private/tests/test_subscriptions.php` → 0 échec

**Condition de complétion :** aucun fichier legacy Stripe dans `src/auth_groups/`, autoload propre.

---

### Phase 5 — Docs `docs/stripe/` ✅

**Actions :**

- [x] `GUIDE.md` — `is_trial`, `trial_end`, `provider` ajoutés dans réponses statut
- [x] `GUIDE.md` — fausse ref `STRIPE_SUCCESS_URL_{APP_ID}` supprimée
- [x] `GUIDE.md` — URLs de redirection documentées comme dynamiques (`/{app_id}/...`)
- [x] `GUIDE.md` — section routes dépréciées ajoutée
- [x] `API_STRIPE_ENDPOINTS.json` — même corrections + section `deprecated_routes`
- [x] `API_STRIPE_ENDPOINTS.json` — version bumped `1.0.0` → `1.1.0`, `generated` mis à jour

**Condition de complétion :** ✅ JSON valide, GUIDE.md lint propre, exemples cohérents.

---

## Maintenances

| Item | Statut |
| - | - |
| `test_subscriptions.php` couvre uniquement `/v2/billing/*` (pas les routes legacy) | ✅ Déjà vrai avant ce plan |
| Webhook prod migré après release v2.7.0 | ⏳ Voir Phase 2 |
| Nouveau app_id adoptant Stripe → vérifier route `[appId]/subscription/*` dans jdb | ⏳ Permanent — créer directive jdb si `appId != puzzle` |

---

## Référence croisée

- Fichiers à supprimer en Phase 4 : aussi listés dans
  `docs/PLAN_refonte-device-subscription-v2.7.0.md` — Phase 5 (marqués BLOQUÉ)
- Routes dépréciées : documentées dans `docs/stripe/GUIDE.md` § Routes dépréciées
- Directive frontend : `c:\code\directives_inter_projet\20260527_110753_cmem2_API_vers_jdb__pages-stripe-subscription.md`
