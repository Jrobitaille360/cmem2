# Plan — Abonnements avec essai gratuit 7 jours

Référence : `C:\code\puzzle\docs\DIRECTIVE_API_abonnement_essai.md`

---

## Vue d'ensemble

Implanter la gestion complète des abonnements premium pour l'app `puzzle`, avec période
d'essai gratuit de 7 jours, selon deux canaux :

- **Google Play** : le store gère l'essai et la facturation ; l'API valide et synchronise.
- **Web / Windows (Stripe)** : l'API gère tout — création de session de paiement, webhooks,
  activation, expiration.

---

## Identité de l'abonné : architecture hybride `user_id` + `device_token`

### Ce qui est déjà en place

La table `subscriptions` utilise `user_id` comme clé primaire métier avec
`UNIQUE KEY uq_user_app_provider (user_id, app_id, provider)`.

### Le problème

- **Play Store** : l'abonnement est lié au **compte Google**, pas à l'appareil.
  Un utilisateur peut restaurer ses achats sur un second device. Stocker uniquement
  `device_token` empêche cette portabilité.
- **Web / Windows** : Stripe exige un email pour la facturation. Un compte cmem2
  (`user_id`) est nécessaire pour retrouver l'abonnement sur n'importe quel appareil.

### Solution — identifiant hybride

Conserver `device_token` (nullable) ET `user_id` (nullable), en appliquant la règle :

| Situation | Identifiant actif |
| - | - |
| Session anonyme pure | `device_token` seulement |
| Play Store + `obfuscatedExternalAccountId` | `user_id` (lu depuis la réponse Google) |
| Web / Windows (Stripe) | `user_id` (login obligatoire avant checkout) |
| Utilisateur connecté sur nouvel appareil | lookup par `user_id` → retrouve l'abonnement |

**Le pont Play Store → `user_id`** : Flutter passe `obfuscatedExternalAccountId = user_id`
au moment de l'achat. Google stocke cette valeur et la retourne dans
`externalAccountIdentifiers.obfuscatedExternalAccountId` lors de chaque appel à
l'API subscriptionsv2. L'API peut donc lire son propre `user_id` sans que
l'utilisateur ait à se connecter explicitement.

### Clé unique révisée

```sql
UNIQUE KEY uq_user_app   (user_id, app_id),   -- prioritaire si user_id connu
UNIQUE KEY uq_device_app (device_token, app_id) -- fallback anonyme
```

### Maintenance à prévoir

- Fusion des entrées `device_token` et `user_id` lorsqu'un utilisateur anonyme
  se connecte après avoir souscrit.
- Nettoyage périodique des entrées `device_token`-only expirées (CRON).

---

## Contrat de réponse unifié

### Ce qui est déjà en place

`GET /subscription/status` retourne `{ is_premium, show_ads, expires_at, provider, plan }`.

### Améliorations

Tous les endpoints d'abonnement retournent désormais :

```json
{
  "app_id":     "puzzle",
  "is_premium": true,
  "show_ads":   false,
  "provider":   "google_play",
  "plan":       "monthly",
  "is_trial":   true,
  "trial_end":  "2026-05-02T00:00:00Z",
  "expires_at": "2026-05-02T00:00:00Z"
}
```

`is_premium = true` pendant l'essai ET pendant un abonnement payant actif.
`trial_end = null` si aucun essai en cours.

### Maintenance à prévoir

Vérifier que Flutter (PurchaseService) consomme correctement les nouveaux champs
sans régression sur `is_premium` et `show_ads`.

---

## Migration de la base de données

### Ce qui est déjà en place

Table `subscriptions` avec colonnes : `id`, `user_id`, `app_id`, `provider`,
`product_id`, `purchase_token`, `stripe_sub_id`, `status`, `plan`, `started_at`,
`expires_at`, `cancelled_at`, `created_at`, `updated_at`.

### Améliorations

Ajouter les colonnes manquantes et réviser la structure :

```sql
ALTER TABLE subscriptions
  ADD COLUMN device_token    VARCHAR(64)   NULL              AFTER user_id,
  ADD COLUMN is_premium      TINYINT(1)    NOT NULL DEFAULT 0 AFTER plan,
  ADD COLUMN show_ads        TINYINT(1)    NOT NULL DEFAULT 1 AFTER is_premium,
  ADD COLUMN is_trial        TINYINT(1)    NOT NULL DEFAULT 0 AFTER show_ads,
  ADD COLUMN trial_end       DATETIME      NULL              AFTER is_trial,
  ADD COLUMN stripe_customer VARCHAR(64)   NULL              AFTER stripe_sub_id,
  ADD UNIQUE KEY uq_device_app (device_token, app_id);
```

Rendre `user_id` nullable (les sessions anonymes n'en ont pas) :

```sql
ALTER TABLE subscriptions MODIFY COLUMN user_id INT(11) NULL;
```

Retirer `provider` de la contrainte unique existante et remplacer par la logique hybride.

Migration placée dans `docs/core/migrations/20260425_subscriptions_trial.sql` et
intégrée dans `docs/v-2-3-1/build_DB-v-2.3.1.sql` → prochaine version majeure.

### Maintenance à prévoir

- Le CRON `checkAndExpireSubscriptions()` doit mettre à jour `is_premium`, `show_ads`
  et `is_trial` lors de l'expiration (pas seulement le `status`).

---

## Google Play — `POST /subscription/verify`

### Ce qui est déjà en place

`GooglePlayService` dans le module `puzzle` appelle l'API Google Play (v3) et valide
un `purchase_token`. `SubscriptionController::verify()` accepte `google_play` comme
provider et calcule `expires_at` manuellement (+31/+365 jours).

### Améliorations

1. **Migrer vers subscriptionsv2** :

   ```
   GET https://androidpublisher.googleapis.com/androidpublisher/v3/applications/
       {packageName}/purchases/subscriptionsv2/tokens/{token}
   ```

2. **Lire `lineItems[0].expiryTime`** → `expires_at` (fiable, fourni par Google).

3. **Mapper `subscriptionState`** vers `is_premium` / `show_ads` / `is_trial` :

   | `subscriptionState` | `is_premium` | `show_ads` | `is_trial` |
   | - | - | - | - |
   | `SUBSCRIPTION_STATE_ACTIVE` | `true` | `false` | voir détection |
   | `SUBSCRIPTION_STATE_IN_GRACE_PERIOD` | `true` | `false` | `false` |
   | `SUBSCRIPTION_STATE_CANCELED` | `true` | `false` | `false` |
   | `SUBSCRIPTION_STATE_EXPIRED` | `false` | `true` | `false` |
   | `SUBSCRIPTION_STATE_ON_HOLD` | `false` | `true` | `false` |
   | Autre | `false` | `true` | `false` |

4. **Détecter l'essai** : chercher `"free-trial"` dans
   `lineItems[0].offerDetails.offerTags`. Si absent, vérifier que
   `startTime + 7 jours > NOW()` et qu'aucun paiement confirmé n'existe.
   Stocker `trial_end = expiryTime` si essai détecté.

5. **Lire `obfuscatedExternalAccountId`** depuis
   `externalAccountIdentifiers.obfuscatedExternalAccountId` → lier le `user_id`
   cmem2 à l'abonnement. Flutter doit passer cette valeur à l'achat.

6. **Fail-safe** : si l'appel Google échoue, retourner l'état en base avec
   `"stale": true` plutôt qu'une erreur 5xx.

### Maintenance à prévoir

- RTDN (Real-Time Developer Notifications) via Google Cloud Pub/Sub : à configurer
  pour les renouvellements automatiques. En attendant, `GET /subscription/status`
  re-valide le token à chaque lancement d'app.
- Endpoint `POST /subscription/rtdn` à créer ultérieurement pour recevoir les
  notifications Pub/Sub et mettre à jour `expires_at` et `is_trial` en base.

---

## Stripe — Web et Windows

### Ce qui est déjà en place

Aucune intégration Stripe dans le projet. `stripe_sub_id` existe dans la table
mais n'est jamais peuplé automatiquement.

### Prérequis métier

Créer un compte cmem2 (login JWT) est **obligatoire** avant d'accéder au checkout
Stripe. Cela garantit que `user_id` est toujours présent pour les abonnements
web/Windows et qu'un utilisateur retrouve son abonnement sur n'importe quel appareil.

### 2a — `POST /subscription/checkout`

Créer une Stripe Checkout Session avec essai de 7 jours :

```php
\Stripe\Checkout\Session::create([
  'customer'           => $stripeCustomerId,
  'mode'               => 'subscription',
  'payment_method_types' => ['card'],
  'line_items'         => [['price' => $priceId, 'quantity' => 1]],
  'subscription_data'  => [
    'trial_period_days' => 7,
    'metadata'          => ['user_id' => $userId, 'app_id' => 'puzzle'],
  ],
  'success_url'        => 'https://journauxdebord.com/puzzle/subscription/success?session_id={CHECKOUT_SESSION_ID}',
  'cancel_url'         => 'https://journauxdebord.com/puzzle/subscription/cancel',
  'client_reference_id' => (string) $userId,
]);
```

Retourner `{ "checkout_url": "https://checkout.stripe.com/…", "session_id": "cs_…" }`.

Prix Stripe à configurer dans `.env` :

| Plan | Variable `.env` | Montant |
| - | - | - |
| `monthly` | `STRIPE_PRICE_PUZZLE_MONTHLY` | 1,99 $ / mois |
| `yearly` | `STRIPE_PRICE_PUZZLE_YEARLY` | 19,99 $ / an |

### 2b — `POST /stripe/webhook`

Vérification de signature obligatoire :

```php
$event = \Stripe\Webhook::constructEvent(
  $payload, $_SERVER['HTTP_STRIPE_SIGNATURE'], STRIPE_WEBHOOK_SECRET
);
```

Événements à traiter :

| Événement | Action en base |
| - | - |
| `checkout.session.completed` | Lire `client_reference_id` → `user_id` ; persister `stripe_sub_id`, `stripe_customer` ; `is_premium=1`, `is_trial=1`, `trial_end=NOW()+7j` |
| `customer.subscription.updated` | Mettre à jour `expires_at`, `is_trial`, `plan` ; si `status=active` et `trial_end` dépassé → `is_trial=0` |
| `invoice.payment_succeeded` | `is_premium=1`, `show_ads=0`, `is_trial=0` |
| `invoice.payment_failed` | Si hors grâce → `is_premium=0`, `show_ads=1` |
| `customer.subscription.deleted` | `is_premium=0`, `show_ads=1` |
| `customer.subscription.trial_will_end` | Optionnel — flag pour notification future |

Champs Stripe utiles dans `customer.subscription` :

- `status` : `'trialing'` → `is_trial=1` ; `'active'` → `is_trial=0`
- `trial_end` (timestamp unix) → `trial_end` en base
- `current_period_end` (timestamp unix) → `expires_at`

### Maintenance à prévoir

- Enregistrer l'URL webhook dans le tableau de bord Stripe.
- Stocker `STRIPE_WEBHOOK_SECRET` dans `.env`.
- Log de tous les événements reçus (table `webhook_logs` ou fichier log) pour audit.

---

## Règles métier transversales

1. **Essai = premium à part entière** : `is_premium=1` et `show_ads=0` dès l'instant 0.
2. **Un seul abonnement actif par identifiant+app** : `ON DUPLICATE KEY UPDATE`.
3. **Ne jamais supprimer** : les lignes expirées ou annulées sont conservées pour l'historique.
4. **Expiration naturelle** : lorsque `expires_at < NOW()`, `GET /status` dérive
   `is_premium=false` sans supprimer l'entrée.
5. **Annulation** : accès conservé jusqu'à `expires_at` ; c'est l'expiration qui coupe.

---

## Phases d'implantation

---

### Phase 0 — Prérequis client (hors API)

**Priorité : bloquante pour la Phase 4 (Stripe).**

#### Contexte

Flutter puzzle ne demande pas d'inscription par email pour le moment. Or, Stripe exige
un email pour la facturation, les reçus et le portail client. Sans compte cmem2 lié,
il est impossible de retrouver un abonnement web/Windows sur un autre appareil.

#### Actions à coordonner avec `client_puzzle`

- Implanter une page **login / register** (email + mot de passe, ou OAuth Google)
  accessible depuis l'app web et l'app Windows, déclenchée avant l'accès au checkout.
- Une fois connecté, l'app transmet le JWT cmem2 à `POST /subscription/checkout`.
- L'abonnement Stripe est lié au `user_id` — l'utilisateur retrouve son accès
  premium sur n'importe quel appareil web/Windows en se connectant.

#### Enjeux

- Ce prérequis est **indépendant de l'API** — l'API est prête dès la Phase 4, mais
  inutilisable sans le flux login côté client.
- Le flux Play Store (mobile) n'est pas affecté : pas de login requis, `user_id`
  transmis via `obfuscatedExternalAccountId`.

#### Critères de complétion

- `client_puzzle` confirme l'intégration d'une page login/register pour web/Windows.
- L'app transmet un JWT valide avant d'appeler `POST /subscription/checkout`.

---

### Phase 1 — Migration de la base de données

**Priorité : critique — tout repose sur ce schéma.**

#### Actions

- Créer `docs/core/migrations/20260425_subscriptions_trial.sql` avec les
  `ALTER TABLE` décrits plus haut.
- Rendre `user_id` nullable.
- Ajouter colonnes : `device_token`, `is_premium`, `show_ads`, `is_trial`,
  `trial_end`, `stripe_customer`.
- Ajouter `UNIQUE KEY uq_device_app (device_token, app_id)`.
- Mettre à jour `docs/v-2-3-1/build_DB-v-2.3.1.sql`.

#### Enjeux

- La contrainte unique existante `uq_user_app_provider` inclut `provider` —
  à remplacer par `uq_user_app (user_id, app_id)` pour permettre le changement
  de provider sans doublon.
- Les lignes existantes en base ont `is_premium` / `show_ads` / `is_trial` à `0`
  par défaut — les recalculer via script si des abonnements actifs existent déjà.

#### Tests

- Vérifier que la migration s'applique sans erreur sur la base de dev.
- Vérifier que les contraintes unique empêchent les doublons (user+app, device+app).
- Vérifier que `user_id NULL` est accepté.

#### Critères de complétion

- Migration appliquée sans erreur.
- `build_DB-v-2.3.1.sql` à jour et testé from scratch.
- Aucune régression sur les tests existants (`test_subscriptions.php`).

---

### Phase 2 — Contrat de réponse et modèle

**Priorité : haute — base pour toutes les phases suivantes.**

#### Actions

- Mettre à jour `Subscription.php` (Model) : ajouter propriétés `device_token`,
  `is_premium`, `show_ads`, `is_trial`, `trial_end`, `stripe_customer`.
- Mettre à jour `SubscriptionService::getStatus()` : lire `is_premium`, `show_ads`,
  `is_trial`, `trial_end` depuis la base (plus de dérivation à la volée).
- Mettre à jour `SubscriptionService::activatePremium()` : accepter et persister
  les nouveaux champs.
- Mettre à jour `SubscriptionController::getStatus()` : retourner le contrat unifié
  (inclure `is_trial`, `trial_end`).
- Mettre à jour les réponses de login / `users/me` (qui embarquent `subscriptions{}`).

#### Enjeux

- La dérivation de `is_premium` à la volée (actuellement dans `getStatus()`) est
  remplacée par la valeur stockée — s'assurer que tous les chemins d'écriture
  (verify, webhook, CRON) maintiennent cette valeur correcte.

#### Tests

- `GET /subscription/status` retourne `is_trial` et `trial_end`.
- `POST /auth/login` et `GET /users/me` retournent le nouveau contrat.
- Un abonnement expiré retourne `is_premium: false` sans suppression de la ligne.

#### Critères de complétion

- Tous les endpoints existants retournent le contrat unifié.
- `test_subscriptions.php` mis à jour et vert.

---

### Phase 3 — Google Play subscriptionsv2 + détection essai

**Priorité : haute — canal principal de l'app mobile.**

#### Actions

- Refactoriser `GooglePlayService::validateSubscription()` pour appeler
  `/subscriptionsv2/tokens/{token}`.
- Lire `lineItems[0].expiryTime` → `expires_at`.
- Mapper `subscriptionState` → `is_premium`, `show_ads`.
- Détecter essai via `offerTags["free-trial"]` ou heuristique `startTime + 7j`.
- Lire `externalAccountIdentifiers.obfuscatedExternalAccountId` → `user_id`.
- Mettre à jour `SubscriptionController::verify()` pour passer les nouveaux champs
  à `activatePremium()`.
- Ajouter fail-safe : si appel Google échoue → retourner état en base + `"stale": true`.

#### Enjeux

- L'authentification Google (service account JWT) doit rester fonctionnelle après
  le changement d'endpoint.
- Le champ `obfuscatedExternalAccountId` n'est disponible que si Flutter le passe
  à l'achat — coordonner avec l'équipe Flutter.
- La détection d'essai par heuristique est moins fiable que `offerTags` — préférer
  la configuration play store avec tag `"free-trial"`.

#### Tests

- Mock de la réponse subscriptionsv2 pour chaque `subscriptionState`.
- Vérifier que `is_trial=1` lorsque `offerTags` contient `"free-trial"`.
- Vérifier que `user_id` est correctement extrait de `obfuscatedExternalAccountId`.
- Vérifier le fail-safe (retour état en base si Google inaccessible).

#### Critères de complétion

- `POST /subscription/verify` avec `provider=google_play` persiste correctement
  `is_premium`, `show_ads`, `is_trial`, `trial_end`, `user_id`.
- Tests verts incluant les cas limites (essai, actif, expiré, suspendu).

---

### Phase 4 — Stripe checkout et webhook

**Priorité : haute — canal web/Windows.**

#### Actions

- Installer le SDK Stripe PHP via Composer.
- Ajouter variables `.env` : `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`,
  `STRIPE_PRICE_PUZZLE_MONTHLY`, `STRIPE_PRICE_PUZZLE_YEARLY`.
- Créer `src/auth_groups/Services/StripeService.php` :
  - `createCheckoutSession(int $userId, string $appId, string $plan): array`
  - `getOrCreateCustomer(int $userId): string`
- Créer `src/auth_groups/Controllers/StripeController.php` :
  - `checkout(array $request): void` → `POST /subscription/checkout`
  - `webhook(array $request): void` → `POST /stripe/webhook`
- Créer `src/auth_groups/Routing/RouteHandlers/StripeRouteHandler.php`.
- Enregistrer les routes dans `Router.php`.
- Implanter la gestion des 6 événements webhook (voir section Stripe ci-dessus).

#### Enjeux

- Le webhook doit être accessible **sans JWT** (Stripe appelle directement l'URL).
- La vérification de signature (`Webhook::constructEvent`) est non négociable —
  rejeter tout payload non signé avec 400.
- `POST /subscription/checkout` requiert un JWT valide (login obligatoire).
- Éviter les doubles traitements webhook (idempotence via `stripe_sub_id`).

#### Tests

- `POST /subscription/checkout` sans JWT → 401.
- `POST /subscription/checkout` avec plan invalide → 422.
- Simuler `checkout.session.completed` → vérifier `is_premium=1`, `is_trial=1`.
- Simuler `invoice.payment_succeeded` → vérifier `is_trial=0`.
- Simuler `customer.subscription.deleted` → vérifier `is_premium=0`.
- Webhook avec signature invalide → 400.

#### Critères de complétion

- Session Stripe créée avec `trial_period_days=7`.
- Les 6 événements webhook mettent correctement à jour la base.
- `test_subscriptions.php` couvre le flux Stripe complet.

---

### Phase 5 — CRON et expiration

**Priorité : normale — filet de sécurité.**

#### Actions

- Mettre à jour `SubscriptionService::checkAndExpireSubscriptions()` pour
  basculer `is_premium=0`, `show_ads=1`, `is_trial=0` à l'expiration
  (pas seulement `status='expired'`).
- Ajouter un email "votre essai a expiré" distinct de l'email d'expiration standard.
- (Optionnel) Ajouter un email "votre essai se termine dans 2 jours" déclenché
  lorsque `is_trial=1` et `trial_end < NOW() + 48h`.
- Intégrer dans `src/cron/maintenance.php`.

#### Enjeux

- Le CRON ne doit pas écraser les abonnements Stripe dont le renouvellement
  vient d'arriver (race condition webhook vs CRON) — vérifier `expires_at` à la
  seconde près.

#### Tests

- Insérer une ligne avec `is_trial=1`, `expires_at=NOW()-1s` → CRON la bascule.
- Vérifier que l'email "essai expiré" est envoyé (log ou mock SMTP).

#### Critères de complétion

- `test_maintenance.php` couvre l'expiration des essais.
- Aucune régression sur le CRON existant.

---

### Phase 6 — RTDN Google Play (futur)

**Priorité : basse — acceptable de livrer sans pour la v1.**

#### Actions

- Configurer Google Cloud Pub/Sub pour recevoir les RTDN.
- Créer `POST /subscription/rtdn` (sans JWT, vérification du token Pub/Sub).
- Re-valider le `purchase_token` à chaque notification et mettre à jour la base.

#### Enjeux

- Nécessite un compte Google Cloud et une configuration Pub/Sub.
- Sans RTDN, `GET /subscription/status` re-valide à chaque lancement d'app —
  acceptable mais coûteux en appels API.

#### Critères de complétion

- Les renouvellements automatiques Play Store mettent à jour `expires_at` et
  `is_trial` sans intervention manuelle.
