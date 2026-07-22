<!-- markdownlint-disable MD013 -->
# PLAN — Dépréciation du chemin d'occurrences matérialisées

> Directive source : `directives_inter_projet/20260721_202158_cmem_web_vers_cmem2_API__depreciation-ancien-chemin-occurrences.md`
> Décisions actées avec l'utilisateur (2026-07-21) : endpoints legacy → `410` (les deux, calendrier + par-événement) ; listing `/events` → **option B** (plus d'expansion récurrente, tout passe par `/occurrences/expand`) ; support **RDATE à la volée** ajouté (option 1) pour rendre la purge sûre. Compteurs RDATE en base = 0 (support préventif).

## Critères d'acceptation (testables)

1. `GET /calendars/{id}/events/occurrences` → `410 Gone` (message pointant vers `/occurrences/expand`).
2. `GET /calendars/{id}/events/{eventId}/occurrences` → `410 Gone`.
3. `GET /calendars/{id}/events/occurrences/expand?start&end` → inchangé (occurrences + exceptions).
4. Expansion à la volée correcte **au-delà de 2099** (ex. `start=2100-01-01&end=2100-01-31` sur un quotidien).
5. Créer / modifier un événement récurrent **n'écrit plus** de lignes bulk dans `event_occurrences`.
6. Le CRON (`maintenance_occurrences.php`) et `MaintenanceService` **ne re-matérialisent plus** (no-op déprécié).
7. Les exceptions restent la source de vérité : EXDATE (`is_cancelled=1`) et modifications (`is_modified=1`, `modified_*`) toujours écrites et lues par `/expand`.
8. Un événement avec `rdate` produit ses occurrences RDATE **via `/expand`** (moteur à la volée), sans dépendre de lignes matérialisées.
9. `PUT`/`DELETE /calendars/{id}/events/{eventId}/occurrences` par **clé date** (RECURRENCE-ID) fonctionnent même sans ligne matérialisée pré-existante (materialize-on-demand).

## Chemins matérialisés recensés (audit)

| Lecteur/écrivain | Fichier:ligne | Action |
| - | - | - |
| Legacy GET calendrier-entier | CalendarController.php:2245 | → `410` |
| Legacy GET par-événement | CalendarController.php:1762 | → `410` |
| Listing générique (lecture table) | CalendarEvent.php:276 | Option B — retiré |
| Bulk write (create) | CalendarEvent.php:151 | Retiré |
| Bulk write (update) | CalendarEvent.php:510 | Retiré |
| Write RDATE | CalendarEvent.php:772/885 | Retiré (RDATE à la volée) |
| EXDATE → `is_cancelled=1` | CalendarEvent.php:764/882 | **Conservé** |
| Régénération maintenance | OccurrenceMaintenanceService::performMaintenance | No-op déprécié |
| Notifications (`modified_*`) | EmailNotificationService.php:558 | Safe (fallback null) |
| Génération .ics (`is_cancelled`) | IcsGenerator.php:53/97 | Safe (lignes conservées) |
| Moteur `/expand` | EventOccurrence::getExpandedByCalendarId → RecurrenceService::expandInRangeTzAware | + support RDATE |

Dead code après changements (aucun appelant) : `EventOccurrence::getByCalendarId/getByEventId/getByEventIds`, cap `ICS_OCCURRENCES_MAX_DATE`. Laissés en place (hors périmètre), à retirer à une version ultérieure.

## Ordre d'exécution (invariant directive)

1. (Étape 1) **[FAIT]** Confirmer zéro trafic legacy (logs prod — confirmé par l'utilisateur).
2. (Étape 2) Désactiver matérialisation à l'écriture + no-op maintenance (code, non-destructif).
3. (Étape 3) Arrêter matérialisation + lever cap 2099 + RDATE à la volée + option B (code, non-destructif).
4. (Étape 5) Retirer endpoints legacy → `410` (code, non-destructif).
5. (Étape 4) **[STOP]** Purge `event_occurrences` non-modifiées — **backup + confirmation avant `DELETE`**. À exécuter **après** déploiement du code (la re-matérialisation devient no-op).

## Étape 4 — purge (à confirmer séparément)

```sql
-- Backup préalable OBLIGATOIRE (mysqldump event_occurrences)
-- Comptage exceptions AVANT
SELECT COUNT(*) AS ex_avant FROM event_occurrences WHERE is_cancelled=1 OR is_modified=1;
-- Purge des lignes recalculables (non-exceptions), RDATE (recurrence_index=-1) inclus car régénérés à la volée
DELETE FROM event_occurrences WHERE is_cancelled=0 AND is_modified=0;
-- Comptage exceptions APRÈS (doit être identique à ex_avant)
SELECT COUNT(*) AS ex_apres FROM event_occurrences WHERE is_cancelled=1 OR is_modified=1;
```

## Checklist production

> **Aucune modif crontab nécessaire** (voir `docs/cron.md`) : `maintenance_occurrences.php`
> n'est pas planifié. La régénération d'occurrences passait par le cron `src/cron/maintenance.php`
> (3h00) → `ICS\MaintenanceService::regenerateOccurrences()` → `OccurrenceMaintenanceService::performMaintenance()`,
> désormais **no-op via le code**. Déployer le code coupe donc la re-matérialisation ; le cron
> `maintenance.php` reste en place pour ses autres tâches.

- [ ] **Déployer le code (2-3-5)** — invariant : coupe la re-matérialisation (no-op) **avant** la purge.
- [ ] Backup `event_occurrences` (mysqldump).
- [ ] Exécuter la purge, vérifier comptage exceptions identique avant/après.
- [ ] Vérifier `/expand` au-delà de 2099.
