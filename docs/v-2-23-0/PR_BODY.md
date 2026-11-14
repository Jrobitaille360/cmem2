# Release v2.23.0 — cmem2_API

## Résumé

Module Assistant (tâche racine cmem #515) : chat avec Claude en JSON ou en streaming SSE,
consentement Loi 25, historique par usager, outils sur les tâches et leurs rappels,
suppressions soumises à confirmation, budget IA commun avec `/ai/summarize`. Plus deux
correctifs déjà en Unreleased (#501 texte des rappels, #508 CORS).

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.23.0] — 2026-11-14` ; impact client :
`docs/v-2-23-0/2.23.0_CLIENT.md`.

- Aucun BREAKING CHANGE ; `GET /modules` retourne 9 clés (`assistant` ajoutée).
- Ajouté : plugin `src/assistant/`, 12 routes `/assistant/*`, 6 outils serveur
  (`list_tasks`, `create_task`, `update_task`, `set_reminder`, `list_events`, `propose_delete`),
  `ai_usage.feature`, `GET /assistant/usage`.
- Corrigé : texte exact des rappels (#501), `If-Unmodified-Since` en CORS (#508).
- Migration : `docs/v-2-23-0/20261114_module_assistant.sql` (schéma de référence
  `docs/v-2-23-0/build_DB-v-2.23.0.sql`).
- Plan : `docs/v-2-23-0/PLAN_module-assistant.md` + journal d'implantation.

---

## Checklist commune

- [x] Version mise à jour (`.env.example` `APP_VERSION=2.23.0`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-23-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-23-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] `PLAN_module-assistant.md` et son journal d'implantation déplacés dans `docs/v-2-23-0/`
- [x] Suite complète contre dev : 3239 / 3241, puis les 2 tests de modules corrigés
      (9 clés) et relancés verts ; `test_assistant.php` 84 / 84 ; évaluation du modèle 21 / 21
- [ ] Reviewer assigné

## Checklist API PHP

- [ ] Migration SQL appliquée en prod (schéma avant code)
- [ ] `composer install --no-dev` exécuté sur le serveur (deploy.ps1)
- [ ] Endpoint `/health` répond `2.23.0`

---

## Notes pour le release manager

- Après merge, tagger le commit : `git tag -a v2.23.0 -m "Release v2.23.0"` puis
  `git push origin v2.23.0`.
- Checklist de déploiement : `docs/v-2-23-0/2.23.0_PRODUCTION.md`.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
