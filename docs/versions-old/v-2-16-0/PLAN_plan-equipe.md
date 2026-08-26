# PLAN — Plan équipe (facturation Stripe de groupe + modules de groupe)

Origine : directive inter-projet `20260813_143000_cmem_web_vers_cmem2_API__plan-equipe.md`
(requérant `cmem_web`, Phase 8 du roadmap cmem). Un groupe doit pouvoir porter son propre
abonnement Stripe (tier `team`) et piloter ses modules, avec un plan effectif par usager qui prend
le meilleur entre son plan personnel et celui de chacun de ses groupes actifs.

## Correction actée avant implémentation

`GET /v2/access/status` (`AccessService::getMatrix`) est un matrix `{android,web,windows}`
générique multi-app, sans notion de plan cmem — il n'est **pas** le porteur de `plan`/`source`/
`group_id` demandé par la directive. Le vrai porteur est `GET /auth/me` → `data.user.plan`, via
`EntitlementService::getEffectivePlanForCmem()`. C'est là que `source`/`group_id` sont ajoutés.
`/v2/access/status` n'est pas touché par ce lot.

## Grandes lignes

### 1. Abonnement Stripe porté par un groupe

- **Déjà en place** : `stripe_subscriptions` keyed `(user_id, app_id)` unique ; `BillingController`
  (`checkout`/`portal`), `SubscriptionController` (`status`/`cancel`), `StripeService` (checkout
  session, webhooks), `StripeSubscriptionService` — tout strictement individuel.
- **Améliorations à faire** : ajouter `group_id` nullable + XOR sur `stripe_subscriptions`
  (précédent `tenant_modules`) ; `group_id` optionnel sur les 4 endpoints, restreint `role=admin`
  pour checkout/portal/cancel, membre simple pour la lecture du statut ; nouveau tier `team`
  (mensuel seul en v1, prix placeholder documenté) ; webhooks routés par `metadata.owner_type`.
- **Maintenances à prévoir** : la validation `plan` dans `BillingController::checkout()` doit
  rester synchronisée avec `CmemPlans`/prix Stripe configurés à chaque nouveau tier.

### 2. Plan effectif d'un membre = meilleur des deux

- **Déjà en place** : `EntitlementService::getEffectivePlanForCmem()` — perso uniquement
  (stripe actif > `cmem_plan_override` > `free`), déjà retourne `{code, source, status, features}`.
- **Améliorations à faire** : résoudre aussi les groupes actifs de l'usager
  (`Group::getActiveGroupIdsByUserId`), comparer via un classement de plans
  (`CmemPlans::rank()`), retourner `group_id` quand `source === 'group'`. Additif — les 3
  consommateurs existants (`ModuleController`, `AiController`, `AuthController::me`) n'ont pas à
  changer.
- **Maintenances à prévoir** : tout nouveau tier doit être ajouté à `CmemPlans::RANK`.

### 3. Activation de modules au niveau du groupe

- **Déjà en place** : `tenant_modules.group_id` posé en juillet (directive `modules-gating`),
  jamais servi par le code. `ModuleController`/`TenantModule` = perso uniquement.
- **Améliorations à faire** : `GET /modules` fusionne perso ∪ groupes actifs (OR sur
  `available`/`enabled`, quota reste perso-only en v1) ; nouveaux `GET/PATCH
  /groups/{id}/modules[/{key}]` (lecture = membre, écriture = admin).
- **Maintenances à prévoir** : si un quota de groupe devient nécessaire plus tard, `quota_used`
  devra être porté par la ligne `group_id` au lieu d'être ignoré — actuellement hors scope.

### 4. Retrait d'un membre — non-régression

- **Déjà en place** : `GroupMemberController::leave()`, `Group::removeMember()` (soft delete),
  aucune couche de cache sur l'entitlement (résolution live à chaque requête).
- **Améliorations à faire** : aucune — la résolution live suffit, à couvrir par un test explicite
  (perte immédiate du plan/modules de groupe après `leave`, sans perte des données perso).
- **Maintenances à prévoir** : si un cache d'entitlement est introduit un jour, il faudra
  l'invalider sur `leave`/retrait — le documenter alors.

## Décisions actées (réponses aux points à trancher de la directive)

1. Tier `team`, mensuel seul, placeholder 15 $ CAD/mois. Pas de plafond de sièges appliqué en code.
2. `GET /modules` fusionne (union) **et** `GET /groups/{id}/modules` reste disponible.
3. Conflit perso/groupe : OR logique.
4. Plusieurs groupes actifs : meilleur plan via `CmemPlans::rank()`, égalité → perso puis plus
   petit `group_id`.
5. Annulation groupe : même grâce que l'individuel (`cancel_at_period_end`), aucun code dédié.

## Phases d'implantation

### Phase 1 — Schéma + config (bloquant, nécessite confirmation avant migration)

- **Actions** : `docs/v-2-16-0/20260813_group_billing.sql` (ALTER `stripe_subscriptions` : `group_id`
  nullable, XOR, `uq_group_app`) ; `CmemPlans::RANK`/`rank()` + tier `team` ; `CmemModules`
  `AVAILABLE['team']`/`QUOTAS['ia']['team']`.
- **Enjeux** : migration sur table de production existante (contrainte CHECK MySQL 8+, clé unique
  avec NULL) — ne pas casser les lignes existantes (`user_id` déjà toutes non-null, migration
  additive et rétrocompatible).
- **Tests** : vérifier après migration que les lignes existantes sont inchangées, que l'insertion
  d'une ligne `group_id` sans `user_id` réussit et qu'une ligne avec les deux ou aucun échoue (CHECK).
- **Conditions de complétion** : migration appliquée en dev, `build_DB` de la version courante
  non modifié (fichier séparé dans `/docs/`), tests de contrainte verts.

### Phase 2 — Facturation de groupe (Stripe)

- **Actions** : modèle (`findByGroupAndApp(s)`, `updateByGroupAndApp`,
  `findStripeCustomerByGroupAndApp`, `upsert` XOR-aware) ; service (`createCheckoutSession`
  + metadata `owner_type`, webhooks routés) ; contrôleurs (`group_id` optionnel + garde
  `role=admin` sur checkout/portal/cancel, membre simple sur status).
- **Enjeux** : ne pas régresser le flux individuel existant (tests `test_stripe_v2.php` doivent
  rester verts) ; idempotence webhook déjà en place à préserver.
- **Tests** : `test_stripe_group_billing.php` — checkout admin OK / member 403
  `GROUP_ADMIN_REQUIRED` / plan≠team rejeté ; portal/cancel mêmes gardes ; status lecture membre.
- **Conditions de complétion** : 4 endpoints acceptent `group_id`, gardes de rôle vérifiées par
  test, `test_stripe_v2.php` toujours vert.

### Phase 3 — Plan effectif fusionné

- **Actions** : `Group::getActiveGroupIdsByUserId` ; `EntitlementService::getEffectivePlanForCmem`
  (merge best-of) + `getEffectivePlanForGroup`.
- **Enjeux** : ne pas casser `ModuleController`/`AiController`/`AuthController::me` (consommateurs
  existants, changement additif uniquement).
- **Tests** : `/auth/me` retourne `source: group` + `group_id` quand le groupe gagne ; retombée
  immédiate après `leave`.
- **Conditions de complétion** : les 3 consommateurs existants passent sans modification, nouveau
  comportement couvert par test.

### Phase 4 — Modules de groupe

- **Actions** : `TenantModule::findAllByGroup`/`setEnabledForGroup` ; fusion dans
  `ModuleController::index` ; nouveau `GroupModuleController` ; routes
  `/groups/{id}/modules[/{key}]`.
- **Enjeux** : forme de réponse de `GET /modules` inchangée (pas de rupture front) ; erreurs
  `UNKNOWN_MODULE_KEY`/`VALIDATION_ERROR`/`MODULE_NOT_AVAILABLE` cohérentes avec l'existant.
- **Tests** : `test_group_modules.php` — GET membre / PATCH admin requis / fusion OR vérifiée dans
  `GET /modules` ; `test_modules.php` toujours vert.
- **Conditions de complétion** : les 2 nouveaux endpoints fonctionnels, fusion testée, aucune
  régression sur `test_modules.php`.

### Phase 5 — Documentation, changelog, directive

- **Actions** : `docs/stripe/GUIDE.md` + `API_STRIPE_ENDPOINTS.json`, `docs/modules/GUIDE.md` +
  `API_MODULES_ENDPOINTS.json`, `docs/entrypoints.md`, `.env.example`, `CHANGELOG.md`.
- **Enjeux** : docs transmises au client (`cmem_web`) — doivent refléter exactement le
  comportement livré.
- **Tests** : n/a (revue documentaire).
- **Conditions de complétion** : fichier directive coché intégralement, `statut: complété`.
