# Release v2.24.0 — cmem2_API

## Résumé

Prépare la release `v2.24.0` de **cmem2_API** : SMTP par usager du module Assistant
(tâches cmem #252, #253, #254).

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

- `GET|PUT|DELETE /assistant/smtp`, `POST /assistant/smtp/test` (sonde sans envoi, erreurs
  lisibles, 5 essais / 10 min, option `send_to_self`).
- Table `user_smtp_settings`, mot de passe chiffré `sodium_crypto_secretbox`
  (`SMTP_ENCRYPTION_KEY`).
- Hôtes privés / loopback / réservés refusés.
- `send_reminders.php` : rappels via le SMTP de l'usager, repli sur le SMTP système.

Détails : `CHANGELOG.md`, section `## [2.24.0]`.

---

## Checklist commune

- [x] Version mise à jour (`APP_VERSION=2.24.0` dans `.env.example`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-24-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-24-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Pas de `PLAN_*.md` propre à cette release (plan du module : `docs/versions-old/v-2-23-0/`)
- [x] Tests : `test_assistant_smtp.php` 79/79 ; suite complète 3325/3325 sur dev

## Checklist API PHP

- [ ] `SMTP_ENCRYPTION_KEY` ajoutée à `.env.prod`
- [ ] Migration `docs/v-2-24-0/20261114_user_smtp_settings.sql` appliquée en prod
- [ ] `composer install --no-dev` exécuté sur le serveur (`deploy.ps1`)
- [ ] Endpoint `/health` répond `version: 2.24.0`

## Checklist journauxdebord.com

- [ ] Fiche `cmem2-api` (item 25) mise à jour (`version`)

---

🤖 Generated with [Claude Code](https://claude.com/claude-code)
