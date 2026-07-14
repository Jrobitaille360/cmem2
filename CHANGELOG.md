# Changelog

Toutes les modifications notables de ce projet sont documentées ici.

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
Versioning : [Semantic Versioning](https://semver.org/lang/fr/)

---

## [Unreleased 2026-07-14 12:00]

### Feat — corbeille récupérable events/todos/journals (directive `20260714_120000_cmem_web_vers_cmem2_API`)

- **`GET /calendars/{id}/events|todos|journals/deleted`** — liste les éléments soft-deleted d'un calendrier, triés `deleted_at DESC`, paginés (`page`/`limit`, pattern `Response::getPaginationParams()`), limités aux 30 derniers jours (fenêtre de restauration, cf. ci-dessous)
- **`POST /calendars/{id}/events|todos|journals/{itemId}/restore`** — restaure un élément soft-deleted (`deleted_at = NULL` via `SoftDeleteTrait::restore()`), réplique du pattern `POST /tags/{id}/restore` ; répond `{ <type>_id }` ; 404 si non trouvé, pas supprimé, ou fenêtre de 30 jours dépassée ; autorisation alignée sur les routes `DELETE` existantes (`canUserWrite` pour events, propriétaire pour todos/journals)
- **`src/ics/Models/CalendarEvent.php`, `CalendarTodo.php`, `CalendarJournal.php`** — constante `RESTORE_RETENTION_DAYS = 30` + `getDeletedByCalendarId()` ; `restore()`/`findById($id, true)` déjà fournis par `BaseModel`/`SoftDeleteTrait` (aucune nouvelle méthode de restauration à écrire)
- **Rétention/purge** : la purge physique définitive reste sur le cron existant `ICS\Services\MaintenanceService` (90 jours) — aucune migration ni nouveau cron. La fenêtre RGPD 30 jours du plan de rewrite client est appliquée au niveau applicatif (liste + restore filtrent `deleted_at >= NOW() - 30 DAY`), pas au niveau de la purge physique (marge de sécurité serveur inchangée)
- **`docs/ics/API_ICS_ENDPOINTS.json`** — 6 routes documentées (events/todos/journals × deleted + restore)
- **`private/tests/test_calendars.php`** — 14 tests ajoutés (16e.17–30 : corbeille + restore + doublon 404 + inexistant 404, sur les 3 types) ; suite complète 466/466 en local (code local + DB dev-cmem2)

### Fix — `PUT .../occurrences` rejetait `occurrence_id` entier (bug pré-existant, détecté en régression)

- **`src/ics/Controllers/CalendarController.php::updateEventOccurrence`** — validateur exigeait `occurrence_id` de type `string` alors que la clé (id de `event_occurrences`) est un entier ; incohérent avec `deleteEventOccurrence` qui valide déjà `optional|integer` sur le même champ. Corrigé : `'occurrence_id' => 'optional|integer'`
- Détecté via `test_ics_occurrences_expand.php` (test 3.3, 4 échecs sur 250) en régression du diff corbeille ci-dessus — aucun lien fonctionnel avec la corbeille, mais corrigé au passage. Suite ré-exécutée : 250/250

---

## [Unreleased 2026-07-13 17:00]

### Feat — `related_to` exposé sur VJOURNAL create/update (directive `20260713_161125_cmem_web_vers_cmem2_API`)

- **`src/ics/Controllers/JournalController.php`** — `createJournal()` et `updateJournal()` acceptaient déjà `related_to` au niveau modèle/ICS mais ne le validaient/assignaient pas ; ajouté `'related_to' => 'optional|string|max:255'` aux règles et assignation explicite (hors boucle générique, comme `categories`/`dtstart`)
- **`src/ics/Models/CalendarJournal.php::update()`** — `related_to` géré via `isset()` ne permettait pas la remise à `null` (retrait du lien) ; ajout du flag `clearRelatedTo` pour forcer `related_to = NULL` en SQL quand le client envoie explicitement `null`
- **`docs/ics/API_ICS_ENDPOINTS.json`** — `related_to` documenté sur les routes `POST`/`PUT` journals
- **`private/tests/test_calendars.php`** — 5 tests ajoutés (16e.12b–e : création avec `related_to`, validation max 255, mise à jour, remise à `null`) ; suite complète 213/213 en local

---

## [Unreleased 2026-07-13 08:50]

### Fix — queue notifications email jamais alimentée (directive `20260713_084317_cmem_web_vers_cmem2_API`)

- **`src/ics/Services/EmailNotificationService.php::scheduleEmailsForEvent`** — lisait `$notif['minutes']` (clé `minutes_before` documentée/envoyée par le client) et comparait `$notif['type'] !== 'email'` (client envoie `'EMAIL'`) ; skip silencieux, aucune ligne insérée dans `email_notification_queue`, aucun log d'erreur
- Corrigé : `strtoupper($notif['type'] ?? '') !== 'EMAIL'` + `(int)($notif['minutes_before'] ?? 0)` — conforme au contrat `docs/ics/API_ICS_ENDPOINTS.json` (déjà correct, aucun changement de doc requis)
- `rescheduleEmailsForEvent()` corrigé du même coup (même chemin de code)
- `IcsGenerator.php` et `EventValidator.php` acceptaient déjà les deux clés — non affectés
- **`private/tests/test_ics_email_notifications.php`** — nouveau : 12 tests (queue alimentée avec `minutes_before`/status corrects sur événement `EMAIL`, non-régression `DISPLAY` seul → aucune ligne email) ; vérifié en direct sur dev-cmem2 ; ajouté à `run_all_tests.php` et `CLAUDE.md`

---

## [Unreleased 2026-07-08 20:25]

### Fix — freebusy : récurrence non expansée + troncature borne `end` (directive `20260708_200813_cmem_web_vers_cmem2_API`)

- **`GET /calendars/{id}/freebusy`** — un événement OPAQUE récurrent ne produisait qu'un seul créneau busy (la ligne parent), les autres occurrences absentes ; borne `end` date-seule tronquée à minuit (`strtotime('2026-08-31')` = `00:00:00`), excluant tout événement du dernier jour commençant après minuit
- **`src/ics/Models/EventOccurrence.php`** — nouvelle méthode `getExpandedOpaqueByCalendarId()` : réutilise le moteur d'expansion RRULE TZID-aware de `/occurrences/expand` (`expandEventInRange`), filtré en amont sur `status != 'cancelled'` et `transp IS NULL OR transp = 'OPAQUE'` ; un événement récurrent produit désormais un créneau busy par occurrence réelle, exceptions `is_cancelled`/`is_modified` appliquées
- **`src/ics/Controllers/CalendarController.php::getFreeBusy`** — `end` passé par `EventOccurrence::endOfDayIfDateOnly()` avant `strtotime` (même correctif que la directive `20260707_082006`, étendu à ce chemin) ; appel remplacé par `getExpandedOpaqueByCalendarId()`
- **`src/ics/Models/CalendarEvent.php`** — `getOpaqueEventsForFreeBusy()` (devenue morte, plus aucun appelant) supprimée
- **`private/tests/test_ics_freebusy_recurrence.php`** — nouveau : 26 tests (expansion récurrence 4/6 puis 3/6 occ. après annulation, occurrence modifiée reflétée, dernier-jour inclus, TRANSPARENT exclu, réponse ICS `VFREEBUSY` alignée) ; ajouté à `run_all_tests.php` et `CLAUDE.md`
- **`docs/ics/API_ICS_ENDPOINTS.json`** / **`docs/ics/GUIDE.md`** — contrat corrigé (réponse réelle `{ busy: [...] }` au lieu de l'ancien `{ freebusy: [...] }` documenté mais jamais implémenté ; comportement récurrence + borne `end` documenté)
- Non bloquant pour cmem_web (contournait déjà via `/occurrences/expand` côté client) ; utile aux autres consommateurs (CalDAV free-busy-query, export ICS `VFREEBUSY`)
- Suite complète : 1457/1457 tests verts (aucune régression) ; déployé sur dev et prod

---

## [Unreleased 2026-07-08 14:25]

### Exceptions d'occurrence par date — double clé `occurrence_date` (directive `20260708_105308_cmem_web_vers_cmem2_API`)

- **`PUT` / `DELETE /calendars/{id}/events/{eventId}/occurrences`** — acceptent désormais `occurrence_date` (clé naturelle RECURRENCE-ID, RFC 5545 §3.8.4.4) comme alternative à `occurrence_id` : exactement une des deux clés requise (les deux ou aucune → `400` explicite) ; formats `YYYY-MM-DD` ou `YYYY-MM-DD HH:MM:SS` (désambiguïsation), interprétés dans le `TZID` de l'événement ; ligne matérialisée existante réutilisée (pas de doublon), sinon date validée contre la grille RRULE (même moteur TZID-aware que `/expand`) puis ligne d'exception **matérialisée à la demande** ; date hors grille → `404`, jamais de `500` ; chemin `occurrence_id` strictement inchangé (client Flutter) ; réponses des deux endpoints enrichies de `occurrence_date`
- **`scope=all_future` par date** — opère sur `occurrence_date >= date` (au lieu de `id >=`) : occurrences antérieures et leurs exceptions intactes ; occurrences futures non matérialisées matérialisées sur un horizon de 2 ans pour que le résultat soit visible via `/expand`
- **`src/ics/Controllers/CalendarController.php`** — helper `extractOccurrenceKey()` (XOR + validation de format) ; branchement double clé dans `deleteEventOccurrence()` / `updateEventOccurrence()`
- **`src/ics/Models/EventOccurrence.php`** — nouvelles méthodes `resolveOrMaterializeByDate()`, `cancelFromDate()`, `modifyFromDate()`, `materializeMissingInRange()` (privée) ; méthodes par id inchangées
- **`docs/ics/API_ICS_ENDPOINTS.json`** / **`docs/ics/GUIDE.md`** — contrat double clé documenté ; divergence doc/code du `PUT` corrigée (le JSON documentait `occurrence_date` alors que le code exigeait `occurrence_id`)
- **`private/tests/test_ics_occurrences_exceptions_by_date.php`** — nouveau : 66 tests (XOR des clés, DELETE/PUT par date matérialisée et non matérialisée, réutilisation de ligne, format datetime TZ, hors grille → 404, `all_future` par date avec exceptions antérieures intactes, non-régression chemin `occurrence_id`) ; ajouté à `run_all_tests.php` et `CLAUDE.md`
- Suite complète : 1365/1365 tests verts (aucune régression) ; déployé sur dev (prod attendue avant la fin de la phase 4 cmem_web)

---

## [Unreleased 2026-07-08 07:46]

### Nouvel endpoint — expansion d'occurrences RRULE à la demande (directive `20260707_082007_cmem_web_vers_cmem2_API`)

- **`GET /calendars/{id}/events/occurrences/expand`** et **`GET /calendars/{id}/events/{eventId}/occurrences/expand`** (nouveau, additif) — expanse les RRULE à la volée sur la seule plage `[start, end]` demandée, dans le `TZID` de l'événement (DST-safe), sans dépendre de la table pré-calculée `event_occurrences` ni du CRON ; `start`/`end` requis, date-seule acceptée (fin de journée inclusive pour `end`) ; occurrences annulées absentes, occurrences modifiées retournées avec leurs `modified_*` appliqués ; RRULE non supportée par `simshaun/recurr` → `422` explicite (jamais `500`) ; ancien chemin (`/events/occurrences` sans `/expand`), CRON et table `event_occurrences` strictement inchangés (client Flutter non affecté)
- **`src/ics/Services/RecurrenceService.php`** — nouvelle méthode `expandInRangeTzAware()` (Rule construite avec le `TZID` de l'événement + `BetweenConstraint` de `simshaun/recurr`, isolée de `calculateOccurrences()` existant)
- **`src/ics/Models/EventOccurrence.php`** — nouvelles méthodes `getExpandedByCalendarId()` / `getExpandedByEventId()` (lecture seule : `calendar_events` + exceptions `event_occurrences`) ; `endOfDayIfDateOnly()` et `applyModifications()` rendues `public` pour réutilisation
- **`src/ics/Routing/RouteHandlers/CalendarRouteHandler.php`** — 2 routes additives, insérées avant les routes `.../occurrences` existantes (garde `!isset($segments[5])` ajoutée aux anciennes routes pour éviter toute ambiguïté de matching)
- **`docs/ics/API_ICS_ENDPOINTS.json`** / **`docs/ics/GUIDE.md`** — contrat documenté (paramètres, réponse, codes d'erreur, sous-ensemble RRULE supporté)
- **`private/tests/test_ics_occurrences_expand.php`** — nouveau : 33 tests (DST mars + novembre America/Toronto, exceptions annulée/modifiée, bornes inclusives + plage vide, RRULE invalide, non-régression ancien chemin) ; ajouté à `run_all_tests.php`
- Suite complète : 1365/1365 tests verts (aucune régression) ; déployé sur dev uniquement (prod hors scope — le client React n'est pas encore en phase 4)

---

## [Unreleased 2026-07-07 14:42]

### Fix — frontière `end_date` date-seule sur les occurrences (directive `20260707_082006_cmem_web_vers_cmem2_API`)

- **`src/ics/Models/EventOccurrence.php`** — `end_date` date-seule (`YYYY-MM-DD`) était comparé par MySQL à `00:00:00`, écartant toute occurrence horaire du dernier jour de la fenêtre demandée ; nouveau helper `endOfDayIfDateOnly()` normalise en `YYYY-MM-DD 23:59:59`, appliqué en tête de `getByCalendarId`, `getByEventId`, `getByEventIds` ; `start_date` inchangé (déjà inclusif) ; bascule de génération à la volée `> 2099-12-31` préservée ; bornes déjà horodatées non affectées
- **`private/tests/test_calendars.php`** — section 9bis (4 tests) : occurrence horaire dernier jour incluse (calendrier + événement récurrent), borne horodatée inchangée, bascule 2099 non altérée
- Suite complète : 1332/1332 tests verts (aucune régression)

---

## [Unreleased 2026-07-07 08:45]

### CORS pour la SPA cmem-web + compte de test OTP à code fixe (dev) — directive `20260707_064207_cmem_web_vers_cmem2_API`

- **`index.php`** — CORS : écho de l'origin exact quand `Origin` figure dans `ALLOWED_ORIGINS` (+ `Vary: Origin`), fallback `*` sinon (compat clients existants) ; préflight `OPTIONS` répond désormais `204` (au lieu de 200) sans authentification ; bloc CORS dupliqué retiré (il écrasait `Allow-Methods`/`Allow-Headers` sans PATCH ni X-API-Key)
- **`environment.php`** — défaut `ALLOWED_METHODS` inclut `PATCH` ; nouvelles constantes `OTP_TEST_ACCOUNT_EMAIL` / `OTP_TEST_ACCOUNT_CODE` (vides par défaut)
- **`AuthController::sendCode()`** — compte de test E2E (dev seulement, activé par les deux vars d'env) : code OTP fixe stocké, **aucun email envoyé**, exempt du rate limit anti-brute-force ; `verify-code` émet JWT + device token normaux ; inactif en prod (vars absentes)
- **`.env` / `.env.dev.online` / `.env.dev.local`** — `ALLOWED_ORIGINS` += `https://cmem-web.journauxdebord.com`, `http://localhost:5173` ; `OTP_TEST_ACCOUNT_EMAIL=e2e@test.local`, `OTP_TEST_ACCOUNT_CODE=000000`
- **`.env.prod`** — `ALLOWED_ORIGINS` += `https://cmem-web.journauxdebord.com` seulement (pas de compte de test)
- **`.env.example`** — vars `OTP_TEST_ACCOUNT_*` documentées (commentées, dev seulement)
- **`docs/core/GUIDE.md`** — sections « CORS » et « Compte de test E2E (dev seulement) » ajoutées
- **`docs/core/API_ENDPOINTS.json`** — bloc `api.cors` (préflight 204, écho origin whitelist + Vary, fallback `*`, headers/méthodes) ; note compte de test E2E sur `/auth/send-code` ; date regénérée
- **`private/tests/test_cors_e2e_account.php`** — nouveau : 18 tests (préflight OPTIONS, écho origin, Vary, fallback `*`, origin non listé, code fixe, exemption rate limit ×7, JWT/device token, `GET /auth/me` cross-origin) ; ajouté à `run_all_tests.php` et `CLAUDE.md`
- Audit `email_verified=0` (prod) : **0 compte** concerné (`deleted_at IS NULL`) — aucune migration nécessaire en phase 8

---

## [Unreleased 2026-07-06 14:30]

### Docs — alignement des GUIDE.md sur les JSON et le code + 3 nouveaux guides (audit complet)

- **`docs/puzzle/GUIDE.md`** — réécriture v2.0.0 (était resté pré-v2.7.0) : chemins migrés vers `/v2/puzzle/*` ; routes fictives retirées (`POST /puzzle/auth/pseudonym`, `POST /puzzle/auth/verify-subscription`, `POST .../shared/{uid}/move`) ; documentation des vraies routes `pick`/`drop` (verrou exclusif, 409/423, `to_tray`), `POST /v2/puzzle/backup/claim` et `POST /puzzle/auth/link-device` (JWT) ; auth/pseudonyme/abonnement renvoient vers les modules playstore et access ; section « Routes dépréciées » ajoutée
- **`docs/core/GUIDE.md`** — section Webhooks retirée (aucune route `/webhooks/*` dans le code) ; `POST /subscription/checkout` et `POST /subscription/portal` marqués dépréciés 410 Gone (v2.7.0) avec renvoi vers `/v2/billing/*` ; table Statistiques alignée sur le code (`/stats/users/{id}` au lieu de `/stats/user/{id}`, ajout build/platform/groups/users/my-stats/cleanup-sessions) ; table `is_trial`/`trial_end` réparée (ligne vide qui cassait le tableau)
- **`docs/pomo/GUIDE.md`** — Ph1B support marqué « À venir — non implémenté » (était annoncé actif alors que la route répond 404) ; avertissements 404 explicites sur les sections Ph1B/Ph2/Ph3 (contrat prévisionnel) ; table d'erreurs corrigée (404 au lieu de 503/401)
- **`docs/ics/GUIDE.md`** — routes manquantes ajoutées : `DELETE /calendars/{id}/events/{eventId}/hard` et `GET /calendars/{id}/events/occurrences` (occurrences globales du calendrier)
- **`docs/puzzle/API_PUZZLE_ENDPOINTS.json`** — reliquat `POST /move` corrigé en `pick`/`drop` dans `client_integration.shared_polling.move_flow`
- **`docs/access/GUIDE.md`** — nouveau guide : `GET /v2/access/status` (matrice d'accès premium par plateforme, sources Stripe, filtre `platform`)
- **`docs/webdevice/GUIDE.md`** — nouveau guide : `/v2/devices/web/*` et alias `/v2/devices/windows/*` (register JWT-optionnel, pseudonyme unique par app_id partagé entre plateformes)
- **`docs/traque/GUIDE.md`** — nouveau guide : création de personnage (classes/races/stats), monstres géolocalisés (biomes OSM, scaling `X-Player-Level`), combat (start/attack/flee, contre-attaque serveur), repos, journal/achievements/bestiaire, leaderboard
- Vérifié conformes sans changement : `docs/items/GUIDE.md`, `docs/playstore/GUIDE.md`, `docs/stripe/GUIDE.md`, `docs/quiz/GUIDE.md` (export CSV clairement sous Roadmap)
- **`docs/entrypoints.md`** — nouvelle section « Guides narratifs par module » : table des 12 guides avec lien et résumé du contenu

---

## [Unreleased 2026-07-06 12:00]

### Docs — alignement des JSON d'endpoints sur le code réel (audit complet docs ↔ src)

- **`docs/core/API_ENDPOINTS.json`** — version 2.2.4 → 2.8.0, date regénérée ; section `secret-admin` retirée (le handler exige que ces routes ne soient pas documentées publiquement) ; ajout `GET/PUT /users/me/notification-preferences` (fournies par le plugin ICS, 503 si absent)
- **`docs/stripe/API_STRIPE_ENDPOINTS.json`** — `deprecated_routes` corrigé : `GET /subscription/status`, `POST /subscription/verify`, `DELETE /subscription/cancel` documentées (actives, jamais documentées auparavant) ; nouvelle section `removed_routes` : `POST /subscription/checkout` et `POST /subscription/portal` répondent 410 Gone depuis v2.7.0, `POST /stripe/webhook` répond 404
- **`docs/puzzle/API_PUZZLE_ENDPOINTS.json`** — route fictive `POST /v2/puzzle/shared/{uid}/move` remplacée par les vraies routes `POST .../pick` (verrou exclusif, 409/423) et `POST .../drop` (pose, `to_tray`, 409) ; ajout `POST /v2/puzzle/backup/claim` (récupération de sauvegarde par pseudonyme) et `POST /puzzle/auth/link-device` (liaison device ↔ compte JWT)
- **`docs/puzzle/API_PUZZLE_ADMIN_MANAGER.json`** — v1.0.5 : ajout `GET /puzzle/admin/themes/{slug}`, `POST/DELETE /puzzle/admin/themes/{slug}/images/{uid}`, section `image_delivery` (`GET /puzzle/admin/thumb/{uid}`, `GET /puzzle/admin/thumb/theme/{slug}`, `GET /puzzle/admin/image/{uid}`)
- **`docs/traque/API_TRAQUE_ENDPOINTS.json`** — ajout `POST /traque/players/me/rest` (soin 50 % ou full, cooldown 30 min/4 h) et `GET /traque/players/check-name` (disponibilité d'un nom de personnage)
- **`docs/pomo/API_POMO_ENDPOINTS_v1_0_0.json`** — v1.0.1 : routes non implémentées retirées (support, sync/*, stripe/webhook — phases 1B/2/3) ; seul `POST /pomo/engagement` existe ; en-tête et notes clarifiés, `db_tables` conservées comme schéma prévisionnel
- **`docs/webdevice/API_WEBDEVICE_ENDPOINTS.json`** — nouveau fichier : documente `/v2/devices/web/*` et `/v2/devices/windows/*` (register JWT-optionnel + pseudonym GET/POST/DELETE/check), jusqu'ici sans doc JSON
- **`docs/entrypoints.md`** — nouveau fichier : index des 12 docs JSON d'endpoints avec lien, nombre de routes et description par module

---

## [Unreleased 2026-06-30 19:30]

### Ajout — `show_question_to_player` dans les quiz (directive kestyon)

- **`docs/20260630_show_question_to_player.sql`** — migration : `ALTER TABLE quiz_quizzes ADD COLUMN show_question_to_player TINYINT(1) NOT NULL DEFAULT 1 AFTER show_leaderboard`
- **`src/quiz/Models/Quiz.php`** — propriété `$show_question_to_player` ajoutée ; `createFromData()` : colonne insérée (défaut 1) ; `updateFromData()` : champ mis à jour si présent dans le body
- **`src/quiz/Controllers/ParticipantController.php`** — `getSession()` : `show_question_to_player` ajouté dans `quiz_settings` (GET `/quiz/session/{sid}`) ; défaut `true` pour quiz existants sans la colonne
- `GET /quiz/{id}` retourne automatiquement `show_question_to_player` via `SELECT *`

---

## [Unreleased 2026-06-29 20:45]

### Ajout — `GET /files/png-from-svg` : conversion SVG→PNG à la demande (directive kestyon)

- **`src/auth_groups/Controllers/FileController.php`** — nouveau endpoint `svgToPng()` : détecte le convertisseur disponible (`rsvg-convert` > `inkscape` > `convert`) via `detectSvgConverter()`, exécute la commande via `runSvgConversion()` avec `proc_open` (tableau d'args, zéro interpolation shell) ; paramètres : `id` (requis), `width` (1–4096 px), `height` (1–4096 px), `dpi` (1–600, défaut 96), `bg` (hex sans `#`, regex validée), `scale` (0.01–10, défaut 1.0) ; réponse `image/png` avec `Cache-Control: public, max-age=86400` ; erreurs 400/404/422/500 en JSON ; contrôle d'accès identique à `download()` (grand-public sans JWT, public avec JWT, private propriétaire/admin)
- **`src/auth_groups/Controllers/FileController.php`** — `image/svg+xml` ajouté aux types MIME autorisés ; `svg` ajouté aux extensions autorisées dans `validateFile()`
- **`src/auth_groups/Routing/RouteHandlers/FileRouteHandler.php`** — middleware : `isOptionalAuth` étendu à `action === 'png-from-svg'` (JWT optionnel comme pour `GET /files/{id}`) ; route `GET /files/png-from-svg` ajoutée au match

---

## [Unreleased 2026-06-27 10:00]

### Refactor — limites d'upload par type extraites vers `.env` / `environment.php`

- **`src/auth_groups/Controllers/FileController.php`** — `$maxFileSizes` tableau hardcodé remplacé par les constantes `MAX_IMAGE_SIZE`, `MAX_DOCUMENT_SIZE`, `MAX_AUDIO_SIZE`, `MAX_VIDEO_SIZE`, `MAX_EXECUTABLE_SIZE` ; même remplacement sur la limite 200 MB inline des exécutables/archives
- **`src/auth_groups/environment.php`** — `MAX_IMAGE_SIZE` défaut 5 MB → **10 MB** ; `MAX_AUDIO_SIZE` défaut 10 MB → **20 MB** ; ajout `MAX_EXECUTABLE_SIZE` 200 MB ; suppression de `MAX_FILE_SIZE` (constante morte)
- **`src/auth_groups/Utils/FileValidator.php`** — `getMaxSizeForType()` étendu aux cinq catégories (`image`, `document`, `audio`, `video`, `default`) avec les constantes typées ; référence à `MAX_FILE_SIZE` supprimée
- **Tous les fichiers `.env`** (`.env`, `.env.example`, `private/.env`, `private/utilitaires/.env*`) — `MAX_FILE_SIZE` retiré ; `MAX_IMAGE_SIZE`, `MAX_DOCUMENT_SIZE`, `MAX_AUDIO_SIZE`, `MAX_VIDEO_SIZE`, `MAX_EXECUTABLE_SIZE` ajoutés

---

## [Unreleased 2026-06-26 12:00]

### Fix — `strip_tags(null)` dans `File.php` brisait le JSON de réponse (directive kestyon)

- **`src/auth_groups/Models/File.php`** — `strip_tags($var)` → `strip_tags($var ?? '')` sur `original_name`, `file_name` et `description` : PHP 8.1+ déprécie `strip_tags(null)`, ce qui émettait un warning HTML en préfixe du JSON et causait une `FormatException` côté Flutter

### Fix — `MAIL_FROM` → `MAIL_FROM_ADDRESS` dans `InvitationService`

- **`src/auth_groups/Services/InvitationService.php`** — `$_ENV['MAIL_FROM']` → `$_ENV['MAIL_FROM_ADDRESS']` avec fallback `no_reply@journauxdebord.com`

### Fix — biome vide pour `young_dragon` (`traque`)

- **`docs/20260626_fix_dragon_biome.sql`** — `UPDATE monsters SET biome = 'peak' WHERE asset_key = 'young_dragon' AND (biome IS NULL OR biome = '')`

### Docs — `CLAUDE.md` complet

- Liste de tests complète et triée alphabétiquement (13 fichiers ajoutés)
- Table des modules : `access`, `stripe`, `playstore`, `traque`, `webdevice`, `notifications`, `cron` ajoutés
- Chemin DB init : `docs/build_cmem2_DB.sql` → `docs/v-2-8-0/build_DB-v-2.8.0.sql`

---

## [2.8.0] — 2026-06-22

### Ajout — Tags pour `quiz_questions` (directive kestyon)

- **`docs/20260622_tags-quiz-questions.sql`** — migration : `ALTER TABLE tags MODIFY table_associate ENUM(…,'quiz_questions')` ; `CREATE TABLE quiz_question_tag_relations (quiz_question_id, tag_id, created_at, updated_at, deleted_at)` avec FK CASCADE vers `quiz_questions` et `tags`
- **`src/auth_groups/Models/Tag.php`** — `getRelationTable()` : case `quiz_questions` → `quiz_question_tag_relations` ; `getItemColumnName()` : case `quiz_questions` → `quiz_question_id` ; nouvelle méthode `findTagsByQuestionIds(array $ids): array` (batch, keyed by question_id)
- **`src/auth_groups/Controllers/TagController.php`** — `quiz_questions` ajouté aux validators `in:` de `create()`, `update()`, `getOrCreate()`, `associateOrDissociate()` et à `$validTables` de `getMostUsed()`
- **`src/auth_groups/Routing/RouteHandlers/TagRouteHandler.php`** — `quiz_questions` ajouté à la liste `handleByTableRoute()`
- **`src/quiz/Controllers/QuizController.php`** — `attachQuestionsWithChoices()` charge les tags via `findTagsByQuestionIds()` et les expose sous `tags: [{id, name, color}]` par question dans `GET /quiz/{id}`

### Ajout — Upload grand-public dossier `kestyon` + `download_url` (directive kestyon)

- **`src/auth_groups/Controllers/FileController.php`** — exception à la restriction `grand-public` : un utilisateur authentifié (non-admin) peut uploader dans le dossier `kestyon` avec `accessibility: grand-public` ; champ `download_url` ajouté à la réponse d'upload au format `{APP_URL}/files/{id}`

### Fix — `quiz_question_tag_relations` : colonne `updated_at` manquante

- **`docs/v-2-8-0/20260622_tags-quiz-questions.sql`** — `updated_at timestamp … ON UPDATE current_timestamp()` ajouté au `CREATE TABLE` (aligné sur `file_tag_relations` / `group_tag_relations`)

### Fix — auth : comparaison de date de naissance

- **`src/auth_groups/`** — correction de la comparaison de date de naissance à minuit pour éviter les décalages de fuseau horaire (4 commits)

### Ajout — Phase 2 : attaques spéciales et jets de sauvegarde (`traque`)

- **`docs/20260616_traque_special_attack.sql`** — migration : `ALTER TABLE monsters ADD COLUMN special_attack ENUM('none','poison','spell')`, `save_dc TINYINT UNSIGNED`, `save_stat ENUM('con','sag')` ; seeds Naga (poison DC 12 CON), Ratman (poison DC 10 CON), Liche (spell DC 14 SAG)
- **`src/traque/Routing/TraqueRouteHandler.php`** — `GET /traque/monsters/nearby` expose `special_attack`, `save_dc`, `save_stat` sur chaque monstre ; défaut `'none' / 0 / 'con'` si colonnes absentes (rétrocompat)
- **`docs/traque/API_TRAQUE_ENDPOINTS.json`** — exemple `response_200` de `/traque/monsters/nearby` complété avec les 3 nouveaux champs
- **`private/tests/test_traque.php`** — section 3.4 : vérif présence et validité de `special_attack` / `save_dc` / `save_stat` sur chaque monstre retourné ; assertions spécifiques Naga/Ratman/Liche

### Ajout — Phase 2.1 : détection biome OSM pour monstres (`traque`)

- **`src/traque/Services/OverpassService.php`** (nouveau) — `detect(lat, lng)` : interroge l'API Overpass dans un rayon de 100 m, applique les priorités OSM (`landuse=forest/cemetery/industrial`, `natural=wood/peak/cliff/water`, `waterway=river`, `amenity=place_of_worship`) et retourne l'un des 7 biomes Flutter (`forest|peak|water|cemetery|worship|industrial|urban`). Échec réseau → `urban` (défaut)
- **`src/traque/Models/Monster.php`** — `respawn()` appelle `OverpassService::detect()` et met à jour `biome` en DB ; `biomeMultiplier` : `'mountain'` renommé `'peak'` (×1,2) pour aligner sur l'enum Flutter
- **`docs/20260615_traque_biome_osm.sql`** — migration : `ALTER TABLE monsters MODIFY biome ENUM('forest','peak','water','cemetery','worship','industrial','urban')` ; `UPDATE` `mountain` → `peak` (ordre UPDATE avant ALTER pour éviter troncation MySQL)
- **`docs/traque/API_TRAQUE_ENDPOINTS.json`** — note biome ajoutée sur `GET /traque/monsters/nearby` : valeurs et source OSM documentées
- **`private/tests/test_traque.php`** — section 3.4 : vérification que chaque biome retourné appartient à l'enum Flutter (régression `mountain` détectée et corrigée)

### Ajout — Phase 1.4 : repos hors combat + régénération passive (`traque`)

- **`src/traque/Models/Player.php`** — `rest(playerId, type)` : repos actif (50 % HP manquants, cooldown 30 min) ou complet (100 % HP, cooldown 4 h) ; `applyPassiveRegen(playerId)` : 1 HP / 5 min depuis `last_combat_at` (calcul SQL timezone-safe) ; `updateLastCombatAt(playerId)`
- **`src/traque/Routing/TraqueRouteHandler.php`** — route `POST /traque/players/me/rest?type=active|full` ; `playerMe()` applique la régén passive avant retour ; `formatPlayer()` expose `rest_available_at` (ISO 8601 UTC, nullable)
- **`src/traque/Services/CombatService.php`** — `last_combat_at` mis à jour après victoire (`attack`) et fuite réussie (`flee`)
- **`docs/20260612_traque_rest.sql`** — migration `ALTER TABLE traque_players ADD COLUMN rest_available_at DATETIME NULL, ADD COLUMN last_combat_at DATETIME NULL`

### Ajout — `character_name` unique dans `traque`

- **`src/traque/Models/Player.php`** — méthode `isCharacterNameTaken(string $name): bool`
- **`src/traque/Routing/TraqueRouteHandler.php`** — `playerCreate()` retourne 422 `character_name_taken` si nom pris ; nouvelle route `GET /traque/players/check-name?name=X` → `{ "available": true/false }`
- **`docs/20260607_traque_character_name_unique.sql`** — migration `ALTER TABLE traque_players ADD CONSTRAINT uq_traque_players_character_name UNIQUE (character_name)` (appliquée sur dev)

### Fix — leaderboard `traque` : `character_name` au lieu du courriel

- **`src/traque/Models/Player.php`** — `getLeaderboard` : `display_name` utilise désormais `tp.character_name` dans les 3 requêtes (`class`, `biome`, `global`) ; JOIN `users` retiré des requêtes `biome` et `global` où il était inutile

### Ajout — `character_name` dans le module `traque`

- **`src/traque/Models/Player.php`** — colonne `character_name` ajoutée dans `INSERT` de `create()`
- **`src/traque/Routing/TraqueRouteHandler.php`** — validation `character_name` (requis, max 50 chars, 422 `character_name_required`) dans `playerCreate()` ; retourné dans `formatPlayer()`
- **`docs/20260607_traque_character_name.sql`** — migration `ALTER TABLE traque_players ADD character_name VARCHAR(50) NOT NULL DEFAULT ''`
- **`docs/traque/API_TRAQUE_ENDPOINTS.json`** — `character_name` ajouté dans requête et réponse de `POST /traque/players/create` et `GET /traque/players/me`

### Ajout — Module `traque` (gamification géolocalisée)

Nouveau plugin `src/traque/` : monstres, joueurs, sessions de combat et achievements.

- **`src/traque/TraquePlugin.php`** — entrypoint plugin ; enregistre les routes via `TraqueRouteHandler`
- **`src/traque/Routing/TraqueRouteHandler.php`** — définition des routes du module
- **`src/traque/Models/Monster.php`** — modèle monstre (positions géographiques)
- **`src/traque/Models/Player.php`** — modèle joueur
- **`src/traque/Models/CombatSession.php`** — sessions de combat
- **`src/traque/Services/CombatService.php`** — logique de résolution des combats
- **`src/traque/Services/AchievementService.php`** — gestion des achievements joueur
- **`docs/20260605_traque_init.sql`** — migration SQL initiale (tables `traque_*`)
- **`composer.json`** — ajout dépendance `mjaschen/phpgeo ^4.1` (calculs géodésiques) + namespace `Traque\`

### Fix — Validation GDPR âge ≥ 16 à la création d'utilisateur

- **`src/auth_groups/Controllers/UserManagerController.php`** — validation du champ `birthdate` (format `YYYY-MM-DD`) lors de `createUser` ; retourne HTTP 422 + `age_restriction` si âge < 16 ; mappe `birthdate` → `date_of_birth` si ce dernier est absent

### Docs — Plan refonte v3.0.0 — section OpenAPI 3.2.0

- **`docs/PLAN_refonte-v3.0.0.md`** — section 3.10 mise à jour : cible OAS 3.2.0, validation via Spectral, exposition `GET /v3/openapi.yaml`, webhooks Stripe documentés sous `webhooks:` top-level, suppression `nullable` (migré vers array de types JSON Schema 2020-12)

---

## [2.7.0] — 2026-05-29

### Refactor — Consolidation Stripe Phase 4 : suppression code legacy auth_groups

Webhook prod migré vers `/v2/billing/webhook` (Stripe Dashboard). Fichiers legacy désormais orphelins supprimés.

- **`src/auth_groups/Controllers/StripeController.php`** — supprimé
- **`src/auth_groups/Routing/RouteHandlers/StripeRouteHandler.php`** — supprimé
- **`src/auth_groups/Services/StripeService.php`** — supprimé
- **`src/auth_groups/Routing/Router.php`** — import `StripeRouteHandler` retiré, entrée `'stripe'` retirée du map de routes (route `/stripe/webhook` → 404)

---

### Refactor — Phase 5 v2.7.0 : destruction code mort + migration backup + nouveaux crons

#### Suppression fichiers legacy

- **`src/puzzle/Controllers/AuthController.php`** — supprimé ; routes `/puzzle/auth/*` (register-device, verify-subscription, subscription-status, pseudonym, check-pseudonym) retournent désormais 410
- **`src/puzzle/Models/PuzzleDevice.php`** — supprimé ; table `puzzle_devices` abandonnée
- **`src/puzzle/Services/GooglePlayService.php`** — supprimé ; dupliqué de `src/playstore/Services/GooglePlayService.php`
- **`src/cron/expire_subscriptions.php`** — supprimé ; remplacé par `expire_playstore.php` + `expire_stripe.php`

#### Migration backup → android_devices / web_devices

- **`docs/20260529_backup_json_devices.sql`** — migration SQL : ajout `backup_json MEDIUMTEXT NULL` et `backup_saved_at DATETIME NULL` sur `android_devices` et `web_devices`
- **`src/playstore/Models/AndroidDevice.php`** — méthodes `setUserId`, `saveBackup`, `findLatestWithBackupByUser` ajoutées
- **`src/webdevice/Models/WebDevice.php`** — mêmes méthodes ajoutées
- **`src/puzzle/Controllers/SyncController.php`** — réécrit sans `PuzzleDevice` ; `saveBackup`/`getBackup` via `_device_type` ; `claimBackup` via `AppUserSettings::findUserByPseudonym` → device le plus récent avec backup

#### Routage + link-device

- **`src/puzzle/Routing/PuzzleRouteHandler.php`** — bloc `/puzzle/auth/*` réduit à `link-device` uniquement (autres : 410) ; `handleLinkDevice` inline sans `PuzzleDevice`

#### Maintenance + backup

- **`src/puzzle/Services/MaintenanceService.php`** — `deleteExpiredDevices` et `deleteInactiveDevices` ciblent `android_devices` + `web_devices` (plus `puzzle_devices`)
- **`src/cron/backup/backup_puzzle.php`** — 9 → 11 tables : `puzzle_devices` retiré, `android_devices` + `web_devices` + `app_user_settings` ajoutés
- **`src/cron/backup/backup_core.php`** — 23 → 25 tables : `subscriptions` retiré, `playstore_subscriptions` + `stripe_subscriptions` + `stripe_processed_events` ajoutés

#### Nouveaux crons

- **`src/cron/expire_playstore.php`** — expire `playstore_subscriptions` actifs dont `expires_at < NOW()`
- **`src/cron/expire_stripe.php`** — expire `stripe_subscriptions` actifs/trialing/past_due dont `expires_at < NOW()` (fallback webhook Stripe)

---

### Fix — Play Store upgrade/downgrade : gestion `linked_purchase_token`

- **`src/playstore/Models/PlaystoreSubscription.php`** — nouvelle méthode `expireByToken(purchaseToken, appId)` : expire la ligne active identifiée par token (retourne bool)
- **`src/playstore/Services/PlaystoreSubscriptionService.php`** — `verify()` accepte paramètre optionnel `$linkedPurchaseToken` ; si présent et token Google valide, expire l'ancien token avant l'upsert du nouveau ; si absent en base, log warning et continue sans erreur
- **`src/playstore/Controllers/SubscriptionController.php`** — extrait `linked_purchase_token` du body et le transmet au service
- **`private/tests/test_subscriptions.php`** — test 3.6 : payload avec `linked_purchase_token` fictif accepté, Google rejette le nouveau token → 422 attendu

Note : critère "linked non trouvé → warning" non testable automatiquement (requiert token Google Play réel). Couvert par log `WARNING linked_purchase_token not found in DB`.

---

### 2026-05-28

### Refactor — Dépréciation routes legacy `/subscription/checkout` et `/subscription/portal`

- **`src/auth_groups/Controllers/SubscriptionController.php`** — `checkout()` et `portal()` retournent désormais HTTP 410 avec message de redirection vers `POST /v2/billing/checkout` et `POST /v2/billing/portal` ; import `StripeService` retiré (plus utilisé dans ce contrôleur)
- **`src/auth_groups/Services/StripeService.php`** — `success_url` et `cancel_url` Stripe Checkout dynamiques via `$appId` (était hardcodé sur `puzzle`) : `https://journauxdebord.com/{app_id}/subscription/success` et `/cancel`

### Docs — Stripe endpoints v1.1.0

- **`docs/stripe/API_STRIPE_ENDPOINTS.json`** — v1.0.0 → v1.1.0 ; détail `checkout.session.completed` (status=trialing, is_trial=1) ; `customer.subscription.updated` inclut `is_trial`, `trial_end`, `plan` ; `invoice.payment_succeeded` passe `is_trial=0` ; réponse `/v2/subscriptions/stripe/status` enrichie : champs `is_trial`, `trial_end`, `provider` ; table `status_values` ; note URL retour portail ; table d'événements idempotente renommée `stripe_processed_events`
- **`docs/stripe/GUIDE.md`** — mise à jour URLs success/cancel/return, section trial, idempotence, prérequis webhook prod
- **`docs/PLAN_refonte-device-subscription-v2.7.0.md`** — note blocage suppression `StripeController`/`StripeRouteHandler`/`StripeService` legacy (webhook prod pointe encore sur `/stripe/webhook`) ; référence `docs/PLAN_consolidation-stripe.md` Phase 3

---

### 2026-05-24

### Refactor — Abonnements Play Store : `device_uuid` remplace `user_id`

Android étant entièrement anonyme (pas d'email, pas de JWT), les abonnements Play Store sont
maintenant liés à `device_uuid` plutôt qu'à `user_id`. Le `device_uuid` sert également de
`obfuscatedExternalAccountId` côté Google Play, ce qui permet la restauration d'abonnement
sur un nouvel appareil.

#### Migration SQL

- **`docs/20260524_playstore_subscriptions_device_uuid.sql`** — migration appliquée :
  suppression `user_id` + FK, ajout `device_uuid VARCHAR(64)`,
  nouvelle clé unique `(device_uuid, app_id)`, index `purchase_token`

#### Modèle

- **`src/playstore/Models/PlaystoreSubscription.php`** — propriété `user_id` → `device_uuid` ;
  `upsertSubscription(string $deviceUuid, ...)` ; `findActive(string $deviceUuid, string $appId)` ;
  `markCancelled` et `expireStale` acceptent `string $deviceUuid`

#### Services

- **`src/playstore/Services/GooglePlayService.php`** — `obfuscatedExternalAccountId` retourné
  comme `string $deviceUuid` (plus de cast `int`)
- **`src/playstore/Services/PlaystoreSubscriptionService.php`** — `verify()` accepte
  `string $callerDeviceUuid` ; restauration cross-device via `$result['device_uuid'] ?? $callerDeviceUuid`

#### Contrôleurs et routage

- **`src/playstore/Controllers/SubscriptionController.php`** — tous les endpoints acceptent
  `array $device` (au lieu de `array $user`) et utilisent `$device['device_uuid']`
- **`src/playstore/Routing/PlaystoreRouteHandler.php`** — `/v2/subscriptions/playstore/*`
  authentifié via `X-Device-Token` (plus de JWT) ; validation par `AndroidDevice::findByValidToken()`

#### Module Access

- **`src/access/Services/AccessService.php`** — suppression du lookup Play Store (impossible
  sans `user_id`) ; `GET /v2/access/status` (JWT) couvre désormais Stripe uniquement ;
  Android consulte son statut via `GET /v2/subscriptions/playstore/status` (device_token)

#### Tests

- **`private/tests/test_new_base.php`** — ajout `callApiWithDeviceToken()` et
  `callTestWithDeviceToken()` (envoie `X-Device-Token` au lieu de JWT)
- **`private/tests/test_subscriptions.php`** — réécriture complète ; teste les nouveaux
  endpoints plugin (playstore + stripe) au lieu des anciens `/subscription/*` supprimés
- **`private/tests/test_playstore.php`** — sections 4-6 utilisent désormais
  `callTestWithDeviceToken($deviceToken, ...)` pour les routes Play Store
- **`private/tests/test_access.php`** — section 5 : enregistrement device + `callTestWithDeviceToken`
  pour le setup Play Store ; section 5.0 corrigée (accepte 422 local)

---

### 2026-05-22

### Feat — Enregistrement device anonyme + routage v2/puzzle

- **`src/auth_groups/Routing/RouteHandlers/V2RouteHandler.php`** — câblage `PuzzleRouteHandler` pour les routes `/v2/puzzle/*` (manquait dans le router v2)
- **`src/playstore/Routing/PlaystoreRouteHandler.php`** — JWT désormais optionnel pour `POST /v2/devices/android/register` et `POST /v2/devices/web/register` (anonyme si absent) ; autres routes android gardent JWT obligatoire ; section `/v2/devices/web/*` ajoutée
- **`src/playstore/Controllers/DeviceController.php`** — `register()` accepte `?array $user` (nullable) ; `AppUserSettings::get()` ignoré si `user_id` null
- **`src/playstore/Models/AndroidDevice.php`** — `upsertDevice()` accepte `?int $userId` ; ON DUPLICATE KEY UPDATE utilise `COALESCE(VALUES(user_id), user_id)` pour préserver le `user_id` existant ; ajout `findByValidToken()` et `touchLastSeen()`

### Feat — Mode simulation email (`EMAIL_SIMULATION`)

- **`src/auth_groups/environment.php`** — constante `EMAIL_SIMULATION` (défaut `false`)
- **`src/auth_groups/Services/EmailService.php`** — `$simulationMode` actif si `isDevMode && EMAIL_SIMULATION` ; log "simulation" distinctement de "development" ; `testSMTPConnection()` et `canSendEmails()` honorent le mode simulation
- **`.env.example`** — variable `EMAIL_SIMULATION=false` documentée

---

### 2026-05-21

### Fix — Endpoints thumb/image accessibles avec device_token anonyme Android

- **`composer.json`** — ajout namespace `WebDevice\\` au PSR-4 ; sans cela, `new WebDevice()` levait une `Error` non catchée (HTTP 200 avec body d'erreur PHP) pour tout device_token inconnu d'`android_devices`
- **`src/puzzle/Models/PuzzleImage.php`** — `formatImage()` génère désormais `/v2/puzzle/thumb/{uid}` et `/v2/puzzle/image/{uid}` (était `/puzzle/thumb/`, non-v2)
- **`src/puzzle/Controllers/ImageDeliveryController.php`** — `serveThumb()` et `serveImage()` acceptent maintenant un paramètre `$device` (transmis par le router pour usage futur)
- **`src/puzzle/Routing/PuzzleRouteHandler.php`** — passe `$device` aux appels `serveThumb()` et `serveImage()`
- **`private/tests/test_playstore.php`** — section 1b : tests device anonyme (register sans JWT, carousel, thumb, image, token invalide) ; test 1.1 mis à jour (attendu 200 au lieu de 401)

**Déploiement requis :** `composer dump-autoload` sur le serveur après push.

---

### 2026-05-19 Phase 6

### Docs — Nouveaux modules v2.7.0

- **`docs/playstore/GUIDE.md`** — guide complet : register-device, pseudonyme, vérification/statut/annulation Play Store
- **`docs/playstore/API_PLAYSTORE_ENDPOINTS.json`** — référence JSON tous les endpoints `/v2/devices/android/*` et `/v2/subscriptions/playstore/*`
- **`docs/stripe/GUIDE.md`** — guide complet : checkout, portail, webhook, statut/annulation Stripe
- **`docs/stripe/API_STRIPE_ENDPOINTS.json`** — référence JSON tous les endpoints `/v2/billing/*` et `/v2/subscriptions/stripe/*`
- **`docs/access/API_ACCESS_ENDPOINTS.json`** — référence JSON `GET /v2/access/status` avec matrice d'accès et exemples

### Docs — Mise à jour docs existants

- **`docs/puzzle/API_PUZZLE_ENDPOINTS.json`** — v1.2.0 → v2.0.0 : routes `/puzzle/*` → `/v2/puzzle/*`, suppression section `auth` (register-device, verify-subscription, pseudonym — désormais dans module playstore), mise à jour `client_integration` (référence `/v2/devices/android/register` et `/v2/access/status`)
- **`docs/core/API_ENDPOINTS.json`** — suppression section `subscription` complète (`/subscription/status`, `/subscription/verify`, `/subscription/checkout`, `/subscription/portal`, `/subscription/cancel`); retrait champ `subscriptions` de la réponse `POST /auth/login` et `POST /auth/verify-code`

### BREAKING CHANGE — Champ `subscriptions` retiré de POST /auth/login

Le champ `subscriptions` n'est plus retourné dans la réponse de `POST /auth/login` ni de
`POST /auth/verify-code`. **Aucun client local connu ne lit ce champ** (audit Phase 0 confirmé).
Pour connaître l'état premium, utiliser `GET /v2/access/status?app_id={app_id}`.

### Directives inter-projets

- **`directives_inter_projet/20260519_120000_cmem2_API_vers_puzzle__migration-v2.7.0-android.md`** — directive Puzzle Android : migration device, Play Store, accès unifié, routes `/v2/puzzle/*`
- **`directives_inter_projet/20260519_120100_cmem2_API_vers_puzzle__migration-v2.7.0-web-windows.md`** — directive Puzzle Web/Windows : migration Stripe, accès unifié, routes `/v2/puzzle/*`, retrait champ `subscriptions` du login

---

### 2026-05-20

### Tests — OTP dev sans injection DB directe (21.6)

- **`OtpService::generateAndStore()`** — paramètre optionnel `?string $forceCode = null` : si fourni, utilisé à la place du code aléatoire
- **`AuthController::sendCode()`** — en `APP_ENV=development`, passe `TMP_CODE` (défini dans `.env.dev.*`) à `generateAndStore()` pour rendre le code OTP prévisible dans les tests
- **`environment.php`** — `define('TMP_CODE', $_ENV['TMP_CODE'] ?? '')` ; valeur `654321` dans `.env.dev.local` et `.env.dev.online` uniquement
- **`test_users.php` 21.6** — remplace l'injection PDO directe (`injectOtpCode`) par un appel HTTP `POST /auth/send-code` suivi de `POST /auth/verify-code` avec `654321` ; SKIP propre si env non-dev

---

### 2026-05-19 23:00

### v2 API — Modules Playstore, Stripe, Access (feat — b88cbfa)

- **`src/playstore/`** — nouveau module : enregistrement device Android (`POST /v2/devices/android/register`), gestion pseudonyme par `(user_id, app_id)` (`GET|POST|DELETE /v2/devices/android/pseudonym`, `GET .../check/{pseudo}`), vérification/statut/annulation abonnement Google Play (`/v2/subscriptions/playstore/*`)
- **`src/stripe/`** — nouveau module : checkout Stripe (`POST /v2/billing/checkout`), portail client (`POST /v2/billing/portal`), webhook signé (`POST /v2/billing/webhook`), statut/annulation abonnement Stripe (`/v2/subscriptions/stripe/*`)
- **`src/access/`** — nouveau module : endpoint unifié statut accès multi-plateforme (`GET /v2/access/status?app_id=&platform=`)
- **`src/auth_groups/Routing/RouteHandlers/V2RouteHandler.php`** — dispatch centralisé `/v2/*`
- **`src/auth_groups/Routing/Router.php`** — enregistrement route v2

### Auth — Retrait subscriptions des réponses auth/user

- **`AuthController`** — suppression de `SubscriptionService::getAllStatuses` dans la réponse login; l'état d'abonnement est désormais fourni par `/v2/access/status`
- **`UserListController`** — idem pour `GET /users/{id}`; champ `subscriptions` retiré

### Maintenance — Retrait expireSubscriptions

- **`MaintenanceService`** — suppression méthode `expireSubscriptions` et de son appel dans `run()`; l'expiration est gérée côté modules playstore/stripe

### Playstore — Fix pseudonyme : vérification disponibilité pré-écriture

- **`DeviceController::setPseudonym()`** — ajout check `AppUserSettings::isAvailable()` avant `set()` → HTTP 409 si pseudonyme déjà pris par un autre utilisateur
- **`AppUserSettings::set()`** — remplacement `INSERT ON DUPLICATE KEY UPDATE` par UPDATE + INSERT séparé (nécessaire pour que `rowCount()` détecte les conflits réels)

### Fix — Upload fichiers exécutables/archives

- **`File::getFileCategory()`** — correction : retournait `'default'` (valeur hors enum) pour les types `application/zip`, `application/x-dosexec`, etc. → retourne désormais `'executable'`; élimine `SQLSTATE[01000]: Data truncated for column 'media_type'`

### Fix — VTODO : types entiers priority et percent_complete

- **`CalendarTodo::decode()`** — cast `(int)` ajouté pour `priority` et `percent_complete`; PDO retournait ces valeurs en string, causant des échecs de comparaison stricte (`=== 1`, `=== 100`) dans les tests

### Environnement

- **`environment.php`** — guard `!defined('TMP_ASSETS_DIR')` pour éviter redéfinition lors du chargement multi-contexte
- **`.env.example`** — commentaire ajouté : `BASE_PATH` vide si API à la racine du vhost
- **`.env`** — nettoyage variables mortes, structure multi-environnement documentée (7a7ea07)

### SQL — Migration 20260514_device_subscription_refonte.sql

- `TRUNCATE` remplacé par `DELETE` sur `puzzle_shared_events/pieces/shared` (contrainte FK empêchait TRUNCATE)
- **`build_DB-v-2.6.5.sql`** — suppression `DEFINER=\`root\`@\`localhost\`` des vues (portabilité multi-serveur)

---

## [2.6.5] — 2026-05-14

### Déploiement — Traçabilité version déployée

- **`private/deploy.ps1`** (étape 4/4) — injecte `APP_COMMIT` (hash git court) et `APP_DEPLOYED_AT` (timestamp ISO) dans le `.env` distant à chaque déploiement
- **`.env.example`** — nouvelles variables `APP_COMMIT` et `APP_DEPLOYED_AT` documentées

### Puzzle — Sync statut abonnement Google Play (fix annulation PlayStore)

- **`GET /puzzle/auth/subscription-status`** (device_token) — nouvel endpoint; interroge l'API Google Play Developer et retourne `is_premium` + `stale`; met à jour `subscriptions` et `puzzle_devices` en base; fail-safe : état DB conservé si Google Play inaccessible (`stale: true`); résout le bug où l'app restait en mode abonnement après annulation PlayStore
- **`AuthController::getSubscriptionStatus()`** — handler du nouvel endpoint
- **`AuthController::verifySubscription()`** — appelle désormais `PuzzleDevice::updateSubscription()` après activation; stocke `purchase_token`, `product_id` et `premium_expires_at` dans `puzzle_devices` (prérequis pour le lookup dans `requireDeviceToken` et `subscription-status`)
- **`docs/puzzle/API_PUZZLE_ENDPOINTS.json`** — v1.1.0 → v1.2.0; nouveau endpoint documenté; `client_notes` de `verify-subscription` mis à jour

### Puzzle — Sync statut Google Play sur GET /subscription/status

- **`SubscriptionController::getStatus()`** — re-vérifie l'état d'un abonnement `google_play` auprès de l'API Google Play Developer à chaque appel `GET /subscription/status?app_id=puzzle`; met à jour `is_premium` et `expires_at` en base de données; fail-safe : valeur DB conservée si Google Play est inaccessible
- **`SubscriptionController::syncGooglePlayStatus()`** — méthode privée encapsulant la logique de sync (appel `GooglePlayService::validateSubscription`, mise à jour via `Subscription::renewByPurchaseToken`, logging)
- **`purchase_token` et `product_id`** jamais exposés dans la réponse de l'endpoint

### Maintenance — Rapport courriel conditionnel

- **`MaintenanceReport::send()`** — courriel envoyé uniquement si des erreurs sont détectées; en l'absence d'erreur, seul le log fichier est écrit
- **`CRON_maintenance.md`** — documentation mise à jour pour refléter le nouveau comportement

---

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

## [2.5.0] — 2026-05-02

### Portail de facturation Stripe

#### Nouvel endpoint

- **`POST /subscription/portal`** — crée une session Stripe Billing Portal pour un utilisateur
  ayant un `stripe_customer` en base pour l'`app_id` fourni; retourne `{ portal_url }`
- JWT obligatoire; `401` si absent, `404 NO_SUBSCRIPTION` si aucun customer Stripe trouvé,
  `500 STRIPE_ERROR` en cas d'erreur Stripe

#### Modèle `Subscription`

- **`findStripeCustomerByUserAndApp(userId, appId)`** — nouvelle méthode ciblant
  le couple `(user_id, app_id)` avec `stripe_customer IS NOT NULL`
- **`upsert()`** — fix : `stripe_customer = COALESCE(VALUES(stripe_customer), stripe_customer)` —
  la valeur existante est préservée si l'appelant (ex. `POST /subscription/verify`) ne fournit
  pas de customer Stripe, évitant un écrasement silencieux

#### Service `StripeService`

- **`createPortalSession(customerId, appId)`** — appel HTTP natif vers
  `/v1/billing_portal/sessions`; retourne `{ portal_url }`

#### Tests `test_subscriptions.php`

- **Section 14** — `POST /subscription/portal` : 401 (sans JWT), 422 (sans `app_id`),
  404 `NO_SUBSCRIPTION`, 200 avec `portal_url` valide

#### Documentation

- **`docs/core/API_ENDPOINTS.json`** — ajout `POST /subscription/portal`,
  `POST /subscription/checkout` (manquant), champs `is_trial`/`trial_end` dans
  la réponse `/subscription/status`
- **`docs/core/GUIDE.md`** — idem + sections exemples pour `checkout` et `portal`

---

### Fichiers — niveau d'accessibilité `grand-public` (sans JWT)

#### Migration DB

- **`docs/v-2-4-1/20260430_files_accessibility.sql`** — ENUM étendu à `('public','private','grand-public')`; correction du commentaire (défaut `private`, non `public`)

#### Modèle `File`

- Whitelist `['public','private','grand-public']` appliquée dans `create()`, `update()` et `updateAccessibility()`

#### Contrôleur `FileController`

- **`upload(int $userId, string $role)`** — nouveau paramètre `$role`; valeur `grand-public` réservée aux administrateurs (retourne 403 sinon)
- **`download()`** — logique à trois branches : `grand-public` → accès libre sans JWT; `public` → JWT requis; `private` → propriétaire ou administrateur
- **`getFileInfo()`** — même logique à trois branches que `download()`
- **`updateAccessibility()`** — valeur `grand-public` réservée aux administrateurs (retourne 403 sinon)
- Messages d'erreur mis à jour : `valeurs acceptées : public, private, grand-public`

#### Routage `FileRouteHandler`

- **`getMiddlewares()`** surchargée — JWT optionnel pour `GET /files/{id}` et `GET /files/{id}/info` : si le token est absent ou invalide, un utilisateur `guest` (`user_id=null, role='guest'`) est injecté; toutes les autres routes conservent l'auth obligatoire
- Appel `upload()` mis à jour : passe `$user['role']` en second argument

#### Tests `test_files.php`

- **E0** — upload `grand-public` par non-admin → 403
- **E1–E6** — upload admin, download sans JWT → 200, `/info` sans JWT → 200, PATCH `private`, vérification re-lock
- **D6** — PATCH `grand-public` par propriétaire non-admin → 403
- **D7** — PATCH `grand-public` par admin → 200

#### Documentation

- **`docs/core/GUIDE.md`** — tableau d'accessibilité mis à jour avec colonne JWT et ligne `grand-public`; note sur l'absence d'en-tête `Authorization` pour les routes concernées
- **`docs/core/API_ENDPOINTS.json`** — ENUM mis à jour (`public|private|grand-public`) dans tous les payloads; notes d'accessibilité enrichies pour `GET /files/{id}` et `GET /files/{id}/info`

---

## [2.4.1] — 2026-04-30

### Fichiers — champ `accessibility` (public / private)

#### Migration DB

- **`docs/20260430_files_accessibility.sql`** — `ALTER TABLE files ADD COLUMN accessibility ENUM('public','private') NOT NULL DEFAULT 'private' AFTER uploaded_by`

#### Modèle `File`

- Nouvelle propriété `$accessibility`
- **`create()`** — insère la colonne `accessibility`; validation en whitelist avant `bindParam`
- **`update()`** — persiste `accessibility` avec `original_name` et `description`
- **`getByUserId()`** — sélectionne désormais `accessibility` dans la liste `SELECT`
- **`updateAccessibility($fileId, $accessibility)`** — nouvelle méthode dédiée `UPDATE … SET accessibility = :accessibility`

#### Contrôleur `FileController`

- **`upload()`** — accepte le champ FormData `accessibility` (`private` par défaut); retourne 422 si valeur invalide; inclut `accessibility` dans la réponse 201
- **`download()`** — applique la règle d'accès : `public` → tout utilisateur JWT valide; `private` → propriétaire ou administrateur uniquement (retourne 403 sinon)
- **`getFileInfo()`** — même règle d'accessibilité que le téléchargement
- **`updateAccessibility(int $fileId, int $userId, string $role)`** — nouveau handler `PATCH /files/{id}/accessibility`; vérifie propriétaire ou admin; retourne `{file_id, accessibility}`

#### Routage `FileRouteHandler`

- Nouvelle entrée `PATCH /files/{id}/accessibility` → `controller->updateAccessibility()`

#### Documentation

- **`docs/core/API_ENDPOINTS.json`** — champ `accessibility` ajouté aux payloads POST /files, GET /files/{id}/info, GET /files/user/{user_id}; nouvelle entrée PATCH /files/{id}/accessibility avec codes HTTP 200/401/403/404/422
- **`docs/core/GUIDE.md`** — PATCH /files/{id}/accessibility ajouté au tableau des routes; sections explicatives sur `accessibility` et la règle d'accès

---

### 2026-04-27 11:00

### Stripe — chargement des constantes depuis `.env`

- **`src/auth_groups/environment.php`** — bloc `STRIPE_*` ajouté : `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_PUZZLE_MONTHLY`, `STRIPE_PRICE_PUZZLE_YEARLY` définis avec `if (!defined(...))` depuis `$_ENV`; valeur par défaut `APP_VERSION` corrigée de `2.0.0` à `2.4.0`
- **`.env.example`** — nouvelle section commentée `STRIPE — Paiements et abonnements (v2.4.0+)` avec instructions de configuration ; `APP_VERSION` mis à jour à `2.4.0`

### Public — version dynamique et chemins uploads/logs

- **`src/auth_groups/Routing/RouteHandlers/PublicRouteHandler.php`** — version hardcodée `2.0.0` remplacée par `defined('APP_VERSION') ? APP_VERSION : '2.4.0'` dans `/info`, `/help` et `/health`; chemins uploads/logs corrigés via `dirname(__DIR__, 4)` (résolvait un faux négatif sur le health check en production)

---

## [2.4.0] — 2026-04-27

### Abonnements — Stripe, essai, champs étendus (auth_groups)

#### Migration DB

- **`docs/20260426_subscriptions_trial.sql`** — nouvelles colonnes : `device_token`, `stripe_customer`, `is_premium`, `show_ads`, `is_trial`, `trial_end` ; `user_id` rendu nullable ; contraintes `UNIQUE` hybrides `uq_user_app` / `uq_device_app` en remplacement de `uq_user_app_provider`

#### Modèle `Subscription`

- **`upsert()`** — intègre les 6 nouveaux champs ; `ON DUPLICATE KEY UPDATE` sur `uq_user_app`
- **`updateByStripeSubId()`** — mise à jour ciblée par `stripe_sub_id` (whitelist de colonnes)
- **`findStripeCustomerByUserId()`** — retrouve le `stripe_customer_id` d'un user existant
- **`markExpired()`** — positionne aussi `is_premium=0`, `show_ads=1`, `is_trial=0`

#### Service `SubscriptionService`

- **`activatePremium()`** — fusionne les valeurs par défaut `is_premium=1 / show_ads=0 / is_trial=0 / trial_end=null` avec les données de l'appelant
- **`getStatus()` / `getAllStatuses()`** — retournent désormais `is_trial` et `trial_end`

#### Contrôleur `SubscriptionController`

- **`verify()`** — transmet `is_trial` et `trial_end` du body vers `activatePremium()`
- **`checkout()`** — nouvel endpoint `POST /subscription/checkout` : requiert JWT, valide `app_id` et `plan` (monthly|yearly → 422), délègue à `StripeService::createCheckoutSession()`, retourne `{checkout_url, session_id}`

#### Nouveau — `StripeService`

- **`createCheckoutSession()`** — crée une Stripe Checkout Session (HTTP brut, sans SDK) ; `client_reference_id = user_id`
- **`getOrCreateCustomer()`** — recherche le `stripe_customer_id` en base ou le crée via l'API Stripe
- **`verifyWebhookSignature()`** — HMAC-SHA256 sur format `t=timestamp,v1=hash` ; rejet si signature absente, invalide ou timestamp > 300 s
- Handlers : `handleCheckoutCompleted`, `handleSubscriptionUpdated`, `handlePaymentSucceeded`, `handlePaymentFailed`, `handleSubscriptionDeleted`

#### Nouveau — `StripeController` + `StripeRouteHandler`

- **`POST /stripe/webhook`** — lit `php://input` + `HTTP_STRIPE_SIGNATURE`, vérifie la signature, dispatche les événements Stripe
- Pas de JWT requis ; route enregistrée dans `Router`

#### Variables `.env` requises

```
STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET
STRIPE_PRICE_PUZZLE_MONTHLY, STRIPE_PRICE_PUZZLE_YEARLY
```

---

### Auth — Option A : auto-register silencieux dans `send-code`

- **`AuthController::sendCode()`** — si l'email est inconnu, le compte est créé silencieusement avant l'envoi du code OTP (`email_verified=1`, `password_hash` = bcrypt d'un token aléatoire 32 octets pour respecter la contrainte `NOT NULL`) ; les comptes supprimés reçoivent une réponse générique sans recréation

---

### Google Play — Migration vers API subscriptionsv2

- **`GooglePlayService`** — migré de `subscriptions/{productId}/tokens/{token}` vers `subscriptionsv2/tokens/{token}` (v3)
  - `subscriptionState` → `is_premium` : `ACTIVE / IN_GRACE_PERIOD / CANCELED` = 1, `EXPIRED / ON_HOLD` = 0
  - `lineItems[0].expiryTime` (RFC 3339) remplace `expiryTimeMillis`
  - Détection essai via `lineItems[0].offerDetails.offerTags` contenant `"free-trial"`
  - `externalAccountIdentifiers.obfuscatedExternalAccountId` → `user_id`
  - Retourne : `{is_premium, show_ads, is_trial, trial_end, product_id, purchase_token, expires_at, user_id}`

---

### Cron — Maintenance centralisée

- **`src/Core/Maintenance/MaintenanceTaskInterface.php`** — interface `run(\PDO): array` avec rapport structuré
- **`src/Core/Maintenance/MaintenanceOrchestrator.php`** — exécute les `MaintenanceTaskInterface` de chaque module, agrège les rapports
- **`src/Core/Maintenance/MaintenanceReport.php`** — formatage du rapport (console + email)
- **`src/auth_groups/Services/MaintenanceService.php`** — purge : notifications, stats, invitations groupe/plan, `login_attempts`, `otp_codes`, `jwt_blacklist`, device tokens, vérifications email, reset mot de passe, sessions, abonnements expirés
- **`src/ics/Services/MaintenanceService.php`** — purge des données ICS périmées
- **`src/items/Services/MaintenanceService.php`** — purge des items soft-deleted anciens
- **`src/pomo/Services/MaintenanceService.php`** — purge des engagements Pomo anciens
- **`src/puzzle/Services/MaintenanceService.php`** — purge des parties puzzle expirées, tokens révoqués
- **`src/quiz/Services/MaintenanceService.php`** — purge des sessions quiz terminées anciennes
- **`src/cron/maintenance.php`** — script CRON unique, lock file (exécution simultanée bloquée), mode `--dry-run`, crontab recommandée `0 3 * * *`

---

### Cron — Correctif sauvegarde

- **`src/cron/backup/backup_uploads.php`** — suppression de l'archive `.tar.gz` existante avant conversion `PharData::compress` (évite l'erreur "phar exists and must be unlinked prior to conversion") ; nettoyage des fichiers partiels `.tar` / `.tar.gz` dans le bloc `catch`

---

### Convention — Migrations SQL entre versions

- **`CLAUDE.md`** (global + projet) — règle documentée : les migrations en attente se placent dans `docs/` (`YYYYMMDD_description.sql`) ; les `build_DB-v-x-x-x.sql` des versions fixées ne sont jamais modifiés ; la prochaine version intègre les migrations dans son `build_DB`

---

### Tests

- **`private/tests/test_users.php`** — section 21 : `POST /auth/send-code` Option A (email inconnu → 200 + compte créé, 2e appel → 200, email existant → 200, format invalide → 400, injection OTP + verify-code)
- **`private/tests/test_subscriptions.php`** — `callStripeWebhook()` helper ; assertions `is_trial` / `trial_end` dans sections 3, 5, 6, 7 ; section 5b (activation essai) ; section 12 (`POST /subscription/checkout` — 401/422/200) ; section 13 (`POST /stripe/webhook` — signatures absente/invalide/expirée → 400)

---

## [2.3.1] — 2026-04-23

### Module Files — sous-dossiers, exécutables et archives (auth_groups)

#### Nouveaux endpoints

- **`GET /files?folder=<slug>`** — liste les fichiers d'un sous-dossier `uploads/<folder>/` ; réservé aux ADMINISTRATEURS ; retourne tableau vide si le dossier n'a aucun fichier

#### Modifications

- **`POST /files`** — paramètre FormData `folder` optionnel : dépose le fichier dans `uploads/<folder>/` si fourni (slug `a-z 0-9 - _`, max 80 car.) ; défaut `uploads/files/`
- **`FileController`** — support des exécutables et archives (`exe`, `msi`, `zip`, `7z`) ; taille max 200 MB ; types MIME : `application/x-msdownload`, `application/x-msi`, `application/zip`, `application/x-zip-compressed`, `application/x-7z-compressed`, `application/octet-stream`
- **`FileController`** — fix chemin `$filePath` dans download (`GET /files/{id}`) et delete (`DELETE /files/{id}`) : `__DIR__ . '/../../..'` au lieu de `__DIR__ . '/../..'`
- **`File::getByFolder()`** — nouvelle méthode : liste les fichiers par pattern `file_path LIKE '/uploads/<folder>/%'`
- **`File::getFileCategory()`** — retourne `'executable'` pour les MIME archives/exécutables
- **`docs/core/API_ENDPOINTS.json`** — `GET /files` et `POST /files` mis à jour
- **`docs/core/GUIDE.md`** — section Files reécrite (tableau types MIME/tailles, doc `folder`, doc `GET /files?folder`)

#### Migration DB

```sql
ALTER TABLE files MODIFY COLUMN media_type
  ENUM('text','audio','video','image','gpx','summary','event','todo','document','executable')
  DEFAULT NULL;
```

---

### Plugin Quiz — GET /quiz/session/{id} accessible après fin de session

- **`QuizRouteHandler::requireParticipantToken()`** — ajout du paramètre `$allowEnded` (défaut `false`) ; le check `status === 'ended'` est sauté quand `true`
- **`QuizRouteHandler`** — `GET /quiz/session/{id}` passe `$allowEnded = true` ; les autres routes participant (`/answer`, `/leaderboard`) conservent le comportement 403 sur session terminée
- **`test_quiz.php`** — test `3.14b` : vérifie que `GET /quiz/session/{sid}` retourne 200 avec `status: ended`, `current_question: null`, `session_id` entier et `quiz_settings` complet
- **`docs/quiz/API_QUIZ_ENDPOINTS_v1_0_0.json`** — description et réponses de l'endpoint mises à jour ; `quiz_settings` ajouté à la réponse 200 ; `403` ne mentionne plus « session terminée » pour cet endpoint ; `error_handling.403_joueur` mis à jour
- **`docs/quiz/GUIDE.md`** — section `GET /quiz/session/{id}` : note d'accessibilité après `ended`, exemple de réponse avec `quiz_settings`, `current_question: null` documenté pour `ended` ; tableau des erreurs mis à jour

---

### Correctif — répertoire d'upload des fichiers

- **`FileController`** — corrigé le chemin `$uploadDir` : `__DIR__ . '/../../uploads/files/'` remplacé par `'/../../../uploads/files/'` ; les fichiers téléversés via `POST /files` sont désormais sauvegardés dans `uploads/files/` à la racine du projet et non dans `src/uploads/files/`

---

### Plugin Items — endpoints publics sans JWT

#### Nouveaux endpoints

- **`GET /items/publics`** — liste tous les items `access=public` non supprimés sans JWT ; filtres `category`, `category_match`, `limit`, `offset` ; même format de réponse que `GET /items`

#### Changements antérieurs (même session)

- **`GET /items/{id}`** — accessible sans JWT si l'item a `access=public` ; `private` et `share` retournent 403 sans JWT valide

#### Code modifié

- **`Item::findPublic()`** — nouvelle méthode : `WHERE access='public' AND deleted_at IS NULL` + filtres catégories/pagination
- **`ItemController::listPublic()`** — nouvel endpoint sans paramètre `$user`
- **`ItemRouteHandler`** — route `GET /items/publics` ajoutée (avant le bloc catégories) ; auth optionnelle (`requiresAuth = false`) ; `requireAuth()` interne pour les routes protégées
- **`ItemController::show()`** — signature `?array $user`
- **`ItemAccessService::canRead()`** — signature `?array $user` ; `public` court-circuite sans inspecter le user

#### Documentation

- **`docs/items/GUIDE.md`** — section « Endpoint public sans JWT » avec tableau d'exemples d'usage
- **`docs/items/API_ITEMS_ENDPOINTS.json`** — `GET /items/publics` ajouté ; `GET /items/{id}` : `auth_required: false`

---

## [2.3.0] — 2026-04-15

### Nouveau plugin — Items Manager

#### Base de données

- **`docs/items/migrations/001_items_base.sql`** — deux nouvelles tables :
  - `items` — item générique avec `owner_user_id`, `access` (private/public/share), `categories` (JSON), `json_item` (LONGTEXT), soft-delete `deleted_at`
  - `item_user_access` — liste de partage par item : `user_id`, `can_update` (0=lecture, 1=écriture) ; cascade DELETE sur l'item parent

#### Plugin `src/items/`

- **`ItemsPlugin`** — entry point ; enregistre le route handler `items` dans le `PluginManager`
- **`Item`** (Model) — `createItem`, `findItemById`, `findAccessibleByUser` (filtres access/owner/categories OR/AND/pagination), `updateItem`, `softDeleteItem`, `findDistinctCategories`, `decodeRow`
- **`ItemUserAccess`** (Model) — `findByItem`, `findByItemAndUser`, `upsert` (ON DUPLICATE KEY), `deleteRelation`
- **`ItemAccessService`** — `canRead`, `canUpdate`, `canDelete`, `canManageShares` ; règles centralisées private/public/share × owner/admin/invité
- **`ItemController`** — `list`, `create`, `show`, `update`, `delete`, `listCategories`, `byCategory`
- **`ItemShareController`** — `changeAccess`, `listShares`, `addShare`, `updateShare`, `removeShare`
- **`ItemRouteHandler`** — dispatch URI ; priorité `categories` avant `{id}` numérique pour éviter toute collision

#### Endpoints

| Méthode | Route | Description |
| - | - | - |
| GET | `/items` | Liste (filtres : owner, access, category OR/AND, limit, offset) |
| POST | `/items` | Créer un item |
| GET | `/items/categories` | Catégories distinctes accessibles, triées |
| GET | `/items/categories/{name}` | Items d'une catégorie |
| GET | `/items/{id}` | Lire un item |
| PUT | `/items/{id}` | Mettre à jour categories / json_item |
| DELETE | `/items/{id}` | Soft-delete (owner/admin) |
| PUT | `/items/{id}/access` | Changer private/public/share (owner/admin) |
| GET | `/items/{id}/shares` | Lister les invités |
| POST | `/items/{id}/shares` | Ajouter un invité |
| PUT | `/items/{id}/shares/{user_id}` | Modifier can_update d'un invité |
| DELETE | `/items/{id}/shares/{user_id}` | Retirer un invité |

#### Tests

- **`private/tests_mine/test_items.php`** — 84 assertions couvrant sécurité, CRUD, partages, catégories, filtres OR/AND, pagination, contrôle d'accès complet ; ajouté à `run_all_tests.php`

#### Documentation

- **`docs/items/GUIDE.md`** — guide client complet
- **`docs/items/API_ITEMS_ENDPOINTS.json`** — référence JSON des endpoints

---

## [2.3.0 2026-04-14 11h]

### Sécurité — Refresh token rotatif, détection replay attack, sessions globales (auth_groups)

#### Migration DB

- **`docs/core/migrations/20260413_device_token_family.sql`** — migration non destructive :
  - `device_tokens` : ajout colonne `family_id VARCHAR(36)` (UUID partagé par tous les tokens d'une même chaîne de rotation) ; index `idx_device_family` pour révocation rapide

#### Service `DeviceTokenService`

- **`generate()`** — nouveau paramètre `$familyId` : conserve le `family_id` existant lors d'une rotation, en crée un nouveau pour le premier token ; validation UUID du `device_id` (rejet si format invalide)
- **`validate()`** — refactorisé : requête sans filtre `revoked_at` pour détecter les replay attacks ; si un token révoqué est présenté à nouveau → log `CRITICAL` + appel `revokeFamily()` ; distinction token expiré vs révoqué vs introuvable dans les logs
- **`revokeFamily(string $familyId)`** — nouveau : révoque en bloc tous les tokens d'une famille (réponse à un replay attack détecté)
- **`isValidDeviceId()`** / **`generateUuid()`** — helpers privés ajoutés

#### Service `JwtService`

- **`validate()`** — vérification de l'algorithme déclaré dans le header JWT avant contrôle de la signature (défense en profondeur contre l'attaque `alg:none`) ; logs enrichis avec contexte IP/route via `getRequestContext()`

#### Middleware `JwtAuthMiddleware`

- Log `warning` émis dès la détection d'un token absent (IP, méthode, route) — renforce la traçabilité des tentatives d'accès non autorisées
- **`getClientIp()`** — nouveau helper : résout l'IP cliente avec support `X-Forwarded-For`

#### Contrôleur `AuthController`

- **`refreshToken()`** — rate limiting par `device_id` avant validation (429 `RATE_LIMIT_EXCEEDED` si quota dépassé) ; transmission du `family_id` lors de la rotation ; `RateLimitService::clear()` après refresh réussi
- **`listSessions(int $userId)`** — nouvel endpoint `GET /auth/sessions` : retourne la vue unifiée des sessions JWT actives et des appareils de confiance (`sessions`, `sessions_count`, `devices`, `devices_count`)
- **`revokeAllSessions()`** — nouvel endpoint `DELETE /auth/sessions` : blackliste le JWT courant, termine toutes les sessions JWT actives, révoque tous les device tokens — déconnexion globale tous appareils

#### Routeur `AuthRouteHandler`

- `GET /auth/sessions` → `listSessions()`
- `DELETE /auth/sessions` → `revokeAllSessions()` (transmet `jti` et `exp` du JWT courant)

#### `PublicRouteHandler`

- `GET /auth/sessions` et `DELETE /auth/sessions` déclarés dans la liste publique des endpoints disponibles

#### Correctif `UserManagerController`

- `$pdo` déplacé avant la condition `if ($freePlan)` (évite une variable non définie si le plan est absent)

#### Documentation

- **`docs/core/API_ENDPOINTS.json`** — `POST /auth/refresh` : ajout champ `rotation`, `rate_limiting`, erreur 429 ; nouveaux blocs `GET /auth/sessions` et `DELETE /auth/sessions`
- **`docs/core/GUIDE.md`** — description du refresh rotatif avec `family_id` et replay attack ; documentation des endpoints `/auth/sessions`

---

## [2.3.0 2026-04-13 17h]

### Refonte — SharedPuzzle v2 (plugin Puzzle)

#### Migration DB

- **`docs/puzzle/migrations/002_puzzle_pieces_state.sql`** — migration non destructive :
  - `puzzle_shared.status` : ajout valeur `'complete'` à l'enum
  - `puzzle_shared_pieces` : ajout colonnes `state ENUM('tray','floating','locked','held') DEFAULT 'tray'`, `held_by_id INT UNSIGNED NULL`, `prev_state ENUM('tray','floating') DEFAULT 'tray'`, `held_at DATETIME NULL`, `by_id INT UNSIGNED NULL` ; `x`/`y` rendus `NULL`-ables ; `rotation` migré de `TINYINT` à `SMALLINT UNSIGNED` (évite overflow avec valeurs legacy 90/180/270°) ; suppression colonne `locked`
  - `puzzle_shared_events` : mêmes ajouts (`state`, `held_by_id`, `by_id`) ; `x`/`y` `NULL`-ables ; `rotation` → `SMALLINT UNSIGNED` ; suppression colonne `locked`

#### Modèle `SharedPuzzle`

- **`activeGameExists(int $creatorId, int $partnerId)`** — vérifie si une partie active existe déjà entre deux devices (dans les deux sens de la relation)
- **`insertPieces(int $sharedId, int $pieceCount)`** — signature revue : insère `$pieceCount` lignes avec `state = 'tray'` (au lieu d'accepter un tableau de positions)
- **`getPieces()`** — retourne uniquement les pièces dont `state ≠ 'tray'` ; ajout champs `state`, `held_by`, `by` ; `x`/`y` nullable
- **`pickPiece()`** — transition `tray|floating → held` ; vérifie `locked` (→ `LOCKED`) et `held` par autre joueur (→ `HELD_BY_OTHER`) ; sauvegarde `prev_state`, `held_by_id`, `held_at`
- **`dropPiece()`** — transition `held → tray|floating|locked` ; logique snap côté serveur (tolérance `PUZZLE_SNAP_TOLERANCE`, grille carrée `sqrt(piece_count)`) ; recalcul `completion` ; retour état final
- **`movePiece()`** — supprimé (remplacé par `pick` + `drop`)
- **`insertEvent()`** — signature revue : accepte `state`, `x?`, `y?` au lieu de `locked` ; dérive `held_by_id` / `by_id` automatiquement selon l'état
- **`getPartnerEvents()`** — retourne désormais tous les événements (les deux joueurs, pour réconciliation client) ; format mis à jour : `state`, `held_by`, `by`, `x`/`y` nullable
- **`expireHeldPieces()`** — TTL opportuniste : expire les pièces `held` depuis plus de `$ttlSeconds`, les remet à `prev_state`, insère un événement TTL
- **`releaseHeldPieces()`** — relâche toutes les pièces tenues par un device (utilisé au `leave`)
- **`listActiveForDevice()`** — renommage `partner_pseudonym` → `partner_pseudo` ; ajout `creator_pseudo`, `status`, `is_creator`
- **`createFromData()`** — `seed` rendu optionnel (`?? null`)

#### Contrôleur `SharedController`

- **`createShared()`** — lit `partner_pseudo` (était `partner_pseudonym`) ; vérifie `activeGameExists()` → 409 `ALREADY_IN_GAME` ; réponse enrichie : `uid`, `creator_pseudo`, `partner_pseudo`, `is_creator`, `status`
- **`pick()`** — nouvel endpoint : `POST /puzzle/shared/{uid}/pick` — 200/422/409/423
- **`drop()`** — nouvel endpoint : `POST /puzzle/shared/{uid}/drop` — 200/422/409
- **`move()`** — supprimé
- **`getEvents()`** — appel opportuniste `expireHeldPieces()` avant le poll
- **`leave()`** — appelle `releaseHeldPieces()` avant `archive()`
- **`deleteShared()`** — retourne `204` (corps vide) au lieu de `200`

#### Routeur

- **`src/puzzle/Routing/PuzzleRouteHandler.php`** — `POST /pick` et `POST /drop` ajoutés ; `POST /move` retiré

#### Configuration

- **`src/puzzle/config/puzzle_config.php`** — ajout `PUZZLE_SNAP_TOLERANCE` (défaut `0.15`) et `PUZZLE_HELD_TTL_SECONDS` (défaut `30`)

#### Tests

- **`private/tests_mine/test_puzzle_share.php`** — suite entièrement reécrite : **110/110** — sections 0–12 couvrant création (validation, `ALREADY_IN_GAME`, champs v2), liste (`games`, `partner_pseudo`, `creator_pseudo`, `status`), state (pièces tray filtrées), pick/drop (held/floating/locked/snap/423/409), events (format `state`, TTL), leave, DELETE 204

---

## [2.3.0 2026-04-12 22h]

### Correctif — POST /puzzle/backup/claim (plugin Puzzle)

- **`src/puzzle/Controllers/SyncController.php`** — ajout `claimBackup()` : retrouve le device propriétaire par pseudonyme (insensible à la casse), copie son `backup_json` sur le device courant, transfère l'ownership du pseudonyme, retourne le backup
- **`src/puzzle/Routing/PuzzleRouteHandler.php`** — `POST /puzzle/backup/claim` dispatché vers `claimBackup()` avant le `match` ; corrige le bug où la route tombait sur `saveBackup()` (qui exigeait un champ `backup` côté client)
- **`private/tests_mine/test_pseudo.php`** — suite de tests pseudonyme : 85/85 (enregistrement device, 401, GET/check/POST/DELETE, unicité insensible à la casse, idempotence, libération et réattribution)

## [2.3.0 2026-04-12 21h]

### Nouveau — Endpoints pseudonyme complétés (plugin Puzzle)

- **`src/puzzle/Controllers/AuthController.php`** — ajout `getPseudonym()`, `checkPseudonym()`, `deletePseudonym()` ; validation centralisée `isValidPseudonym()` (3–20 chars, regex Unicode, pas d'espaces) ; `setPseudonym()` migré vers recherche insensible à la casse
- **`src/puzzle/Models/PuzzleDevice.php`** — ajout `findByPseudonymCI()` (recherche `LOWER()` pour unicité insensible à la casse) ; ajout `clearPseudonym()` (met `pseudonym = NULL`)
- **`src/puzzle/Routing/PuzzleRouteHandler.php`** — branchement des routes `GET /puzzle/auth/pseudonym`, `GET /puzzle/auth/check-pseudonym/{pseudonym}`, `DELETE /puzzle/auth/pseudonym`

## [2.2.5] — 2026-04-11

### Maintenance

- **`src/puzzle/Routing/PuzzleRouteHandler.php`** — `requirePremium()` : bypass debug via constante `PUZZLE_DEBUG_PREMIUM` (désactivé en production)
- **`src/puzzle/config/puzzle_config.php`** — ajout constante `PUZZLE_DEBUG_PREMIUM` lue depuis `$_ENV`
- **`src/puzzle/Controllers/ThemeController.php`** — suppression import mort `LogService` et ligne de log commentée

### Nouveau — Système de backup modulaire

- **`src/cron/backup/run_all.php`** — orchestrateur : exécute tous les scripts de backup dans l'ordre
- **`src/cron/backup/_bootstrap.php`** — initialisation partagée (connexion DB, config, helpers)
- **`src/cron/backup/_export.php`** — utilitaire export SQL/JSON partagé par tous les modules
- **`src/cron/backup/backup_core.php`** — backup module auth_groups (users, groups, tokens…)
- **`src/cron/backup/backup_puzzle.php`** — backup module Puzzle (devices, images, thèmes, shared)
- **`src/cron/backup/backup_ics.php`** — backup module ICS (calendriers, événements)
- **`src/cron/backup/backup_pomo.php`** — backup module Pomodoro / Journal
- **`src/cron/backup/backup_quiz.php`** — backup module Quiz
- **`src/cron/backup/backup_uploads.php`** — archivage des fichiers uploadés
- **`src/cron/backup/cleanup_backups.php`** — nettoyage des backups expirés
- **`src/cron/backup/cleanup_logs.php`** — nettoyage des logs expirés
- Suppression des anciens scripts monolithiques : `src/backup_data.php`, `src/backup_to_json.php`, `src/restore_data.php`

### Documentation

- **`docs/cron/PLAN_backup_system.md`** — plan complet du système de backup : stratégie, modules, planification CRON, rétention
- **`docs/v 2.2.5/2.2.5_CLIENT.md`** — guide migration client v2.2.4 → v2.2.5
- **`docs/v 2.2.5/2.2.5_PRODUCTION.md`** — checklist déploiement production v2.2.5

---

## [2.2.4] — 2026-04-10

### Maintenance

- **`docs/build_cmem2_DB.sql`** — renommé en **`docs/v 2.2.4/build_DB-v-2.2.4.sql`** pour respecter la convention de nommage `CLAUDE.md`
- **`docs/pour claude.md`** — supprimé ; contenu migré dans `~/.claude/CLAUDE.md` (instructions globales Claude Code)

---

## [2.2.4] — 2026-04-09

### Correctif

- **`src/auth_groups/Controllers/SubscriptionController.php`** — `getStatus()` : lecture du query param `app_id` via `$_GET` au lieu de `$request['query']` (non peuplé par `Router::parseRequest()`)

### Tests

- **`private/tests_mine/test_subscriptions.php`** — suite complète 79/79 : auth 401, `GET /subscription/status` (all + par app), `POST /subscription/verify` (validations 400 + activation monthly/yearly), `DELETE /subscription/cancel`, vérification `subscriptions{}` dans `/auth/login` et `/users/me`

### Nouveau — Gestion des abonnements Premium (par application)

#### Base de données

- **`docs/core/migrations/20260409_subscriptions.sql`** — nouvelle table `subscriptions` avec colonne `app_id` ; contrainte `UNIQUE (user_id, app_id, provider)` ; index sur `expires_at` + `status` ; `users` non modifiée

#### Modèles

- **`src/auth_groups/Models/Subscription.php`** — `upsert()` (INSERT … ON DUPLICATE KEY UPDATE), `findActive()`, `findAllActive()`, `findExpired()`, `markExpired()`, `cancel()`, `setStripeSubId()`

#### Services

- **`src/auth_groups/Services/SubscriptionService.php`** — `activatePremium()`, `deactivatePremium()`, `getStatus()`, `getAllStatuses()`, `checkAndExpireSubscriptions()` (CRON)

#### Contrôleurs

- **`src/auth_groups/Controllers/SubscriptionController.php`** — `getStatus()`, `verify()`, `cancel()`

#### Routing

- **`src/auth_groups/Routing/RouteHandlers/SubscriptionRouteHandler.php`** — routes `/subscription/status`, `/subscription/verify`, `/subscription/cancel` (JWT requis)
- **`src/auth_groups/Routing/Router.php`** — enregistrement de la route `subscription`

#### Intégration

- **`src/auth_groups/Controllers/AuthController.php`** — `issueToken()` : ajout de `subscriptions{}` dans la réponse `/auth/login`
- **`src/auth_groups/Controllers/UserListController.php`** — ajout de `subscriptions{}` dans la réponse `GET /users/me`

#### CRON

- **`src/cron/expire_subscriptions.php`** — expiration automatique des abonnements dépassés + notification email ; à planifier à 03:00 (`0 3 * * *`)

#### Documentation

- **`docs/core/pub_web_windows.md`** — réécriture complète : modèle économique, guides Web/Windows, plan d'implantation en 5 phases

---

### Documentation

- **`docs/core/pub_web_windows.md`** — réécriture complète : modèle économique (gratuit avec publicité / Premium mensuel ou annuel), guides d'installation Web et Windows, flux utilisateur, plan d'implantation dans l'API CMEM2
  - Modèle Premium **par application** : statut stocké dans une table `subscriptions` avec colonne `app_id` (pas de modification de la table `users`)
  - Plan d'implantation en 5 phases : migration SQL, `SubscriptionService`, endpoints `/subscription/*`, intégration dans `/auth/login` et `/users/me`, CRON d'expiration
  - Providers supportés : Stripe (Web/Windows), Google Play, Apple App Store, Microsoft Store

---

## [2.2.3] — 2026-04-06

### Nouveau plugin — Puzzle (Phases 1–4)

Plugin puzzle sans compte : carrousel d'images, remplacement quotidien, thèmes premium, sauvegarde en ligne et casse-têtes partagés en temps réel. Authentification par token d'appareil opaque, abonnement Google Play. Endpoint prefix `/puzzle`.

#### Infrastructure

- **`src/puzzle/plugin.json`** — déclaration du plugin (namespace `Puzzle`, main_class `Puzzle\PuzzlePlugin`, 9 tables, migrations)
- **`src/puzzle/autoloader.php`** — chargeur PSR-4 pour le namespace `Puzzle\`
- **`composer.json`** — ajout `"Puzzle\\": "src/puzzle/"` dans l'autoload PSR-4
- **`src/puzzle/PuzzlePlugin.php`** — hérite `AbstractPlugin`, enregistre `puzzle` → `PuzzleRouteHandler`
- **`uploads/puzzle/.htaccess`** — `Deny from all` : images servies uniquement via PHP

#### Routing

- **`src/puzzle/Routing/PuzzleRouteHandler.php`** — handler unique `/puzzle/*`, `requiresAuth=false`,
  auth via `requireDeviceToken()` (Bearer 64-char hex) et `requirePremium()` (is_premium + premium_expires_at)

#### Modèles

- **`src/puzzle/Models/PuzzleDevice.php`** — upsert appareil, `findByValidToken()`, upgrade abonnement, sauvegarde blob, `touchLastSeen()`, `setLastReplacedAt()`
- **`src/puzzle/Models/PuzzleImage.php`** — carrousel (30 images), remplacement aléatoire, images par thème, chemins thumb/full, traductions i18n COALESCE fr/en/es
- **`src/puzzle/Models/PuzzleTheme.php`** — thèmes actifs avec image_count, chemin thumbnail
- **`src/puzzle/Models/SharedPuzzle.php`** — création, état pièces, `movePiece()` transactionnel + recalcul completion, événements polling, purge auto

#### Services

- **`src/puzzle/Services/DeviceTokenService.php`** — `generateToken()` = `bin2hex(random_bytes(32))`, `expiresAt()` = NOW + 365 j
- **`src/puzzle/Services/GooglePlayService.php`** — OAuth2 service-account JWT → `androidpublisher.googleapis.com` ; vérifie `expiryTimeMillis`
- **`src/puzzle/Services/SharedPuzzleService.php`** — `generateSeed()`, UUID v4, `generatePiecesFromSeed()` (LCG reproductible)

#### Controllers

- **`src/puzzle/Controllers/AuthController.php`** — `registerDevice` (public), `verifySubscription` (Google Play), `setPseudonym` (unicité 409)
- **`src/puzzle/Controllers/CarouselController.php`** — `getCarousel`, `replaceOne` (limite 1/jour, 429 ALREADY_REPLACED_TODAY), `replaceAll` (premium)
- **`src/puzzle/Controllers/ThemeController.php`** — `getThemes`, `getThemeImages` — Accept-Language → fr/en/es
- **`src/puzzle/Controllers/ImageDeliveryController.php`** — `serveThumb`, `serveImage`, `serveThemeThumb` via `readfile()` — protection path traversal `realpath()`
- **`src/puzzle/Controllers/SyncController.php`** — `saveBackup` (max 512 Ko), `getBackup`
- **`src/puzzle/Controllers/SharedController.php`** — cycle de vie complet partagé : créer, lister, état, mouvement, polling, quitter, supprimer

#### Migration DB

- **`docs/puzzle/migrations/001_puzzle_base.sql`** — 9 tables : `puzzle_devices`, `puzzle_images`, `puzzle_image_translations`, `puzzle_themes`, `puzzle_theme_translations`, `puzzle_image_themes`, `puzzle_shared`, `puzzle_shared_pieces`, `puzzle_shared_events`

#### Routes créées

| Méthode | Route | Auth |
| --- | --- | --- |
| POST | `/puzzle/auth/register-device` | aucune |
| POST | `/puzzle/auth/verify-subscription` | device_token |
| POST | `/puzzle/auth/pseudonym` | device_token |
| GET | `/puzzle/carousel` | device_token |
| POST | `/puzzle/carousel/replace-one` | device_token |
| POST | `/puzzle/carousel/replace-all` | device_token + premium |
| GET | `/puzzle/themes` | device_token + premium |
| GET | `/puzzle/themes/{slug}/images` | device_token + premium |
| GET | `/puzzle/thumb/{uid}` | device_token |
| GET | `/puzzle/image/{uid}` | device_token |
| GET | `/puzzle/thumb/theme/{slug}` | device_token |
| POST | `/puzzle/backup` | device_token + premium |
| GET | `/puzzle/backup` | device_token + premium |
| POST | `/puzzle/shared` | device_token + premium |
| GET | `/puzzle/shared` | device_token + premium |
| GET | `/puzzle/shared/{shared_uid}/state` | device_token + premium |
| POST | `/puzzle/shared/{shared_uid}/move` | device_token + premium |
| GET | `/puzzle/shared/{shared_uid}/events` | device_token + premium |
| POST | `/puzzle/shared/{shared_uid}/leave` | device_token + premium |
| DELETE | `/puzzle/shared/{shared_uid}` | device_token + premium (créateur) |

### Endpoints Admin — Plugin Puzzle (puzzle_images_manager)

Interface d'administration REST destinée au SPA React **puzzle_images_manager**. Toutes les routes requièrent un JWT cmem2 avec rôle `ADMINISTRATEUR`. Upload GD : JPEG/PNG → JPEG (pleine résolution ≤ 2000 px + miniature 400 px).

#### Fichiers créés / modifiés

- **`src/puzzle/Controllers/AdminController.php`** — CRUD complet images et thèmes (list, create, update, delete, reorder, setThemeImages) avec guard 409 sur sessions actives
- **`src/puzzle/Services/AdminImageService.php`** — pipeline GD : validation MIME/taille, flatten alpha PNG, resize pleine résolution, génération miniature
- **`src/puzzle/Routing/PuzzleRouteHandler.php`** — ajout du bloc admin (`$s1 === 'admin'`), méthode `handleAdminRoute()`, méthode `requireAdminJwt()` (JWT + rôle `ADMINISTRATEUR`)
- **`.env.example`** — `ALLOWED_ORIGINS` étendu : ajout `http://localhost:5173` et `https://images_manager.journauxdebord.com`

#### Routes créées

| Méthode | Route | Auth |
| --- | --- | --- |
| GET | `/puzzle/admin/images` | JWT + ADMINISTRATEUR |
| POST | `/puzzle/admin/images` | JWT + ADMINISTRATEUR |
| PUT | `/puzzle/admin/images/reorder` | JWT + ADMINISTRATEUR |
| PUT | `/puzzle/admin/images/{uid}` | JWT + ADMINISTRATEUR |
| DELETE | `/puzzle/admin/images/{uid}` | JWT + ADMINISTRATEUR |
| GET | `/puzzle/admin/themes` | JWT + ADMINISTRATEUR |
| POST | `/puzzle/admin/themes` | JWT + ADMINISTRATEUR |
| PUT | `/puzzle/admin/themes/{slug}` | JWT + ADMINISTRATEUR |
| DELETE | `/puzzle/admin/themes/{slug}` | JWT + ADMINISTRATEUR |
| PUT | `/puzzle/admin/themes/{slug}/images` | JWT + ADMINISTRATEUR |

---

## [2.2.2] — 2026-04-05

### Nouveau plugin — Quiz Phase 0 (prérequis)

Phase 0 déjà satisfaite par les prérequis Pomo (voir [2.2.2]) :
`AbstractPlugin`, `PluginManager`, `CalendarPlugin` conformes — aucun fichier créé.

### Nouveau plugin — Quiz Phase 1 (MVP REST)

Plugin de quiz interactifs en temps réel (type Kahoot). Endpoint prefix `/quiz`.

#### Infrastructure

- **`src/quiz/plugin.json`** — déclaration du plugin (namespace `Quiz`, main_class `Quiz\QuizPlugin`, tables, migrations)
- **`src/quiz/autoloader.php`** — chargeur PSR-4 pour le namespace `Quiz\`
- **`composer.json`** — ajout `"Quiz\\": "src/quiz/"` dans l'autoload PSR-4
- **`src/quiz/QuizPlugin.php`** — hérite `AbstractPlugin`, enregistre `quiz` → `QuizRouteHandler`

#### Routing

- **`src/quiz/Routing/QuizRouteHandler.php`** — handler unique `/quiz/*`, `requiresAuth=false`,
  auth conditionnelle : JWT pour routes hôte, `participant_token` (Bearer HMAC-SHA256) pour routes participant

#### Modèles

- **`src/quiz/Models/Quiz.php`** — CRUD table `quiz_quizzes`
- **`src/quiz/Models/Question.php`** — CRUD table `quiz_questions` (auto-position)
- **`src/quiz/Models/Choice.php`** — CRUD table `quiz_choices` + `findByQuestionIds()` batch
- **`src/quiz/Models/Session.php`** — table `quiz_sessions` — `advanceQuestion()`, `end()`, `listByHost()`
- **`src/quiz/Models/Participant.php`** — table `quiz_participants` — `addScore()`, `updateRanks()`, `updateToken()`, `getLeaderboard()`
- **`src/quiz/Models/ParticipantAnswer.php`** — table `quiz_participant_answers` — contrainte unicité `(participant_id, question_id)`, agrégations résultats

#### Services & Validators

- **`src/quiz/Services/SessionService.php`** — génération `session_code` 6 chars (alphabet sans ambiguïté, `random_int`), `participant_token` HMAC-SHA256(`session_id|participant_id|device_id`, `JWT_SECRET`), scoring décroissant `floor(points * max(0, 1 - elapsed_ms / (time_limit_sec * 1000)))`
- **`src/quiz/Validators/QuizValidator.php`** — validation CRUD quiz et questions/choix
- **`src/quiz/Validators/SessionValidator.php`** — validation `join` et `answer`

#### Controllers

- **`src/quiz/Controllers/QuizController.php`** — CRUD quiz, CRUD questions/choix, historique sessions
- **`src/quiz/Controllers/SessionController.php`** — `createSession`, `nextQuestion`, `endSession`, `getResults`
- **`src/quiz/Controllers/ParticipantController.php`** — `join` (reconnexion device supportée), `getSession` (question courante sans `is_correct`), `submitAnswer` (vérif question courante + unicité), `getLeaderboard`

#### Migration DB

- **`src/quiz/migrations/001_quiz_base.sql`** — 6 tables : `quiz_quizzes`, `quiz_questions`, `quiz_choices`, `quiz_sessions`, `quiz_participants`, `quiz_participant_answers` avec FK CASCADE et index

#### Routes créées

| Méthode | Route | Auth |
| --- | --- | --- |
| POST | `/quiz/join` | `device_id` + body |
| GET | `/quiz/session/{id}` | `participant_token` |
| POST | `/quiz/session/{id}/answer` | `participant_token` |
| GET | `/quiz/session/{id}/leaderboard` | `participant_token` |
| GET | `/quiz` | JWT |
| POST | `/quiz` | JWT |
| GET | `/quiz/{id}` | JWT |
| PUT | `/quiz/{id}` | JWT |
| DELETE | `/quiz/{id}` | JWT |
| POST | `/quiz/{id}/questions` | JWT |
| PUT | `/quiz/{id}/questions/{q_id}` | JWT |
| DELETE | `/quiz/{id}/questions/{q_id}` | JWT |
| POST | `/quiz/{id}/sessions` | JWT |
| POST | `/quiz/sessions/{sid}/next` | JWT |
| POST | `/quiz/sessions/{sid}/end` | JWT |
| GET | `/quiz/sessions/{sid}/results` | JWT |
| GET | `/quiz/history` | JWT |

---

## [2.2.3] — 2026-04-03

### Plugin ICS — VTODO : support RRULE (RFC 5545 §3.8.5.4)

- **`docs/build_cmem2_DB.sql`** — colonne `recurrence_rule VARCHAR(255)` ajoutée à `calendar_todos`
- **`src/ics/Models/CalendarTodo.php`** — propriété `$recurrenceRule`, INSERT et UPDATE mapping
- **`src/ics/Controllers/TodoController.php`** — validation `optional|string|max:255` + `isValidRecurrenceRule()` dans `createTodo` et `updateTodo`
- **`src/ics/Utils/IcsGenerator.php`** — propriété `RRULE` émise dans `buildVTodo()` si présente
- **`src/ics/Utils/IcsParser.php`** — `recurrence_rule` parsé dans `normalizeVTodo()` lors de l'import ICS
- **`docs/docs_ICS/API_ICS_ENDPOINTS_v1_0_0.json`** — champ `recurrence_rule` documenté sur `POST` et `PUT /calendars/{id}/todos`

#### Migration DB

```sql
ALTER TABLE calendar_todos
  ADD COLUMN recurrence_rule VARCHAR(255) DEFAULT NULL
  COMMENT 'RRULE RFC 5545 §3.8.5.4'
  AFTER related_to;
```

---

## [2.2.2] — 2026-04-02

### Nouveau plugin — Pomo Phase 0 (prérequis système)

- **`src/Core/AbstractPlugin.php`** — classe de base pour tous les plugins
  - Centralise `safeLog()` (supprimé de `PluginManager` et `CalendarPlugin`)
  - Defaults : `deactivate(): void {}`, `getDependencies(): array { return []; }`
  - Hook `runMigrations(string $path): void` (vide — à surcharger)
- **`src/ics/CalendarPlugin.php`** — hérite désormais de `AbstractPlugin`
- **`src/Core/PluginManager.php`** — `scanPluginDirectories()` utilise uniquement la présence de `plugin.json` comme critère

### Nouveau plugin — Pomo Phase 1A (engagement MVP)

Endpoint public `POST /pomo/engagement` — waitlist (courriel) et sondage (5 questions).

- **`src/pomo/plugin.json`** — déclaration du plugin (namespace `Pomo`, main_class `Pomo\PomoPlugin`)
- **`src/pomo/autoloader.php`** — chargeur PSR-4 pour le namespace `Pomo\`
- **`composer.json`** — ajout `"Pomo\\": "src/pomo/"` dans l'autoload PSR-4
- **`src/pomo/PomoPlugin.php`** — hérite `AbstractPlugin`, enregistre `pomo` → `PomoRouteHandler`
- **`src/pomo/Routing/PomoRouteHandler.php`** — handler unique `/pomo/*`, auth conditionnelle par sous-route
- **`src/pomo/Controllers/EngagementController.php`** — dispatch interne par `type` (waitlist / survey)
- **`src/pomo/Models/Engagement.php`** — accès table `pomo_engagements` (emailExists, createWaitlist, createSurvey)
- **`src/pomo/Validators/EngagementValidator.php`** — validation courriel (waitlist) + 5 réponses yes|no|maybe (survey)
- **`src/pomo/migrations/001_pomo_engagement.sql`** — table `pomo_engagements`

#### Comportement

| Cas | HTTP |
| --- | ---- |
| `type=waitlist` — succès | 201 `{success: true, data: {reference_id}}` |
| `type=waitlist` — doublon courriel | 409 |
| `type=survey` — succès | 201 `{success: true, data: {reference_id}}` |
| Validation échouée | 422 `{success: false, errors: [{field, code, message}]}` |
| `GET /health` | 200 (non impacté — core) |

### Documentation

- **`docs/pomo/API_POMO_ENDPOINTS_v1_0_0.json`** — documentation complète des endpoints Pomo (Ph1A–Ph3)

---

## [2.2.1] — 2026-04-01

### Plugin ICS — Phase 2 (Propriétés VEVENT enrichies)

- **[2.1]** `CATEGORIES` — tableau de chaînes, sérialisé `CATEGORIES:Travail,Réunion` dans l'ICS
- **[2.2]** `PRIORITY` — entier 0–9 (0 = non défini, 1 = haute, 9 = basse), propriété RFC 5545 `PRIORITY`
- **[2.3]** `CLASS` — `PUBLIC` | `PRIVATE` | `CONFIDENTIAL`, propriété `CLASS`
- **[2.4]** `TRANSP` — `OPAQUE` | `TRANSPARENT`, propriété `TRANSP`
- **[2.5]** `GEO` — latitude/longitude WGS84 (`geo_lat`, `geo_lng`), propriété `GEO:lat;lng`
  - Les deux champs doivent être fournis ensemble — fournir l'un sans l'autre retourne `400`
- **[2.6]** `ATTACH` — tableau d'objets `{url}` ou `{data_base64}` avec `mime_type` optionnel,
  propriété `ATTACH;FMTTYPE=…:…` pour URL, `ATTACH;ENCODING=BASE64;…` pour données inline

Tous ces champs sont optionnels, rétrocompatibles, et disponibles sur :
`POST /calendars/{id}/events`, `PUT /calendars/{id}/events/{eventId}`,
`GET /calendars/{id}/events/{eventId}`, `GET /calendars/{id}/ics`, import ICS

### Plugin ICS — Phase 3 (ATTENDEE, ORGANIZER, iTIP)

- **[3.1]** `ATTENDEE` complet — champs `email`, `name`, `role`, `partstat`, `rsvp`, `cutype`
  - Export : `ATTENDEE;CN=…;ROLE=…;PARTSTAT=…;RSVP=…:mailto:…`
  - Import sabre/vobject
- **[3.2]** `ORGANIZER` — colonnes `organizer_email` / `organizer_name`
  - Export : `ORGANIZER;CN=Nom:mailto:email@ex.com`
- **[3.3]** iTIP — `METHOD:REQUEST` à la création, `METHOD:CANCEL` pour annulations
  - Endpoint `POST /notifications/attendee-reply` (PARTSTAT : ACCEPTED / DECLINED / TENTATIVE)
- **[3.4]** Email d'invitation avec pièce jointe `.ics` (PHPMailer multipart/mixed, `Content-Type: text/calendar; method=REQUEST`)

### Plugin ICS — Phase 4 (Récurrence avancée & VALARM)

- **[4.1]** `EXDATE` — exceptions de récurrence dérivées de `event_occurrences.is_cancelled = 1`
  - Export : `EXDATE;TZID=…:datetime,datetime`
  - Import : crée des occurrences annulées correspondantes
- **[4.2]** `RDATE` — dates additionnelles (colonne `rdate TEXT`, CSV de datetimes locales)
  - Export / import ; génère des `event_occurrences` supplémentaires
- **[4.3]** `RELATED-TO` — colonne `related_to VARCHAR(255)` (UID parent)
  - Export : `RELATED-TO;RELTYPE=PARENT:<uid>`
- **[4.4]** `VALARM` — export automatique depuis le champ `notifications` existant
  - `ACTION:DISPLAY` / `ACTION:EMAIL`, `TRIGGER:-PT{n}M`, `DESCRIPTION:Rappel`
  - Aucune colonne supplémentaire requise
- **[4.5]** `DURATION` vs `DTEND` — colonne `duration VARCHAR(20)` format ISO 8601 (ex. `PT1H30M`)
  - Si `duration` défini → export `DURATION:…` (sans `DTEND`)
  - Import : calcule `end_datetime` depuis `DTSTART + DURATION`
  - `duration` et `end_datetime` sont mutuellement exclusifs (retourne `400` si les deux sont fournis)

### Plugin ICS — Phase 5 (Composants CalDAV additionnels)

- **[5.1]** `VTODO` — nouvelle table `calendar_todos`
  - CRUD : `POST/GET/PUT/DELETE /calendars/{id}/todos[/{todoId}]`
  - Champs : `title`, `description`, `due`, `dtstart`, `status`, `priority`, `percent_complete`,
    `location`, `categories`, `url`, `timezone`
  - Export dans `GET /calendars/{id}/ics` comme composant `BEGIN:VTODO`
- **[5.2]** `VJOURNAL` — nouvelle table `calendar_journals`
  - CRUD : `POST/GET/PUT/DELETE /calendars/{id}/journals[/{journalId}]`
  - Champs : `summary`, `description`, `dtstart`, `status` (DRAFT/FINAL/CANCELLED), `categories`, `url`
  - Export dans `GET /calendars/{id}/ics` comme composant `BEGIN:VJOURNAL`
- **[5.3]** `VFREEBUSY` — endpoint `GET /calendars/{id}/freebusy?start=…&end=…`
  - Agrège les événements `TRANSP=OPAQUE` → retourne les plages occupées
  - Exposé également via `REPORT` CalDAV
  - Nécessite Phase 2.4 (`TRANSP`) complété

### Migrations DB

Exécuter dans l'ordre :

1. `docs/docs_ICS/migrations/20260331_ph2_vevent_props.sql` (Ph2 — 7 colonnes `calendar_events`)
2. `docs/docs_ICS/migrations/20260401_ph3_organizer.sql` (Ph3 — 2 colonnes `calendar_events`)
3. `docs/docs_ICS/migrations/20260401_ph4_recurrence.sql` (Ph4 — colonnes `rdate`, `related_to`, `duration`)
4. `docs/docs_ICS/migrations/20260401_ph5_components.sql` (Ph5 — tables `calendar_todos`, `calendar_journals`)

### Documentation

- `docs/2.2.1_CLIENT.md` — guide migration client (aucun changement cassant)
- `docs/2.2.1_PRODUCTION.md` — procédure déploiement production
- `docs/core/API_ENDPOINTS_v2_0_0.json` — mis à jour (Ph2–Ph5 : nouveaux champs + VTODO/VJOURNAL/VFREEBUSY)
- `docs/docs_ICS/API_ICS_ENDPOINTS_v1_0_0.json` — mis à jour (Ph3–Ph5 complets)

---

## [2.2.0] — 2026-03-30

### Sécurité

- **Anti-énumération** — `POST /auth/resend-verification` et `POST /users/password-change`
  retournent désormais `200` avec message générique quelle que soit l'existence ou l'état du compte
  (protège contre l'énumération d'adresses email)

### Nouvelles routes

- `GET /plans/{id}` — détails d'un plan spécifique (public, non authentifié)
- `GET /stats/my-stats` — statistiques de l'utilisateur connecté (tout rôle authentifié)
  → route existante, maintenant câblée sur une méthode dédiée (séparée de `GET /stats/users/{id}`)
- `GET /secret-admin/plugins` — liste des plugins chargés, maintenant sécurisée avec `admin_secret`
  (refactorisé : retiré de `PluginController` isolé, intégré dans `SecretAdminController`)

### Normalisation des réponses

- **Fichiers** — `POST /files` : réponse enveloppée dans `{ file: { id, name, … } }` (champ `file_id` → `id`)
  `GET /files/{id}/info` : clé `data` → `file`
  `GET /files/user/{id}` : champ `file_id` → `id` dans la liste
- **Utilisateurs** — `GET /users/me` et `GET /users/{id}` : clé `data` → `user`
  `DELETE /users/me`, `DELETE /users/{id}` : réponse `{ deleted: true }` (était message texte)
  `POST /users/{id}/restore` : réponse `{ restored: true }`
- **Groupes** — `PUT /groups/{id}`, `DELETE /groups/{id}`, `POST /groups/{id}/restore`,
  `POST /groups/{id}/leave` : réponses incluent maintenant `{ group_id }`
  `PUT /groups/{id}/members/{user_id}` : réponse inclut `{ group_id, user_id }`
- **Tags** — `DELETE /tags/{id}`, `POST /tags/{id}/restore` : réponses incluent `{ tag_id }`
  `PUT /tags/{tag_id}/{item_id}` : body `action` supprimé, paramètre renommé `table_associate` (était `table`)
- **Apps** — `DELETE /users/app/{app_id}` : réponse `{ deleted: true }`
- **Plans** — `GET /plans` : supporte maintenant pagination (`page`, `limit`) et filtre `active`

### Correctifs

- `GET /stats/users/{id}` — accès restreint aux administrateurs uniquement
  (auparavant, un utilisateur pouvait consulter ses propres stats via cette route — désormais via `my-stats`)
- `CalendarController` — `updateCalendar()` et `deleteCalendar()` retournent `404` si le calendrier n'existe pas,
  avant même la vérification des permissions (ordre corrigé)
- `CalendarController::hardDeleteCalendar()` — utilise `isOwner($id, $userId, includingDeleted: true)`
  pour distinguer correctement `404` (inexistant) de `403` (pas propriétaire), y compris sur soft-deleted
- `Calendar::create()` — le champ `title` est maintenant inclus dans la réponse de création

### Refactorisation

- `Calendar::isOwner()` — paramètre optionnel `$includingDeleted = false` remplace la méthode
  `isOwnerIncludingDeleted()` séparée (rétrocompatible)
- `StatsController::getMyStats()` — requête SQL simplifiée (`ORDER BY generated_at DESC LIMIT 1`
  remplace la sous-requête corélée avec double bind)
- `.gitignore` — remplace les multiples entrées `.env.*` par `private/` (répertoire de données privées)

### Documentation

- `docs/core/API_ENDPOINTS_v2_0_0.json` — mise à jour complète :
  réponses détaillées (schemas JSON), codes HTTP, contraintes de validation,
  champs `query` / `body` / `params` enrichis pour tous les modules

---

## [2.1.1] — 2026-03-27

### Plugin ICS — Phase 1 (Fondations iCal)

- **[1.1]** Intégration `sabre/vobject` — remplacement des parseurs iCal manuels
  - Wrappers centralisés autour de `Sabre\VObject\Component\VCalendar`
  - Génération et parsing d'événements via la librairie de référence PHP CalDAV
  - Prérequis de toutes les phases ICS suivantes

- **[1.3]** UID stable RFC-conforme (UUID v4)
  - `uid` généré une seule fois à la création, jamais modifié lors des mises à jour
  - Garantit la fiabilité de la synchronisation CalDAV avec les clients externes

- **[1.4]** `DTSTART` avec paramètre `TZID`
  - Format `DTSTART;TZID=America/Montreal:20260101T090000` conforme RFC 5545
  - Les événements exportés incluent le fuseau horaire explicitement

- **[1.2]** Line folding RFC 5545 §3.1
  - `sabre/vobject` gère automatiquement le repliement à 75 octets/ligne
  - Vérifié compatible avec Google Calendar et Apple Calendar

### Nettoyage — Retrait du système de clés API

> Le système de clés API (`api_keys`) est entièrement retiré. L'authentification
> est désormais exclusivement par JWT (`POST /auth/login`, `POST /auth/verify-code`).

#### Fichiers supprimés

- `src/auth_groups/Middleware/ApiKeyAuthMiddleware.php`
- `src/auth_groups/Controllers/SecretApiKeyController.php`
- `src/auth_groups/Models/ApiKey.php`

#### Code retiré

- `UserManagerController::authenticate()` — login par API key (mort depuis v2.0.0)
- `UserController::authenticate()` et `UserController::logout()` — délégations orphelines
- `POST /users/logout` — route supprimée depuis v2.0.0 mais encore présente dans `UserRouteHandler`
- `UserSessionService::updateActivity()` — méthode morte (jamais appelée)
- `UserSessionService::endSession(?int $apiKeyId)` — paramètre `api_key_id` retiré

#### Base de données (`build_cmem2_DB.sql`)

- Table `api_keys` supprimée
- Colonne `api_key_id` retirée de `user_sessions`
- Vue `active_user_sessions` reconstruite sans JOIN `api_keys`
- Procédure `cleanup_expired_api_keys` retirée
- Contraintes FK `fk_api_keys_*` retirées

#### Divers

- `index.php` — `X-API-Key` retiré de `Access-Control-Allow-Headers`
- `restore_data.php` — `api_keys`, `login_codes`, `user_plan_history` retirés de la liste de restauration
- `GET /help` — section `api-keys` retirée

### Intégration routes — Sessions utilisateur et plugins

- `GET /users/{id}/sessions` — sessions actives d'un utilisateur (self ou admin)
- `DELETE /users/{id}/sessions` — terminer toutes les sessions (self ou admin)
- `GET /users/{id}/session-status` — vérifier si session active (self ou admin)
- `GET /stats/online` — statistiques sessions actives (admin)
- `POST /stats/cleanup-sessions` — purge des sessions expirées (admin)
- `GET /secret-admin/plugins` — liste des plugins chargés (admin)

> Ces routes existaient dans `UserSessionController` et `PluginController`
> mais n'étaient pas câblées dans les route handlers. Intégrées dans
> `UserRouteHandler`, `StatsRouteHandler` et `SecretAdminRouteHandler`.

### Migration DB (production existante)

```sql
-- Supprimer la colonne api_key_id de user_sessions
ALTER TABLE `user_sessions`
  DROP FOREIGN KEY `fk_user_sessions_api_key`,
  DROP KEY `idx_api_key_id`,
  DROP COLUMN `api_key_id`;

-- Supprimer la table api_keys
DROP TABLE IF EXISTS `api_keys`;
```

---

## [2.1.0] — 2026-03-26

> Plan complet : `docs/cmem2_Plan_Complet_Ph0-5.md`

### Sécurité

- **[A4]** Fix CORS — `Response::setCorsHeaders()` mis à jour
  - `Access-Control-Allow-Methods` : ajout de `PATCH` et `HEAD`
  - `Access-Control-Allow-Headers` : ajout de `X-API-Key`

- **[A3]** Rotation du device token à chaque `POST /auth/refresh` réussi
  - L'ancien token est révoqué, un nouveau est généré et retourné dans la réponse
  - Le client doit remplacer son `device_token` par la nouvelle valeur retournée
  - Intégration dans `AuthController::refresh()`

- **[A2]** Rate limiting — `POST /auth/login` et `POST /auth/send-code`
  - 5 tentatives max / 10 min par couple email+IP → `429 Too Many Requests`
  - Nouvelle table `login_attempts` — migration : `src/auth_groups/docs/20260325_A2_login_attempts.sql`
  - Nouveau service `RateLimitService` : `check()`, `record()`, `clear()`, `deleteExpired()`
  - Login réussi efface le compteur ; send-code enregistre chaque appel (anti-bombing)
  - Configurable : `RATE_LIMIT_AUTH_MAX_ATTEMPTS` (défaut 5), `RATE_LIMIT_AUTH_WINDOW_MINUTES` (défaut 10)

- **[A1]** Blacklist JWT — ajout du claim `jti` (UUID v4) dans chaque token généré
  - Nouvelle table `jwt_blacklist` — migration : `src/auth_groups/docs/20260325_A1_jwt_blacklist.sql`
  - `POST /auth/logout` révoque maintenant le token côté serveur (plus seulement côté client)
  - `JwtService::validate()` vérifie la blacklist à chaque requête authentifiée
  - Nouveau modèle `JwtBlacklist` : `add()`, `isBlacklisted()`, `deleteExpired()`

### Correctifs

- **[C1]** Fix contamination `self::$errors` entre appels dans `Validator`
  - Propriété statique `$errors` supprimée — variable locale `$errors` passée par référence
  - Chaque appel à `validate()` est désormais entièrement isolé
  - `applyRule()` et `addError()` reçoivent `array &$errors` en paramètre

- **[C2]** Fix règle `required` — remplace `empty()` par `!isset($value) || $value === ''`
  - `0`, `'0'`, `false` sont maintenant acceptés comme valeurs valides
  - Seuls `null` et `''` (chaîne vide) déclenchent l'erreur

- **[C3]** Fix `Response::error(array, 429)` dans `ApiKeyAuthMiddleware::authenticate()`
  - Premier argument corrigé : `array` → `string` (message)
  - Détails déplacés en second argument, `429` en troisième

### Refactoring

- **[B1]** `static $db` → propriété d'instance dans `BaseModel`
  - Supprime le slot statique partagé entre sous-classes
  - `__construct()` assigne directement `$this->db` via le singleton
  - `getDb()` retourne `$this->db` sans condition

- **[B2]** Refactor `User::findById()` / `findByEmail()` via `mapFromArray()`
  - 26 lignes d'affectation manuelle remplacées par `$this->mapFromArray($data)`

- **[B3]** Fusion `Group::create()` + `create2()` en transaction unique
  - `create()` intègre maintenant `beginTransaction()` / `commit()` / `rollBack()`
  - `create2()` assigne les propriétés depuis l'input et délègue à `create()`

- **[B4]** Retrait de `htmlspecialchars` des modèles `User` et `Group`
  - PDO prepared statements protègent déjà contre l'injection
  - Supprimé de `User::create()`, `User::update()`, `Group::create()`, `Group::update()`, `Group::updateGroup()`

- **[C4]** `countFiltered()` + pagination enrichie
  - `User::countFiltered(?string $email)` — total exact selon le filtre actif
  - `Group::countByUserId(int $userId)` et `Group::countPublic(string $search)`
  - Réponses paginées enrichies : `total`, `page`, `per_page`, `total_pages`
  - Contrôleurs mis à jour : `UserListController`, `GroupListController`

- **[E1]** Endpoint `GET /auth/me` — profil utilisateur (JWT requis)
  - `AuthController::me(int $userId)` — retourne le profil sans `password_hash`
  - Route ajoutée dans `AuthRouteHandler`

- **[E2]** Cron nettoyage — `src/cron/cleanup.php`
  - Purge : `otp_codes` (expirés/utilisés), `jwt_blacklist` (expirés), `login_attempts` (périmés)
  - CLI uniquement (`php_sapi_name() !== 'cli'` → 403)
  - Rapport horodaté sur stdout — compatible `crontab >> cron.log`

- **[D1]** Lazy-load des handlers (factory closures) dans `Router`
  - `routeHandlers` contient des `fn() => new XxxHandler()` au lieu d'instances
  - Le handler n'est instancié qu'à la réception d'une requête sur sa route

- **[D2]** `BASE_PATH` externalisé dans `environment.php`
  - Défaut `/cmem2_API`, surchargeable via `$_ENV['BASE_PATH']`
  - `Router::parseRequest()` utilise `BASE_PATH` au lieu de la chaîne littérale

- **[D3]** Suppression du fallback `$GLOBALS['pending_route_handlers']`
  - `loadPluginRouteHandlers()` ne supporte plus que `$GLOBALS['plugin_manager']`
  - Les factories de plugins sont aussi enveloppées en closures lazy

- **[D4]** Pipeline middleware dans `BaseRouteHandler`
  - `getMiddlewares()` : liste de callables surchargeable par les sous-classes
  - `runMiddleware()` : exécution séquentielle ; retourne `false` si interrompue
  - `handle()` délègue au pipeline puis à `handleRoute()`

### Infrastructure / maintenance

- Réorganisation `docs/` — migration de tous les documents dans `/docs/`
  - `cmem2_Plan_Complet_Ph0-5.md`, `2.1.0_PRODUCTION.md`, `2.1.0_CLIENT.md`
  - Sous-dossier `docs/docs_ICS/` pour la documentation du plugin ICS

- Fix chemin migrations ICS dans `CalendarPlugin::runMigrations()`
  - Chemin corrigé : `__DIR__ . '/docs_ICS/migrations/'` → `__DIR__ . '/../../docs/docs_ICS/migrations/'`

- Renommage `.env.auth_groups` → `.env` (fichier de configuration unifié)
  - `.env.example` mis à jour en conséquence
  - `environment.php` et `JwtService.php` mis à jour (`ADMIN_ENDPOINT` → `SECRET_ADMIN_ENDPOINT`)

- Séparation `docs/build_cmem2_DB.sql` — DDL pur uniquement
  - Suppression des vues inutilisées : `active_api_keys`, `api_keys_stats_by_user`,
    `group_statistics`, `v_active_users`, `v_group_dashboard`
  - Suppression des tables orphelines : `user_plan_history`, `login_codes`
  - Extraction des `INSERT users` sensibles dans `docs/seed_users.sql` (ignoré par git)
  - Purge de l'historique git (données sensibles) via `filter-branch`

---

## [2.0.0] — 2026-03-22

> Migration complète de l'authentification par API Key vers JWT Bearer.

### BREAKING CHANGES (depuis v1.x)

- `X-API-Key` supprimé — remplacé par `Authorization: Bearer {jwt}`
- `POST /users/login` supprimé — remplacé par `POST /auth/login`
- `POST /users/logout` supprimé — remplacé par `POST /auth/logout`
- La réponse de `POST /users/register` ne retourne plus `api_key`
- Les API keys ne sont plus créées à l'inscription

### Ajouté

- **Auth JWT** — `POST /auth/login` (email + password → JWT 15 jours)
- **Auth OTP** — `POST /auth/send-code` + `POST /auth/verify-code` (code 6 chiffres par email)
- **Device tokens** — `POST /auth/refresh` pour renouveler un JWT sans re-login
- **Gestion appareils** — `GET /auth/devices`, `DELETE /auth/devices/{device_id}`
- **Notifications email** — `POST /notifications/send-email`
- **Cron logs** — rotation quotidienne, 2 jours de rétention
- Table `otp_codes` — codes OTP hashés (bcrypt), 15 min, 5 tentatives max
- Table `device_tokens` — tokens longue durée associés à un appareil

### Modifié

- `user_sessions.api_key_id` rendu nullable (sessions JWT sans clé associée)
- Algorithme JWT : HS256 (HMAC-SHA256), implémentation pure PHP sans dépendance externe

### Migration DB

Exécuter : `src/auth_groups/docs/MIGRATION_JWT.sql`

---

## [1.x] — Historique

Voir les commits git antérieurs au `2024-06-17`.
