# Release v2.17.4 — cmem2 API

## Résumé

Prépare la release `v2.17.4` de **cmem2 API**. Correctif comportemental sur les
notifications push de rappel : le corps affiche désormais la date/heure réelle de
l'élément plutôt que le délai générique.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

- Push : corps des rappels `event`/`recurring`/`task_due` — date/heure réelle au lieu de
  « dans X minutes » (directive cmem_web #210, task cmemweb #215).

Voir `CHANGELOG.md` — section `## [2.17.4]`.

---

## Checklist commune

- [x] Version mise à jour (`.env`, `.env.example`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-17-4/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-17-4/RELEASE_NOTES.md` rempli et sauvegardé
- [ ] `PLAN_*.md` associés déplacés dans `docs/v-2-17-4/` (aucun pour cette release)
- [ ] CI status checks green
- [ ] Reviewer assigné

---

## Checklist API PHP

- [x] Tests `private/tests/test_push.php` — 126/126 (dont section 8c, vérification réelle du
      nouveau format de corps sur `dev-cmem2`)
- [x] Suite complète `run_all_tests.php` — 3122/3128 (6 échecs pré-existants, sans lien :
      concurrence `If-Unmodified-Since`, idempotence webhook Stripe)
- [ ] `composer install --no-dev` exécuté sur le serveur (production)
- [ ] Migrations SQL appliquées (aucune migration dans cette release)
- [ ] Caches vidés / régénérés
- [ ] Endpoint `/health` répond correctement

---

## Notes pour le release manager

- Après merge, tagger le commit :
  `git tag -a v2.17.4 -m "Release v2.17.4"` puis `git push origin v2.17.4`.
- Aucune migration DB dans cette release.
