# Pull Request — cmem2_API v2.6.0

> **Projet :** cmem2_API (PHP API)
> **Description :** API principale cmem2, architecture plugin, auth, iCal

---

## Résumé

Release v2.6.0 : fix premium Windows/Web (link-device), subscriptions comme source unique de vérité
pour Google Play (Phase 1), idempotency Stripe, hardening Google Play config, fix cron backup.

## Type de changement

- [x] Correction de bug
- [x] Nouvelle fonctionnalité
- [ ] Refactoring / amélioration interne
- [ ] Documentation
- [ ] Performance
- [ ] Sécurité
- [x] Base de données / migration

## Changements apportés

### Puzzle — Fix premium Windows/Web

- **`POST /puzzle/auth/link-device`** — nouvel endpoint JWT : lie un `device_token` au `user_id`
  cmem2; résout root cause bug premium Windows/Web (`puzzle_devices.user_id` jamais rempli)
- **`PuzzleDevice::setUserId()`** — nouvelle méthode
- **`PuzzleRouteHandler::requireAnyJwt()`** — nouvelle méthode JWT sans vérification de rôle

### Puzzle — Subscriptions source unique de vérité (Phase 1)

- `POST /puzzle/auth/verify-subscription` écrit dans `subscriptions` via `SubscriptionService`
- `Subscription::findActiveByPurchaseToken()` — lookup par `purchase_token` pour devices anonymes
- `Subscription::expireByPurchaseToken()` — expiration à l'upgrade Google Play
- `linked_purchase_token` géré à l'upgrade/downgrade
- Migration : `docs/20260505_subscriptions_purchase_token_unique.sql` — contrainte unique + INSERT IGNORE

### Stripe webhook

- Idempotency via table `stripe_processed_events` — `INSERT IGNORE` sur `event_id`
- `handleSubscriptionUpdated` : upsert au lieu de UPDATE-only
- Migration : `docs/20260508_stripe_idempotency.sql`

### Cron

- `backup_uploads.php` : remplace `PharData` (indisponible sur certains serveurs) par `exec('tar')`

### Google Play config

- `PUZZLE_GOOGLE_PLAY_PACKAGE` hardcodé dans `puzzle_config.php`
- `GooglePlayService` : erreurs réseau OAuth/API loguées (étaient silencieuses)
- Nouveau script diagnostic : `private/tests/check_google_play_config.php`

## Tests effectués

- [x] Suite complète : **1344 / 1344** (0 échec)
- [x] `test_puzzle_admin.php` — link-device : 5 assertions, 0 échec
- [x] `test_link_device_e2e.php` — E2E premium Windows : 7/7 verts
- [x] `test_stripe_webhooks.php` — 13 assertions, 0 échec
- [x] `check_google_play_config.php` — credentials Google Play OAuth2 validés
- [x] Aucune régression sur les fonctionnalités existantes

## Captures / logs (si pertinent)

```
GRAND RÉSUMÉ — TOUS LES TESTS
Succès              : 1344 / 1344
Échecs              : 0 / 1344
Réussite globale    : 100%
```

## Références

- `docs/PLAN_state_20260510.md` — plan consolidé (Priorité 1 complétée)
- `docs/ROOTCAUSE_premium-windows-android.md` — root cause analysis
- Directive `20260510_202000_cmem2_API_vers_puzzle__fix-windows-premium-link-device.md` — Flutter side
- Directive `20260510_214155_cmem2_API_vers_puzzle__validation-sandbox-play-store.md` — sandbox Phase 2

## Points d'attention pour la revue

- Les deux migrations SQL (`20260505_*.sql`, `20260508_*.sql`) sont **déjà appliquées en prod**
- `puzzle_devices.user_id` doit être rempli via `link-device` au login Windows pour que
  `requireDeviceToken()` trouve la subscription Stripe — Flutter côté puzzle doit appeler
  l'endpoint (directive en attente)

## Checklist avant merge

- [x] Code compile sans erreur
- [x] `APP_VERSION=2.6.0` dans `.env` et `.env.example`
- [x] Aucune clé/secret committée
- [x] `CHANGELOG.md` mis à jour — `[2.6.0] — 2026-05-10`
- [ ] `composer install --no-dev` exécuté sur le serveur cible
- [ ] Endpoint `/health` répond correctement après déploiement
- [ ] Reviewer assigné
