# PLAN — Simplification subscriptions — Implantation

## Phase 1 — SQL + code

### Étape 1.1 — Migration SQL

- Début : 2026-05-05 (session courante)
- Fin : 2026-05-05
- Résultat : fichier `docs/20260505_subscriptions_purchase_token_unique.sql` créé.
  Contient `ALTER TABLE subscriptions ADD UNIQUE KEY uq_purchase_token_app`
  et migration `INSERT IGNORE` depuis `puzzle_devices`.
  À appliquer manuellement en production avant déploiement.

### Étape 1.2 — GooglePlayService : linked_purchase_token

- Début : 2026-05-05
- Fin : 2026-05-05
- Résultat : `src/puzzle/Services/GooglePlayService.php` — champ `linked_purchase_token`
  ajouté au tableau de retour de `validateSubscription()`.

### Étape 1.3 — Subscription model : nouvelles méthodes

- Début : 2026-05-05
- Fin : 2026-05-05
- Résultat : `src/auth_groups/Models/Subscription.php` — deux méthodes ajoutées :
  `findActiveByPurchaseToken(string, string): ?array`
  `expireByPurchaseToken(string): void`

### Étape 1.4 — SubscriptionService : ?int $userId

- Début : 2026-05-05
- Fin : 2026-05-05
- Résultat : `src/auth_groups/Services/SubscriptionService.php` —
  signature `activatePremium(int $userId, ...)` remplacée par `activatePremium(?int $userId, ...)`.

### Étape 1.5 — AuthController::verifySubscription()

- Début : 2026-05-05
- Fin : 2026-05-05
- Résultat : `src/puzzle/Controllers/AuthController.php` —
  `PuzzleDevice::updateSubscription()` remplacé par `SubscriptionService::activatePremium()`.
  Gestion upgrade/downgrade via `expireByPurchaseToken($linked_purchase_token)`.
  `use` ajoutés pour `Subscription` et `SubscriptionService`.

### Étape 1.6 — PuzzleRouteHandler::requireDeviceToken()

- Début : 2026-05-05
- Fin : 2026-05-05
- Résultat : `src/puzzle/Routing/PuzzleRouteHandler.php` —
  Lookup par `purchase_token` ajouté pour les devices anonymes (sans `user_id`).
  `use AuthGroups\Models\Subscription` ajouté.

## Phase 2 — Observation en production

- À démarrer après déploiement de Phase 1.
- Durée recommandée : 4 à 6 semaines.
- Voir le plan principal pour les conditions de complétion.

## Phase 3 — Nettoyage

- À planifier après stabilisation Phase 2.
- Voir le plan principal pour le détail des suppressions SQL et code.
