# PLAN — Suppression de compte : purge physique après 30 jours (Loi 25)

> Source : directive `20260729_220000_cmem_web_vers_cmem2_API__suppression-compte-purge-30-jours.md`
> Décisions tranchées le 2026-08-02 (§2, §3, §4, périmètre) — consignées dans la directive.
> Échéance ferme : **2026-08-31** (Phase C cmem_web, conformité Loi 25 QC).

## Résumé

Le client cmem_web a publié une politique de confidentialité qui promet un effacement définitif
après 30 jours de grâce. L'API doit rendre cette promesse exacte, libérer le courriel après purge,
poser un filet Stripe sur la suppression, et documenter le périmètre effacé pour recopie dans la
politique.

**Correction au contexte de la directive** — la directive affirme qu'« il n'existe aucun mécanisme
d'effacement différé ». C'est faux : `AuthGroups\Services\MaintenanceService::purgeDeletedUsers()`
exécute déjà `DELETE FROM users WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL
30 DAY`, via `src/cron/maintenance.php`. Le travail consiste donc à **durcir un mécanisme existant**,
non à en créer un. Cela retire l'essentiel du §1 et réduit le risque de la livraison.

## Grandes lignes

### 1. Purge physique à 30 jours

**Déjà en place**

- `MaintenanceService::purgeDeletedUsers()` — hard delete des `users` soft-deleted depuis > 30 jours,
  avec mode `--dry-run` et remontée dans le rapport de maintenance.
- `src/cron/maintenance.php` — orchestrateur CLI, lock file, rapport courriel, ordre FK-safe.
- Cascade FK vers `users(id)` sur la quasi-totalité des tables usager : `calendars`,
  `calendar_events`, `event_occurrences`, `calendar_todos`, `calendar_journals`, `projects`,
  `task_dependencies`, `contacts`, `contact_shares`, `interaction`, `opportunite`, `items`,
  `item_user_access`, `links`, `tags`, `group_members`, `group_invitations`, `push_subscriptions`,
  `notification_prefs`, `user_app_setup`, `user_sessions`, `user_stats_snapshot`, `notifications`,
  `email_verifications`, `password_resets`, `traque_*`, `stripe_subscriptions`.

**Améliorations à faire**

- **Fichiers téléversés** — `files.uploaded_by` n'a **aucune contrainte FK** : les lignes survivent
  à la purge, et le fichier sur disque n'est jamais retiré. Avant le `DELETE FROM users`, lire les
  `files` de l'usager, `unlink()` chaque `file_path` (préfixe racine projet, cf.
  `FileController` ligne 425), puis supprimer les lignes. Les `file_tag_relations` partent en
  cascade depuis `files`.
- **Groupes** — `groups.owner_id` est en `ON DELETE CASCADE` : la purge détruit aujourd'hui un
  groupe partagé encore vivant. Avant la purge : si le groupe compte d'autres membres actifs,
  transférer la propriété au membre le plus ancien ; sinon, supprimer le groupe.
- **Puzzle** — `puzzle_shared.creator_id` et `partner_id` sont `NOT NULL` en CASCADE. Avant la
  purge, réattribuer la colonne de l'usager purgé au partenaire survivant
  (`creator_id = partner_id = survivant`) pour conserver la ligne, ses `puzzle_shared_pieces` et ses
  `puzzle_shared_events`. Les `held_by_id` / `by_id` / `device_id` passent à NULL d'eux-mêmes.
- **Facturation** — recopier les `stripe_subscriptions` de l'usager dans `billing_archive`
  (anonymisée) avant que le CASCADE ne les emporte.
- **Tables sans FK** — `otp_codes`, `login_attempts`, `device_tokens`, `pomo_engagements` :
  suppression explicite par courriel ou `user_id` selon la table.
- **Journalisation** — un décompte par usager purgé (`user_id`, nombre de lignes par domaine,
  fichiers disque retirés), exigé par la directive.
- **Idempotence** — la purge d'un usager déjà purgé ne doit produire aucune erreur ; l'ordre des
  opérations doit être rejouable après interruption.

**Maintenance à prévoir**

- Toute nouvelle table portant un `user_id` doit soit déclarer une FK CASCADE, soit être ajoutée
  explicitement au service de purge. À inscrire dans `CLAUDE.md`.
- Vérifier annuellement que la liste des tables du service correspond au schéma réel.

### 2. Libération du courriel et délai de grâce

**Déjà en place**

- `users.email` porte un `UNIQUE KEY` — la recréation pendant la grâce échoue.
- `User::findByEmail()` filtre `deleted_at IS NULL` ; `AuthController::sendCode()` répond `200`
  générique pour un compte supprimé (anti-énumération) et auto-inscrit un courriel inconnu.
- `SoftDeleteTrait::restore()` existe mais n'est appelé par aucune route.

**Trou de sécurité découvert en phase 2 — le JWT survit à la suppression**

`JwtAuthMiddleware::authenticate()` ne consulte jamais la base : il valide la signature et
l'expiration du payload, rien d'autre. Un JWT émis avant `DELETE /users/me` reste donc accepté sur
toutes les routes JWT **jusqu'à 15 jours** après la suppression, et continuerait d'authentifier un
`user_id` inexistant après la purge physique. Confirmé par le test 1.5 (`auth/me` répond `200` sur
un compte supprimé).

La directive affirme que « l'accès est bien coupé » : c'est vrai d'une **nouvelle** connexion
(`AuthService` refuse un `deleted_at` non nul), faux d'un jeton déjà émis. Correction à livrer en
phase 4 : le middleware doit vérifier que le compte existe et n'est pas supprimé (lecture indexée
sur `users.id`), sinon `401`.

**Améliorations à faire**

- `POST /auth/send-code` sur un compte en grâce → `409`, `error_code:
  ACCOUNT_PENDING_DELETION`, `data.purge_scheduled_at` (= `deleted_at + 30 jours`, ISO 8601 UTC).
  Le 409 reste soumis au rate limit `send-code` existant.
- Voie de restauration usager : `POST /auth/restore-account` `{ email }` envoie un OTP (réponse
  générique), `POST /auth/restore-account/verify` `{ email, code }` restaure puis émet un JWT. Un
  `POST /auth/login` valide sur un compte en grâce restaure de la même façon.
- Voie de restauration admin : **déjà livrée** — `POST /users/{id}/restore`
  (`UserRouteHandler` ligne 95 → `UserManagerController::restore`), refus `403` pour un
  `UTILISATEUR`. Vérifié par les tests 3.6 à 3.8 de la phase 2, aucun code à écrire.
- Après purge, l'adresse redevient libre — garanti par la suppression physique de la ligne `users`.

**Maintenance à prévoir**

- Le délai de 30 jours devient une constante unique (`ACCOUNT_PURGE_GRACE_DAYS`), lue à la fois par
  la purge et par le calcul de `purge_scheduled_at`. Aucune valeur en dur dans deux endroits.

**Réserve consignée** — le 409 explicite révèle à un tiers qu'une adresse est enregistrée
(énumération de courriels), alors que le 200 générique actuel existe pour l'empêcher. Décision
maintenue par l'utilisateur ; atténuation = rate limit.

### 3. Filet Stripe sur `DELETE /users/me`

**Déjà en place**

- `DELETE /v2/subscriptions/stripe` → `StripeSubscriptionService::cancel()` →
  `StripeService::cancelSubscription()`, qui pose `cancel_at_period_end = true` (pas de résiliation
  immédiate : la période payée va à son terme, sans nouveau prélèvement).
- `UserManagerController::delete()` ne consulte Stripe à aucun moment.

**Améliorations à faire**

- Sur `DELETE /users/me` : chercher un abonnement actif (`app_id = 'cmemweb'`) ; aucun → poursuivre ;
  trouvé → annuler ; échec de l'appel Stripe → `409` `STRIPE_CANCEL_FAILED`, aucun `deleted_at`
  posé, erreur journalisée.
- Réponse enrichie : `data.purge_scheduled_at` en plus de `deleted: true`.
- Webhooks : traités normalement pendant la grâce ; après purge, compte introuvable → journaliser et
  répondre `200` pour que Stripe cesse de réessayer.

**Maintenance à prévoir**

- Si d'autres `app_id` deviennent payants, la recherche d'abonnement doit couvrir tous les `app_id`
  de l'usager, pas seulement `cmemweb`.

### 4. Documentation transmise à cmem_web

**Déjà en place** — `docs/entrypoints.md`, `docs/*/API_*_ENDPOINTS.json`, guides par module.

**Améliorations à faire** — liste définitive « effacé / conservé anonymisé », recopiable mot pour
mot dans la politique de confidentialité :

| Donnée | Sort | Raison |
| - | - | - |
| Calendriers, événements, occurrences, journaux, tâches | Effacé | Donnée personnelle |
| Projets, dépendances de tâches | Effacé | Donnée personnelle |
| Contacts, interactions CRM, opportunités | Effacé | Donnée personnelle |
| Fichiers téléversés (base et disque) | Effacé | Donnée personnelle |
| Liens `/links`, étiquettes | Effacé | Donnée personnelle |
| Compte, profil, préférences, sessions | Effacé | Donnée personnelle |
| Jetons d'appareil, abonnements Web Push et préférences | Effacé | Donnée personnelle |
| Données de jeu traque | Effacé | Donnée personnelle |
| Adhésion aux groupes partagés | Effacé | L'adhésion part, le groupe des autres survit |
| Groupe dont l'usager est seul membre | Effacé | Plus aucun membre |
| Partie de casse-tête partagée | Conservée chez le partenaire | Donnée conjointe, sans trace de l'usager parti |
| Registres de facturation Stripe | Conservés anonymisés | Obligation fiscale ; dissociés de l'identité |
| Journaux techniques (adresse IP) | Conservés 12 mois maximum | Sécurité et diagnostic — **à confirmer** |

Le dernier point (12 mois) est annoncé par le client mais n'est pas encore garanti par un mécanisme
de rotation vérifié côté API : à valider en phase 5 avant recopie dans la politique.

## Phases d'implantation

### Phase 1 — Migrations SQL

**Actions**

1. Créer `docs/20260802_suppression_compte_purge.sql` :
   - `CREATE TABLE billing_archive` — `app_id`, `stripe_customer_id`, `stripe_subscription_id`,
     `plan`, `status`, `is_trial`, `trial_end`, `expires_at`, `cancel_at_period_end`,
     `subscribed_at`, `archived_at`. **Aucun** `user_id`, courriel ni nom.
     `stripe_subscriptions` ne stocke ni montant ni devise — ils vivent chez Stripe, et les
     identifiants Stripe conservés sont les clés de rapprochement comptable.
   - Aucun index à ajouter sur `files(uploaded_by)` : `idx_file_uploaded_by` couvre déjà cette
     lecture. Une première version de la migration en créait un second (`idx_files_uploaded_by`),
     retiré de dev et de production le 2026-08-02 — vérifier les index existants avant d'en ajouter.
2. Ne pas toucher `docs/v-2-11-0/build_DB-v-2.11.0.sql` (version fixée). Intégration au prochain
   `build_DB` lors de l'ancrage de version.

**Enjeux** — aucune FK ajoutée sur `files.uploaded_by` : des lignes orphelines antérieures
existent probablement, une contrainte échouerait à la création. La purge les traite par code.

**Tests** — application de la migration sur la base de dev, puis `SHOW CREATE TABLE billing_archive`.

**Fin de phase** — migration appliquée en dev, idempotente (`CREATE TABLE IF NOT EXISTS`).

> **STOP obligatoire** — confirmation explicite de l'utilisateur avant toute exécution de cette
> migration, en dev comme en production.

### Phase 2 — Tests en échec (spec-first)

**Actions**

1. Créer `private/tests/test_account_deletion.php` couvrant les critères d'acceptation :
   - `DELETE /users/me` renvoie `deleted: true` et `purge_scheduled_at` à J+30 ;
   - connexion impossible après suppression ;
   - `POST /auth/send-code` sur compte en grâce → `409 ACCOUNT_PENDING_DELETION` + date ;
   - `POST /auth/restore-account` + `/verify` restaurent le compte et émettent un JWT ;
   - `POST /users/{id}/restore` en admin restaure ; en `UTILISATEUR` → `403` ;
   - `DELETE /users/me` avec abonnement Stripe actif → abonnement annulé, suppression effectuée ;
   - purge : usager forcé à `deleted_at = NOW() - 31 DAY`, exécution de la purge, puis vérification
     que la ligne `users`, ses calendriers, contacts, fichiers (base **et** disque) ont disparu ;
   - la même adresse peut créer un compte neuf après purge ;
   - groupe partagé toujours vivant après purge de son propriétaire, propriété transférée ;
   - partie `puzzle_shared` toujours lisible par le partenaire ;
   - `billing_archive` contient la ligne, sans identifiant d'usager ;
   - seconde exécution de la purge sur le même usager : aucune erreur (idempotence).
2. Exécuter et confirmer que chaque test échoue **pour la bonne raison**.
3. Ajouter le fichier à `private/tests/run_all_tests.php` et à `CLAUDE.md`.

**Enjeux** — les tests frappent un serveur réel : la purge doit être déclenchable en test sans
attendre 30 jours (helper CLI ou appel direct du service, pas d'endpoint public de purge).

**Fin de phase** — suite complète rouge sur les nouveaux tests, verte partout ailleurs.

### Phase 3 — Service de purge

**Actions**

1. Créer `AuthGroups\Services\AccountPurgeService` :
   - `purgeUser(PDO $db, int $userId, bool $dryRun): array` — retourne le décompte par domaine ;
   - ordre : archive facturation → transfert des groupes → réattribution puzzle → suppression des
     fichiers (disque puis base) → tables sans FK → `DELETE FROM users` (le CASCADE fait le reste) ;
   - transaction par usager ; les `unlink()` hors transaction, journalisés individuellement.
2. Remplacer le corps de `MaintenanceService::purgeDeletedUsers()` par une boucle sur les usagers
   éligibles appelant `AccountPurgeService`, en conservant le contrat `rows_deleted` / `dry-run`.
3. Introduire `ACCOUNT_PURGE_GRACE_DAYS` (défaut 30) dans `environment.php` et `.env.example`.

**Enjeux**

- Un `unlink()` échoué ne doit pas bloquer la purge : journaliser en `warning` et poursuivre, sinon
  un fichier verrouillé rend le compte impurgeable indéfiniment.
- Le transfert de propriété d'un groupe doit se faire **avant** le `DELETE`, sinon le CASCADE a déjà
  détruit le groupe.
- La réattribution puzzle touche des données du projet `puzzle` : directive à émettre (phase 5).

**Tests** — les tests de purge, d'idempotence, de groupe partagé, de puzzle et de `billing_archive`
de la phase 2 passent au vert.

**Fin de phase** — purge complète verte, `--dry-run` ne modifie rien, décompte par usager présent
dans le rapport de maintenance.

### Phase 4 — Endpoints

**Actions**

1. `UserManagerController::delete()` — filet Stripe (option C) et `purge_scheduled_at` dans la
   réponse.
2. `AuthController::sendCode()` — `409 ACCOUNT_PENDING_DELETION` sur compte en grâce.
3. `AuthController` — `restoreAccount()` et `restoreAccountVerify()`, plus restauration implicite
   sur `login` valide ; routes déclarées dans le routeur `auth_groups`.
4. `JwtAuthMiddleware::authenticate()` — vérifier en base que le compte existe et que
   `deleted_at IS NULL`, sinon `401`. Sans cela, un jeton émis avant la suppression reste valide
   15 jours (voir le trou de sécurité ci-dessus).
5. Webhooks Stripe — compte introuvable : journaliser, répondre `200`, ne rien réinsérer.

`POST /users/{id}/restore` existe déjà et n'a pas à être écrit.

**Enjeux**

- Ne pas régresser l'anti-énumération sur les comptes **inexistants** : `send-code` doit continuer à
  répondre `200` générique et à auto-inscrire. Seul le compte en grâce change de comportement.
- Les routes de restauration doivent refuser un compte déjà purgé (compte introuvable → réponse
  générique, jamais de recréation silencieuse par cette voie).

**Tests** — tous les tests de la phase 2 au vert ; `test_users.php`, `test_auth_otp.php`,
`test_stripe_v2.php`, `test_subscriptions.php` sans régression.

**Fin de phase** — `php private/tests/run_all_tests.php` : zéro échec.

### Phase 5 — Documentation et directives

**Actions**

1. `docs/entrypoints.md` et les `API_*_ENDPOINTS.json` concernés : nouvelles routes, `409`
   `ACCOUNT_PENDING_DELETION`, `409` `STRIPE_CANCEL_FAILED`, `purge_scheduled_at`.
2. `CHANGELOG.md` — entrée `[Unreleased]`.
3. Confirmer la rétention réelle des journaux techniques (12 mois annoncés par le client) et
   corriger le tableau du présent plan si l'API ne la garantit pas.
4. Émettre la directive `cmem2_API` → `puzzle` sur la réattribution des parties partagées.
5. Répondre à cmem_web : passer la directive à `statut: complété`, cocher les conditions, joindre le
   tableau « effacé / conservé anonymisé » et signaler la correction du § Contexte (la purge
   existait déjà).

**Fin de phase** — directive close, tableau transmis, directive puzzle déposée et indexée.

### Phase 6 — Déploiement

**Actions**

1. Commit et `private\deploy.ps1` vers `dev-cmem2`.
2. Migration appliquée en dev, puis vérification manuelle du parcours complet sur un compte jetable :
   suppression, 409 sur send-code, restauration, re-suppression, purge forcée, réinscription.
3. `src/cron/maintenance.php --dry-run` en dev : contrôler le décompte avant exécution réelle.
4. Production : migration, déploiement, première exécution en `--dry-run`, puis exécution normale.

**Enjeux** — la première exécution réelle en production purgera **tous** les comptes soft-deleted de
plus de 30 jours déjà présents. Le `--dry-run` préalable est obligatoire pour connaître le volume.

> **STOP obligatoire** — confirmation explicite avant la migration de production et avant la
> première exécution non-dry-run.

**Fin de phase** — déployé en production avant le **2026-08-31**, dry-run archivé, première purge
réelle journalisée sans erreur.

## Ordre de priorité

Phases 1 → 2 → 3 → 4 → 5 → 6, sans chevauchement. Les phases 1 et 6 comportent chacune un arrêt
obligatoire pour confirmation.
