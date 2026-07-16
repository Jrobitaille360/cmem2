---
titre: Monétisation Stripe + caps serveur + purge RGPD + suppression compte OTP (phase 7a)
directive_source: /c/code/directives_inter_projet/20260715_140000_cmem_web_vers_cmem2_API__monetisation-stripe-caps-groupes-phase7a.md
statut: phase 1+2 complétées 2026-07-15 — phases 3-7 restantes
---

# PLAN — Monétisation + caps + RGPD (phase 7a)

## Contexte

Directive reçue de `cmem_web` (rewrite React, phase 7). Le rewrite consomme déjà
`POST /v2/billing/checkout|portal|webhook`, `GET /v2/subscriptions/stripe/status`,
`DELETE /v2/subscriptions/stripe`, mais rien de ce que ces endpoints exposent ne porte
aujourd'hui de notion de caps/quotas cmem, et deux bugs bloquants (mot de passe requis pour
supprimer un compte OTP, aucune purge RGPD) empêchent l'UI 7b de se construire.

## État des lieux (vérifié dans le code, 2026-07-15)

### Table `plans` — inutilisable telle quelle

`plans` (`docs/v-2-8-0/build_DB-v-2.8.0.sql:368-381`) n'a **pas de colonne `app_id`**. Les 4
lignes seed (`free/bronze/argent/platine`) portent un schéma `features` générique
(`scopes`, `max_requests_per_day`, `expires_in_days`, support email/priority) — un système de
rate-limiting par clé API, sans rapport avec caps calendriers/groupes/stockage. `users.plan_id`
pointe vers cette table legacy — pas de recouvrement exploitable avec Stripe.

La vraie source de vérité pour l'abonnement puzzle est `stripe_subscriptions`
(`user_id, app_id, stripe_customer_id, stripe_subscription_id, plan, status, expires_at, ...`,
unique `(user_id, app_id)`) — table déjà scopée par app, mais sans `features`/caps JSON.

→ **Décision actée (2026-07-15, retour requérant)** : `plans`/`app_plans` en DB pas nécessaire.
Stripe est déjà fonctionnel (puzzle le prouve) — caps par plan = **config PHP statique**
(array `app_id → code_plan → features`), pas de table. Pas de migration, pas de risque DB,
ajustable par déploiement de code. Seule donnée qui reste en DB : l'état Stripe
(`stripe_subscriptions`, déjà là) + l'override manuel "Ami" (voir plus bas).

### StripeService — `app_id='puzzle'` par défaut

`StripeService.php:168` et `:210` : fallback `'puzzle'` seulement si les métadonnées Stripe
(session/subscription) n'ont pas de `app_id` — jamais côté création. `createCheckoutSession()`
(`StripeService.php:16-52`) exige `appId` explicite du contrôleur ; si `BillingController`
passe toujours `app_id` reçu du client, le risque décrit dans la directive ne se matérialise
que si un appel omet le champ. → point de vérif : forcer `app_id` requis (Validator) côté
`BillingController::checkout()`, jamais de valeur par défaut silencieuse.

`price_id` : constantes d'env **puzzle-only**, pas de pattern générique
(`STRIPE_PRICE_PUZZLE_MONTHLY/YEARLY`, `environment.php:365-369`). Ajouter
`STRIPE_PRICE_CMEM_MONTHLY` / `STRIPE_PRICE_CMEM_YEARLY` suit la convention existante — pas de
raison de la casser pour une table de config DB à ce stade.

### `DELETE /users/me` — confirmé cassé pour OTP

`UserManagerController.php:253-284` exige `password` (`Validator required|string`) +
`password_verify()` contre `password_hash`. Or l'auto-register OTP
(`AuthController.php:146`) fixe `password_hash = password_hash(random_bytes(32), BCRYPT)` —
un mot de passe aléatoire que l'utilisateur ne connaît jamais. **Suppression de compte OTP
impossible aujourd'hui, confirmé, pas une supposition.**

Bonne nouvelle : `SoftDeleteTrait` existe déjà (`users.deleted_at`), le self-delete est déjà
toujours soft (`force_delete = false` forcé sur ce chemin). Il suffit de retirer l'exigence de
mot de passe pour le cas `currentUserId === userId`.

### Purge RGPD — absente, confirmé

`cleanup.php` : OTP expirés, JWT blacklist, rate limits. `MaintenanceService::run()` : 10
purges (notifications, stats, invitations, login attempts, OTP, JWT, device tokens, email
verif, password reset, sessions) — **aucune ne touche `users`**. Comptes soft-deleted restent
indéfiniment.

### `GET /auth/me` — pas de plan exposé

`AuthController::me()` (`AuthController.php:387-405`) retourne toutes les colonnes `users`
sauf `password_hash` (incluant `plan_id`/`payment_status`/`payment_plan` — colonnes du système
legacy, sans rapport avec Stripe). Aucun join `stripe_subscriptions`.

### Comptage appareils — deux candidats confirmés

`device_tokens` (auth/OTP, refresh) — **pas de colonne `app_id`**, partagé toutes apps.
`web_devices` (`src/webdevice/`) et `android_devices` — **ont `app_id`**, scopés
`(app_id, device_uuid)` + index `(user_id, app_id)`.
→ **Décision : `web_devices` est le bon point de comptage** pour cmem (web/PWA) — scopé par
app, contrairement à `device_tokens` qui compterait les appareils de toutes les apps de
l'utilisateur (puzzle + cmem + traque...) contre le cap cmem, ce qui serait faux.

### Groupes — cap membres existe, cap nombre-de-groupes n'existe pas

`groups.max_members` (colonne par groupe, 1-1000, défaut 50) déjà enforced à l'acceptation
d'invitation (`GroupInvitationController.php:270-281`). Mais c'est un cap **par groupe fixé à
la création**, pas dérivé du plan du possédant — actuellement rien n'empêche de créer un
groupe avec `max_members=1000` sur un compte Gratuit.
`MAX_GROUPS_PER_USER` (`environment.php:235`, défaut 10) est **défini mais jamais lu nulle
part** — confirmé par grep, aucune référence hors définition. Aucun cap sur le nombre de
groupes possédés aujourd'hui.

### Calendriers/journaux/tâches — pas de scoping app_id nécessaire

`calendars`, `calendar_events`, et les tables journaux/tâches (`CalendarJournal`,
`CalendarTodo` — module ICS entièrement dédié à cmem) n'ont pas de colonne `app_id` : le
module ICS *est* cmem, pas de risque de compter les objets d'une autre app.

## Décisions d'architecture proposées

1. **Pas de table `plans`/`app_plans` en DB** — Stripe fonctionne déjà (puzzle le prouve),
   pas de raison de réinventer. Caps par plan = **config PHP statique**
   (`src/stripe/Config/CmemPlans.php` ou équivalent, array `code_plan → features`), versionnée
   en git, zéro migration, zéro risque DB. Codes de plan = ceux déjà utilisés par
   `stripe_subscriptions.plan` (`monthly`/`yearly`) + `free`/`ami` pour les deux cas sans
   abonnement Stripe actif.
2. **`price_id` en constantes d'env** (`STRIPE_PRICE_CMEM_MONTHLY/YEARLY`), suit le pattern
   puzzle existant.
3. **Comptage appareils via `web_devices`** (scopé `app_id`), pas `device_tokens`.
4. **Cap `max_groups`** enforced à `GroupManagerController::create()` (nouveau check, compte
   des `groups.owner_id = user` non supprimés).
5. **Cap `max_group_members`** : appliqué à la création du groupe en **plafonnant** la valeur
   demandée pour `max_members` au cap du plan du possédant (pas seulement au moment de
   l'invitation) — évite de contourner en demandant `max_members=1000` sur un compte Gratuit.
6. **Override manuel "Ami"** : seule donnée qui doit exister en DB au-delà de
   `stripe_subscriptions` — une colonne `users.cmem_plan_override` (nullable varchar, ex.
   `'ami'`), posée manuellement par un admin. Pas de table, une colonne suffit.
7. **Résolution du plan effectif** (`/auth/me`) : ordre de priorité —
   `stripe_subscriptions` actif pour `app_id='cmem'` **>** `users.cmem_plan_override` **>**
   `free` par défaut. Un abonnement Stripe actif gagne toujours sur l'override Ami s'il existe
   (cas limite mentionné en directive point 8) — à confirmer avec `cmem_web`, hypothèse
   raisonnable mais pas actée côté produit.
8. **`DELETE /users/me`** : retirer l'exigence de mot de passe pour le chemin self-delete
   (JWT déjà validé par le middleware d'auth = premier facteur suffisant, cohérent avec le
   reste de l'API qui n'a pas de second facteur système pour les comptes OTP).

## Valeurs de caps — actées par `cmem_web` le 2026-07-15

Règle verrouillée : `max_journals = max_tasks / 2` (cohérence avec le ratio acté 50/100 sur
Gratuit). Valeurs mergées dans `src/stripe/Config/CmemPlans.php` :

| Plan | `max_calendars` | `max_journals` | `max_tasks` | `max_devices` | `max_storage_mb` | `max_groups` | `max_group_members` |
| - | - | - | - | - | - | - | - |
| Gratuit | 3 | 100 | 200 | 2 | 100 | 1 | 5 |
| Mensuel | 25 | 2500 | 5000 | 5 | 2000 | 10 | 50 |
| Annuel | 25 | 2500 | 5000 | 5 | 2000 | 10 | 50 |
| Ami | 25 | 2500 | 5000 | 5 | 2000 | 10 | 50 |

## Point ouvert non résolu par la directive

Cap stockage (`max_storage_mb`) : `files.file_size` existe mais la table `files` n'a **pas**
de colonne `app_id` — un utilisateur avec des fichiers kestyon/puzzle partagerait le même total
que ses pièces jointes cmem. Vérifier au moment de l'implémentation si les fichiers cmem sont
distinguables (via jointure sur une table de liaison scopée cmem) avant d'enforcer ce cap — sinon
le cap `max_storage_mb` compterait du stockage hors-cmem contre le quota cmem, ce qui serait
un bug, pas juste une approximation.

## Phases

### Phase 1 — Stripe CAD (mode test) + config price_id

**Actions**

- Créer produits/prix Stripe CAD en mode test (dashboard) : Mensuel 5$/30j, Annuel 50$/365j.
- Ajouter `STRIPE_PRICE_CMEM_MONTHLY`/`STRIPE_PRICE_CMEM_YEARLY` à `.env`, `.env.example`,
  `environment.php` (même pattern que `STRIPE_PRICE_PUZZLE_*`).
- `BillingController::checkout()` : router le `price_id` selon `app_id` reçu (actuellement
  probablement hardcodé puzzle — à vérifier au moment du code) + rendre `app_id` obligatoire
  (Validator), plus de fallback silencieux.

**Enjeux** : ne pas casser le flux puzzle existant en généralisant le sélecteur de price_id.

**Tests** : `POST /v2/billing/checkout {app_id:cmem, plan:monthly}` → session Stripe test avec
le bon `price_id` et `metadata.app_id=cmem` ; test de non-régression puzzle inchangé.

**Complétion** : price_id cmem configurés, checkout cmem retourne une session test valide,
suite puzzle toujours verte.

### Phase 2 — Config PHP des plans/caps cmem (pas de DB)

**Actions**

- Créer `src/stripe/Config/CmemPlans.php` (ou équivalent) : array statique
  `['free' => [...features], 'monthly' => [...], 'yearly' => [...], 'ami' => [...]]`.
- Migration `docs/20260715_users_cmem_plan_override.sql` : `ALTER TABLE users ADD COLUMN
  cmem_plan_override VARCHAR(20) NULL` (seule colonne DB nécessaire, pour le plan "Ami").
  Fichier neuf dans `docs/`, ne touche pas `build_DB-v-2.8.0.sql`.
- **Valeurs de caps validées par `cmem_web` (2026-07-15) — mergées.** Migration
  `cmem_plan_override` appliquée en dev (confirmation explicite utilisateur).

**Enjeux** : garder les codes de plan alignés avec `stripe_subscriptions.plan`
(`monthly`/`yearly`) pour que la résolution reste une simple lecture, pas un mapping.

**Tests** : la config charge sans erreur ; lookup `CmemPlans::get('monthly')` retourne les
bonnes valeurs ; colonne `cmem_plan_override` s'ajoute proprement sur une copie de dev.

**Complétion** : ✅ fichier de config mergé, migration colonne appliquée en dev.

### Phase 3 — Enforcement des caps

**Actions**

- Helper `EntitlementService::getEffectivePlan(userId, appId)` — résout
  `stripe_subscriptions` + `users.cmem_plan_override` + config `CmemPlans` selon l'ordre
  décrit plus haut.
- Check `403 QUOTA_EXCEEDED` avant création : calendriers, journaux, tâches (tous calendriers
  confondus), stockage (upload), groupes possédés, membres de groupe (plafond à la création +
  re-check à l'invitation existant conservé).
- Comptage appareils via `web_devices` scopé `app_id='cmem'`.

**Enjeux** : point ouvert stockage (voir ci-dessus) à trancher avant d'enforcer ce cap
spécifique — les autres caps n'ont pas ce problème.

**Tests** : un test par ressource — création refusée au cap, acceptée juste en-dessous,
message d'erreur avec `resource` + `limit` corrects.

**Complétion** : les 4 conditions de complétion caps de la directive cochées, code
`QUOTA_EXCEEDED` unique et cohérent partout.

### Phase 4 — CRON purge RGPD

**Actions**

- `MaintenanceService::purgeDeletedUsers()` — hard delete `users` où `deleted_at < NOW() -
  30j`. Vérifier les FK/cascades (fichiers possédés, groupes possédés, calendriers possédés)
  avant d'activer — un hard delete sur `users` avec des FK `RESTRICT` échouera silencieusement
  ou bruyamment selon la config ; à tester en dev d'abord.
- Appeler dans `run()` aux côtés des 10 purges existantes, ou cron dédié si le volume/risque
  justifie une isolation (à trancher au moment du code selon ce que révèle l'audit FK).

**Enjeux** : suppression irréversible — tester abondamment en dev avant tout run en prod ;
confirmer qu'aucune donnée cross-app n'est perdue par erreur pour un compte multi-app
(user actif sur puzzle mais soft-deleted côté cmem ne doit pas arriver — `deleted_at` est sur
`users`, global, pas par app — à vérifier que ce n'est pas un problème produit).

**Tests** : compte soft-deleted 31j → purgé ; compte soft-deleted 29j → conservé ; compte actif
→ jamais touché.

**Complétion** : cron en place, testé en dev, **pas activé en cron prod sans confirmation**
(changement `.env`/crontab serveur = STOP explicite selon règles du projet).

### Phase 5 — `DELETE /users/me` sans mot de passe

**Actions**

- `UserManagerController.php:253-284` : retirer la validation `password` requise pour
  `currentUserId === userId`. Garder `force_delete=false` (déjà le cas).

**Enjeux** : sécurité — perte du 2e facteur pour la suppression. JWT déjà validé en amont
(middleware) ; envisager option OTP de re-confirmation seulement si `cmem_web` le juge
nécessaire côté produit (la directive laisse ce choix ouvert).

**Tests** : `DELETE /users/me` sans `password` dans le body → 200, `deleted_at` posé ;
vérifier qu'un attaquant avec JWT volé ne gagne pas plus qu'avant (le JWT donnait déjà accès
complet au compte).

**Complétion** : bouton suppression débloquable côté client, test dédié vert.

### Phase 6 — `/auth/me` plan effectif + doc résolution entitlement

**Actions**

- `AuthController::me()` : ajouter `plan: {code, features, source}` via
  `EntitlementService::getEffectivePlan()`.
- Documenter dans `docs/stripe/GUIDE.md` (ou équivalent) l'ordre de résolution
  Stripe > manuel > gratuit, pour que `cmem_web` n'ait pas à agréger 3 appels.

**Enjeux** : si Phase 3 (EntitlementService) prend du retard, ce point peut être livré en
report explicite (repli documenté = les 3 appels existants), conformément à la directive.

**Tests** : `/auth/me` retourne le bon plan pour compte gratuit / stripe actif / override Ami.

**Complétion** : champ `plan` présent et correct, ou report explicite documenté.

### Phase 7 — Vérification `app_id='cmem'` bout en bout

**Actions**

- Test dédié : checkout → webhook → `stripe_subscriptions.app_id='cmem'` bien posé, jamais
  `'puzzle'` par défaut. Revue de code des 2 fallbacks (`StripeService.php:168,210`) pour
  confirmer qu'ils ne se déclenchent que si `BillingController` a un bug (donc rendre `app_id`
  obligatoire côté validator = filet de sécurité réel, pas juste documentation).

**Tests** : simulateur webhook Stripe CLI avec metadata cmem → ligne `stripe_subscriptions`
correcte ; test négatif (metadata manquante) → erreur explicite plutôt que fallback silencieux
vers puzzle.

**Complétion** : test dédié vert, code review confirmée.

## Conditions de complétion globales (reprises de la directive)

- [ ] Produits/prix Stripe CAD créés (mode test), `price_id` référencés côté serveur.
- [ ] Config des caps `CmemPlans` validée par `cmem_web` — **puis** mergée, et migration
      `cmem_plan_override` exécutée (pas avant confirmation explicite).
- [ ] Caps enforced sur les 7 ressources, point de comptage appareils documenté
      (`web_devices`).
- [ ] CRON de purge RGPD en place, testé en dev, **pas activé en prod sans confirmation**.
- [ ] `DELETE /users/me` fonctionne sans mot de passe.
- [ ] Plan effectif exposé dans `/auth/me`, ou report explicite documenté.
- [ ] `app_id='cmem'` confirmé de bout en bout, test dédié vert.
- [ ] Tests : caps par ressource, purge RGPD >30j uniquement, suppression compte OTP,
      metadata Stripe `app_id='cmem'`.
