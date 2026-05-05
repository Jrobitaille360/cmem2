# PLAN — Simplification du système de subscriptions Puzzle

Date : 2026-05-04
Auteur : JRobitaille

## Contexte

Le module Puzzle a deux mécanismes parallèles pour suivre le statut premium :

1. `puzzle_devices.is_premium` + `premium_expires_at` + `purchase_token` + `product_id`
   — alimenté par `POST /puzzle/auth/verify-subscription` (Google Play, device_token)
2. Table `subscriptions` (`user_id`, `app_id`, `provider`, …)
   — alimentée par `POST /subscription/verify` et webhooks Stripe (JWT requis)

Cette dualité oblige `requirePremium()` à consulter deux sources selon le contexte,
complexifie les tests et multiplie les points de défaillance.

## Identifiants retenus

| Provider | Clé unique dans `subscriptions` | Justification |
| - | - | - |
| Stripe | `user_id + app_id` (existant) | JWT toujours présent, `user_id` stable, contrainte déjà en place |
| Google Play | `purchase_token + app_id` | Token stable sur toute la durée de l'abonnement, pas lié au device |

### Pourquoi `purchase_token` pour Google Play

- Stable à travers les renouvellements automatiques
- Non lié au device — l'abonnement survit à une réinstallation ou un changement de téléphone
- Déjà stocké dans `puzzle_devices.purchase_token` et dans `subscriptions.purchase_token`
- À la réinstallation, Google Play restaure l'achat → l'app re-soumet le même token → on retrouve l'abonnement
- `GooglePlayService::validateSubscription()` retourne déjà `user_id` via
  `obfuscatedExternalAccountId` — si l'utilisateur était connecté au moment de l'achat,
  on peut lier la subscription à son compte en même temps

### Cas upgrade/downgrade Google Play

Un changement de plan génère un nouveau `purchaseToken`.
L'ancien token reçoit un `linkedPurchaseToken` pointant vers le nouveau.
À l'activation du nouveau : marquer l'ancienne ligne `subscriptions` comme `expired`.
`GooglePlayService` doit retourner `linked_purchase_token` si présent.

## Objectif

Faire de `subscriptions` l'unique source de vérité pour le statut premium,
toutes plateformes et tous providers confondus.

## Ce qui est déjà en place

- Table `subscriptions` avec `user_id`, `purchase_token`, `app_id`, `provider`,
  `status`, `is_premium`, `expires_at`, `stripe_sub_id`
- Contrainte unique `uq_user_app (user_id, app_id)` — couvre Stripe
- `SubscriptionService::activatePremium(int $userId, string $appId, array $data)`
- `SubscriptionController` (JWT) — Stripe checkout, portal, verify, cancel
- `PuzzleRouteHandler::requireDeviceToken()` — JWT path consulte déjà `subscriptions`
  par `user_id` ; device_token avec `user_id` lié aussi
- `GooglePlayService::validateSubscription()` retourne déjà `user_id`
  (depuis `obfuscatedExternalAccountId`) et `purchase_token`

## Ce qui manque

- Contrainte unique `uq_purchase_token_app (purchase_token, app_id)` dans `subscriptions`
- `SubscriptionService::activatePremium()` : `$userId` doit accepter `null`
- `Subscription::findActiveByPurchaseToken(string, string): ?array`
- `Subscription::expireByPurchaseToken(string): void`
- `GooglePlayService::validateSubscription()` : retourner `linked_purchase_token`
- `AuthController::verifySubscription()` : remplacer `PuzzleDevice::updateSubscription()`
  par `SubscriptionService::activatePremium()`
- `PuzzleRouteHandler::requireDeviceToken()` : ajouter lookup par `purchase_token`
  pour les devices anonymes (`user_id = NULL`)

## Maintenances prévues

- Suppression des colonnes premium de `puzzle_devices` (Phase 3)
- Suppression de `PuzzleDevice::updateSubscription()` (Phase 3)
- Suppression de `subscriptions.device_token` (Phase 3, colonne orpheline)

---

## Phases d'implantation

---

### Phase 1 — SQL + code

Priorité : haute.

#### 1.1 Migration SQL

Fichier : `docs/YYYYMMDD_subscriptions_purchase_token_unique.sql`

```sql
-- Contrainte unique pour les abonnements Google Play (purchase_token + app_id)
-- MySQL autorise plusieurs NULL dans un index unique :
-- les lignes Stripe (purchase_token NULL) ne sont pas affectées.
ALTER TABLE `subscriptions`
    ADD UNIQUE KEY `uq_purchase_token_app` (`purchase_token`, `app_id`);

-- Migrer tous les devices Play Store vers subscriptions.
-- INSERT IGNORE : idempotent si relancé.
INSERT IGNORE INTO `subscriptions`
    (`purchase_token`, `app_id`, `provider`, `product_id`, `plan`,
     `status`, `is_premium`, `show_ads`, `started_at`, `expires_at`)
SELECT
    pd.`purchase_token`,
    'puzzle',
    'google_play',
    pd.`product_id`,
    CASE WHEN pd.`product_id` LIKE '%yearly%' THEN 'yearly' ELSE 'monthly' END,
    CASE WHEN pd.`is_premium` = 1 AND pd.`premium_expires_at` > NOW()
         THEN 'active' ELSE 'expired' END,
    CASE WHEN pd.`is_premium` = 1 AND pd.`premium_expires_at` > NOW()
         THEN 1 ELSE 0 END,
    CASE WHEN pd.`is_premium` = 1 AND pd.`premium_expires_at` > NOW()
         THEN 0 ELSE 1 END,
    pd.`created_at`,
    pd.`premium_expires_at`
FROM `puzzle_devices` pd
WHERE pd.`purchase_token` IS NOT NULL;
```

#### 1.2 GooglePlayService — ajouter `linked_purchase_token`

Fichier : `src/puzzle/Services/GooglePlayService.php`

Méthode `validateSubscription()` — tableau de retour actuel :

```php
return [
    'is_premium'     => $isPremium ? 1 : 0,
    'show_ads'       => $isPremium ? 0 : 1,
    'is_trial'       => $isTrial   ? 1 : 0,
    'trial_end'      => $trialEnd,
    'product_id'     => $lineItem['productId'] ?? $productId,
    'purchase_token' => $purchaseToken,
    'expires_at'     => $expiresAt,
    'user_id'        => $userId,
];
```

Ajouter `linked_purchase_token` :

```php
return [
    'is_premium'           => $isPremium ? 1 : 0,
    'show_ads'             => $isPremium ? 0 : 1,
    'is_trial'             => $isTrial   ? 1 : 0,
    'trial_end'            => $trialEnd,
    'product_id'           => $lineItem['productId'] ?? $productId,
    'purchase_token'       => $purchaseToken,
    'expires_at'           => $expiresAt,
    'user_id'              => $userId,
    'linked_purchase_token'=> $data['linkedPurchaseToken'] ?? null,
];
```

#### 1.3 Subscription model — deux nouvelles méthodes

Fichier : `src/auth_groups/Models/Subscription.php`

```php
public function findActiveByPurchaseToken(string $purchaseToken, string $appId): ?array
{
    $stmt = $this->getDb()->prepare("
        SELECT * FROM {$this->table}
        WHERE purchase_token = ? AND app_id = ? AND status = 'active' AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$purchaseToken, $appId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

public function expireByPurchaseToken(string $purchaseToken): void
{
    $stmt = $this->getDb()->prepare("
        UPDATE {$this->table}
        SET status = 'expired', is_premium = 0, show_ads = 1, updated_at = NOW()
        WHERE purchase_token = ? AND status = 'active'
    ");
    $stmt->execute([$purchaseToken]);
}
```

Note sur `upsert()` : après ajout de `uq_purchase_token_app`, MySQL déclenche
`ON DUPLICATE KEY UPDATE` sur `uq_user_app` OU `uq_purchase_token_app` selon
la clé qui matche — aucune modification de `upsert()` nécessaire.

#### 1.4 SubscriptionService — accepter `user_id = null`

Fichier : `src/auth_groups/Services/SubscriptionService.php`

Signature actuelle :

```php
public static function activatePremium(int $userId, string $appId, array $data): void
```

Remplacer par :

```php
public static function activatePremium(?int $userId, string $appId, array $data): void
```

#### 1.5 AuthController::verifySubscription() — remplacer l'écriture

Fichier : `src/puzzle/Controllers/AuthController.php`

Code actuel :

```php
(new PuzzleDevice())->updateSubscription((int) $device['id'], [
    'is_premium'         => $result['is_premium'],
    'purchase_token'     => $result['purchase_token'],
    'product_id'         => $result['product_id'],
    'premium_expires_at' => $result['expires_at'],
]);
```

Remplacer par :

```php
// Upgrade/downgrade : expirer l'ancien abonnement
if (!empty($result['linked_purchase_token'])) {
    (new Subscription())->expireByPurchaseToken($result['linked_purchase_token']);
}

// user_id disponible si Flutter a transmis obfuscatedExternalAccountId à l'achat
$userId = !empty($result['user_id']) ? (int) $result['user_id'] : null;

SubscriptionService::activatePremium($userId, 'puzzle', [
    'purchase_token' => $result['purchase_token'],
    'provider'       => 'google_play',
    'product_id'     => $result['product_id'],
    'plan'           => str_contains($result['product_id'], 'yearly') ? 'yearly' : 'monthly',
    'is_trial'       => $result['is_trial'],
    'trial_end'      => $result['trial_end'],
    'started_at'     => date('Y-m-d H:i:s'),
    'expires_at'     => $result['expires_at'],
]);
```

Ajouter les `use` manquants en tête de fichier :

```php
use AuthGroups\Models\Subscription;
use AuthGroups\Services\SubscriptionService;
```

#### 1.6 PuzzleRouteHandler::requireDeviceToken() — lookup par purchase_token

Fichier : `src/puzzle/Routing/PuzzleRouteHandler.php`

Code actuel (après `touchLastSeen`) :

```php
if (!empty($device['user_id'])) {
    $sub = (new Subscription())->findActive((int) $device['user_id'], 'puzzle');
    if ($sub !== null) {
        $device['is_premium']         = 1;
        $device['premium_expires_at'] = $sub['expires_at'];
    }
}

return $device;
```

Remplacer par :

```php
if (!empty($device['user_id'])) {
    // Device lié à un compte : subscription par user_id (Stripe ou Google Play connecté)
    $sub = (new Subscription())->findActive((int) $device['user_id'], 'puzzle');
} elseif (!empty($device['purchase_token'])) {
    // Device anonyme : subscription par purchase_token (Google Play)
    $sub = (new Subscription())->findActiveByPurchaseToken(
        $device['purchase_token'], 'puzzle'
    );
} else {
    $sub = null;
}

if ($sub !== null) {
    $device['is_premium']         = 1;
    $device['premium_expires_at'] = $sub['expires_at'];
}

return $device;
```

#### Enjeux

- `upsert()` avec `user_id = null` : valider que MySQL n'indexe pas deux lignes NULL
  de la même façon dans `uq_user_app` — MySQL accepte plusieurs NULL dans un index
  unique, donc plusieurs abonnements Google Play anonymes peuvent coexister ✓
- `obfuscatedExternalAccountId` n'est présent que si Flutter l'a envoyé à l'achat.
  Les achats anciens l'auront à null — comportement nominal (clé = `purchase_token`)

#### Tests

- `test_subscriptions.php` :
  - `findActiveByPurchaseToken()` retourne le bon enregistrement
  - `expireByPurchaseToken()` passe le statut à `expired`
  - `activatePremium(null, 'puzzle', [...])` insère sans violer de contrainte
- `test_puzzle_admin.php` : device avec abonnement dans `subscriptions` accède aux endpoints premium
- `test_puzzle_share.php` : utilisateur JWT + abonnement Stripe accède aux partagés
- Test manuel Play Store sandbox :
  achat → `verify-subscription` → ligne dans `subscriptions` avec `purchase_token`
- Simuler upgrade : nouveau `purchaseToken` + `linkedPurchaseToken` →
  ancien `expired`, nouveau `active`

#### Conditions de complétion

- [ ] Contrainte `uq_purchase_token_app` ajoutée sans erreur
- [ ] `findActiveByPurchaseToken()` implémentée et testée
- [ ] `expireByPurchaseToken()` implémentée et testée
- [ ] `activatePremium()` accepte `?int $userId`
- [ ] `GooglePlayService` retourne `linked_purchase_token`
- [ ] `verifySubscription()` écrit dans `subscriptions`
- [ ] `requireDeviceToken()` lookup par `purchase_token` pour les anonymes
- [ ] Aucune régression sur la suite de tests

---

### Phase 2 — Validation sandbox Play Store

Priorité : moyenne. À compléter avant le premier abonné réel Play Store.

Aucune période d'observation prolongée n'est nécessaire : aucun abonné Play Store
n'existe en production. Un test sandbox bout en bout remplace les 4-6 semaines
d'observation.

#### 2.1 Prérequis

- Dans Google Play Console → **Setup → Licence testing** : ajouter l'adresse Gmail
  du testeur dans la liste des licence testers.
- Vérifier que `PUZZLE_GOOGLE_PLAY_PACKAGE` et `PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON`
  sont corrects dans `.env` de l'environnement cible (staging ou prod selon le build).
- Avoir un build Flutter signé avec le même keystore que la piste de test Play Store
  (internal testing ou closed testing selon la configuration).
- L'appareil de test doit être connecté avec le compte Gmail licence tester.

#### 2.2 Achat initial

1. Lancer l'app Flutter sur l'appareil de test.
2. Enregistrer l'appareil : `POST /puzzle/auth/register-device` → noter le `device_token`.
3. Déclencher l'achat depuis l'app (plan `premium_monthly` ou `premium_yearly`).
   Google Play retourne immédiatement un `purchaseToken` sandbox.
4. L'app appelle `POST /puzzle/auth/verify-subscription` avec `purchase_token` et `product_id`.
5. Vérifier en base que la table `subscriptions` contient une ligne avec :
   - `purchase_token` = le token reçu
   - `app_id` = `puzzle`
   - `provider` = `google_play`
   - `status` = `active`
   - `is_premium` = 1
   - `expires_at` dans le futur

#### 2.3 Accès premium

1. Appeler un endpoint premium (ex. `GET /puzzle/themes`) avec le `device_token`.
2. Vérifier que la réponse est 200 (et non 403 `SUBSCRIPTION_REQUIRED`).
3. Inspecter les logs serveur pour confirmer que `requireDeviceToken()` a trouvé
   l'abonnement via `findActiveByPurchaseToken()`.

#### 2.4 Renouvellement automatique sandbox

Les abonnements sandbox se renouvellent toutes les **5 minutes** (mensuel) ou
**30 minutes** (annuel) — Google les révoque après 6 renouvellements.

1. Attendre un renouvellement (≥ 5 min pour monthly).
2. Re-soumettre le même `purchase_token` via `POST /puzzle/auth/verify-subscription`.
   Le `purchase_token` est stable à travers les renouvellements — `upsert()` doit
   mettre à jour `expires_at` sans insérer de doublon.
3. Vérifier en base que la ligne `subscriptions` est mise à jour (pas dupliquée),
   `expires_at` prolongé.

#### 2.5 Réinstallation (survie de l'abonnement)

1. Désinstaller et réinstaller l'app.
2. Enregistrer un nouvel appareil (`register-device`) → nouveau `device_token`.
3. Google Play restaure l'achat au lancement — l'app re-soumet le même `purchase_token`.
4. Vérifier que `GET /puzzle/themes` retourne 200 : l'abonnement est retrouvé dans
   `subscriptions` via `purchase_token`, sans lien avec le device_token précédent.

#### 2.6 Upgrade / downgrade

1. Souscrire un second plan depuis l'app (ex. passer de monthly à yearly).
   Google Play génère un **nouveau** `purchaseToken` et un `linkedPurchaseToken`
   pointant vers l'ancien.
2. L'app appelle `POST /puzzle/auth/verify-subscription` avec le nouveau token.
3. Vérifier en base :
   - L'ancienne ligne `subscriptions` a `status = expired` (via `expireByPurchaseToken`).
   - Une nouvelle ligne est active avec le nouveau `purchase_token` et `plan = yearly`.

#### Conditions de complétion

- [ ] Ligne `subscriptions` créée après premier achat sandbox
- [ ] Accès premium confirmé via `findActiveByPurchaseToken()` (vérifier logs)
- [ ] Renouvellement sandbox met à jour `expires_at` sans doublon
- [ ] Réinstallation : accès premium retrouvé avec le même `purchase_token`
- [ ] Upgrade : ancien token expiré, nouveau token actif

---

### Phase 3 — Nettoyage (après Phase 2 complétée)

Priorité : basse. Ne déployer qu'après Phase 2 complétée.

#### 3.1 Migration SQL

```sql
ALTER TABLE `puzzle_devices`
    DROP COLUMN `is_premium`,
    DROP COLUMN `purchase_token`,
    DROP COLUMN `product_id`,
    DROP COLUMN `premium_expires_at`;

-- Colonne orpheline (remplacée par uq_purchase_token_app)
ALTER TABLE `subscriptions`
    DROP COLUMN `device_token`;
```

#### 3.2 PuzzleDevice model

Supprimer `updateSubscription()`.

#### 3.3 PuzzleRouteHandler

Supprimer la lecture de `$device['is_premium']` comme source de vérité.
Le bloc Phase 1.6 est déjà l'état final — aucun autre changement.

#### 3.4 Grep de sécurité avant déploiement

Vérifier qu'aucune référence ne subsiste à :

- `puzzle_devices.is_premium`
- `puzzle_devices.purchase_token`
- `subscriptions.device_token`
- `PuzzleDevice::updateSubscription`

#### Tests

- `run_all_tests.php` complet au vert
- Test manuel Play Store : achat → vérification → accès premium confirmé
- Test réinstallation : désinstall + réinstall → re-soumettre même `purchase_token`
  → accès premium retrouvé

#### Conditions de complétion

- [ ] Colonnes supprimées sans erreur MySQL
- [ ] `updateSubscription()` supprimée, aucune référence restante
- [ ] `subscriptions.device_token` supprimée, aucune référence restante
- [ ] Suite de tests complète au vert
- [ ] Test réinstallation validé manuellement
