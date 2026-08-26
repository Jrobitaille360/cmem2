# PLAN — Expansion d'occurrences à la demande (endpoint additif)

Directive source : `20260707_082007_cmem_web_vers_cmem2_API__endpoint-occurrences-expand-a-la-demande.md`

## Contexte

Le client React (phase 3 du rewrite cmem_web) ne veut plus dépendre de la table
`event_occurrences` pré-calculée (CRON jusqu'à 2099). Il consommera un nouvel endpoint qui
expanse les RRULE à la volée, uniquement sur la plage demandée, timezone-aware (DST-safe).
Le client Flutter reste en prod sur l'ancien chemin — **rien d'existant ne doit changer**.

## Déjà en place

- `RecurrenceService::calculateOccurrences()` expanse une RRULE via `simshaun/recurr`, mais
  construit `new \DateTime($event['start_datetime'])` **sans timezone** (utilise le fuseau
  serveur/PHP par défaut, pas le `TZID` de l'événement) → pas DST-safe. Ne pas modifier
  (chemin CRON existant, client Flutter).
- `EventOccurrence::applyModifications()` (private) sait déjà fusionner les champs
  `modified_*` d'une exception sur une occurrence — logique réutilisable telle quelle.
- `event_occurrences.is_cancelled` / `is_modified` + `modified_*` : source de vérité commune
  déjà en place, déjà lue par l'ancien chemin.
- `simshaun/recurr` v5 : `Rule` accepte un `$timezone` explicite en 4e paramètre ; `BetweenConstraint`
  permet de limiter la transformation à une plage sans matérialiser toute la série (plus
  efficace que `setVirtualLimit(10000)` utilisé par l'ancien chemin).
- Directive #1 (fix frontière `end_date`) déjà livrée : `EventOccurrence::endOfDayIfDateOnly()`
  disponible et réutilisable pour normaliser les bornes du nouvel endpoint.

## Améliorations à faire

1. **Nouveau service d'expansion TZID-aware** — construire la `Rule` avec le `TZID` de
   l'événement et une `BetweenConstraint([start, end])`, indépendamment du chemin CRON.
2. **Orchestration calendrier / événement** — récupérer les événements (récurrents +
   non récurrents) dans la plage, appliquer les exceptions (`is_cancelled`/`is_modified`)
   depuis `event_occurrences`, sans jamais écrire dans cette table.
3. **Validation RRULE en entrée** — rejeter en 400/422 (jamais 500) les RRULE que `recurr`
   ne sait pas expanser correctement.
4. **Nouvelles routes additives** + doc JSON.

## Maintenances à prévoir

- Si `simshaun/recurr` est mis à jour, revalider le sous-ensemble RRULE documenté.
- Phase 9 (future, hors scope) : dépréciation de l'ancien chemin — **ne pas anticiper ici**.

---

## Conception

### Routes (additives)

```txt
GET /calendars/{id}/events/occurrences/expand?start=&end=
GET /calendars/{id}/events/{eventId}/occurrences/expand?start=&end=
```

Ajoutées dans `CalendarRouteHandler::handleRoute()` — pattern `segments[4] === 'occurrences'
&& segments[5] === 'expand'`, matché **avant** les routes `.../occurrences` existantes (ordre
`match(true)` déjà top-down, donc ajouter les nouvelles routes juste avant les anciennes
équivalentes).

### Validation des paramètres

- `start` / `end` : `date_or_datetime`, tous deux requis (le range doit être borné —
  contrairement à l'ancien chemin qui tolère l'absence de bornes grâce à la table
  pré-calculée, ici pas de bornes = expansion potentiellement infinie).
- `end` date-seule → fin de journée inclusive via `EventOccurrence::endOfDayIfDateOnly()`
  (réutilisé, rendu `public static`).
- `start` date-seule → minuit (déjà le comportement naturel, pas de transformation requise).

### Expansion TZID-aware (nouveau)

Nouvelle méthode `RecurrenceService::expandInRangeTzAware(array $event, string $start, string $end): array` :

```php
$tz = new \DateTimeZone($event['timezone'] ?? 'America/Montreal');
$startDt = new \DateTime($event['start_datetime'], $tz);
$rule = new \Recurr\Rule('RRULE:' . $event['recurrence_rule'], $startDt, null, $event['timezone']);
$constraint = new \Recurr\Transformer\Constraint\BetweenConstraint(
    new \DateTime($start, $tz), new \DateTime($end, $tz), true
);
$transformer = new \Recurr\Transformer\ArrayTransformer();
$occurrences = $transformer->transform($rule, $constraint);
```

- Ne touche pas `calculateOccurrences()` (chemin CRON existant, intact).
- `try/catch` autour de la construction `Rule` + `transform()` : toute exception `recurr`
  (RRULE invalide ou combinaison non supportée) → remontée à l'appelant comme erreur de
  validation (pas de 500). C'est le mécanisme de validation RRULE (pragmatique : on
  documente le sous-ensemble supporté comme "ce que `recurr` accepte sans exception", plutôt
  qu'une liste blanche maison — `recurr` fait déjà cette vérification en interne).

### Orchestration (nouveau)

Nouvelles méthodes dans `EventOccurrence` (modèle, car lit `event_occurrences` pour les
exceptions + `calendar_events` pour les événements) :

- `getExpandedByCalendarId(int $calendarId, string $start, string $end): array`
- `getExpandedByEventId(int $eventId, int $calendarId, string $start, string $end): array`

Logique commune (factorisée dans une méthode privée `expandEventInRange`) :

1. Charger l'événement (ou les événements du calendrier).
2. Si `recurrence_rule` vide → événement inclus tel quel si dans la plage (même règle que
   l'ancien chemin : `end_datetime >= start && start_datetime <= end`).
3. Si `recurrence_rule` présent → appeler `RecurrenceService::expandInRangeTzAware()`.
4. Pour chaque occurrence générée, chercher une ligne d'exception dans `event_occurrences`
   (`WHERE event_id = ? AND occurrence_date = ?`) :
   - `is_cancelled = 1` → occurrence exclue (EXDATE).
   - `is_modified = 1` → fusionner via `EventOccurrence::applyModifications()` (rendue
     réutilisable, visibilité `private` → `protected`/`public static`).
5. Ne jamais écrire dans `event_occurrences` (lecture seule).

### Réponse

Même forme que l'ancien endpoint (`{ occurrences: [...], count: N }`), mêmes noms de champs
(`event_id`, `title`, `start_datetime`, `end_datetime`, `is_cancelled`, `is_modified`,
`modified_*`, `timezone`, etc.). Différences à documenter dans le JSON d'entrypoints :

- Pas de champ `id` (occurrence non stockée, pas de ligne DB).
- Pas de champ `is_on_demand` (tout l'endpoint est "à la demande" par définition).
- Bornes `start`/`end` **requises** (pas de défaut silencieux comme l'ancien chemin).

### Codes d'erreur

| Cas | Code |
| - | - |
| `start`/`end` manquants ou invalides | 400 |
| RRULE hors sous-ensemble supporté (exception `recurr`) | 422 |
| Calendrier/événement inexistant ou non autorisé | 404/403 (inchangé) |
| Erreur interne imprévue | 500 (ne doit jamais être déclenché par une RRULE invalide) |

### Documentation

- `docs/ics/API_ICS_ENDPOINTS.json` — nouveau bloc pour les 2 routes (paramètres, réponse,
  codes d'erreur, sous-ensemble RRULE supporté = "tout ce que `simshaun/recurr` v5 accepte
  sans lever d'exception").
- `docs/ics/GUIDE.md` — section correspondante (cohérence avec convention du projet).

---

## Phases d'implantation

### Phase 1 — Spec-first : critères d'acceptation + tests en échec

**Actions** : traduire la directive en critères testables (déjà fait ci-dessous), écrire les
tests dans `private/tests/test_calendars.php` (ou nouveau fichier
`test_ics_occurrences_expand.php`), exécuter et confirmer qu'ils échouent pour la bonne
raison (404 route inexistante).

**Critères d'acceptation** :

1. Événement hebdo 09:00 `America/Toronto` → occurrences de mars **et** novembre à 09:00
   locale strictement (aucune dérive DST).
2. Occurrence annulée (`is_cancelled`) absente ; occurrence modifiée (`is_modified`) retournée
   avec `modified_summary`/`modified_*` appliqués.
3. `end` date-seule → occurrence horaire du dernier jour incluse ; plage sans occurrence →
   200 + tableau vide.
4. RRULE hors sous-ensemble supporté → 400/422 explicite, jamais 500.
5. Non-régression : ancien endpoint (`/events/occurrences` sans `/expand`), CRON, table
   `event_occurrences` strictement inchangés (déjà couvert par la suite existante — vérifier
   qu'elle reste à 100 %).

**Enjeux** : s'assurer que le TZID est bien celui de l'événement, pas celui du serveur/DB
(`SET time_zone = '+00:00'` dans `Database::connect()` — donc `\DateTime` sans timezone
explicite tombe en UTC, pas en heure locale : piège à éviter, confirmé par le bug déjà présent
dans `calculateOccurrences()`).

**Tests** : les 5 tests ci-dessus, exécutés et rouges avant tout code de prod.

**Condition de fin de phase** : tests écrits, exécutés, échouent pour la bonne raison (route
inexistante / 404), aucun fichier `src/` touché.

### Phase 2 — Implémentation minimale

**Actions** :

- `EventOccurrence::endOfDayIfDateOnly()` → `public static` (réutilisation).
- `EventOccurrence::applyModifications()` → visibilité élargie (réutilisation).
- `RecurrenceService::expandInRangeTzAware()` (nouveau, isolé, ne touche pas
  `calculateOccurrences()`).
- `EventOccurrence::getExpandedByCalendarId()` / `getExpandedByEventId()` (nouveau).
- `CalendarController::getEventsOccurrencesExpand()` / `getEventOccurrenceExpand()` (nouveau).
- 2 routes additives dans `CalendarRouteHandler`.
- Pas d'abstraction supplémentaire (pas de nouvelle classe "Service" séparée tant que la
  logique tient dans `EventOccurrence` + `RecurrenceService` existants).

**Enjeux** : ne pas introduire de régression sur les routes `match(true)` existantes (ordre
des cas) ; ne pas déclencher `generateAllOccurrences`/CRON depuis ce chemin (lecture seule).

**Tests** : réexécuter la suite complète (`run_all_tests.php`) + les 5 nouveaux tests.

**Condition de fin de phase** : les 5 tests passent, suite complète à 100 %, diff ne touche
aucune méthode de l'ancien chemin (`calculateOccurrences`, `generateAllOccurrences`,
`getByCalendarId`, `getByEventId`, `getByEventIds` intacts sauf visibilité des 2 helpers).

### Phase 3 — Documentation + déploiement dev

**Actions** : `docs/ics/API_ICS_ENDPOINTS.json` + `docs/ics/GUIDE.md`, CHANGELOG.md, déploiement
`private\deploy.ps1` (dev uniquement — prod hors scope tant que le client React n'est pas en
phase 4).

**Enjeux** : le contrat documenté est ce que la phase 4 du client React consommera — figer
les noms de champs avant que le client ne commence à intégrer.

**Condition de fin de phase** : JSON + GUIDE à jour, déployé sur dev, directive mise à jour
(`statut: en_cours` → conditions cochées, `complété` seulement après confirmation que le
contrat convient au client React).
