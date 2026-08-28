# Release v2.17.3 — cmem2_API

## Résumé

Feature release : préférence opt-in `show_entity_detail` pour le module Push — titre
réel de l'entité dans le rappel au lieu du générique, directive cmem_web task
cmemweb #199.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur (dev + prod)

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.17.3] — 2026-08-28`.

### Ajouté

- Push : préférence opt-in `show_entity_detail` par kind (défaut `false`). Réglé à
  `true`, `title` devient le titre réel de l'entité (événement, tâche, contact,
  opportunité) au lieu du générique `PUSH_GENERIC_TITLE` ; `body` reste toujours le
  texte générique par délai. `GET`/`PUT /push/preferences` exposent le nouveau champ.

---

## Checklist commune

- [x] Version mise à jour (`.env`, `.env.example` → `APP_VERSION=2.17.3` ;
      `private/utilitaires/.env.dev.online` et `.env.prod` à confirmer avant tag)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-17-3/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-17-3/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Aucun `PLAN_*.md` associé à cette release
- [ ] CI status checks green
- [ ] Reviewer assigné

---

## Checklist API PHP

- [ ] `composer install --no-dev` exécuté sur le serveur (fait par `deploy.ps1`)
- [x] Migrations SQL : `docs/v-2-17-3/20260828_push_show_entity_detail.sql` — déjà
      appliquée sur dev-cmem2 et prod
- [ ] Caches vidés / régénérés — n/a
- [x] `php private/tests/test_push.php` — 114/114 sur dev-cmem2 (show_entity_detail
      validé en réel, ON et OFF)
- [ ] Endpoint `/health` répond correctement (post-déploiement)

## Connu / hors scope de cette release

- `run_all_tests.php` : 8 échecs pré-existants, non liés au diff — voir
  `docs/v-2-17-3/2.17.3_PRODUCTION.md` § Connu / hors scope.

---

## Notes pour le release manager

- Après merge, tagger le commit :
  `git tag -a v2.17.3 -m "Release v2.17.3"` puis `git push origin v2.17.3`.
