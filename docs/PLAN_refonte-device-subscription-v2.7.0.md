# Plan de refonte — Device + Subscription (v2.7.0)

## Contexte

Le système actuel mélange trois responsabilités dans des structures incohérentes :

- `puzzle_devices` : état de device ET état d'abonnement Play Store dans la même table
- `subscriptions` : table générique mais uniquement utilisée par Puzzle via un chemin parallèle
- Entrypoints Puzzle (`/puzzle/auth/*`) qui dupliquent la logique d'abonnement du module core

Résultat : impossibilité de raisonner clairement sur l'accès premium par plateforme.

**Décisions structurantes :**

- Portée : multi-app via `app_id`
- Tables séparées pour Play Store et Stripe (pas de table unifiée)
- Stripe → accès web + Windows uniquement
- Play Store → accès Android + web + Windows
- Les deux abonnements peuvent coexister sur le même compte
- Données actuelles : caduques, pas de migration
- Ce plan est livré en v2.7.0 avant la refonte architecturale v3.0.0

---

## Phase 0 — Inventaire et destruction planifiée

### Ce qui est en place

| Fichier | Rôle actuel | Sort |
| - | - | - |
| `src/puzzle/Models/PuzzleDevice.php` | Device + subscription Play Store | Supprimer |
| `src/puzzle/Controllers/AuthController.php` | Register device, verify sub, status | Supprimer |
| `src/puzzle/Services/GooglePlayService.php` | Valide purchase_token via API Google | Déplacer |
| `src/puzzle/Routing/PuzzleRouteHandler.php` | Routes `/puzzle/auth/*` | Nettoyer |
| `src/auth_groups/Controllers/SubscriptionController.php` | 5 routes subscription | Réécrire |
| `src/auth_groups/Models/Subscription.php` | Queries table `subscriptions` | Supprimer |
| `src/auth_groups/Services/SubscriptionService.php` | Logique activation/expiration | Réécrire |
| `src/auth_groups/Services/StripeService.php` | Stripe API, webhooks | Réécrire |
| `src/auth_groups/Services/DeviceTokenService.php` | Tokens persistants login | Conserver |
| `src/cron/expire_subscriptions.php` | Expiration quotidienne | Réécrire |

### Tables SQL à supprimer

```sql
DROP TABLE puzzle_devices;
DROP TABLE subscriptions;
```

### Tables SQL à conserver

```sql
device_tokens  -- login persistant multi-device, non lié aux abonnements
```

### Conditions de complétion

- [ ] Inventaire complet : chaque fichier marqué Supprimer/Déplacer/Conserver/Réécrire
- [ ] Aucune référence à `puzzle_devices` ou `subscriptions` dans le code actif
- [ ] Liste des routes à supprimer documentée dans ce plan

---

## Phase 1 — Nouveau schéma DB

### Nouvelles tables

#### `android_devices`

Enregistrement des devices Android par app. Aucune donnée d'abonnement.

```sql
CREATE TABLE android_devices (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NOT NULL,
    app_id        VARCHAR(64)     NOT NULL,
    device_uuid   VARCHAR(64)     NOT NULL,
    device_token  VARCHAR(256)    NOT NULL,
    token_expires_at DATETIME     NOT NULL,
    last_seen_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_device (app_id, device_uuid),
    KEY idx_user_app (user_id, app_id),
    CONSTRAINT fk_ad_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

#### `playstore_subscriptions`

Un enregistrement par `(user_id, app_id, purchase_token)`. Le token est lié à l'utilisateur, pas au device.

```sql
CREATE TABLE playstore_subscriptions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED NOT NULL,
    app_id          VARCHAR(64)     NOT NULL,
    purchase_token  VARCHAR(512)    NOT NULL,
    product_id      VARCHAR(128)    NOT NULL,
    status          ENUM('active','expired','cancelled','pending') NOT NULL DEFAULT 'pending',
    expires_at      DATETIME        NULL,
    verified_at     DATETIME        NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token_app (purchase_token, app_id),
    KEY idx_user_app (user_id, app_id),
    CONSTRAINT fk_ps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

#### `stripe_subscriptions`

Un enregistrement par `(user_id, app_id)`. Identifiant Stripe = email du user.

```sql
CREATE TABLE stripe_subscriptions (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id              BIGINT UNSIGNED NOT NULL,
    app_id               VARCHAR(64)     NOT NULL,
    stripe_customer_id   VARCHAR(64)     NOT NULL,
    stripe_subscription_id VARCHAR(64)   NULL,
    plan                 VARCHAR(64)     NOT NULL,
    status               ENUM('active','trialing','past_due','cancelled','expired') NOT NULL DEFAULT 'active',
    is_trial             TINYINT(1)      NOT NULL DEFAULT 0,
    trial_end            DATETIME        NULL,
    expires_at           DATETIME        NULL,
    cancel_at_period_end TINYINT(1)      NOT NULL DEFAULT 0,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_app (user_id, app_id),
    KEY idx_stripe_sub (stripe_subscription_id),
    KEY idx_stripe_cust (stripe_customer_id),
    CONSTRAINT fk_ss_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### Migration SQL

Fichier : `docs/20260514_device_subscription_refonte.sql`

Contenu :

```sql
DROP TABLE IF EXISTS puzzle_devices;
DROP TABLE IF EXISTS subscriptions;
-- CREATE TABLE android_devices ... (voir ci-dessus)
-- CREATE TABLE playstore_subscriptions ... (voir ci-dessus)
-- CREATE TABLE stripe_subscriptions ... (voir ci-dessus)
```

### Conditions de complétion

- [ ] `android_devices` créée, testée (insert, select, contraintes FK)
- [ ] `playstore_subscriptions` créée, unicité `(purchase_token, app_id)` vérifiée
- [ ] `stripe_subscriptions` créée, unicité `(user_id, app_id)` vérifiée
- [ ] `puzzle_devices` et `subscriptions` supprimées
- [ ] Fichier `docs/20260514_device_subscription_refonte.sql` écrit

---

## Phase 2 — Module Play Store (nouveau)

### Structure

```tree
src/playstore/
  Controllers/
    DeviceController.php      # POST /v1/devices/android/register
    SubscriptionController.php # POST /v1/subscriptions/playstore/verify
                               # GET  /v1/subscriptions/playstore/status
                               # DELETE /v1/subscriptions/playstore
  Models/
    AndroidDevice.php
    PlaystoreSubscription.php
  Services/
    GooglePlayService.php     # déplacé depuis src/puzzle/Services/
    PlaystoreSubscriptionService.php
  Routing/
    PlaystoreRouteHandler.php
```

### Entrypoints

| Méthode | URL | Auth | Description |
| - | - | - | - |
| POST | `/v1/devices/android/register` | JWT | Enregistre device_uuid, retourne device_token |
| POST | `/v1/subscriptions/playstore/verify` | JWT | Valide purchase_token via Google, active l'abonnement |
| GET | `/v1/subscriptions/playstore/status` | JWT | État actuel (status, expires_at, product_id) |
| DELETE | `/v1/subscriptions/playstore` | JWT | Marque l'abonnement annulé côté API |

### Logique `POST /v1/devices/android/register`

1. Valider `device_uuid` (requis, format UUID v4)
2. Upsert dans `android_devices` : `device_token` = nouveau token aléatoire, `token_expires_at` = +365 jours
3. Retourner `{device_token, expires_at}`

### Logique `POST /v1/subscriptions/playstore/verify`

1. Valider `purchase_token`, `product_id`, `app_id` (requis)
2. Appel `GooglePlayService::validateSubscription(app_id, product_id, purchase_token)`
3. Si valide : upsert `playstore_subscriptions` avec `status=active`, `expires_at` = expiryTimeMillis de Google
4. Si invalide : 422 avec message d'erreur Google
5. Log de l'opération

### Logique `GET /v1/subscriptions/playstore/status`

1. `app_id` depuis query string (optionnel, défaut = tous)
2. Requête sur `playstore_subscriptions` par `(user_id, app_id)` le plus récent actif
3. Si `expires_at` < now : sync Google Play en temps réel, mettre à jour status
4. Retourner `{is_premium, status, expires_at, product_id}`

### Accès accordé

Play Store actif → `android`, `web`, `windows` déverrouillés pour cet `app_id`.

### Conditions de complétion

- [ ] `POST /v1/devices/android/register` fonctionnel, testé (register, re-register même uuid)
- [ ] `POST /v1/subscriptions/playstore/verify` valide un vrai token Google Play Sandbox
- [ ] `GET /v1/subscriptions/playstore/status` retourne état correct + sync temps réel
- [ ] `DELETE /v1/subscriptions/playstore` marque annulé
- [ ] Tests dans `private/tests/test_playstore.php`

---

## Phase 3 — Module Stripe (réécriture)

### Structure

```tree
src/stripe/
  Controllers/
    BillingController.php     # POST /v1/billing/checkout
                              # POST /v1/billing/portal
                              # POST /v1/billing/webhook (public, sig Stripe)
    SubscriptionController.php # GET  /v1/subscriptions/stripe/status
                               # DELETE /v1/subscriptions/stripe
  Models/
    StripeSubscription.php
  Services/
    StripeService.php         # refactorisé depuis src/auth_groups/Services/
    StripeSubscriptionService.php
  Routing/
    StripeRouteHandler.php
```

### Entrypoints

| Méthode | URL | Auth | Description |
| - | - | - | - |
| POST | `/v1/billing/checkout` | JWT | Crée session Stripe Checkout, retourne URL |
| POST | `/v1/billing/portal` | JWT | Crée session Stripe Billing Portal, retourne URL |
| POST | `/v1/billing/webhook` | Sig Stripe | Reçoit événements Stripe, met à jour `stripe_subscriptions` |
| GET | `/v1/subscriptions/stripe/status` | JWT | État abonnement Stripe actuel |
| DELETE | `/v1/subscriptions/stripe` | JWT | Annule l'abonnement Stripe (cancel_at_period_end) |

### Mapping Stripe → plateforme

Stripe actif → `web`, `windows` déverrouillés. Android reste verrouillé.

### Idempotence webhook

Conserver la logique existante (`20260508_stripe_idempotency.sql`). La table `stripe_webhook_events` est gardée intacte.

### Conditions de complétion

- [ ] `POST /v1/billing/checkout` retourne une URL Stripe valide
- [ ] `POST /v1/billing/webhook` traite `checkout.session.completed` et `customer.subscription.updated`
- [ ] `GET /v1/subscriptions/stripe/status` retourne état correct
- [ ] `DELETE /v1/subscriptions/stripe` déclenche `cancel_at_period_end=true` via Stripe API
- [ ] Tests dans `private/tests/test_stripe.php`

---

## Phase 4 — Endpoint d'accès unifié

### Entrypoint

| Méthode | URL | Auth | Description |
| - | - | - | - |
| GET | `/v1/access/status` | JWT | Statut premium consolidé, toutes sources |

### Query params

- `app_id` (requis)
- `platform` (optionnel) : `android`, `web`, `windows`

### Logique

```
1. Récupérer playstore_subscription active pour (user_id, app_id)
2. Récupérer stripe_subscription active pour (user_id, app_id)
3. Calculer access_matrix :
   - playstore actif → android=true, web=true, windows=true
   - stripe actif    → android=false, web=true, windows=true
   - OR des deux
4. Si platform fourni, retourner is_premium = access_matrix[platform]
   Sinon retourner access_matrix complet
```

### Réponse

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
      { "provider": "playstore", "status": "active", "expires_at": "2026-12-01T00:00:00Z" }
    ]
  }
}
```

### Conditions de complétion

- [ ] Retourne `is_premium=true` si Play Store actif, `android=true`
- [ ] Retourne `android=false` si uniquement Stripe actif
- [ ] Retourne `is_premium=false` si les deux sont expirés
- [ ] Testé avec combinaisons : aucun, seulement Play Store, seulement Stripe, les deux

---

## Phase 5 — Destruction du code mort

### Fichiers à supprimer

```
src/puzzle/Models/PuzzleDevice.php
src/puzzle/Controllers/AuthController.php
src/puzzle/Services/GooglePlayService.php
src/auth_groups/Controllers/SubscriptionController.php
src/auth_groups/Models/Subscription.php
src/auth_groups/Services/SubscriptionService.php
src/auth_groups/Services/StripeService.php
src/auth_groups/Routing/RouteHandlers/SubscriptionRouteHandler.php
src/cron/expire_subscriptions.php
```

### Routes à retirer

```
GET    /subscription/status
POST   /subscription/verify
POST   /subscription/checkout
POST   /subscription/portal
DELETE /subscription/cancel
POST   /stripe/webhook
POST   /puzzle/auth/register-device
POST   /puzzle/auth/verify-subscription
GET    /puzzle/auth/subscription-status
```

### Cron à réécrire

- `src/cron/expire_playstore.php` : expire `playstore_subscriptions` dont `expires_at` < now
- `src/cron/expire_stripe.php` : expire `stripe_subscriptions` dont `expires_at` < now (fallback si webhook manqué)

### Conditions de complétion

- [ ] Aucun fichier listé ci-dessus n'existe plus
- [ ] Aucune route listée n'est enregistrée dans les RouteHandlers actifs
- [ ] Cron jobs réécrits, testables manuellement
- [ ] `grep -r "puzzle_devices\|SubscriptionService\|PuzzleDevice" src/` retourne 0 résultats

---

## Phase 6 — Documentation et directives clients

### Docs API à créer

```
docs/playstore/API_PLAYSTORE_ENDPOINTS.json
docs/playstore/GUIDE.md
docs/stripe/API_STRIPE_ENDPOINTS.json
docs/stripe/GUIDE.md
docs/access/API_ACCESS_ENDPOINTS.json
```

### Docs API à supprimer

```
docs/puzzle/API_PUZZLE_ENDPOINTS.json  -- remplacer par version nettoyée sans auth/subscription
docs/core/API_ENDPOINTS_v2_0_0.json   -- retirer routes subscription/stripe supprimées
```

### Directives clients

Créer une directive inter-projet vers chaque client affecté :

1. **Puzzle Android** — nouveaux entrypoints device + Play Store + accès unifié
2. **Puzzle Web** — nouvel entrypoint `/v1/access/status` pour vérifier le premium
3. **Puzzle Windows** — idem Web

Modèle de directive : `c:\code\directives_inter_projet\_GABARIT.md`

### Conditions de complétion

- [ ] `docs/playstore/GUIDE.md` couvre register-device + verify + status
- [ ] `docs/stripe/GUIDE.md` couvre checkout + portal + webhook + status
- [ ] `docs/access/API_ACCESS_ENDPOINTS.json` couvre GET /v1/access/status
- [ ] Directives créées pour chaque client Puzzle (Android, Web, Windows)
- [ ] `docs/puzzle/API_PUZZLE_ENDPOINTS.json` nettoyé (sans routes subscription)

---

## Phase 7 — Tests et validation

### Fichiers de tests à créer

```
private/tests/test_playstore.php
private/tests/test_stripe_v2.php
private/tests/test_access.php
```

### Scénarios obligatoires

#### Play Store

- [ ] Register device → device_token retourné
- [ ] Register même device_uuid → token renouvelé (upsert)
- [ ] Verify subscription avec token valide Sandbox → `status=active`
- [ ] Verify subscription avec token invalide → 422
- [ ] Status sans abonnement → `is_premium=false`
- [ ] Status avec abonnement expiré → `is_premium=false` + sync Google

#### Stripe

- [ ] Checkout → URL valide
- [ ] Webhook `checkout.session.completed` → row créée dans `stripe_subscriptions`
- [ ] Webhook doublon (idempotency) → ignoré, pas de doublon
- [ ] Status après webhook → `is_premium=true`
- [ ] Cancel → `cancel_at_period_end=true` dans Stripe

#### Accès unifié

- [ ] Aucune sub → `{android:false, web:false, windows:false}`
- [ ] Stripe actif → `{android:false, web:true, windows:true}`
- [ ] Play Store actif → `{android:true, web:true, windows:true}`
- [ ] Les deux actifs → `{android:true, web:true, windows:true}`
- [ ] `?platform=android` → `is_premium=bool` correct

### Conditions de complétion

- [ ] Tous les scénarios ci-dessus passent
- [ ] `php private/tests/run_all_tests.php` → 0 échec

---

## Phase 8 — Release v2.7.0

### Étapes

1. Mettre à jour `CHANGELOG.md`
2. Bumper version dans `composer.json` et `.env.example`
3. Construire `docs/v-2-7-0/build_DB-v-2.7.0.sql` (intégrer `20260514_device_subscription_refonte.sql`)
4. Créer `docs/v-2-7-0/2.7.0_PRODUCTION.md` (checklist déploiement)
5. Créer `docs/v-2-7-0/2.7.0_CLIENT.md` (breaking changes : routes supprimées, nouvelles routes)
6. PR release → merge → tag `v2.7.0`

### STOP obligatoires

- Avant tout `ALTER/DROP TABLE` en production : confirmation explicite
- Avant `git push` de la branche release : tests locaux 0 échec
- Avant déploiement : checklist `2.7.0_PRODUCTION.md` complétée

### Conditions de complétion

- [ ] CHANGELOG.md entrée v2.7.0 complète
- [ ] `build_DB-v-2.7.0.sql` inclut les 3 nouvelles tables, supprime les 2 anciennes
- [ ] PR mergée, tag `v2.7.0` créé
- [ ] Directives clients envoyées

---

## Relation avec PLAN_refonte-v3.0.0.md

Ce plan (v2.7.0) est **préalable** au plan v3.0.0 :

- v2.7.0 : refonte fonctionnelle du domaine subscription/device, même architecture micro-framework
- v3.0.0 : refonte architecturale (PSR-7/11/15, DI, OpenAPI) — peut maintenant intégrer le nouveau modèle sans dette supplémentaire

Mettre à jour `PLAN_refonte-v3.0.0.md` Phase 1 pour référencer les routes v2.7.0 comme point de départ des routes subscription/billing/access.

---

## Ordre de livraison recommandé

```
Phase 0 → Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5 → Phase 6 → Phase 7 → Phase 8
```

Phases 2 et 3 peuvent être parallélisées si deux branches de travail distinctes.
