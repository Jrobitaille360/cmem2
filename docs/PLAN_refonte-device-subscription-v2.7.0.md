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
| `src/puzzle/Routing/PuzzleRouteHandler.php` | Routes `/puzzle/auth/*` + toutes routes `/puzzle/*` | Adapter |
| `src/auth_groups/Routing/Router.php` | Dispatch `segments[0]` uniquement | Adapter |
| `src/auth_groups/Routing/RouteHandlers/V2RouteHandler.php` | Sub-dispatch `/v2/{module}/*` | Créer |
| `src/auth_groups/Controllers/SubscriptionController.php` | 5 routes subscription | Réécrire |
| `src/auth_groups/Controllers/StripeController.php` | Route `/stripe/webhook` | Supprimer |
| `src/auth_groups/Routing/RouteHandlers/SubscriptionRouteHandler.php` | Routes `/subscription/*` | Supprimer |
| `src/auth_groups/Routing/RouteHandlers/StripeRouteHandler.php` | Route `/stripe/webhook` | Supprimer |
| `src/auth_groups/Models/Subscription.php` | Queries table `subscriptions` | Supprimer |
| `src/auth_groups/Services/SubscriptionService.php` | Logique activation/expiration | Réécrire |
| `src/auth_groups/Services/StripeService.php` | Stripe API, webhooks | Réécrire |
| `src/auth_groups/Services/DeviceTokenService.php` | Tokens persistants login | Conserver |
| `src/auth_groups/Controllers/AuthController.php` | Injecte `SubscriptionService::getAllStatuses` dans `/auth/me` | Adapter |
| `src/auth_groups/Controllers/UserListController.php` | Injecte `SubscriptionService::getAllStatuses` dans liste users | Adapter |
| `src/auth_groups/Services/MaintenanceService.php` | Expire `subscriptions` (UPDATE status) | Adapter |
| `src/puzzle/Models/SharedPuzzle.php` | JOINs sur `puzzle_devices` (creator_id, partner_id, held_by_id, by_id, pseudonym, last_seen_at) | Adapter ⚠️ |
| `src/puzzle/Services/MaintenanceService.php` | Nettoyage `puzzle_devices` (expired/inactive) | Adapter |
| `src/cron/expire_subscriptions.php` | Expiration quotidienne | Réécrire |
| `src/cron/backup/backup_puzzle.php` | Inclut `puzzle_devices` dans la sauvegarde | Adapter |
| `src/cron/backup/backup_core.php` | Inclut `subscriptions` dans la sauvegarde | Adapter |
| `src/auth_groups/Models/AppUserSettings.php` | Pseudonymes + settings par `(user_id, app_id)` | Créer |

### Dépendance critique — SharedPuzzle ↔ puzzle_devices

`puzzle_shared` et `puzzle_shared_events` utilisent `puzzle_devices.id` comme FK pour
`creator_id`, `partner_id`, `held_by_id`, `by_id`. La colonne `pseudonym` de `puzzle_devices`
est affichée dans les sessions partagées ; `last_seen_at` sert à détecter la présence du partenaire.

**Impact sur Phase 2** : la nouvelle table `android_devices` ne contient pas `pseudonym`.
Il faudra soit :

- Ajouter `pseudonym` dans `android_devices`, ou
- Créer une table `puzzle_pseudonyms(android_device_id, pseudonym)` séparée.

Cette décision doit être prise avant d'écrire `AndroidDevice.php`.

**Impact sur le schéma** : les FK dans `puzzle_shared` et `puzzle_shared_events`
doivent pointer sur `android_devices.id` au lieu de `puzzle_devices.id`.

**Décision pseudonyme (arrêtée)** : `pseudonym` stocké dans nouvelle table `app_user_settings(user_id, app_id)` avec `UNIQUE(app_id, pseudonym)`. Pas dans `android_devices` (pseudonyme doit survivre au changement de device). `items` rejeté (pas de contrainte UNIQUE DB sur JSON).

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

- [x] Inventaire complet : chaque fichier marqué Supprimer/Déplacer/Conserver/Réécrire/Adapter
- [x] Toutes les références à `puzzle_devices` et `subscriptions` identifiées et couvertes par l'inventaire
- [x] Liste des routes à supprimer documentée dans ce plan (voir Phase 5)

---

## Phase 1 — Nouveau schéma DB

> **Environnement :** migration appliquée sur la base **locale uniquement** (`localhost`).
> Stripe et Play Store ne sont pas impliqués à cette phase — pas besoin du serveur dev.
> La base de production n'est jamais touchée avant Phase 8.
> Voir [PLAN_environnement-dev-distant.md](PLAN_environnement-dev-distant.md) pour la stratégie d'environnement complète.

### Nouvelles tables

#### `android_devices`

Enregistrement des devices Android par app. Aucune donnée d'abonnement.

```sql
CREATE TABLE android_devices (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       int(11)         NOT NULL,
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

#### `app_user_settings`

Pseudonyme par `(user_id, app_id)`. Unique pour éviter la confusion dans les sessions partagées.

```sql
CREATE TABLE app_user_settings (
    user_id   int(11)     NOT NULL,
    app_id    VARCHAR(64) NOT NULL,
    pseudonym VARCHAR(64) NULL,
    PRIMARY KEY (user_id, app_id),
    UNIQUE KEY uq_pseudo_app (app_id, pseudonym),
    CONSTRAINT fk_aus_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

#### `playstore_subscriptions`

Un enregistrement par `(user_id, app_id, purchase_token)`. Le token est lié à l'utilisateur, pas au device.

```sql
CREATE TABLE playstore_subscriptions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         int(11)         NOT NULL,
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
    user_id              int(11)         NOT NULL,
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
-- CREATE TABLE app_user_settings ... (voir ci-dessus)
-- CREATE TABLE playstore_subscriptions ... (voir ci-dessus)
-- CREATE TABLE stripe_subscriptions ... (voir ci-dessus)
```

### Conditions de complétion

- [x] `android_devices` créée en local, testée (insert, select, contraintes FK)
- [x] `app_user_settings` créée en local, unicité `(app_id, pseudonym)` vérifiée (conflit = 409)
- [x] `playstore_subscriptions` créée en local, unicité `(purchase_token, app_id)` vérifiée
- [x] `stripe_subscriptions` créée en local, unicité `(user_id, app_id)` vérifiée
- [x] `puzzle_devices` et `subscriptions` supprimées de la base locale
- [x] Fichier `docs/20260514_device_subscription_refonte.sql` écrit

---

## Phase 2 — Module Play Store (nouveau)

### Structure

```tree
src/playstore/
  Controllers/
    DeviceController.php      # POST /v2/devices/android/register
                              # GET|POST|DELETE /v2/devices/android/pseudonym
                              # GET /v2/devices/android/pseudonym/check/{pseudo}
    SubscriptionController.php # POST /v2/subscriptions/playstore/verify
                               # GET  /v2/subscriptions/playstore/status
                               # DELETE /v2/subscriptions/playstore
  Models/
    AndroidDevice.php
    PlaystoreSubscription.php
  Services/
    GooglePlayService.php     # déplacé depuis src/puzzle/Services/
    PlaystoreSubscriptionService.php
  Routing/
    PlaystoreRouteHandler.php

src/auth_groups/Models/
  AppUserSettings.php         # modèle transversal — namespace AuthGroups\Models
                              # utilisé par playstore, stripe, et tout futur module
```

### Entrypoints

| Méthode | URL | Auth | Description |
| - | - | - | - |
| POST | `/v2/devices/android/register` | JWT | Enregistre device_uuid, retourne device_token |
| GET | `/v2/devices/android/pseudonym` | JWT | Pseudonyme actuel pour `app_id` |
| POST | `/v2/devices/android/pseudonym` | JWT | Définit ou remplace le pseudonyme (unique par app_id) |
| DELETE | `/v2/devices/android/pseudonym` | JWT | Supprime le pseudonyme |
| GET | `/v2/devices/android/pseudonym/check/{pseudo}` | JWT | Vérifie disponibilité d'un pseudonyme |
| POST | `/v2/subscriptions/playstore/verify` | JWT | Valide purchase_token via Google, active l'abonnement |
| GET | `/v2/subscriptions/playstore/status` | JWT | État actuel (status, expires_at, product_id) |
| DELETE | `/v2/subscriptions/playstore` | JWT | Marque l'abonnement annulé côté API |

### Logique `POST /v2/devices/android/register`

1. Valider `device_uuid` (requis, format UUID v4) et `app_id` (requis)
2. Upsert dans `android_devices` : `device_token` = nouveau token aléatoire, `token_expires_at` = +365 jours
3. Retourner `{device_token, expires_at, pseudonym}` (pseudonym = valeur dans `app_user_settings` ou `null`)

### Logique `GET /v2/devices/android/pseudonym`

1. `app_id` depuis query string (requis)
2. Retourner `{pseudonym}` depuis `app_user_settings` pour `(user_id, app_id)`, ou `null`

### Logique `POST /v2/devices/android/pseudonym`

1. Valider `pseudonym` (requis, 2–64 chars, pas de caractères spéciaux dangereux) et `app_id`
2. Upsert dans `app_user_settings` — si `(app_id, pseudonym)` pris par autre `user_id` : 409
3. Retourner `{pseudonym}`

### Logique `DELETE /v2/devices/android/pseudonym`

1. `app_id` depuis body/query (requis)
2. `UPDATE app_user_settings SET pseudonym = NULL WHERE user_id = ? AND app_id = ?`

### Logique `GET /v2/devices/android/pseudonym/check/{pseudo}`

1. `app_id` depuis query string (requis)
2. Retourner `{available: bool}` — `false` si un autre `user_id` détient ce pseudonyme pour cet `app_id`

### Logique `POST /v2/subscriptions/playstore/verify`

1. Valider `purchase_token`, `product_id`, `app_id` (requis)
2. Appel `GooglePlayService::validateSubscription(app_id, product_id, purchase_token)`
3. Si valide : upsert `playstore_subscriptions` avec `status=active`, `expires_at` = expiryTimeMillis de Google
4. Si invalide : 422 avec message d'erreur Google
5. Log de l'opération

### Logique `GET /v2/subscriptions/playstore/status`

1. `app_id` depuis query string (optionnel, défaut = tous)
2. Requête sur `playstore_subscriptions` par `(user_id, app_id)` le plus récent actif
3. Si `expires_at` < now : sync Google Play en temps réel, mettre à jour status
4. Retourner `{is_premium, status, expires_at, product_id}`

### Accès accordé

Play Store actif → `android`, `web`, `windows` déverrouillés pour cet `app_id`.

### Conditions de complétion

- [x] `POST /v2/devices/android/register` fonctionnel, testé (register, re-register même uuid)
- [x] `POST /v2/devices/android/pseudonym` upsert correct, 409 si pseudo pris par autre user
- [x] `GET /v2/devices/android/pseudonym/check/{pseudo}` retourne `available` correct
- [x] `DELETE /v2/devices/android/pseudonym` met pseudonym à NULL
- [ ] `POST /v2/subscriptions/playstore/verify` valide un vrai token Google Play Sandbox
- [ ] `GET /v2/subscriptions/playstore/status` retourne état correct + sync temps réel
- [x] `DELETE /v2/subscriptions/playstore` marque annulé
- [ ] Tests dans `private/tests/test_playstore.php`

> **Code implémenté.** Items POST verify + GET status + tests : validation en Phase 7 (dev server HTTPS requis).

---

## Phase 3 — Module Stripe (réécriture)

### Structure

```tree
src/stripe/
  Controllers/
    BillingController.php     # POST /v2/billing/checkout
                              # POST /v2/billing/portal
                              # POST /v2/billing/webhook (public, sig Stripe)
    SubscriptionController.php # GET  /v2/subscriptions/stripe/status
                               # DELETE /v2/subscriptions/stripe
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
| POST | `/v2/billing/checkout` | JWT | Crée session Stripe Checkout, retourne URL |
| POST | `/v2/billing/portal` | JWT | Crée session Stripe Billing Portal, retourne URL |
| POST | `/v2/billing/webhook` | Sig Stripe | Reçoit événements Stripe, met à jour `stripe_subscriptions` |
| GET | `/v2/subscriptions/stripe/status` | JWT | État abonnement Stripe actuel |
| DELETE | `/v2/subscriptions/stripe` | JWT | Annule l'abonnement Stripe (cancel_at_period_end) |

### Mapping Stripe → plateforme

Stripe actif → `web`, `windows` déverrouillés. Android reste verrouillé.

### Idempotence webhook

Conserver la logique existante (`20260508_stripe_idempotency.sql`). La table `stripe_webhook_events` est gardée intacte.

### Conditions de complétion

- [ ] `POST /v2/billing/checkout` retourne une URL Stripe valide
- [ ] `POST /v2/billing/webhook` traite `checkout.session.completed` et `customer.subscription.updated`
- [ ] `GET /v2/subscriptions/stripe/status` retourne état correct
- [ ] `DELETE /v2/subscriptions/stripe` déclenche `cancel_at_period_end=true` via Stripe API
- [ ] Tests dans `private/tests/test_stripe_v2.php`

> **Code implémenté** (`src/stripe/` — tous les fichiers). Toutes les conditions requièrent Stripe API ou dev server HTTPS — validation en Phase 7.

---

## Phase 4 — Endpoint d'accès unifié

### Entrypoint

| Méthode | URL | Auth | Description |
| - | - | - | - |
| GET | `/v2/access/status` | JWT | Statut premium consolidé, toutes sources |

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

- [x] Retourne `is_premium=true` si Play Store actif, `android=true`
- [x] Retourne `android=false` si uniquement Stripe actif
- [x] Retourne `is_premium=false` si les deux sont expirés
- [ ] Testé avec combinaisons : aucun, seulement Play Store, seulement Stripe, les deux

> **Code implémenté** (`src/access/`). Dernier item (tests combinaisons) : validation en Phase 7.

---

## Phase 5 — Destruction du code mort et versionnement des routes

> **STOP — prérequis avant d'exécuter cette phase :**
> Les directives clients (Phase 6) doivent avoir été envoyées ET chaque client concerné doit
> avoir confirmé la migration vers les nouveaux endpoints. Détruire le code avant cette
> confirmation brise les clients en production.

### Infrastructure de routage v2

Le router dispatch sur `segments[0]`. Pour supporter `/v2/*`, ajouter :

```php
// src/auth_groups/Routing/Router.php — initializeRouteHandlers()
'v2' => fn() => new V2RouteHandler($auth),
```

`V2RouteHandler` sub-dispatch sur `segments[1]` :

| `segments[1]` | Handler |
| - | - |
| `puzzle` | `PuzzleRouteHandler` (sans bloc `auth`) |
| `devices` | `PlaystoreRouteHandler` → `DeviceController` |
| `subscriptions` | `PlaystoreRouteHandler` ou `StripeRouteHandler` selon `segments[2]` |
| `billing` | Nouveau `StripeRouteHandler` |
| `access` | Nouveau `AccessRouteHandler` (Phase 4) |

`PuzzleRouteHandler` adapté : lit `segments[2]` comme `$s1` (décalage de +1 quand appelé via V2).

### Routes puzzle versionnées

| Ancienne route | Nouvelle route |
| - | - |
| `GET /puzzle/carousel` | `GET /v2/puzzle/carousel` |
| `POST /puzzle/carousel/replace-one` | `POST /v2/puzzle/carousel/replace-one` |
| `POST /puzzle/carousel/replace-all` | `POST /v2/puzzle/carousel/replace-all` |
| `GET /puzzle/themes` | `GET /v2/puzzle/themes` |
| `GET /puzzle/themes/{slug}/images` | `GET /v2/puzzle/themes/{slug}/images` |
| `GET /puzzle/thumb/{uid}` | `GET /v2/puzzle/thumb/{uid}` |
| `GET /puzzle/thumb/theme/{slug}` | `GET /v2/puzzle/thumb/theme/{slug}` |
| `GET /puzzle/image/{uid}` | `GET /v2/puzzle/image/{uid}` |
| `GET\|POST /puzzle/backup` | `GET\|POST /v2/puzzle/backup` |
| `POST /puzzle/backup/claim` | `POST /v2/puzzle/backup/claim` |
| `GET\|POST\|DELETE /puzzle/shared[/*]` | `GET\|POST\|DELETE /v2/puzzle/shared[/*]` |
| `GET\|POST /puzzle/admin/*` | `GET\|POST /v2/puzzle/admin/*` |
| `POST /puzzle/auth/link-device` | `POST /v2/puzzle/auth/link-device` |
| `GET\|POST\|DELETE /puzzle/auth/pseudonym` | Remplacé par `/v2/devices/android/pseudonym` |

### Fichiers à supprimer

```
src/puzzle/Models/PuzzleDevice.php
src/puzzle/Controllers/AuthController.php
src/puzzle/Services/GooglePlayService.php
src/auth_groups/Controllers/SubscriptionController.php
src/auth_groups/Controllers/StripeController.php
src/auth_groups/Models/Subscription.php
src/auth_groups/Services/SubscriptionService.php
src/auth_groups/Services/StripeService.php
src/auth_groups/Routing/RouteHandlers/SubscriptionRouteHandler.php
src/auth_groups/Routing/RouteHandlers/StripeRouteHandler.php
src/cron/expire_subscriptions.php
```

### Fichiers à créer / adapter

```
src/auth_groups/Routing/RouteHandlers/V2RouteHandler.php   # nouveau
src/auth_groups/Routing/Router.php                          # ajouter clé 'v2'
src/puzzle/Routing/PuzzleRouteHandler.php                   # supprimer bloc auth, décaler segments
src/auth_groups/Controllers/AuthController.php              # retirer champ 'subscriptions' de login()
src/auth_groups/Controllers/UserListController.php          # retirer champ 'subscriptions' de la réponse admin
src/auth_groups/Services/MaintenanceService.php             # retirer expiration table subscriptions
src/puzzle/Services/MaintenanceService.php                  # retirer nettoyage puzzle_devices
src/cron/backup/backup_puzzle.php                           # retirer puzzle_devices de la liste
src/cron/backup/backup_core.php                             # retirer subscriptions de la liste
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
GET    /puzzle/auth/pseudonym
POST   /puzzle/auth/pseudonym
DELETE /puzzle/auth/pseudonym
GET    /puzzle/auth/check-pseudonym/{pseudo}
```

### Cron à réécrire

- `src/cron/expire_playstore.php` : expire `playstore_subscriptions` dont `expires_at` < now
- `src/cron/expire_stripe.php` : expire `stripe_subscriptions` dont `expires_at` < now (fallback si webhook manqué)

### Conditions de complétion

- [ ] Directives clients envoyées (Phase 6 complétée)
- [ ] Confirmation migration reçue de chaque client affecté
- [ ] `V2RouteHandler` dispatch correct sur `segments[1]` pour tous les modules v2
- [ ] `/v2/puzzle/carousel` accessible, ancienne route `/puzzle/carousel` retourne 404
- [ ] `POST /auth/login` ne retourne plus de champ `subscriptions`
- [ ] Aucun fichier listé dans "Fichiers à supprimer" n'existe plus
- [ ] Aucune route listée dans "Routes à retirer" n'est enregistrée
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

1. **Puzzle Android** — nouveaux entrypoints device + Play Store + accès unifié + routes `/v2/puzzle/*`
2. **Puzzle Web** — nouvel entrypoint `/v2/access/status` pour vérifier le premium + routes `/v2/puzzle/*`
3. **Puzzle Windows** — idem Web
4. ~~**Tous clients CMEM2 utilisant `POST /auth/login`**~~ — **annulé** : audit Phase 0 confirme
   qu'aucun client local ne lit le champ `subscriptions` de la réponse login. Retrait sans directive.
   Documenter dans CHANGELOG comme breaking change au cas où client inconnu.

Modèle de directive : `c:\code\directives_inter_projet\_GABARIT.md`

### Protocole de communication retour (client → API)

Chaque directive envoyée aux clients **doit inclure** les deux sections suivantes :

#### Confirmation de migration

Quand le client a complété la migration :

1. Créer une directive retour dans `c:\code\directives_inter_projet\` :
   - Nom : `YYYYMMDD_HHMMSS_{client}_vers_cmem2_API__migration-v2.7.0-confirmée.md`
   - `statut: complété` dès création (c'est une notification, pas une demande)
   - Corps : liste des endpoints migrés, date de déploiement, version du client livrée

2. Mettre à jour `_INDEX.md`.

La directive Phase 5 (destruction) ne peut démarrer que lorsque **toutes** les directives
de confirmation sont reçues.

#### Demande d'ajustement / signalement de problème

Si le client découvre un écart entre la spec et le comportement réel de l'API :

1. Créer une directive dans `c:\code\directives_inter_projet\` :
   - Nom : `YYYYMMDD_HHMMSS_{client}_vers_cmem2_API__ajustement-{sujet}.md`
   - `statut: en_attente`
   - Corps : endpoint concerné, comportement observé vs attendu, exemple req/resp

2. L'API traite la demande et répond en mettant `statut: complété` ou `rejeté` avec explication.

3. Si la correction modifie le contrat API, une nouvelle directive est envoyée au client
   concerné avant de déployer le correctif.

### Conditions de complétion

- [ ] `docs/playstore/GUIDE.md` couvre register-device + verify + status + pseudonyme
- [ ] `docs/stripe/GUIDE.md` couvre checkout + portal + webhook + status
- [ ] `docs/access/API_ACCESS_ENDPOINTS.json` couvre GET /v2/access/status
- [ ] Directive créée : Puzzle Android (device, Play Store, v2/puzzle/*)
- [ ] Directive créée : Puzzle Web/Windows (accès unifié, v2/puzzle/*)
- [ ] CHANGELOG.md mentionne retrait champ `subscriptions` de `POST /auth/login` (breaking change, aucun client local affecté)
- [ ] `docs/puzzle/API_PUZZLE_ENDPOINTS.json` nettoyé (sans routes subscription, routes v2)
- [ ] Chaque directive inclut les sections "Confirmation de migration" et "Demande d'ajustement"

---

## Phase 7 — Tests et validation

> **Environnement : serveur dev (`dev-cmem2.journauxdebord.com`).**
> Les tests d'intégration Play Store Sandbox et Stripe nécessitent une URL publique HTTPS.
> Les tests API (`private/tests/`) pointent sur `https://dev-cmem2.journauxdebord.com`.
> Aucun test ne touche la base ou le serveur de production avant Phase 8.
> Voir [PLAN_environnement-dev-distant.md](PLAN_environnement-dev-distant.md).

### Fichiers de tests à créer

```
private/tests/test_playstore.php
private/tests/test_stripe_v2.php
private/tests/test_access.php
```

### Scénarios obligatoires

#### Play Store

- [x] Register device → device_token retourné
- [x] Register même device_uuid → token renouvelé (upsert)
- [ ] Verify subscription avec token valide Sandbox → `status=active`
- [x] Verify subscription avec token invalide → 422
- [x] Status sans abonnement → `is_premium=false`
- [ ] Status avec abonnement expiré → `is_premium=false` + sync Google

#### Stripe

- [x] Checkout → URL valide (200) ou 500 si clé absente
- [x] Webhook `checkout.session.completed` → signature validée (400 si invalide)
- [x] Webhook doublon (idempotency) → signature rejetée avant traitement
- [x] Status après webhook → structure validée
- [x] Cancel → 422 si aucun abonnement Stripe

#### Accès unifié

- [x] Aucune sub → `{android:false, web:false, windows:false}`
- [ ] Stripe actif → `{android:false, web:true, windows:true}`
- [ ] Play Store actif → `{android:true, web:true, windows:true}`
- [ ] Les deux actifs → `{android:true, web:true, windows:true}`
- [x] `?platform=android` → `is_premium=bool` correct

> **Note :** scénarios Play Store Sandbox + combinaisons Stripe actif requièrent tokens réels
> (Play Store Sandbox, Stripe CLI). Validés manuellement sur dev server quand tokens disponibles.

### Conditions de complétion

- [x] Tous les scénarios testables sans tokens externes passent (73/73 playstore, 0 erreur stripe_v2 + access)
- [x] `php private/tests/run_all_tests.php` → 3 nouveaux fichiers inclus, 0 échec Phase 7

---

## Phase 8 — Release v2.7.0

> **Premier contact avec la production.**
> C'est ici seulement que la migration SQL et le nouveau code sont déployés
> sur le serveur réel. Tous les STOP obligatoires s'appliquent.

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
Phase 0 → Phase 1 → Phase 2 → Phase 3 → Phase 4   ← CODE COMPLET (2026-05-17)
                                              ↓
                                         Phase 7 (tests)   ← PROCHAINE ÉTAPE
                                              ↓
                                         Phase 6 (docs + directives clients)
                                              ↓
                                    [attente confirmation migration]
                                              ↓
                                         Phase 5 (destruction)
                                              ↓
                                         Phase 8 (release)
```

**Phases 2 et 3** peuvent être parallélisées si deux branches de travail distinctes.

**Cohabitation obligatoire** : après Phase 4, anciens et nouveaux endpoints coexistent.
Les anciens ne sont détruits (Phase 5) qu'après confirmation explicite de tous les clients concernés.

---

## Addendum opérationnel — 2026-05-18

### Décisions validées

- L'environnement de développement de référence est `dev-cmem` (HTTPS), avec support `localhost` selon les besoins.
- Le retrait des dépendances à `puzzle_devices` reste prioritaire dans la trajectoire v2.7.0.
- Les tests OTP doivent être exécutables aussi sur `dev-cmem` (pas seulement en local).
- Les suites de tests doivent réutiliser les helpers partagés de `private/tests/test_new_base.php`.
- La valeur `executable` est confirmée dans le build de référence v2.6.5 (`files.media_type`).

### Clarifications de test

- Référence helper HTTP/API Key : `callNewApi()`
- Référence helper JWT : `callApiWithJWT()` et `callTestWithJWT()`
- Référence helper téléchargement ICS : `callNewDownloadICS()`
- Référence helper OTP : `injectOtpCode()`

### Références de schéma validées

- Source de vérité pour `files.media_type` : `docs/v-2-6-5/build_DB-v-2.6.5.sql` (`executable` inclus).
- Source de vérité pour la refonte subscription/device : `docs/20260514_device_subscription_refonte.sql`.
- Contrainte d'implémentation : ne pas modifier les artefacts legacy de reset historique.

### Ordre d'attaque mis à jour

1. Retirer les dépendances runtime à `puzzle_devices` dans les flux testés en dev-cmem.
2. Rendre les scénarios OTP exécutables en dev-cmem.
3. Uniformiser les tests (helpers `test_new_base`) pour les flux ICS/HTTP.
4. Corriger les écarts de schéma test/reset (`media_type`) puis relancer la campagne globale.
