# Plan de travail — Fuseau horaire de l'usager (`users.timezone`)

Origine : point resté ouvert à la livraison des notifications push web
(directive `cmem_web` 20260726_140426, réponse cmem2_API du 2026-07-26).

## Objectif

Persister le fuseau horaire de l'usager pour que le cron d'envoi push évalue les échéances
et la plage « ne pas déranger » à son heure locale réelle, y compris pour un compte qui
n'a aucun calendrier.

## Ce qui est déjà en place

- [DueScanner::userTimezone()](../src/push/Services/DueScanner.php) lit le fuseau du
  **premier calendrier** de l'usager (`calendars.timezone`), à défaut `America/Montreal`.
- Le fuseau sert à deux calculs, tous deux en heure locale :
  - échéance sans heure (opportunité) fixée à 00:00 dans ce fuseau ;
  - plage `quiet_from` / `quiet_to` de `notification_prefs`.
- `GET /users/me` et `PUT /users/me` existent
  ([UserRouteHandler.php:83-89](../src/auth_groups/Routing/RouteHandlers/UserRouteHandler.php#L83-L89))
  et passent par `UserManagerController::updateProfile()`, qui valide un jeu de champs fixe
  (`name`, `email`, `bio`, `phone`, `date_of_birth`, `location`).
- `User::update()` écrit une liste de colonnes figée — toute nouvelle colonne doit y être
  ajoutée explicitement.

### Limite constatée

La table `users` n'a pas de colonne `timezone`. Un usager à Paris qui n'utilise que le
pilier Contacts — donc sans calendrier — reçoit ses rappels en heure de Montréal : sa plage
`22:00 → 07:00` devient un silence de 04:00 à 13:00 chez lui, et le push tombe la nuit.

## Améliorations à faire

### 1. Colonne `users.timezone` — **nullable**, pas `NOT NULL DEFAULT`

`ALTER TABLE users ADD COLUMN timezone VARCHAR(50) NULL DEFAULT NULL AFTER location`.

Écart assumé par rapport à la formulation initiale (`NOT NULL DEFAULT 'America/Montreal'`),
pour une raison de non-régression : avec une valeur par défaut posée sur **toutes** les
lignes existantes, la colonne gagnerait immédiatement la priorité sur le calendrier, et un
usager européen ayant aujourd'hui un calendrier en `Europe/Paris` basculerait sans le savoir
en heure de Montréal. `NULL` signifie « jamais renseigné » et laisse le repli calendrier
faire son travail jusqu'à ce que le client pose la vraie valeur.

La valeur par défaut fonctionnelle (`America/Montreal`) reste appliquée en dernier recours,
côté code, comme aujourd'hui.

### 2. Lecture et écriture sur le profil

- `GET /users/me` (et `GET /users/{id}` pour un admin) retourne `timezone` — la colonne est
  reprise telle quelle par `findById()`, aucune modification nécessaire au-delà de la
  migration.
- `PUT /users/me` accepte `timezone`. Validation : identifiant IANA reconnu par PHP
  (`timezone_identifiers_list()`), sinon `422`. Champ absent du corps = valeur inchangée
  (`$input['timezone'] ?? $userData['timezone']`, comme les autres champs).
- `null` explicite est accepté : il remet l'usager sur le repli calendrier.

### 3. Priorité dans le scanner

`DueScanner::userTimezone()` : `users.timezone` → `calendars.timezone` du premier calendrier
→ `America/Montreal`. Un identifiant invalide en base (import, saisie directe) est ignoré au
profit du repli suivant, jamais propagé à `DateTimeZone`.

## Maintenances à prévoir

- La liste IANA évolue avec la version de PHP/tzdata du serveur. Un fuseau valide à
  l'écriture peut disparaître d'une version ultérieure : le repli du scanner couvre ce cas
  sans plantage, aucune purge n'est requise.
- Le prochain `build_DB-v-x-x-x.sql` doit intégrer la migration, puis le fichier
  `docs/20260726_users_timezone.sql` part dans `docs/v-x-x-x/`.
- `docs/core/API_ENDPOINTS.json` et `docs/core/GUIDE.md` mentionnent les champs de profil :
  à compléter avec `timezone`.

## Phases d'implantation

### Phase 1 — Tests d'abord

**Actions.** Créer `private/tests/test_user_timezone.php` couvrant les critères
d'acceptation ci-dessous ; l'ajouter à `private/tests/run_all_tests.php` et à la liste de
`CLAUDE.md`. Exécuter et confirmer l'échec pour la bonne raison (colonne absente).

**Enjeux.** Le cron doit être testé sur le serveur dev par SSH, comme dans
`test_push.php` : l'OpenSSL de XAMPP ne peut pas chiffrer en `aes128gcm`, donc aucun envoi
réel n'est observable en local.

**Critères d'acceptation.**

| # | Critère |
| - | - |
| 1 | `GET /users/me` d'un compte neuf expose `timezone` à `null` |
| 2 | `PUT /users/me` `{"timezone":"Europe/Paris"}` → `200`, valeur relue identique |
| 3 | `PUT /users/me` avec un fuseau inconnu (`Mars/Olympus`) → `422` |
| 4 | `PUT /users/me` avec `timezone` absent → valeur précédente inchangée |
| 5 | `PUT /users/me` `{"timezone":null}` → `200`, retour au repli |
| 6 | Cron : usager sans calendrier, `timezone = Europe/Paris`, plage silencieuse couvrant l'heure de Paris → aucune échéance |
| 7 | Cron : même usager, plage couvrant l'heure de Montréal mais **pas** celle de Paris → échéance listée (preuve que Paris fait autorité) |
| 8 | Cron : `users.timezone = NULL` + calendrier `Europe/Paris` → le calendrier fait autorité (non-régression) |

**Terminé quand.** Les 8 critères sont écrits et échouent sur l'absence de colonne, sans
erreur de syntaxe ni de configuration.

### Phase 2 — Migration

**Actions.** Écrire `docs/20260726_users_timezone.sql`. **STOP** : demander confirmation
avant application sur la base dev.

**Enjeux.** `ALTER TABLE users` sur une table centrale. Opération additive, sans réécriture
de données, sans index — verrou bref. Aucune ligne existante n'est modifiée (colonne
`NULL`).

**Tests.** Relecture de `SHOW COLUMNS FROM users` ; les suites existantes touchant `/users`
(`test_users.php`) doivent rester vertes.

**Terminé quand.** La colonne existe sur dev et `test_users.php` est inchangé dans ses
résultats.

### Phase 3 — Code

**Actions.**

1. `User` : propriété `$timezone`, colonne ajoutée à `create()` et `update()`.
2. `UserManagerController::updateProfile()` : validation IANA + affectation.
3. `DueScanner::userTimezone()` : `users.timezone` prioritaire, calendrier en repli.

**Enjeux.** `User::update()` réécrit toutes les colonnes listées : oublier `timezone` dans
la requête effacerait la valeur à chaque mise à jour de profil. Le contrôleur doit
distinguer « champ absent » (conserver) de « `null` explicite » (effacer).

**Tests.** `test_user_timezone.php` vert, puis `test_push.php` (77 assertions) pour
vérifier qu'aucun comportement push n'a bougé.

**Terminé quand.** Les deux suites passent sur dev.

### Phase 4 — Documentation et clôture

**Actions.** `docs/push/GUIDE.md` (section fuseaux), `docs/core/API_ENDPOINTS.json` et
`docs/core/GUIDE.md` (champ de profil), `CHANGELOG.md`, `CLAUDE.md` (liste des tests).
Mettre à jour la directive `20260726_161400_cmem2_API_vers_cmem_web` : le volet B est livré
côté API, il ne reste que la confirmation d'usage par le front.

**Terminé quand.** Suite complète du dépôt verte et docs à jour.

## Journal d'implantation

| Phase | Début | Fin | Résultat |
| - | - | - | - |
| 1 — Tests | 2026-07-26 16:18 | 2026-07-26 16:22 | `test_user_timezone.php` créé (38 assertions), 9 échecs sur colonne absente et validation manquante — échec attendu |
| 2 — Migration | 2026-07-26 16:23 | 2026-07-26 16:24 | `docs/20260726_users_timezone.sql` écrit ; appliqué par l'utilisateur sur **dev et prod** ; colonne vérifiée `varchar(50) NULL` |
| 3 — Code | 2026-07-26 16:24 | 2026-07-26 16:35 | `User::$timezone` + `create()`/`update()`, validation IANA dans `updateProfile()` (`422`), priorité dans `DueScanner::userTimezone()` ; déployé sur dev |
| 4 — Docs | 2026-07-26 16:36 | 2026-07-26 16:47 | `docs/core/GUIDE.md`, `docs/core/API_ENDPOINTS.json`, `docs/push/GUIDE.md`, `CHANGELOG.md`, `CLAUDE.md`, `run_all_tests.php` |

Écart au plan : deux assertions ont d'abord échoué à tort (`$u['timezone'] ?? 'absent'` ne
distingue pas `null` d'une clé absente). Le contrat serveur était correct — vérifié sur la
réponse brute de `GET /users/me` — seules les assertions ont été corrigées.

Résultat final : **38/38** sur `test_user_timezone.php`.

## Hors périmètre

- Le volet A de la directive 20260726_161400 (relance de contact) — décision produit en
  attente chez cmem_web.
- La mise à jour automatique du fuseau à chaque connexion : c'est au client de poser la
  valeur ; l'API se contente de l'accepter.
