# release 2.6.0

## [2.6.0] — 2026-05-10

### Puzzle — Fix premium Windows/Web (link-device)

- **`POST /puzzle/auth/link-device`** — nouvel endpoint JWT : lie un `device_token` Puzzle au `user_id` cmem2 de l'utilisateur connecté; résout le bug premium Windows/Web (root cause : `puzzle_devices.user_id` jamais rempli → subscription Stripe jamais trouvée)
- **`PuzzleDevice::setUserId(int $id, int $userId)`** — nouvelle méthode
- **`AuthController::linkDevice(array $user)`** — nouveau handler
- **`PuzzleRouteHandler::requireAnyJwt()`** — nouvelle méthode JWT sans vérification de rôle
- **Tests** — Section 5 dans `test_puzzle_admin.php` (5 assertions : 401/422/404/200)

### Composer — nettoyage

- Retiré `"version"` du `composer.json` racine (champ déconseillé pour un projet root, causait résolution incorrecte des sous-dépendances sabre/\*)
- `composer update` : sabre/uri 3.1.0, sabre/xml 4.1.0, doctrine/collections 2.6.0 restaurés

### Documentation — état 2026-05-10

- `docs/PLAN_state_20260510.md` — plan consolidé unique (remplace les 5 plans fragmentés)
- `docs/ROOTCAUSE_premium-windows-android.md` — analyse root cause premium Windows/Web
- `docs/PLAN_auth-subscription-googleplay.md` — diagnostic auth flows
- `docs/PLAN_subscription-hardening.md` — hardening Phases A/B/C

### Logs — nettoyage des traces d'initialisation

- **Logs d'init supprimés** — retiré les `safeLog('info', "Plugin X initialisé")` dans `PluginManager`, `CalendarPlugin`, `ItemsPlugin`, `PomoPlugin`, `PuzzlePlugin`, `QuizPlugin`; retiré `LogService::info('Cron notifications email terminé')` dans `send_email_notifications.php`

### Puzzle — Google Play : durcissement configuration

- **`puzzle_config.php`** — `PUZZLE_GOOGLE_PLAY_PACKAGE` hardcodé à `com.journauxdebord.puzzle` (plus de surcharge .env); `PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON` résout un chemin relatif depuis la racine du projet
- **`GooglePlayService`** — appels `LogService::error()` sur les échecs réseau OAuth et API (étaient silencieux)
- **`.env.example`** — variable `PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON` documentée avec instructions de stockage hors web root
- **`.gitignore`** — `src/puzzle/google-service-account.json` ignoré

### CLAUDE.md — gouvernance

- Ajout sections : Cross-Project Directives & Plans, Personal Data & Memory, Git & Release Discipline, Windows / File Editing, Changelog Workflow
- `docs/PLAN_simplification-subscriptions.md` — conditions de complétion Phase 1 cochées (121/121 tests, 110/110 tests)

### Stripe webhook — signature verification + idempotency

- **Idempotency** — `stripe_processed_events` table (new migration `docs/20260508_stripe_idempotency.sql`); duplicate `event.id` returns `{"received":true,"skipped":true}` without re-processing
- **`StripeService::isEventProcessed()` / `markEventProcessed()`** — `INSERT IGNORE` deduplication via primary key on `event_id`
- **`handleSubscriptionUpdated` upsert** — now upserts when `user_id` + `app_id` available in metadata, instead of UPDATE-only (fixes new subscriptions delivered via webhook without prior checkout row)
- **Tests** — `private/tests/test_stripe_webhooks.php` (13 assertions, 0 failures): covers AC1–AC8 (signature validation, idempotency, subscription.updated upsert, subscription.deleted cancel)

### Puzzle — Simplification abonnements Phase 1

- **`subscriptions` source unique de vérité** — `POST /puzzle/auth/verify-subscription` écrit dans
  `subscriptions` via `SubscriptionService::activatePremium()` au lieu de `PuzzleDevice::updateSubscription()`
- **Upgrade/downgrade Google Play** — `linked_purchase_token` reçu de Google expire l'ancien
  abonnement via `Subscription::expireByPurchaseToken()` avant activation du nouveau
- **Device anonyme** — `PuzzleRouteHandler::requireDeviceToken()` consulte `subscriptions`
  par `purchase_token` pour les appareils sans `user_id` (en plus du lookup existant par `user_id`)
- **`GooglePlayService::validateSubscription()`** — retourne `linked_purchase_token`
  (`linkedPurchaseToken` de l'API Google)
- **`Subscription::findActiveByPurchaseToken(string $purchaseToken, string $appId): ?array`** — nouvelle méthode
- **`Subscription::expireByPurchaseToken(string $purchaseToken): void`** — nouvelle méthode
- **`SubscriptionService::activatePremium(?int $userId, ...)`** — accepte `null` pour les devices anonymes
- **Migration SQL** — `docs/20260505_subscriptions_purchase_token_unique.sql` :
  contrainte `uq_purchase_token_app (purchase_token, app_id)` +
  `INSERT IGNORE` des devices Google Play existants vers `subscriptions`
- **Documentation** — `docs/puzzle/API_PUZZLE_ENDPOINTS.json` v1.1.0, `docs/puzzle/GUIDE.md` v1.1.0,
  `docs/puzzle/API_PUZZLE_ADMIN_MANAGER.json` v1.0.4, `docs/core/API_ENDPOINTS.json`,
  `docs/core/GUIDE.md` mis à jour

### Cron — backup uploads

- **`src/cron/backup_uploads.php`** — remplacé `PharData` (indisponible sur certains serveurs) par `exec('tar ...')` pour la création d'archives

### Tests — infrastructure

- **`test_auth_otp.php`** — cleanup Z.1 migré de API key hardcodée (invalide) vers login JWT admin; ajout Z.0 (login admin pour cleanup)
- **`private/tests/check_google_play_config.php`** — nouveau script diagnostic standalone : vérifie SA JSON, clé RSA, échange OAuth2 avant tests sandbox

### ICS — configuration

- **`src/ics/config/.env.ics`** — `ICS_BASE_URL` commentée (valeur localhost
  désactivée; l'URL de base principale est utilisée par défaut)
- PLAN files déplacés dans `docs/v-2-5-0/` (ancrage v2.5.0)
- `docs/v-2-5-0/PR_BODY.md` — checklist de déploiement complétée (composer,
  migration SQL, endpoint `/health`)

---
