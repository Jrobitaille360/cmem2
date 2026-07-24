# Release v2.10.0 — cmem2 API

## Résumé

Release `v2.10.0` de **cmem2 API**. Ajoute trois piliers backend (Contacts, Liens
croisés, Projets), étend le tenant Stripe `cmemweb`, et **déprécie** le chemin
d'occurrences de calendrier matérialisées (BREAKING). Suite complète : 1803/1803 verts.

## Formats publiés dans cette release

- [x] API — Déploiement serveur (prod + dev)

## Types de changement

- [x] Nouvelle fonctionnalité (Contacts, Links, Projets)
- [x] BREAKING CHANGE (occurrences matérialisées → `410`, Google Play désactivé pour puzzle)
- [x] Migration SQL
- [x] Documentation

## Changements (résumé)

### Ajouté

- Pilier **Contacts** (`/contacts`) : CRUD, vCard 4.0 import/export, cap `max_contacts`.
- **Liens croisés** polymorphes (`/links`) : event/task/journal/project/project_task.
- Plugin **Projets** (`/projets`) : arbre/DAG, round-trip JSON, export `.ics`.
- Tenant Stripe **cmemweb** (`app_id` `cmemweb` primaire, `cmem` alias legacy).
- `POST /files` accepte `.gpx`.

### Modifié / BREAKING

- Occurrences matérialisées retirées → `410 Gone` ; expansion via `.../occurrences/expand`.
- Google Play / AdMob désactivés pour `app_id=puzzle` → `410 PROVIDER_DISABLED` (Stripe seul).

## Tests

- Suite complète : **1803 / 1803** (0 échec) — `php private/tests/run_all_tests.php`.
- Tests playstore/access réalignés sur le `410 PROVIDER_DISABLED` (puzzle).

## Migrations SQL

- `20260721_projets_taches.sql`, `20260722_links.sql`, `20260723_contacts.sql`
  intégrées dans `docs/v-2-10-0/build_DB-v-2.10.0.sql`.

## Références

- `docs/v-2-10-0/2.10.0_CLIENT.md`, `docs/v-2-10-0/2.10.0_PRODUCTION.md`
- `docs/v-2-10-0/PLAN_gestion_projet_icalendar.md`
- `docs/v-2-10-0/PLAN_depreciation_occurrences_materialisees.md`

## Checklist avant merge

- [x] `APP_VERSION=2.10.0` (`.env`, `.env.example`)
- [x] `CHANGELOG.md` mis à jour
- [x] `PR_BODY.md` / `RELEASE_NOTES.md` remplis
- [x] `PLAN_*.md` associés déplacés dans `docs/v-2-10-0/`
- [x] Suite de tests verte (1803/1803)
- [ ] Reviewer assigné

## Notes pour le release manager

- **Pas de `composer` sur le serveur** — `vendor/` déjà déployé.
- Après merge : `git tag -a v2.10.0 -m "Release v2.10.0"` puis `git push origin v2.10.0`.
