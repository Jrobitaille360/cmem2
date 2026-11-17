# Release v2.24.1 — cmem2_API

## Résumé

Prépare la release correctif `v2.24.1` de **cmem2_API** : délai de lecture SMTP borné à 10 s
pour le SMTP par usager (PHPMailer `Timelimit` restait à 300 s), et refus immédiat du couple
port 465 + `tls`.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

- `UserSmtpService` : `Timelimit` = 10 s dans `probe()` et `send()`.
- Port 465 sans `ssl` → `422 SMTP_TLS_FAILED` sans connexion.
- Tests : S5.3b (sans attente) ; S7 envoi réel vérifié (courriel reçu).

Détails : `CHANGELOG.md`, section `## [2.24.1]`.

---

## Checklist commune

- [x] Version mise à jour (`APP_VERSION=2.24.1` dans `.env.example`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-24-1/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-24-1/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Aucun `PLAN_*.md`
- [x] Tests : `test_assistant_smtp.php` 86/86 ; suite complète 3325/3325 sur dev

## Checklist API PHP

- [x] Aucune migration
- [ ] `composer install --no-dev` exécuté sur le serveur (`deploy.ps1`)
- [ ] Endpoint `/health` répond `version: 2.24.1`

## Checklist journauxdebord.com

- [ ] Fiche `cmem2-api` (item 25) mise à jour (`version`)

---

🤖 Generated with [Claude Code](https://claude.com/claude-code)
