# Release v2.17.5 — cmem2_API

## Résumé

Correctif Stripe webhook : exposition des indicateurs `received`/`skipped` dans la réponse,
et ajout de la colonne générée `is_premium` sur `stripe_subscriptions`.

## Formats publiés dans cette release

- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.17.5]`.

---

## Checklist commune

- [x] Version mise à jour (`.env`, `.env.example`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-17-5/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-17-5/RELEASE_NOTES.md` rempli et sauvegardé
- [ ] CI status checks green
- [ ] Reviewer assigné

---

## Checklist API PHP

- [ ] `composer install --no-dev` exécuté sur le serveur
- [x] Migration SQL appliquée sur dev-cmem2 (`docs/v-2-17-5/20260901_stripe_subscriptions_is_premium.sql`)
- [ ] Migration SQL appliquée sur prod (après merge — STOP confirmation requise)
- [ ] Caches vidés / régénérés
- [ ] Endpoint `/health` répond correctement

---

## Notes pour le release manager

- Après merge, tagger le commit :
  `git tag -a v2.17.5 -m "Release v2.17.5"` puis `git push origin v2.17.5`.
