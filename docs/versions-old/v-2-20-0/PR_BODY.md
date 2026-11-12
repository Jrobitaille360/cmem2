# Release v2.20.0 — cmem2_API

## Résumé

Release `v2.20.0` de **cmem2_API** : caps de ressources à trois niveaux administrables
(plan #269, Phase 3 bis, tasks cmem #290 et #293 à #295, PR #31) et rappels courriel par
occurrence des événements récurrents (directives cmem_web #438 et #440, PR #30).

Le code de ces deux PR tourne déjà en prod (commit `14edd4e`). Cette PR fige la version :
bump `APP_VERSION`, CHANGELOG, `build_DB-v-2.20.0.sql`, documents de version.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.20.0] — 2026-11-10`.

- Caps : défaut → surcharge par groupe → surcharge par utilisateur ; endpoints
  `GET /caps/defaults`, `GET/PUT /users/{id}/caps`, `GET/PUT /groups/{id}/caps`.
- Quotas et `GET /auth/me` ne lisent plus le plan Stripe ; `max_group_members` vérifié à
  l'ajout de membre et à l'acceptation d'invitation.
- **BREAKING** : rappel d'événement non `EMAIL` → `422 REMINDER_CHANNEL_UNSUPPORTED`.
- Rappels courriel envoyés pour chaque occurrence d'une série (fenêtre glissante 48 h).

---

## Checklist commune

- [x] Version mise à jour (`APP_VERSION=2.20.0` dans `.env.example`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-20-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-20-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Migration `20261109_resource_caps.sql` déplacée dans `docs/v-2-20-0/` et intégrée
      dans `build_DB-v-2.20.0.sql`
- [x] `docs/v-2-19-1/` archivé dans `docs/versions-old/`
- [x] Suite complète sur dev : 3444 / 3444 ; `test_resource_caps.php` : 62 / 62

## Checklist API PHP

- [x] Migration SQL appliquée en prod (2026-11-10, avant le déploiement du code)
- [x] `composer install --no-dev --optimize-autoloader` exécuté sur le serveur (`deploy.ps1`)
- [ ] Redéploiement après fusion → `/health` répond `version: 2.20.0`

Détail : `docs/v-2-20-0/2.20.0_PRODUCTION.md`.

## Checklist journauxdebord.com

- [ ] Fiche `cmem2-api` (item 25) mise à jour (`version`, `features`)

---

## Notes pour le release manager

- Après merge : `git tag -a v2.20.0 -m "Release v2.20.0"` puis `git push origin v2.20.0`.
- Clients : `docs/v-2-20-0/2.20.0_CLIENT.md` (BREAKING sur les rappels non EMAIL, déjà
  annoncé à cmem_web par la directive de retour #476).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
