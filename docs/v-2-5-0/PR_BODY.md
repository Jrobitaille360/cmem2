# Release v2.5.0 — cmem2 API

## Résumé

- Portail Stripe : `POST /subscription/portal` — session Billing Portal self-service pour gérer un abonnement existant
- Fichiers : niveau d'accessibilité `grand-public` — téléchargement et métadonnées sans JWT
- Fix : `Subscription::upsert()` préserve `stripe_customer` via `COALESCE` lors d'un `verify` sans customer Stripe

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [x] API — Déploiement serveur
- [ ] Web
- [ ] Windows — Installateur Inno Setup

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.5.0]`.

---

## Checklist commune

- [x] Version mise à jour dans `composer.json`, `.env` et `.env.example`
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-5-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-5-0/RELEASE_NOTES.md` rempli et sauvegardé
- [ ] CI status checks green
- [ ] Reviewer assigné

---

## Checklist API PHP

- [ ] `composer install --no-dev` exécuté sur le serveur
- [ ] Migration SQL appliquée (`docs/v-2-4-1/20260430_files_accessibility.sql`)
- [ ] Endpoint `/health` répond correctement

---

## Notes pour le release manager

Après merge, tagger le commit :

```bash
git tag -a v2.5.0 -m "Release v2.5.0"
git push origin v2.5.0
```
