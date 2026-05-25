# Release v2.4.1 — cmem2 API

## Résumé

Prépare la release `v2.4.1` de **cmem2 API**. Cette PR ajoute le champ `accessibility`
(public/private) sur les fichiers uploadés : contrôle d'accès fin, endpoint PATCH dédié,
migration SQL intégrée dans le build.

## Formats publiés dans cette release

- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.4.1]`.

---

## Checklist commune

- [x] Version mise à jour (`composer.json`, `.env.example` → `2.4.1`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-4-1/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-4-1/RELEASE_NOTES.md` rempli et sauvegardé
- [x] `PLAN_files-accessibility.md` déplacé dans `docs/v-2-4-1/`

---

## Checklist API PHP

- [X] deploy.ps1
- [X] Migration SQL appliquée : `docs/v-2-4-1/20260430_files_accessibility.sql`
- [X] Endpoint `/health` répond correctement
- [X] Tester `POST /files` avec `accessibility=public` et `accessibility=private`
- [X] Tester `GET /files/{id}` en tant qu'utilisateur non-propriétaire (doit retourner 403 si private)
- [X] Tester `PATCH /files/{id}/accessibility`

---

## Notes pour le release manager

- Après merge, tagger le commit :
  `git tag -a v2.4.1 -m "Release v2.4.1"` puis `git push origin v2.4.1`.
