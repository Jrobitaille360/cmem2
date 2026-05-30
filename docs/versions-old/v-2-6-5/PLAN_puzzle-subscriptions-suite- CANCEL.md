# Plan — Puzzle subscriptions : suite (P2 → P4)

Date : 2026-05-10
Contexte : suite de `docs/v-2-6-0/PLAN_state_20260510.md` (Priorité 1 complétée dans v2.6.0)

## Séquence

```
Phase 2 sandbox Play Store (manuel)
  → Phase A hardening backend (cmem2_API)
    → Phase B hardening Flutter (puzzle)
      → Phase 3 nettoyage colonnes (cmem2_API)
        → Phase C account linking (approbation requise)
```

---

## Priorité 2 — Validation sandbox Play Store

Bloquer les phases suivantes jusqu'à ce que les 5 scénarios soient cochés.

### Prérequis

- [ ] Ajouter adresse Gmail testeur dans Play Console → Setup → License testing
- [x] `PUZZLE_GOOGLE_PLAY_PACKAGE` = `com.journauxdebord.puzzle` ✓
- [x] `PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON` OAuth2 validé (`check_google_play_config.php`) ✓
- [ ] Build Flutter signé (keystore piste test) — directive `20260510_214155_cmem2_API_vers_puzzle__validation-sandbox-play-store.md`

### Scénarios sandbox

- [ ] Achat initial → ligne `subscriptions` (`status=active`, `provider=google_play`)
- [ ] Accès premium confirmé via `findActiveByPurchaseToken()` (logs)
- [ ] Renouvellement sandbox : `expires_at` mis à jour, pas de doublon
- [ ] Réinstallation : même `purchase_token` → accès premium retrouvé
- [ ] Upgrade : ancien token `expired`, nouveau `active`

---

## Priorité 3 — Hardening (après Phase 2 validée)

### Phase A — Backend GooglePlayService

#### Ce qui est en place

- `validateSubscription()` retourne `linked_purchase_token`
- Logs d'erreur sur échecs réseau OAuth/API

#### Améliorations à faire

Fichier : `src/puzzle/Services/GooglePlayService.php`

- A1 : Protéger `strtotime()` ligne 97 — vérifier retour `false`, log + retour `null`
- A2 : Retry 3 tentatives (500ms / 1000ms / 2000ms) sur timeout réseau (`file_get_contents`)
- A3 : Logger le code HTTP Google séparément (401 = credentials, 404 = token invalide, 5xx = panne)

Fichier : `src/puzzle/Controllers/AuthController.php`

- A4 : Codes d'erreur distincts : `CREDENTIAL_ERROR` (alerte ops) vs `INVALID_PURCHASE_TOKEN` (client)

Fichier : nouveau `src/cron/reverify_google_play.php`

- A5 : Re-vérifier tokens actifs hebdomadairement → marquer `expired` si révoqués par Google

#### Tests nécessaires

- `test_google_play_service.php` : strtotime false, retry, codes d'erreur distincts
- `test_reverify_cron.php` : token révoqué → status mis à jour

#### Conditions de complétion

- [ ] `strtotime()` protégé — aucune absorption silencieuse
- [ ] Retry implémenté et testé (mock timeout)
- [ ] Codes d'erreur distincts dans logs et réponses API
- [ ] Cron `reverify_google_play.php` fonctionnel, testé en `--dry-run`

---

### Phase B — Flutter hardening

Projet : `c:\code\puzzle`

#### Ce qui est en place

- `verify-subscription` appelé après achat
- `link-device` appelé au login Windows (directive en cours)

#### Améliorations à faire

Fichier : `lib/services/purchase_service.dart`

- B1 : Retirer unlock optimiste (lignes 254–256)
- B2 : Stocker `expires_at` dans `SharedPreferences`; vérifier au lancement
- B3 : 1 retry sur échec réseau (pas sur 422)

Fichier : `lib/screens/purchase_screen.dart`

- B4 : Écran `SubscriptionExpiredScreen` quand `isPremium=false` et `expires_at` passé
- B5 : Bouton "Restore Purchase" → `InAppPurchase.restorePurchases()`

#### Conditions de complétion

- [ ] Aucun unlock optimiste
- [ ] `expires_at` enforced localement au lancement
- [ ] Écran d'expiration affiché sur les deux plateformes (Windows + Android)

---

## Priorité 4 — Nettoyage (après Phase 2 validée + Priorité 3 stable)

### Phase 3 — Suppression colonnes obsolètes

**Bloquer** jusqu'à Phase 2 + Priorité 3 stables en production.

#### Grep de sécurité avant toute migration

```bash
grep -r "puzzle_devices.is_premium"     src/
grep -r "puzzle_devices.purchase_token" src/
grep -r "subscriptions.device_token"    src/
grep -r "updateSubscription"            src/
```

Tous les résultats doivent être vides avant de procéder.

#### Migration SQL

```sql
ALTER TABLE `puzzle_devices`
    DROP COLUMN `is_premium`,
    DROP COLUMN `purchase_token`,
    DROP COLUMN `product_id`,
    DROP COLUMN `premium_expires_at`;

ALTER TABLE `subscriptions`
    DROP COLUMN `device_token`;
```

#### Code à supprimer

Fichier : `src/puzzle/Models/PuzzleDevice.php` — supprimer `updateSubscription()`

#### Conditions de complétion

- [ ] Grep vide sur les 4 patterns
- [ ] Migration appliquée sans erreur
- [ ] `updateSubscription()` supprimée, aucune référence restante
- [ ] Suite de tests 100% verte
- [ ] Test réinstallation validé manuellement

---

### Phase C — Account linking (approbation séparée requise)

**Ne pas implémenter avant approbation explicite.**

#### Migration SQL (à approuver)

```sql
ALTER TABLE subscriptions
    ADD COLUMN anonymous_device_id VARCHAR(64) NULL AFTER user_id,
    ADD INDEX idx_sub_device (anonymous_device_id, app_id);
```

#### Code requis

- `SubscriptionService::migrateAnonymousToUser($deviceId, $userId, $appId)`
- `GET /puzzle/auth/subscription-status` — polling post-retour Stripe portal

#### Conditions d'approbation

- [ ] Phase A + B stables en production (≥ 2 semaines)
- [ ] Migration SQL approuvée explicitement
- [ ] Tests écrits avant tout code (spec-first)
