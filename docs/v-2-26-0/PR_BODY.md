# Release v2.26.0 — cmem2_API

## Résumé

Prépare la release `v2.26.0` de **cmem2_API** : retrait des dépréciations des rappels
unifiés (task #497, suite de #487 / v2.22.0). **BREAKING** — routes, champs, tables et
colonnes retirés ; aucun usage relevé en production.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

- `404` : `/push/preferences`, `/notifications/email*`, `/notifications/send-email`.
- Tâches de projet : `rappelMinutesAvant` retiré (`422 FIELD_REMOVED` en écriture, ignoré
  à l'import JSON).
- `notification-preferences` : `notification_email` retiré (`422 FIELD_REMOVED`).
- Modèles et scripts legacy supprimés (`NotificationPref`, `PushNotificationLog`,
  `EmailNotificationQueue`, `migrate_20261112_reminders.php`).
- Migration `20261120_remove_deprecated_reminders.sql`, intégrée dans
  `build_DB-v-2.26.0.sql` — à appliquer **après** le déploiement du code.

Détails : `CHANGELOG.md`, section `## [2.26.0]`.

---

## Checklist commune

- [x] Version mise à jour (`APP_VERSION=2.26.0` dans `.env.example`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-26-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-26-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Aucun `PLAN_*.md`
- [x] Tests : `test_reminders_deprecations_removed.php` 143/143 ; suite complète
      3432/3432 sur dev (code déployé + migration appliquée)

## Checklist API PHP

- [ ] Code déployé en prod (`deploy.ps1 -Target prod`)
- [ ] Puis migration `docs/v-2-26-0/20261120_remove_deprecated_reminders.sql` appliquée en
      prod avec export préalable (dev : fait)
- [ ] Endpoint `/health` répond `version: 2.26.0`

## Checklist journauxdebord.com

- [ ] Fiche `cmem2-api` (item 25) mise à jour (`version`)

## Références

- Task cmem #497 (parent #487).

---

🤖 Generated with [Claude Code](https://claude.com/claude-code)
