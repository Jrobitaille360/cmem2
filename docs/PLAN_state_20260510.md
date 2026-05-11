# État du projet — Subscriptions & Auth Puzzle

Date : 2026-05-10
Auteur : JRobitaille

Ce document remplace et consolide :

- `PLAN_simplification-subscriptions.md` (Phases 2 et 3 actives)
- `PLAN_simplification-subscriptions_implantation.md` (journal Phase 1 — terminé)
- `PLAN_auth-subscription-googleplay.md` (diagnostic de référence)
- `PLAN_subscription-hardening.md` (Phases A/B/C)
- `ROOTCAUSE_premium-windows-android.md` (bug actif Windows/Web)

---

## 1. Ce qui est fait

### Phase 1 — subscriptions comme source unique de vérité (Google Play)

Code mergé sur `release/v2.5.0`. Tests : 121/121 et 110/110 verts.

| Fichier | Changement |
| - | - |
| `src/puzzle/Services/GooglePlayService.php` | `linked_purchase_token` retourné par `validateSubscription()` |
| `src/auth_groups/Models/Subscription.php` | `findActiveByPurchaseToken(string, string): ?array` |
| `src/auth_groups/Models/Subscription.php` | `expireByPurchaseToken(string): void` |
| `src/auth_groups/Services/SubscriptionService.php` | Signature `activatePremium(?int $userId, ...)` |
| `src/puzzle/Controllers/AuthController.php` | Écrit dans `subscriptions`, plus dans `puzzle_devices` |
| `src/puzzle/Routing/PuzzleRouteHandler.php` | Lookup par `purchase_token` pour devices anonymes |

---

## 2. SQL appliqués en production ✓

| Fichier | Contenu | Statut |
| - | - | - |
| `docs/20260505_subscriptions_purchase_token_unique.sql` | Contrainte `uq_purchase_token_app` + migration depuis `puzzle_devices` | ✓ appliqué prod |
| `docs/20260508_stripe_idempotency.sql` | Table `stripe_processed_events` | ✓ appliqué prod |

---

## 3. Bug actif — Premium Windows/Web cassé

### Root Cause 1 (backend — principal)

`puzzle_devices.user_id` n'est jamais rempli pour les appareils Windows.

`requireDeviceToken()` cherche par `user_id` (NULL) puis par `purchase_token` (NULL pour Stripe)
→ `$sub = null` → `requirePremium()` retourne 403.

La subscription Stripe est correctement stockée dans `subscriptions` par `user_id`, mais
le contexte puzzle n'a aucun moyen de relier le device à cet utilisateur.

Evidence :

- `src/puzzle/Routing/PuzzleRouteHandler.php:325-335` — double lookup; les deux NULL pour Windows
- `src/puzzle/Models/PuzzleDevice.php:23-43` — `upsert()` ne renseigne jamais `user_id`
- `src/auth_groups/Models/Subscription.php:75-85` — `findActive()` requiert `user_id`

### Root Cause 2 (Flutter — secondaire)

`getSubscriptionStatus()` lance `UserNotLoggedInException` si JWT absent.
`catch (_)` dans `purchase_service.dart:106-113` avale l'exception silencieusement.
`isPremium.value` reste `false` côté client.

### Impact par plateforme

| Plateforme | Chemin d'achat | Résultat |
| - | - | - |
| Android | Google Play → `purchase_token` | ✓ Fonctionne |
| iOS | App Store → identique Android | ✓ Fonctionne |
| Windows | Stripe → `user_id` dans `subscriptions` | ✗ Cassé |
| Web | Stripe → identique Windows | ✗ Cassé |

---

## 4. Travaux à faire — par priorité

---

### Priorité 1 — Débloquer prod (bug Windows/Web)

#### SQL (manuel)

Appliquer sur la base de données cible avant tout déploiement :

```bash
mysql -u root -p cmem2 < docs/20260505_subscriptions_purchase_token_unique.sql
mysql -u root -p cmem2 < docs/20260508_stripe_idempotency.sql
```

#### Fix B — `POST /puzzle/auth/link-device` (backend)

Fichier : `src/puzzle/Controllers/AuthController.php`

Endpoint acceptant JWT + device_token. Écrit `user_id` dans `puzzle_devices` pour le device
identifié. Ensuite, `requireDeviceToken()` trouve le `user_id` → `findActive()` retrouve
la subscription Stripe.

Route à ajouter dans `src/puzzle/Routing/PuzzleRouteHandler.php` (méthode POST,
authentification JWT requise).

Conditions de complétion :

- [x] Endpoint créé et testé dans `test_puzzle_admin.php`
- [x] `puzzle_devices.user_id` rempli après appel
- [x] Premium Windows confirmé via `requireDeviceToken()` — validation E2E `test_link_device_e2e.php` (7/7)

#### Fix C — Flutter : appel `link-device` au login Windows ✓

Complété. Voir directive `20260510_202000_cmem2_API_vers_puzzle__fix-windows-premium-link-device.md`.

#### Fix D — Flutter : remplacer `catch (_)` silencieux ✓

Complété. Voir directive `20260510_202000_cmem2_API_vers_puzzle__fix-windows-premium-link-device.md`.

---

### Priorité 2 — Avant premier abonné Play Store

#### Phase 2 — Validation sandbox Play Store (test manuel)

Aucun abonné Play Store n'existe en production. Un test sandbox bout en bout
remplace toute période d'observation.

Prérequis :

- [ ] Ajouter adresse Gmail testeur dans Play Console → Setup → Licence testing
- [x] `PUZZLE_GOOGLE_PLAY_PACKAGE` = `com.journauxdebord.puzzle` ✓
- [x] `PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON` configuré et OAuth2 validé (`check_google_play_config.php`) ✓
- [ ] Build Flutter signé avec le keystore de la piste de test

Scénarios à valider :

- [ ] Achat initial → ligne `subscriptions` créée (`status=active`, `provider=google_play`)
- [ ] Accès premium confirmé via `findActiveByPurchaseToken()` (vérifier logs)
- [ ] Renouvellement sandbox : `expires_at` mis à jour, pas de doublon
- [ ] Réinstallation : même `purchase_token` → accès premium retrouvé
- [ ] Upgrade : ancien token `expired`, nouveau token `active`

---

### Priorité 3 — Hardening (après Priorité 1 déployée)

#### Phase A — Backend GooglePlayService

Fichier : `src/puzzle/Services/GooglePlayService.php`

- A1 : Protéger `strtotime()` — vérification explicite `false`, log + retour null
- A2 : Retry max 3 tentatives (500ms / 1000ms / 2000ms) sur timeout réseau
- A3 : Logger le code HTTP Google séparément (401 = credentials, 404 = token invalide, 5xx = panne Google)

Fichier : `src/puzzle/Controllers/AuthController.php`

- A4 : Retourner codes d'erreur distincts : `CREDENTIAL_ERROR` (alerte ops) vs
  `INVALID_PURCHASE_TOKEN` (erreur client)

Fichier : nouveau `src/cron/reverify_google_play.php`

- A5 : Re-vérifier tokens Google Play actifs hebdomadairement → marquer expirés si révoqués

Conditions de complétion :

- [ ] Aucune absorption silencieuse de date invalide
- [ ] Retry implémenté et testé
- [ ] Codes d'erreur distincts dans les logs et réponses
- [ ] Cron `reverify_google_play.php` fonctionnel

#### Phase B — Flutter hardening

Fichier : `lib/services/purchase_service.dart` (projet `c:\code\puzzle`)

- B1 : Retirer unlock optimiste (lignes 254–256)
- B2 : Stocker `expires_at` dans `SharedPreferences`; vérifier au lancement
- B3 : 1 retry sur échec réseau (pas sur 422)

Fichier : `lib/screens/purchase_screen.dart`

- B4 : Écran `SubscriptionExpiredScreen` quand `isPremium=false` et `expires_at` passé
- B5 : Bouton "Restore Purchase" appelant `InAppPurchase.restorePurchases()`

Conditions de complétion :

- [ ] Aucun unlock optimiste
- [ ] `expires_at` enforced localement au lancement
- [ ] Écran d'expiration affiché correctement

---

### Priorité 4 — Nettoyage (après Phase 2 complétée)

#### Phase 3 — Suppression colonnes obsolètes

**Attention :** ne déployer qu'après Phase 2 validée et Priorité 1 stable en production.

```sql
ALTER TABLE `puzzle_devices`
    DROP COLUMN `is_premium`,
    DROP COLUMN `purchase_token`,
    DROP COLUMN `product_id`,
    DROP COLUMN `premium_expires_at`;

ALTER TABLE `subscriptions`
    DROP COLUMN `device_token`;
```

Fichier : `src/puzzle/Models/PuzzleDevice.php`

Supprimer `updateSubscription()`.

Grep de sécurité avant déploiement :

```bash
grep -r "puzzle_devices.is_premium"     src/
grep -r "puzzle_devices.purchase_token" src/
grep -r "subscriptions.device_token"    src/
grep -r "updateSubscription"            src/
```

Conditions de complétion :

- [ ] Colonnes supprimées sans erreur MySQL
- [ ] `updateSubscription()` supprimée, aucune référence restante
- [ ] Suite de tests complète au vert
- [ ] Test réinstallation validé manuellement

#### Phase C — Account linking (schema change, approbation séparée requise)

Migration :

```sql
ALTER TABLE subscriptions
    ADD COLUMN anonymous_device_id VARCHAR(64) NULL AFTER user_id,
    ADD INDEX idx_sub_device (anonymous_device_id, app_id);
```

- `SubscriptionService::migrateAnonymousToUser($deviceId, $userId, $appId)` : relier
  subscription anonyme à un compte à la connexion
- `GET /puzzle/auth/subscription-status` : polling post-retour Stripe portal

Conditions d'approbation :

- [ ] Phase A + B stables en production
- [ ] Migration SQL approuvée explicitement avant exécution
- [ ] Tests écrits avant tout code

---

## 5. Règle de séquence

```
SQL appliqués
  → Fix B (link-device) + Fix C+D (Flutter)
    → Phase 2 sandbox Play Store
      → Phase A hardening
        → Phase B Flutter hardening
          → Phase 3 nettoyage
            → Phase C account linking
```
