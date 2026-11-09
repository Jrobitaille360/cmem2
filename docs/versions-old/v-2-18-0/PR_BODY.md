# Release v2.18.0 — cmem2_API

## Résumé

Prépare la release `v2.18.0` de **cmem2_API** : budget IA mensuel en dollars (Phase 1 du
plan accès sur approbation, tâches cmem #270 à #276), comptes de test IA sur dev
(directive #414).

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.18.0] — 2026-11-08`.

- **BREAKING** : `quota` → `budget` dans `POST /ai/summarize`, `GET /modules`,
  `PATCH /modules/ia` ; `AI_QUOTA_EXCEEDED` → `AI_BUDGET_EXCEEDED` (`scope` user/global).
- `GET /ai/usage`, `AiBudgetService` (réservation sous verrou puis ajustement),
  `AiPricing`.
- Migration `20261108_ai_budget.sql` (déjà appliquée sur dev et prod),
  `build_DB-v-2.18.0.sql`.
- Haiku 5.5 sans réflexion, `max_tokens` 512.

---

## Checklist commune

- [x] Version mise à jour (`APP_VERSION=2.18.0` dans `.env.example` et les env)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-18-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-18-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Aucun `PLAN_*.md` à déplacer (plan archivé, suivi en tâches cmem)
- [ ] CI status checks green
- [ ] Reviewer assigné

## Checklist API PHP

- [ ] `composer install --no-dev` exécuté sur le serveur (fait par `deploy.ps1`)
- [x] Migrations SQL appliquées (dev et prod, 2026-11-08)
- [ ] Endpoint `/health` répond `version: 2.18.0`

## Tests

Suite complète contre dev : 3 230 / 3 238. Les 8 échecs sont les collisions connues
`If-Unmodified-Since` à la seconde près (tests instables, tâche cmem #413), sans lien avec
cette release.

---

## Notes pour le release manager

- Après merge, tagger le commit :
  `git tag -a v2.18.0 -m "Release v2.18.0"` puis `git push origin v2.18.0`.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
