# Release v2.26.1 — cmem2_API

## Résumé

Prépare la release `v2.26.1` de **cmem2_API** (correctifs). Cette PR fige l'état du dépôt
pour le déploiement de l'API et la poursuite du développement.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.26.1] — 2026-11-22` :

- #534 : `POST /auth/send-code` et `POST /auth/restore-account` → `500` quand le courriel OTP
  ne part pas (avant : `200` trompeur). Variable dev `EMAIL_TEST_FAIL_DOMAIN`, test
  `test_auth_otp_send_failure.php`.
- #532 : panne de base pendant le cron rappels → `WARNING` sans trace au lieu d'`ERROR`.

---

## Checklist commune

- [x] Version mise à jour (`.env.example` → `APP_VERSION=2.26.1`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-26-1/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-26-1/RELEASE_NOTES.md` rempli et sauvegardé
- [x] `PLAN_*.md` associés déplacés dans `docs/v-2-26-1/` (aucun)
- [x] Suite de tests complète sur dev : 3432/3432 (+ 7/7 nouveau test)

---

## Checklist API PHP

- [ ] `composer install --no-dev` exécuté sur le serveur (`deploy.ps1`)
- [x] Migrations SQL appliquées (aucune)
- [ ] Endpoint `/health` répond `version: 2.26.1`

## Checklist journauxdebord.com

- [ ] Fiche `cmem2-api` (item 25) mise à jour (`version`)

---

🤖 Generated with [Claude Code](https://claude.com/claude-code)
