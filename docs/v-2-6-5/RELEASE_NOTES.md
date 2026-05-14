# release 2.6.5

## [2.6.5] — 2026-05-14

### Puzzle — Sync statut abonnement Google Play (fix annulation PlayStore)

- **`GET /puzzle/auth/subscription-status`** (device_token) — nouvel endpoint; interroge l'API Google Play Developer et retourne `is_premium` + `stale`; met à jour `subscriptions` et `puzzle_devices` en base; fail-safe : état DB conservé si Google Play inaccessible (`stale: true`); résout le bug où l'app restait en mode abonnement après annulation PlayStore
- **`AuthController::getSubscriptionStatus()`** — handler du nouvel endpoint
- **`AuthController::verifySubscription()`** — appelle désormais `PuzzleDevice::updateSubscription()` après activation; stocke `purchase_token`, `product_id` et `premium_expires_at` dans `puzzle_devices`
- **`docs/puzzle/API_PUZZLE_ENDPOINTS.json`** — v1.1.0 → v1.2.0; nouvel endpoint documenté

### Puzzle — Sync Google Play sur GET /subscription/status

- **`SubscriptionController::getStatus()`** — re-vérifie l'état d'un abonnement `google_play` auprès de l'API Google Play Developer à chaque appel `GET /subscription/status?app_id=puzzle`; met à jour `is_premium` et `expires_at` en base; fail-safe : valeur DB conservée si Google Play inaccessible
- **`SubscriptionController::syncGooglePlayStatus()`** — méthode privée encapsulant la logique de sync

### Déploiement — Traçabilité version déployée

- **`private/deploy.ps1`** (étape 4/4) — injecte `APP_COMMIT` (hash git court) et `APP_DEPLOYED_AT` (timestamp ISO) dans le `.env` distant à chaque déploiement
- **`.env.example`** — nouvelles variables `APP_COMMIT` et `APP_DEPLOYED_AT` documentées

### Maintenance — Rapport courriel conditionnel

- **`MaintenanceReport::send()`** — courriel envoyé uniquement si des erreurs sont détectées; en l'absence d'erreur, seul le log fichier est écrit

---
