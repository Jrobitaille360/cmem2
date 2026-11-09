# Release v2.19.1 — cmem2_API

## Résumé

Release corrective `v2.19.1` de **cmem2_API** : un `device_id` non UUID à la connexion
répond `422` au lieu de `500`, sans consommer le code OTP (task cmem #427, PR #28).

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.19.1] — 2026-11-09`.

- `POST /auth/login`, `/auth/verify-code`, `/auth/restore-account/verify` : `device_id` non
  UUID → `422` + `errors.device_id` (avant : `500`, code OTP consommé).

---

## Checklist commune

- [x] Version mise à jour (`APP_VERSION=2.19.1` dans `.env.example`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-19-1/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-19-1/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Aucune migration SQL (schéma = `build_DB-v-2.19.0.sql`)
- [x] Suite complète sur dev : 3417 / 3417

## Checklist API PHP

- [ ] `composer install --no-dev --optimize-autoloader` exécuté sur le serveur (`deploy.ps1`)
- [ ] Endpoint `/health` répond `version: 2.19.1`

Détail : `docs/v-2-19-1/2.19.1_PRODUCTION.md`.

## Checklist journauxdebord.com

- [ ] Fiche `cmem2-api` (item 25) mise à jour (`version`)

---

## Notes pour le release manager

- Après merge : `git tag -a v2.19.1 -m "Release v2.19.1"` puis `git push origin v2.19.1`.
- Aucun client à prévenir : correction d'un cas d'erreur, contrat inchangé.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
