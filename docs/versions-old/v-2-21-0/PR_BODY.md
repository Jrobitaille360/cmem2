# Release v2.21.0 — cmem2_API

## Résumé

Prépare la release `v2.21.0` de **cmem2_API** : fin de la commercialisation (plan #269,
Phases 4 et 6). Stripe, Google Play et le catalogue de plans legacy sont retirés ; l'accès
dépend de l'approbation du compte et des modules cochés.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.21.0]` et `docs/v-2-21-0/2.21.0_CLIENT.md`
(BREAKING CHANGES).

---

## Checklist commune

- [x] Version mise à jour (`.env.example` `APP_VERSION=2.21.0`, badge README)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-21-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-21-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Migrations `20261111_*.sql` intégrées dans `build_DB-v-2.21.0.sql` et déplacées dans
      `docs/v-2-21-0/`
- [x] Suite complète locale : aucune régression vs baseline (échecs local-only connus)

## Checklist API PHP

- [ ] Code déployé en prod (`deploy.ps1 -Target prod`, `composer install --no-dev`)
- [ ] Migrations SQL appliquées en prod **après** le déploiement (export préalable)
- [ ] Endpoint `/health` répond `version: 2.21.0`

## Checklist journauxdebord.com

- [ ] Fiche `cmem2-api` (item 25) mise à jour (`version`, `features` sans prix)

---

## Notes pour le release manager

- Après merge : `git tag -a v2.21.0 -m "Release v2.21.0"` puis `git push origin v2.21.0`.
- Clients prévenus : directives #417/#418/#419 (livrées), #481 (puzzle), #482 (cmem_web, pour
  information).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
