# PLAN — Réservation publique (`booking-public`)

Origine : directive inter-projet `20260813_163000_cmem_web_vers_cmem2_API__booking-public.md`
(requérant `cmem_web`, Phase 7 du roadmap cmem, module M1 ⭐). Un usager sur plan payant publie une
page sans authentification où un invité externe réserve un créneau ; l'événement apparaît
directement au calendrier de l'hôte. Décision structurante : pas de freebusy à la volée sur
l'endpoint public — le serveur matérialise des zones (`booking_slots`) à l'avance, réservation =
écriture atomique `UPDATE ... WHERE reserved = 0`.

## Critères d'acceptation (reformulation testable de la directive)

1. `tenant_modules.booking` disponible sur `monthly`/`yearly`/`team`/`ami`, pas `free`.
2. `PUT /booking/page` avec `active: true` et module non disponible/désactivé → `403
   MODULE_NOT_AVAILABLE`.
3. `PUT /booking/page` valide `calendar_id` appartient à l'usager, `slug` unique par `app_id`,
   `availability_windows` cohérent (heures valides, `start < end`).
4. `PUT /booking/page` régénère les zones : supprime les non-réservées futures de la page,
   régénère sur `horizon_days`, exclut ce qui chevauche un événement existant (récurrence expansée
   incluse), ne touche jamais aux zones réservées.
5. `GET /booking/public/{slug}` → `404 BOOKING_UNAVAILABLE` uniforme si page inexistante, module
   indisponible (rétrogradation) ou `active = false` — les trois cas indiscernables côté réponse.
6. `GET /booking/public/{slug}/slots?from=&to=` → zones `reserved = false` dans la plage, UTC,
   plafond 60 jours par appel.
7. `POST /booking/public/{slug}/book` → écriture atomique sur la zone ; deux invités concurrents
   sur le même `slot_id` : un seul `200`, l'autre `409 SLOT_TAKEN`.
8. Réservation réussie : événement créé (`title` gabarit substitué, `description` = courriel
   invité, `status confirmed`), `cancel_token` généré, courriel de confirmation envoyé avec lien
   d'annulation.
9. `POST /booking/public/cancel/{token}` → libère la zone, annule/supprime l'événement, idempotent
   (rejouer ne casse rien), jeton inconnu/déjà utilisé → `404`.
10. Rate-limit actif sur les 3 routes publiques (ordre de grandeur 20/min `slots`, 5/min
    `book`/`cancel`).
11. Cron quotidien étend l'horizon d'un jour et resynchronise les zones non réservées contre le
    calendrier courant, sans jamais toucher aux zones réservées.
12. `DELETE /booking/page` désactive et supprime les zones non réservées ; zones réservées +
    événements liés restent intacts.

## Grandes lignes

### 1. Schéma et gating module

- **Déjà en place** : `tenant_modules.module_key` ENUM contient déjà `'booking'`
  (`docs/v-2-15-0/build_DB-v-2.15.0.sql`) — aucune migration d'ENUM nécessaire.
  `CmemModules::AVAILABLE` (`src/stripe/Config/CmemModules.php:28-34`) place `booking` uniquement
  sur `ami` aujourd'hui.
- **Améliorations à faire** : ajouter `'booking'` aux tableaux `monthly`/`yearly`/`team` de
  `CmemModules::AVAILABLE` (même patron que `ia`, `free` exclu) ; créer `booking_pages` +
  `booking_slots` via `docs/v-2-16-0/20260813_booking_public.sql`.
- **Maintenances à prévoir** : `CmemModules::KEYS` déjà alignée — rien à faire là ; si un jour
  `booking` a un quota (ex. nb réservations/mois), ajouter une entrée `QUOTAS['booking']`, hors
  scope v1 (non demandé par la directive).

### 2. Endpoints hôte (authentifiés)

- **Déjà en place** : patron complet à répliquer — `AiController.php:76-91` pour le gating
  `MODULE_NOT_AVAILABLE`, `Calendar::isOwner()` / `getUserPermissionForCalendar()`
  (`src/ics/Models/Calendar.php:311-357`) pour valider `calendar_id`.
- **Améliorations à faire** : nouveau module `src/booking/` (scaffolding complet, voir Phase 2) ;
  `GET/PUT/DELETE /booking/page`.
- **Maintenances à prévoir** : `event_title_template` — un seul placeholder supporté
  (`{guest_name}`) ; documenter clairement pour éviter des demandes d'extension non prévues en v1.

### 3. Génération / régénération de zones

- **Déjà en place** : `EventOccurrence::getExpandedOpaqueByCalendarId($calendarId, $start, $end)`
  (`src/ics/Models/EventOccurrence.php`, utilisé par `CalendarController::getFreeBusy`,
  `CalendarController.php:2411`) — moteur d'expansion de récurrence déjà éprouvé (TZID-aware,
  exceptions appliquées). C'est le bon outil pour détecter les chevauchements, pas besoin de
  réinventer un calcul de freebusy.
- **Améliorations à faire** : `BookingSlotService::regenerate(bookingPageId)` — calcule les
  créneaux bruts depuis `availability_windows` + `duration_minutes` + buffers sur `horizon_days`,
  retire ceux qui chevauchent `getExpandedOpaqueByCalendarId`, supprime les zones non réservées
  futures existantes, insère les nouvelles. Appelé par `PUT /booking/page` et par le cron (Phase 7).
- **Maintenances à prévoir** : si `horizon_days` passe au-delà du plafond 90j, revalider le coût de
  génération (nb lignes ∝ horizon × créneaux/jour) — surveiller la taille de `booking_slots`.

### 4. Endpoints publics

- **Déjà en place** : patron `app_id` (`ContactController.php:24,36-40`) — `app_id` lu via
  `Response::getRequestParams()`, défaut `'puzzle'` (la directive confirme : `cmem_web` transmet
  toujours `cmemweb`, défaut serveur reste `puzzle`, comme le reste de l'API).
- **Améliorations à faire** : `GET /booking/public/{slug}`, `GET
  /booking/public/{slug}/slots`, `POST /booking/public/{slug}/book`, `POST
  /booking/public/cancel/{token}` — tous non authentifiés (`requiresAuth = false` sur le route
  handler, patron `PomoRouteHandler.php`).
- **Maintenances à prévoir** : le `404 BOOKING_UNAVAILABLE` uniforme doit rester le seul point de
  sortie pour les 3 causes (inexistant / inactif / plan rétrogradé) — toute évolution future qui
  distinguerait ces cas romprait la protection anti-énumération de slug voulue par la directive.

### 5. Réservation, événement, courriel

- **Déjà en place** : `CalendarEvent::create()` (`src/ics/Models/CalendarEvent.php:74-179`) pour
  créer l'événement ; `EmailService::sendEmail()` (`src/auth_groups/Services/EmailService.php:107-146`)
  et le patron `sendActionConfirmation()` (ligne 1148, lien cliquable) comme référence directe pour
  un courriel « confirmation + lien d'annulation ».
- **Améliorations à faire** : `BookingController::book()` — transaction/`UPDATE ... WHERE reserved
  = 0` atomique, `0` ligne affectée → `409 SLOT_TAKEN` ; sur succès, crée l'événement, génère
  `cancel_token` (même famille que les tokens OTP — aléatoire, non devinable), envoie le courriel
  via une nouvelle méthode `EmailService::sendBookingConfirmation(...)`.
- **Enjeu à trancher avant Phase 5** : le lien d'annulation pointe vers la page publique
  `cmem_web` (`/book/{slug}` d'après la note de suite en bas de la directive), pas vers l'API.
  Aucune variable d'environnement `FRONTEND_URL`-style pour `cmemweb` n'existe aujourd'hui
  (`APP_URL` dans `.env.example:26` pointe sur l'API elle-même). Proposition : nouvelle variable
  `CMEMWEB_APP_URL` dans `.env`/`.env.example`, lien = `{CMEMWEB_APP_URL}/book/{slug}?cancel_token={token}`
  — **à confirmer avec l'utilisateur avant d'écrire le code de l'email.**
- **Maintenances à prévoir** : l'annulation peut soit passer l'événement à `status: cancelled`
  soit le supprimer (directive laisse le choix) — retenu : `status: cancelled` (cohérent avec le
  reste du module ICS qui traite déjà ce statut, évite une suppression physique irréversible).

### 6. Rate limiting

- **Déjà en place** : `RateLimitService::check()/record()` (`src/auth_groups/Services/RateLimitService.php:31-90`),
  clé `(email, ip, endpoint)`, mais seuil **global unique** lu depuis
  `RATE_LIMIT_AUTH_MAX_ATTEMPTS`/`RATE_LIMIT_AUTH_WINDOW_MINUTES` — pas de override par endpoint
  aujourd'hui.
- **Améliorations à faire** : ajouter des paramètres optionnels `?int $maxOverride = null, ?int
  $windowOverride = null` à `check()`/`record()` (rétrocompatible, défaut = comportement actuel).
  Endpoints publics booking anonymes → utiliser l'IP (`RateLimitService::getClientIp()`) comme
  identifiant en lieu et place de l'email absent, un bucket par endpoint (`booking_slots`,
  `booking_book`, `booking_cancel`).
- **Maintenances à prévoir** : si d'autres modules ont besoin de seuils différenciés plus tard, ce
  changement d'API de `RateLimitService` les débloque aussi — documenter dans le docstring de la
  classe.

### 7. Tâche planifiée

- **Déjà en place** : patron `src/cron/todo_reschedule.php` (garde anti-exécution web, bootstrap
  `loader.php` + autoloader du module, PDO direct, `LogService`, résumé texte en fin de script) et
  registre `docs/cron.md`.
- **Améliorations à faire** : `src/cron/booking_regenerate.php` — pour chaque `booking_pages.active
  = true`, étend l'horizon d'un jour, appelle `BookingSlotService::regenerate()` (même code que la
  régénération manuelle, Phase 3). Ajouter la ligne crontab à `docs/cron.md`.
- **Maintenances à prévoir** : lock file (`sys_get_temp_dir()`, patron `maintenance.php`) si le
  nombre de pages actives croît au point que deux passages puissent se chevaucher.

## Décisions actées (confirmées par l'utilisateur le 2026-08-13)

1. Variable d'environnement lien d'annulation : **`CMEMWEB_APP_URL`**, lien =
   `{CMEMWEB_APP_URL}/book/{slug}?cancel_token={token}`.
2. Unicité du `slug` : **globale par `app_id`**, même si la page est désactivée — un slug abandonné
   n'est jamais réutilisable.
3. `event_title_template` : stocké tel quel, substitution simple `{guest_name}` par `str_replace` —
   pas de moteur de template.

## Phases d'implantation

### Phase 1 — Schéma + config module gating (bloquant, confirmation migration requise)

- **Actions** : `docs/v-2-16-0/20260813_booking_public.sql` — `CREATE TABLE booking_pages` (unique
  `(owner_id, app_id)`, unique `(app_id, slug)`, FK `calendar_id`), `CREATE TABLE booking_slots`
  (FK `booking_page_id` cascade, index `(booking_page_id, reserved, start_datetime)`, unique
  `cancel_token`) ; `CmemModules::AVAILABLE['monthly'|'yearly'|'team']` += `'booking'`.
- **Enjeux** : `booking_pages.slug` format `[a-z0-9-]+` (CHECK ou validation applicative — MySQL
  CHECK regex limité, préférer validation applicative + contrainte unique simple) ; `event_id`
  nullable tant que non réservé.
- **Tests** : insertion doublon `(app_id, slug)` échoue ; insertion doublon `(owner_id, app_id)` sur
  `booking_pages` échoue ; `CmemModules::isAvailable('monthly','booking')` devient `true`,
  `isAvailable('free','booking')` reste `false`.
- **Conditions de complétion** : migration appliquée en dev, `build_DB` de la version courante non
  modifié (fichier séparé), tests de contrainte verts.

### Phase 2 — Scaffolding module + endpoints hôte (CRUD page, sans génération de zones)

- **Actions** : `src/booking/{BookingPlugin.php, plugin.json, autoloader.php}` (patron
  `src/pomo`) ; `Models/BookingPage.php`, `Models/BookingSlot.php` ; `Controllers/BookingPageController.php`
  (`GET/PUT/DELETE /booking/page`, gating `MODULE_NOT_AVAILABLE` patron `AiController.php:76-91`,
  validation `calendar_id` via `Calendar::isOwner()`) ; `Routing/BookingRouteHandler.php`.
- **Enjeux** : activer le plugin dans `.env` (liste des plugins actifs, voir `loader.php`) ;
  `PUT` doit rester idempotent côté config même avant que la Phase 3 branche la régénération.
- **Tests** : `test_booking.php` — `GET /booking/page` 404 si jamais créé ; `PUT` refuse
  `calendar_id` d'autrui ; `PUT active:true` sans module dispo → 403 `MODULE_NOT_AVAILABLE` ; slug
  dupliqué → erreur.
- **Conditions de complétion** : les 3 endpoints hôte fonctionnels et gardés par module, sans
  encore générer de zones (stub explicite documenté).

### Phase 3 — Génération / régénération de zones

- **Actions** : `Services/BookingSlotService.php::regenerate(int $bookingPageId)` — calcule les
  créneaux depuis `availability_windows`/`duration_minutes`/buffers sur `horizon_days`, appelle
  `EventOccurrence::getExpandedOpaqueByCalendarId()` pour exclure les chevauchements, supprime les
  zones non réservées futures, insère les nouvelles zones réservées jamais touchées ; brancher dans
  `PUT /booking/page` (après upsert) et `DELETE /booking/page` (suppression zones non réservées).
- **Enjeux** : transaction pour la paire supprime+insère (éviter fenêtre où aucune zone n'existe
  pendant un appel `slots` concurrent) ; fuseau horaire — `availability_windows` en heure locale
  hôte (`timezone` de la page), zones stockées en UTC.
- **Tests** : régénération après modif des plages conserve les zones réservées intactes ;
  chevauchement avec événement récurrent exclu correctement ; horizon respecté (aucune zone
  au-delà de `horizon_days`).
- **Conditions de complétion** : `PUT`/`DELETE` génèrent/nettoient correctement, tests verts,
  aucune zone réservée perdue dans aucun scénario testé.

### Phase 4 — Endpoints publics lecture (page + slots)

- **Actions** : `Controllers/BookingPublicController.php` — `GET /booking/public/{slug}`,
  `GET /booking/public/{slug}/slots` ; route handler public (`requiresAuth = false`).
- **Enjeux** : le `404 BOOKING_UNAVAILABLE` uniforme (inexistant / inactif / plan rétrogradé) —
  une seule requête interne qui combine les 3 vérifications, pas de branchement qui fuiterait un
  timing différent entre les cas.
- **Tests** : page inexistante / inactive / plan rétrogradé → même corps `404` ; `slots` respecte
  le plafond 60 jours (`422` ou clamp au-delà, à définir — proposer `422` explicite) ; `slots` ne
  retourne jamais de zone `reserved = true`.
- **Conditions de complétion** : les 2 endpoints publics lecture fonctionnels et testés, aucune
  fuite d'information sur l'état interne d'un hôte.

### Phase 5 — Réservation + annulation

- **Actions** : `POST /booking/public/{slug}/book` (transaction `UPDATE booking_slots SET
  reserved=1, guest_*, cancel_token=... WHERE id=:id AND reserved=0`, `rowCount()===0` → `409
  SLOT_TAKEN` ; sur succès, `CalendarEvent::create()` + `EmailService::sendBookingConfirmation()`) ;
  `POST /booking/public/cancel/{token}` (event → `status cancelled`, zone → `reserved=0` + champs
  invité/`cancel_token` vidés, idempotent si déjà annulé).
- **Enjeux** : dépend de la décision #1 (variable d'environnement lien front) — bloquant pour
  écrire le corps du courriel ; `422` si `slot_id` n'appartient pas au `slug` demandé (pas de fuite
  d'ID d'autres pages, directive §4).
- **Tests** : `test_booking.php` — deux requêtes concurrentes même `slot_id` → un seul `200`,
  l'autre `409` (test avec 2 connexions/processus, patron à définir vu l'absence de test de
  concurrence existant dans la suite) ; annulation libère puis re-propose la zone dans `slots` ;
  jeton rejoué → `404` sans erreur serveur.
- **Conditions de complétion** : les 2 endpoints fonctionnels, courriel envoyé (vérifiable en mode
  simulation `EmailService`), concurrence testée et gagnante côté SQL (pas de revérification
  applicative qui romprait la garantie).

### Phase 6 — Rate limiting

- **Actions** : étendre `RateLimitService::check()/record()` avec overrides optionnels
  `$maxOverride`/`$windowOverride` ; brancher sur les 3 routes publiques avec IP comme identifiant
  (`booking_slots` 20/min, `booking_book`/`booking_cancel` 5/min).
- **Enjeux** : ne pas régresser les appels existants (`login`, `send-code`, etc. — signature
  rétrocompatible, nouveaux paramètres en fin de liste avec défaut `null`).
- **Tests** : dépassement du seuil `book`/`cancel` → `429` ; endpoints auth existants toujours au
  seuil global (`test_auth_otp.php` reste vert).
- **Conditions de complétion** : 3 routes protégées, aucune régression sur les tests de rate-limit
  existants.

### Phase 7 — Cron quotidien

- **Actions** : `src/cron/booking_regenerate.php` (patron `todo_reschedule.php`) — étend l'horizon
  d'un jour et resynchronise les zones non réservées de chaque page active ; entrée `docs/cron.md`.
- **Enjeux** : lock file si volume le justifie (voir maintenance Phase 7 ci-dessus dans les
  grandes lignes) ; le cron doit réutiliser `BookingSlotService::regenerate()` sans dupliquer la
  logique (Phase 3).
- **Tests** : exécution manuelle du script en dev sur une page active — vérifie extension
  d'horizon + préservation des zones réservées, événement ajouté manuellement après génération
  finit par bloquer la zone au passage suivant.
- **Conditions de complétion** : script exécutable en CLI, documenté dans `docs/cron.md`, testé
  manuellement en dev.

### Phase 8 — Tests bout-en-bout, documentation, changelog

- **Actions** : compléter `test_booking.php` avec les scénarios non couverts en amont
  (page indisponible ne distingue pas les 3 causes, cycle complet réserver→annuler→re-réserver) ;
  `docs/booking/GUIDE.md` + `docs/booking/API_BOOKING_ENDPOINTS.json` (patron des autres modules) ;
  `docs/modules/GUIDE.md` mise à jour du mapping `booking` ; `CHANGELOG.md`.
- **Enjeux** : aucun nouveau — consolidation.
- **Tests** : `php private/tests/run_all_tests.php` complet vert (aucune régression sur les autres
  modules, en particulier `test_modules.php`, `test_ics_*`, `test_auth_otp.php`).
- **Conditions de complétion** : suite complète verte, doc à jour, `CHANGELOG.md` mis à jour,
  directive `20260813_163000_cmem_web_vers_cmem2_API__booking-public.md` passée à `statut:
  complété` avec les cases à cocher.
