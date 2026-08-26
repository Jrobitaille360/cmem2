# Release v2.17.1 — cmem2 API

## Résumé

Prépare la release `v2.17.1` de **cmem2 API**. Corbeille (soft-delete + restauration)
pour contacts et projets, alignée sur le contrat déjà en place pour événements/todos/journaux
(directive cmem_web, task cmemweb #192).

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.17.1]` (ex `## [Unreleased 2026-08-25]`).

- **Ajouté** : `GET /contacts/deleted`, `POST /contacts/{id}/restore`,
  `GET /projets/projects/deleted`, `POST /projets/projects/{id}/restore`,
  `GET /projets/projects/{id}/tasks/deleted`, `POST /projets/tasks/{id}/restore`. Fenêtre de
  restauration de 30 jours.
- **Modifié** : `DELETE /projets/projects/{id}` passe de suppression physique à soft-delete
  (`projects.deleted_at`) — tâches et calendrier caché du projet ne sont plus cascade-supprimés.

---

## Checklist commune

- [x] Version mise à jour dans le fichier approprié (`.env` / `.env.example` — pas de champ
      `version` dans `composer.json` pour ce dépôt)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-17-1/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-17-1/RELEASE_NOTES.md` rempli et sauvegardé
- [x] `PLAN_*.md` associés déplacés dans `docs/v-2-17-1/` — aucun applicable
- [ ] CI status checks green
- [ ] Reviewer assigné

---

## Checklist API PHP

- [x] Pas de migration SQL — `deleted_at`/`supprime_le` existaient déjà sur `contacts`,
      `projects`, `calendar_todos`
- [ ] `composer install --no-dev` exécuté sur le serveur (prod, via `private/deploy.ps1 -Target prod`)
- [ ] Migrations SQL appliquées — N/A
- [ ] Caches vidés / régénérés — N/A
- [ ] Endpoint `/health` répond correctement

## Notes pour le release manager

- Déjà validé sur `dev-cmem2.journauxdebord.com` (déploiement itératif +
  `php private/tests/test_contacts.php`, `test_projets.php`, suite complète
  `run_all_tests.php` : 3096/3098, les 2 échecs restants sont une flakiness pré-existante
  documentée sur `test_calendars.php` §16e.6e, sans lien avec cette release).
- Après merge, tagger le commit :
  `git tag -a v2.17.1 -m "Release v2.17.1"` puis `git push origin v2.17.1`.
