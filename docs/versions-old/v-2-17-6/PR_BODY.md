# Release v2.17.6 — cmem2_API

## Résumé

Correctif de l'import JSON de projet (`POST /projets/projects/{id}/import.json[/confirm]`) :
remappage des id numériques temporaires, validation stricte de `dependsOn`, dry-run enrichi
(`liens`, `doublonsTitre`). Directives cmemweb #235 et #234.

## Formats publiés dans cette release

- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.17.6]`.

---

## Checklist commune

- [x] Version mise à jour (`.env`, `.env.example`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-17-6/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-17-6/RELEASE_NOTES.md` rempli et sauvegardé
- [ ] CI status checks green
- [ ] Reviewer assigné

---

## Checklist API PHP

- [ ] `composer install --no-dev` exécuté sur le serveur
- [x] Aucune migration SQL
- [x] Déployé et testé sur dev-cmem2 (`test_projets.php` §5b vert)
- [ ] Déploiement prod (après merge — STOP confirmation requise)
- [ ] Endpoint `/health` répond correctement

---

## Notes pour le release manager

- Après merge, tagger le commit :
  `git tag -a v2.17.6 -m "Release v2.17.6"` puis `git push origin v2.17.6`.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
