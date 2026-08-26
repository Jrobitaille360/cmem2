# Release v2.17.2 — cmem2_API

## Résumé

Patch release : correction du lien d'invitation groupe (mauvais domaine + méthode GET
au lieu de POST), directive cmem_web task cmemweb #195.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur (dev + prod)

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.17.2] — 2026-08-26`.

### Corrigé

- Lien d'invitation groupe (`POST /groups/{id}/invite`) pointait vers le domaine API
  (`APP_URL`) avec un GET implicite au lieu du domaine frontend (`CMEMWEB_APP_URL`) avec
  POST — `/groups/join` public n'accepte que POST → 401 trompeur au clic. `Group::inviteUser`
  utilise maintenant `CMEMWEB_APP_URL`.

### Interne (hors changelog client)

- `private/deploy.ps1` : correction du chemin de scan des `API_*ENDPOINTS.json` publics
  (`docs/docs-api/*` au lieu de `docs/*`) — cassé par le reclassement `docs/` de la release
  précédente (commit `03e8bab`), bloquait tout déploiement.

---

## Checklist commune

- [x] Version mise à jour (`.env`, `.env.example`, `private/utilitaires/.env.dev.online`,
      `private/utilitaires/.env.prod` → `APP_VERSION=2.17.2`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-17-2/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-17-2/RELEASE_NOTES.md` rempli et sauvegardé
- [x] Aucun `PLAN_*.md` associé à cette release
- [ ] CI status checks green
- [ ] Reviewer assigné

---

## Checklist API PHP

- [ ] `composer install --no-dev` exécuté sur le serveur (fait par `deploy.ps1`)
- [x] Migrations SQL : aucune
- [ ] Caches vidés / régénérés — n/a
- [x] `php private/tests/test_groups.php` — 68/68 sur dev-cmem2 (invite_url validé en réel)
- [ ] Endpoint `/health` répond correctement (post-déploiement)

## Connu / hors scope de cette release

- `run_all_tests.php` : 8 échecs pré-existants, non liés au diff — voir
  `docs/v-2-17-2/2.17.2_PRODUCTION.md` § Connu / hors scope.

---

## Notes pour le release manager

- Après merge, tagger le commit :
  `git tag -a v2.17.2 -m "Release v2.17.2"` puis `git push origin v2.17.2`.
