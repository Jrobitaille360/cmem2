# Release v2.19.0 — cmem2_API

## Résumé

Prépare la release `v2.19.0` de **cmem2_API** : accès sur approbation (Phase 2) et modules
cochés par personne (Phase 3) du plan #269, suivis des directives clients jdb #420 et
cmem_web #421, comptes de test dev pour les écrans d'attente.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.19.0] — 2026-11-09`.

- **BREAKING** : nouveaux comptes `pending` → `403 ACCOUNT_PENDING` hors `/auth/me`,
  `/auth/logout`, `DELETE /users/me` (et fichiers publics pour un compte `pending`).
- **BREAKING** : disponibilité des modules = modules cochés (`user_module_grants`), plus le plan.
- Admin : `PUT /users/{id}/access-status`, `/invitations`, `GET/PUT /users/{id}/modules`,
  filtre `GET /users?access_status=`.
- Courriels d'approbation (admin à l'inscription, usager à l'approbation).

---

## Checklist commune

- [x] Version mise à jour (`APP_VERSION=2.19.0` dans `.env.example`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-19-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-19-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Migrations `20261109_*.sql` déplacées dans `docs/v-2-19-0/`, intégrées dans
      `build_DB-v-2.19.0.sql` (import validé, 88 tables)
- [x] Suite complète sur dev : 3399 / 3399

## Checklist API PHP

- [ ] Migrations SQL appliquées sur prod (Phase 2 puis Phase 3) — **avant** le code
- [ ] `ACCESS_ADMIN_NOTIFY_EMAIL` active dans `.env.prod`
- [ ] `composer install --no-dev --optimize-autoloader` exécuté sur le serveur (`deploy.ps1`)
- [ ] Endpoint `/health` répond `version: 2.19.0`

Détail : `docs/v-2-19-0/2.19.0_PRODUCTION.md`.

## Checklist journauxdebord.com

- [ ] Fiche `cmem2-api` (item 25) mise à jour (`version`)

---

## Notes pour le release manager

- Après merge : `git tag -a v2.19.0 -m "Release v2.19.0"` puis `git push origin v2.19.0`.
- Clients prêts : cmem_web (#417, #422), jdb (#418), XMGame (#419, vérification réelle des
  écrans en cours côté XMGame).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
