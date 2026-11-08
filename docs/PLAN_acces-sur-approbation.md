# PLAN — Accès sur approbation (fin de la commercialisation)

Date : 2026-11-08

## Contexte

Décision (2026-11-08) : cmem2_API et cmem_web ne sont plus commercialisés.
L'accès est réservé à des personnes approuvées par l'admin, avec des
modules activables personne par personne. Hormis l'hébergement, seul le
module IA a un coût réel : il est plafonné en dollars par mois, pour tout
le monde, admin compris.

Remplace [PLAN_paiement-externe-securise.md](archives/PLAN_paiement-externe-securise.md)
(Paddle — abandonné) et clôt définitivement
[PLAN_mise-de-cote-paiement.md](archives/PLAN_mise-de-cote-paiement.md).

Décisions actées (2026-11-08) :

| Sujet | Décision |
| - | - |
| Arrivée des nouveaux | Les deux : invitation par l'admin (liste blanche de courriels) **et** inscription libre mise en attente d'approbation |
| Restrictions | Modules cochés un par un, par personne (pas de presets imposés) |
| IA | Budget mensuel en dollars, par personne, admin inclus |
| Code Stripe / Play Store | Retiré (pas de mise en sommeil) |
| Apps jdb, XMGame (et cmem_web) | Plus de modèle payant — tout passe par l'approbation |
| Inscription libre | Ouverte sur toutes les apps (toujours en attente d'approbation) |
| Modules en groupe | Un membre peut utiliser dans un groupe un module que le propriétaire du groupe a, même s'il ne l'a pas lui-même |
| Budget IA | 10 $/mois par personne par défaut ; plafond global 30 $/mois |
| Caps de ressources | Valeurs par défaut, surchargeables par groupe et par utilisateur |

## Ce qui est déjà en place

- `users.cmem_plan_override` posé par l'admin
  (`UserManagerController.php` ~l.737) : assignation manuelle d'un plan,
  dont `ami` = tous les modules.
- `src/stripe/Config/CmemModules.php` : registre des modules
  (`projet`, `contacts`, `crm`, `ged`, `ia`, `caldav`, `booking`,
  `push_avance`), trois états distincts (disponible / activé / quota).
- `tenant_modules` : `enabled` (choix de l'usager), `quota_used`,
  `quota_reset_at` (période mensuelle).
- `AiController.php` : quota décompté **avant** l'appel modèle (anti-course),
  `input_tokens` / `output_tokens` déjà renvoyés par le service IA.
- `src/stripe/Config/CmemPlans.php` : caps de ressources par plan
  (`max_contacts`, `max_tasks`, `max_storage_mb`, …) appliqués via
  `EntitlementService::checkQuota()`.

### Dépendances à défaire avant le retrait de Stripe / Play Store

Le code « métier » vit aujourd'hui dans le namespace `Stripe\` et est
importé partout :

| Classe | Utilisée par |
| - | - |
| `Stripe\Services\EntitlementService` | AiController, AuthController, GroupManagerController, GroupModuleController, ModuleController, ContactController, CalendarController, JournalController, TodoController, WebDeviceController, BookingPageController, BookingGateService |
| `Stripe\Config\CmemModules` | AiController, GroupModuleController, ModuleController, BookingPageController, BookingGateService |
| `Stripe\Config\CmemPlans` | UserManagerController |
| `Stripe\Models\StripeSubscription` | `src/access/Services/AccessService.php` |
| `Playstore\Models\AndroidDevice` | puzzle : CarouselController, SyncController, PuzzleRouteHandler (lien d'appareil, **pas** du paiement) |
| `Stripe\Routing\StripeRouteHandler`, `Playstore\Routing\PlaystoreRouteHandler` | `V2RouteHandler.php` |

## Améliorations à faire (en détail)

### A. Statut d'approbation du compte

- Nouvelle colonne `users.access_status` :
  `pending` / `approved` / `suspended` (défaut `pending`).
- Comptes existants migrés en `approved` (aucune perte d'accès au
  déploiement).
- Un compte `pending` peut s'authentifier (OTP) mais toutes les routes
  métier répondent `403` avec `code: ACCOUNT_PENDING` ; seules
  `/auth/me`, `/auth/logout` et la suppression de compte restent
  ouvertes. `suspended` → `403 ACCOUNT_SUSPENDED`.
- Notification courriel à l'admin à chaque nouvelle inscription en
  attente ; courriel à l'usager à l'approbation.

### B. Invitations (liste blanche)

- Table `access_invitations` (`email`, `invited_by`, `modules` JSON,
  `ai_budget_usd`, `expires_at`, `accepted_at`).
- Un compte créé (ou existant) dont le courriel correspond à une
  invitation valide passe directement `approved` avec les modules et le
  budget de l'invitation.
- Endpoints admin : `POST/GET/DELETE /admin/invitations`.

### C. Modules cochés par personne

- Table `user_module_grants` (`user_id`, `module_key`, `granted_by`,
  `granted_at`) — remplace la notion de plan pour la **disponibilité**.
- « Disponible » = présent dans `user_module_grants` ; « activé » reste le
  choix de l'usager (`tenant_modules.enabled`) — on garde les trois états.
- Valeur par défaut à l'approbation : les 4 modules historiques
  (`projet`, `contacts`, `crm`, `ged`), modifiable.
- Endpoints admin : `GET/PUT /admin/users/{id}/modules`.
- Groupes (acté 2026-11-08) : dans le contexte d'un groupe, un module est
  disponible si le **propriétaire** du groupe l'a, même si le membre ne
  l'a pas à titre personnel. Hors groupe, seuls les grants du membre
  comptent.
- IA en groupe : la dépense est imputée au budget de la personne qui
  fait l'appel (pas au propriétaire) — le budget reste personnel.

### D. Budget IA en dollars, mensuel

- Tarifs par modèle en config (`src/access/Config/AiPricing.php`) :
  prix entrée/sortie par million de tokens — à mettre à jour à la main
  quand le modèle change (`AI_SUMMARIZE_MODEL`).
- Table `ai_usage` : une ligne par appel (`user_id`, `model`,
  `input_tokens`, `output_tokens`, `cost_usd`, `created_at`).
- Budget par personne : `users.ai_budget_usd_monthly` (NULL = défaut
  `.env` `AI_DEFAULT_BUDGET_USD=10`), s'applique aussi à l'admin.
- Contrôle **avant** l'appel : refus si `dépensé du mois + estimation max
  de l'appel` > budget (`402`/`429` avec `code: AI_BUDGET_EXCEEDED`) ;
  coût réel enregistré après. Garde le principe anti-course actuel
  (réservation puis ajustement).
- Plafond global de sécurité : `AI_GLOBAL_BUDGET_USD_MONTHLY=30` — coupe le
  module IA pour tous si atteint (protection contre une fuite de compte).
- `GET /modules` et une route `GET /ai/usage` exposent dépensé / budget /
  date de remise à zéro.

### E. Caps de ressources

Sans plans, les caps de `CmemPlans` (`max_contacts`, `max_storage_mb`,
`max_tasks`, `max_calendars`, `max_groups`, `max_group_members`, …)
deviennent des paramètres à trois niveaux (acté 2026-11-08) :

1. **Défaut** : grille en config (`src/access/Config/ResourceCaps.php`),
   reprise de l'actuel plan `monthly`.
2. **Par groupe** : table `group_resource_caps` (`group_id`, `cap_key`,
   `value`) — s'applique aux ressources du groupe.
3. **Par utilisateur** : table `user_resource_caps` (`user_id`, `cap_key`,
   `value`) — s'applique aux ressources personnelles.

- Résolution : ressource de groupe → surcharge du groupe, sinon défaut ;
  ressource personnelle → surcharge de l'utilisateur, sinon défaut.
  `NULL` = illimité explicite (à éviter, mais possible pour l'admin).
- `EntitlementService::checkQuota()` réécrit sur cette résolution
  (contrat de réponse `403` inchangé pour les clients).
- Endpoints admin : `GET/PUT /admin/users/{id}/caps`,
  `GET/PUT /admin/groups/{id}/caps`, `GET /admin/caps/defaults`.

### F. Retrait de Stripe et Play Store

- Déplacer le métier hors des namespaces de paiement :
  `EntitlementService`, `CmemModules` → module `src/access/`
  (`Access\Services\EntitlementService`, `Access\Config\CmemModules`).
- Extraire `AndroidDevice` (lien d'appareil puzzle) vers `src/webdevice/`
  ou `src/puzzle/` avant suppression de `src/playstore/`.
- Supprimer : `src/stripe/`, `src/playstore/`, `src/cron/expire_stripe.php`,
  routes `/v2/billing/*`, `/v2/subscriptions/*`, variables `STRIPE_*`
  / Play de `.env.example`, dépendance composer `stripe/stripe-php` (et
  Google Play si présente).
- `AccessService::getMatrix()` réécrit sur `access_status` + grants.
- Tests à retirer : `test_stripe_v2.php`, `test_stripe_group_billing.php`,
  `test_stripe_webhooks.php`, `test_subscriptions.php`, `test_playstore.php`,
  `setup_stripe_portal_test.php` ; `test_plans.php` et `test_access.php`
  réécrits.
- Tables `stripe_*` / `subscriptions` / Play : supprimées par migration
  (après export de sauvegarde — données sandbox uniquement).
- Docs : `docs/*/API_*_ENDPOINTS.json`, `docs/entrypoints.md`, guides.

## Maintenances à prévoir

- Mettre à jour `AiPricing.php` à chaque changement de modèle ou de tarif
  Anthropic.
- Revue mensuelle de `ai_usage` (coût réel vs budgets).
- Purge des invitations expirées (cron léger ou au fil des lectures).

## Phases d'implantation (ordre de priorité)

### Phase 1 — Budget IA en dollars

C'est le seul coût réel : à protéger en premier, indépendamment du reste.

- **Actions** : `AiPricing.php`, table `ai_usage`,
  `users.ai_budget_usd_monthly`, plafond global, contrôle dans
  `AiController`, `GET /ai/usage`.
- **Enjeux** : estimation pré-appel fiable (borne `max_tokens`) ; ne
  jamais laisser passer un appel non budgété.
- **Tests** : `test_ai_budget.php` — appel sous budget OK et coût
  enregistré ; appel refusé au-delà ; admin aussi plafonné ; plafond
  global coupe tout ; remise à zéro mensuelle.
- **Terminé quand** : tests verts, `test_ai_summarize.php` adapté.

### Phase 2 — Statut d'approbation + invitations

- **Actions** : `users.access_status`, `access_invitations`, garde
  `ACCOUNT_PENDING` / `ACCOUNT_SUSPENDED`, endpoints admin, courriels.
- **Enjeux** : aucun compte existant bloqué (migration en `approved`) ;
  la garde doit couvrir **tous** les modules (middleware central, pas
  par contrôleur).
- **Tests** : `test_access_approval.php` — inscription → pending →
  403 ; approbation → accès ; invitation → approved direct avec
  modules/budget ; suspension.
- **Terminé quand** : tests verts + suite complète verte.

### Phase 3 — Modules cochés par personne

- **Actions** : `user_module_grants`, `CmemModules::isAvailable()` lit
  les grants au lieu du plan, endpoints admin, migration des comptes
  existants (grants dérivés de leur plan effectif actuel).
- **Enjeux** : ne retirer aucun module à un compte existant au
  déploiement.
- **Tests** : `test_modules.php`, `test_group_modules.php` adaptés ;
  module non coché → 403.
- **Terminé quand** : plus aucune lecture de plan pour la disponibilité.

### Phase 3 bis — Caps de ressources à trois niveaux

- **Actions** : section E — `ResourceCaps.php`, `group_resource_caps`,
  `user_resource_caps`, résolution dans `checkQuota()`, endpoints admin.
- **Enjeux** : aucun compte existant ne doit se retrouver au-dessus de
  son cap au déploiement (défaut = ancien plan `monthly`, plus large que
  `free`).
- **Tests** : `test_resource_caps.php` — défaut appliqué ; surcharge
  utilisateur prioritaire sur ressource perso ; surcharge groupe
  prioritaire sur ressource de groupe ; `max_contacts` existant
  (`test_contacts.php`) toujours vert.
- **Terminé quand** : plus aucune lecture de `CmemPlans` pour les caps.

### Phase 4 — Retrait Stripe / Play Store

- **Actions** : section F.
- **Enjeux** : suite complète verte après déplacement des namespaces,
  avant toute suppression ; puzzle doit continuer à lier ses appareils.
- **Tests** : `run_all_tests.php` complet, `test_puzzle_*` inclus.
- **Terminé quand** : plus aucune référence `Stripe\` / `Playstore\`
  dans `src/`, composer nettoyé, docs à jour.

### Phase 5 — Directives clients

- **Actions** (skill `directive`) :
  - **cmem_web** : retirer checkout/portail/upgrade ; écran « compte en
    attente » ; console admin (approbations, invitations, modules
    cochés, budget IA, suivi dépense).
  - **jdb** : retirer l'affichage statut/CTA abonnement Stripe.
  - **XMGame** : retirer `createCheckoutSession` / `createPortalSession`,
    gérer `ACCOUNT_PENDING`.
- **Terminé quand** : 3 tasks créées, taguées `directive`,
  `from-cmem2_API`.

### Phase 6 — Fiche catalogue et version

- Version mineure (rupture de contrat : routes billing retirées) —
  `CHANGELOG.md`, `x.y.z_CLIENT.md` avec BREAKING CHANGES, fiche
  catalogue jdb (item 25) sans mention de prix.

## Points ouverts

Tous tranchés le 2026-11-08 (voir table des décisions en tête).
