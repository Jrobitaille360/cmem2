# Release v2.22.0 — cmem2_API

## Résumé

Rappels unifiés (contrat cmem_web #236, directive #487) : un rappel = un canal (`EMAIL` /
`DISPLAY`) + un délai, sur événements, tâches, tâches de projet, contacts et opportunités.
Défauts par type (`/notifications/defaults`), filtre par appareil (`/notifications/devices`),
cron unifié courriel + push. Les rappels des tâches de projet partent enfin (#439).

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.22.0] — 2026-11-12` ; impact client :
`docs/v-2-22-0/2.22.0_CLIENT.md`.

- **BREAKING** : `REMINDER_CHANNEL_UNSUPPORTED` retiré, `422` codés `REMINDER_*` ;
  `notifications` relu normalisé (listing des événements compris) ; courriel au titre
  générique ; file `email_notification_queue` retirée.
- Ajouté : champ `notifications` sur 4 nouvelles entités, `/notifications/defaults`,
  `/notifications/devices`, `src/notifications/send_reminders.php` (crontab inchangée).
- Corrigé : #439 ; `/users/me/notification-preferences` capturé par `/users/me`.
- Migrations : `docs/v-2-22-0/20261112_notifications_unifiees.sql` (schéma) +
  `src/notifications/migrate_20261112_reminders.php` (données).

---

## Checklist commune

- [x] Version mise à jour (`.env.example` `APP_VERSION=2.22.0`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-22-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-22-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Pas de `PLAN_*.md` associé (contrat tenu côté cmem_web)
- [x] Suite complète verte contre dev : 3240 / 3240 (après migrations de schéma et de données)
- [ ] Reviewer assigné

## Checklist API PHP

- [ ] `composer install --no-dev` exécuté sur le serveur (deploy.ps1)
- [ ] Migrations SQL appliquées (schéma avant code, données juste après)
- [ ] Endpoint `/health` répond `2.22.0`

---

## Notes pour le release manager

- Après merge, tagger le commit : `git tag -a v2.22.0 -m "Release v2.22.0"` puis
  `git push origin v2.22.0`.
- Checklist de déploiement : `docs/v-2-22-0/2.22.0_PRODUCTION.md`.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
