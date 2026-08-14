# Release v2.16.1 — cmem2_API

## Résumé

Prépare la release `v2.16.1` de **cmem2_API**. PR rétroactive : le contenu de
`v2.16.0` (module `booking`, plan équipe, versioning optimiste `updatedAt`) et de
`v2.16.1` (couverture complète du runner de tests, corrections `test_stripe_webhooks.php`,
documentation de la limite de résolution seconde sur `If-Unmodified-Since`) a été
mergé directement sur `main` sans passer par une PR — aucun tag/release GitHub n'existe
au-delà de `v2.15.0`. Cette PR fige l'état actuel de `main` pour permettre le tag et la
publication de la release, sans réintroduire de changement de code.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [x] API — Déploiement serveur (déjà déployé sur dev-cmem2 et prod pour la partie
      2.16.0 ; 2.16.1 est documentation/tests uniquement, rien à déployer)

## Changelog (résumé)

Voir `CHANGELOG.md` — sections `## [2.16.1] — 2026-08-14` et `## [2.16.0] — 2026-08-14`.

---

## Checklist commune

- [x] Version mise à jour (`.env`, `.env.example`, `README.md` → `2.16.1`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-16-1/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-16-1/RELEASE_NOTES.md` rempli et sauvegardé
- [x] `PLAN_*.md` associés déjà déplacés dans `docs/v-2-16-0/` (booking, plan équipe) ;
      `docs/PLAN_concurrence-updated-at-microsecondes.md` reste à la racine `docs/`
      (décision de ne pas migrer — pertinent au-delà de cette seule release)
- [ ] CI status checks green (pas de CI configurée sur ce dépôt)
- [ ] Reviewer assigné

---

## Checklist API PHP

- [x] `composer install --no-dev` — déjà exécuté lors du déploiement 2.16.0
- [x] Migrations SQL appliquées — `docs/v-2-16-0/build_DB-v-2.16.0.sql` (déployé dev + prod) ;
      aucune migration pour 2.16.1 (documentation/tests uniquement)
- [ ] Caches vidés / régénérés
- [ ] Endpoint `/health` répond correctement (à vérifier post-tag)

---

## Notes pour le release manager

- Contenu déjà en production (2.16.0) — cette PR ne redéploie rien, elle formalise
  l'historique manquant avant de taguer.
- Après merge, tagger le commit :
  `git tag -a v2.16.1 -m "Release v2.16.1"` puis `git push origin v2.16.1`.
- `gh release create v2.16.1 --title "v2.16.1" --notes-file docs/v-2-16-1/RELEASE_NOTES.md --draft`
