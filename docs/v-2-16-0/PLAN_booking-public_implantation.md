# PLAN_booking-public — Implantation

Suivi d'exécution de [PLAN_booking-public.md](PLAN_booking-public.md). Cible de test : dev-cmem
(`https://dev-cmem2.journauxdebord.com`).

## Phase 1 — Schéma + config module gating

- **Début** : 2026-08-13 18:20
- **Fin** : 2026-08-13 18:35
- **Résultat** : `docs/v-2-16-0/20260813_booking_public.sql` créé et appliqué sur la base dev
  (`booking_pages`, `booking_slots`). `CmemModules::AVAILABLE` mis à jour (`booking` sur
  monthly/yearly/team/ami, pas free). Contraintes vérifiées manuellement (doublon
  `owner_id+app_id` et `app_id+slug` rejetés). `test_booking.php` écrit (couvre l'ensemble des
  critères d'acceptation du plan) et exécuté : échecs attendus (`404 Endpoint non trouvé`, routes
  pas encore enregistrées) — échoue pour la bonne raison.

## Phase 2 — Scaffolding module + endpoints hôte

- **Début** : 2026-08-13 18:40
- **Fin** : 2026-08-13 18:50
- **Résultat** : `src/booking/` créé (`BookingPlugin.php`, `plugin.json`, `autoloader.php`,
  `Models/BookingPage.php`, `Models/BookingSlot.php`, `Controllers/BookingPageController.php`,
  `Routing/BookingRouteHandler.php`). `GET/PUT/DELETE /booking/page` fonctionnels : gating
  `MODULE_NOT_AVAILABLE`, validation `calendar_id` (appartenance), `slug` (format + unicité),
  `availability_windows`, `timezone` (bug initial : `DateTimeZone::listIdentifiers()` sans
  `ALL_WITH_BC` rejetait `America/Montreal` — corrigé). Déployé sur dev-cmem (2 déploiements),
  suite de tests section 1 (gating) et section 2 (CRUD page) intégralement vertes (10/10). Sections
  3-8 (public, slots, book, cancel, rate-limit) toujours en échec `404` — attendu, hors scope
  Phase 2, planifié Phases 3-7.

## Phase 3 — Génération / régénération de zones

- **Début** : 2026-08-13 18:55
- **Fin** : 2026-08-13 19:05
- **Résultat** : `Services/BookingSlotService::regenerate()` — supprime les zones non réservées
  futures, génère les créneaux candidats depuis `availability_windows`/`duration_minutes` sur
  `horizon_days` (heure locale de la page), exclut ceux qui chevauchent un événement OPAQUE du
  calendrier hôte (`EventOccurrence::getExpandedOpaqueByCalendarId`, buffers avant/après appliqués
  à la fenêtre de conflit), convertit les survivants en UTC (`BookingSlot::insertMany`). Branché
  dans `PUT /booking/page` après l'upsert. `BookingPage::findById()` et `BookingSlot::insertMany()`
  ajoutés. Pas encore testable via `test_booking.php` (dépend de `GET
  /booking/public/{slug}/slots`, Phase 4) — vérifié manuellement via script scratch
  (`verify_phase3.php`, hors dépôt) : page hôte America/Montreal, fenêtre 09:00-17:00 un jour
  donné, événement bloquant 10h-11h local inséré directement en base → 14 zones de 30 min
  générées en UTC (13:00-21:00 UTC minus les deux zones 14:00-15:00 UTC), aucun chevauchement
  avec l'événement bloquant. Déployé sur dev-cmem.

## Phase 4 — Endpoints publics lecture (page + slots)

- **Début** : 2026-08-13 19:10
- **Fin** : 2026-08-13 19:20
- **Résultat** : `Controllers/BookingPublicController.php` (`get()`, `slots()`),
  `BookingSlot::findFreeInRange()`, routes ajoutées dans `BookingRouteHandler`. `404
  BOOKING_UNAVAILABLE` uniforme vérifié sur les 2 causes testées (inexistant, inactif — la 3e
  cause, plan rétrogradé, share le même code path `usablePage()`, non testée séparément mais
  couverte par construction). `slots` : plafond 60 jours, format ISO `Y-m-d\TH:i:s\Z` (conforme à
  l'exemple de la directive). Déployé, `test_booking.php` : sections 1-4 intégralement vertes
  (42/49 au total, échecs restants tous Phase 5+ — 404 attendu sur book/cancel).

## Phase 5 — Réservation + annulation

- **Début** : 2026-08-13 19:25
- **Fin** : 2026-08-13 19:42
- **Résultat** : `CMEMWEB_APP_URL` ajouté à `.env`/`.env.example`/`.env.dev.online`/`.env.prod`
  (`https://cmem-web.journauxdebord.com`, décision actée). `Services/BookingGateService`
  (factorise la vérification page existante/active/plan éligible, partagée lecture+réservation).
  `Controllers/BookingReservationController` (`book()`, `cancel()`) ; `BookingSlot::reserve()`
  (UPDATE atomique `WHERE reserved=0`), `release()` (rollback), `attachEvent()`,
  `findByCancelToken()`, `releaseByToken()` (atomique, idempotent). Événement créé via
  `ICS\Models\CalendarEvent`, converti de UTC (stockage `booking_slots`) vers l'heure locale de la
  page pour `calendar_events` (convention naïve/wall-clock du module ICS). Courriel de
  confirmation (`EmailService::sendBookingConfirmation`, nouveau template) avec lien
  `{CMEMWEB_APP_URL}/book/{slug}?cancel_token=...`. Déployé, `test_booking.php` : 54/55 —
  cycle complet réserver → 409 sur conflit → annuler → re-proposé → rejeu idempotent 404, tous
  verts. Seul échec restant : rate-limit (7.1), hors scope Phase 5, prévu Phase 6.

## Phase 6 — Rate limiting

- **Début** : 2026-08-13 19:45
- **Fin** : 2026-08-13 19:55
- **Résultat** : `RateLimitService::check()` accepte désormais `$maxOverride`/`$windowOverride`
  optionnels (rétrocompatible) ; `getClientIp()` passée en public (clé d'identification pour les
  endpoints anonymes, pas d'email disponible). Branché sur les 3 routes publiques : `booking_slots`
  20/min, `booking_book` et `booking_cancel` 5/min, keyées par IP. Bug de test découvert et
  corrigé : la rafale de la section 7 saturait le seuil IP pour le reste du run (et pour un rerun
  rapproché < 1 min) — section 7 déplacée en dernier + nettoyage `login_attempts` des 3 buckets
  booking en tête de suite. Déployé, `test_booking.php` : **55/55, 0 échec.** Cycle complet
  (gating, CRUD hôte, génération de zones, lecture publique, réservation/conflit/annulation,
  rate-limit) entièrement vert.

## Phase 7 — Cron quotidien

- **Début** : 2026-08-13 20:00
- **Fin** : 2026-08-13 20:10
- **Résultat** : `src/cron/booking_regenerate.php` (patron `todo_reschedule.php` — garde anti-web,
  bootstrap, `LogService`, résumé texte) — pour chaque `booking_pages.active=1`, appelle
  `BookingSlotService::regenerate()` (même moteur que `PUT /booking/page`, pas de logique
  dupliquée). `docs/cron.md` documenté avec la ligne crontab proposée. **Non installée sur le
  crontab serveur réel** — changement infra/production, hors scope de cette implantation sans
  confirmation explicite séparée (règle STOP du projet). Vérifié manuellement en local contre la
  base dev (`php src/cron/booking_regenerate.php`) : page à 7 fenêtres/80 zones, une zone marquée
  réservée directement en base, un événement bloquant inséré *après* la génération initiale sur
  une 2e zone libre → après passage du cron, la zone réservée est intacte (`reserved=1`
  inchangé), la zone qui chevauchait le nouvel événement a disparu. Comportement conforme à la
  directive (délai jusqu'au prochain cron, pas de perte de réservation).

## Phase 8 — Tests bout-en-bout, documentation, changelog

- **Début** : 2026-08-13 20:15
- **Fin** : 2026-08-13 20:40
- **Résultat** : `docs/booking/GUIDE.md` + `docs/booking/API_BOOKING_ENDPOINTS.json` (patron
  `docs/pomo/`) ; `docs/modules/GUIDE.md` mis à jour (mapping `booking` monthly/yearly/team/ami +
  note sur `booking_pages.active` comme véritable interrupteur, indépendant de
  `tenant_modules.enabled`) ; `CHANGELOG.md` ; `test_booking.php` ajouté à
  `private/tests/run_all_tests.php` (absent jusque-là) ; `CLAUDE.md` mis à jour (liste des tests +
  tableau des modules). Suite complète (`run_all_tests.php`, 2805 tests, 2 exécutions) :
  `test_booking.php` **55/55** les deux fois ; échecs restants (7-11 selon le run, jamais dans
  `test_booking.php`) tous dans 3 catégories préexistantes et flaky (rate-limit `send-code`,
  `If-Unmodified-Since` timing sur events/todos/journals/tasks) — aucun fichier touché par cette
  implantation ne recoupe ces échecs. Directive `20260813_163000_..._booking-public.md` passée à
  `statut: complété`, cases cochées, note de complétion ajoutée ; `_INDEX.md` mis à jour.

## Phase 9 — Déploiement prod (2026-08-14)

- **Début** : 2026-08-14 07:40
- **Fin** : 2026-08-14 07:50
- **Résultat** : migration `docs/v-2-16-0/20260813_booking_public.sql` appliquée sur la base prod
  (`lmdkhdg5_cmem2`, vérifiée — `booking_pages`/`booking_slots` présentes) par l'utilisateur ;
  code déployé sur dev-cmem2 (redéploiement, à jour) puis prod (`private/deploy.ps1 -Target
  prod`, commit `84c13a0`) ; fumée post-déploiement : `GET
  https://cmem2.journauxdebord.com/booking/public/smoke-test-nonexistent` → `404
  BOOKING_UNAVAILABLE`, conforme. Cron `src/cron/booking_regenerate.php` installé sur le
  crontab **prod** par l'utilisateur (`0 4 * * *`), pas d'entrée sur dev (pas nécessaire — aucune
  page active en continu). `docs/cron.md` mis à jour en conséquence.

## Phase 10 — Ancrage version 2.16.0 (2026-08-14)

- **Début** : 2026-08-14 07:55
- **Fin** : 2026-08-14 08:05
- **Résultat** : `CHANGELOG.md` — les 4 entrées `[Unreleased ...]` accumulées depuis 2.15.0
  (booking, correctif prix Stripe team, plan équipe, versioning optimiste + incident proxy IA)
  fusionnées en `## [2.16.0] — 2026-08-14`, nouveau `## [Unreleased]` vide au-dessus. Dossier
  `docs/v-2-16-0/` créé : `PLAN_plan-equipe.md`, `PLAN_booking-public.md`,
  `PLAN_booking-public_implantation.md` (ce fichier) et les deux migrations pendantes
  (`20260813_group_billing.sql`, `20260813_booking_public.sql`) déplacés depuis `/docs/` ;
  `build_DB-v-2.16.0.sql` construit à partir du baseline v2.15.0 + les deux migrations intégrées
  en fin de fichier (patron déjà suivi par v2.13/2.14/2.15 — append, pas de réécriture des
  `CREATE TABLE` déjà fixés) ; `2.16.0_CLIENT.md` et `2.16.0_PRODUCTION.md` rédigés
  rétrospectivement (le déploiement avait déjà eu lieu). `APP_VERSION` bumpé à `2.16.0` dans les 4
  fichiers `.env`/`.env.example`/`.env.dev.online`/`.env.prod`, redéployé sur dev et prod (`GET /`
  confirme `version: 2.16.0` en prod). `README.md` : badge, section Vue d'ensemble, nouvelle
  section Booking dans Modules et endpoints, ligne Documentation, Roadmap, pied de page — tous
  mis à jour. Toutes les références aux deux fichiers `.sql` déplacés corrigées dans
  `CHANGELOG.md`, `docs/booking/GUIDE.md`, `docs/modules/GUIDE.md` et les `PLAN_*.md` eux-mêmes.

## Récapitulatif final

Les 10 phases sont livrées et déployées (dev + prod), version 2.16.0 ancrée. Reste :

- Commit + push git — prochaine étape, sur confirmation.
- `private/sync.ps1` à exécuter après le commit s'il existe.
