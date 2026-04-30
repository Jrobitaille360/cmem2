# Release v2.4.0 — cmem2 API

## Résumé

Prépare la release `v2.4.0` de **cmem2 API**. Cette PR fige l'état du dépôt pour
le déploiement production et poursuit le développement ensuite.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

- **Stripe** — Checkout Session + webhook (StripeService, StripeController, POST /stripe/webhook)
- **Abonnements** — nouveaux champs `is_trial`, `trial_end`, `is_premium`, `show_ads`, `device_token`, `stripe_customer`
- **Auth OTP** — Option A : auto-register silencieux pour email inconnu dans `POST /auth/send-code`
- **Google Play** — migration vers API subscriptionsv2 (offerTags trial, obfuscatedExternalAccountId)
- **Maintenance** — MaintenanceOrchestrator + MaintenanceService par module + cron `maintenance.php`
- **Convention** — migrations SQL dans `docs/` entre versions ; `build_DB` des versions fixées jamais modifié

Voir `CHANGELOG.md` — section `## [2.4.0]`.

---

## Checklist commune

- [x] Version mise à jour dans `composer.json` (`"version": "2.4.0"`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-4-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-4-0/RELEASE_NOTES.md` rempli et sauvegardé
- [ ] CI status checks green
- [ ] Reviewer assigné

---

## Checklist API PHP

- [x] `composer install --no-dev --optimize-autoloader` exécuté sur le serveur
- [x] Migration `docs/v-2-4-0/20260423_files_media_type_executable.sql` appliquée
- [x] Migration `docs/v-2-4-0/20260426_subscriptions_trial.sql` appliquée
- [ ] Variables `.env` ajoutées : `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_PUZZLE_MONTHLY`, `STRIPE_PRICE_PUZZLE_YEARLY`
- [ ] Webhook Stripe configuré dans le tableau de bord : `POST /stripe/webhook`
- [X] Cron `maintenance.php` ajouté (remplace `expire_subscriptions.php` et `cleanup.php`)
- [X] Endpoint `/health` répond correctement
- [X] `php private/tests/test_subscriptions.php` → 112/112
- [ ] `php private/tests/test_users.php` → 103/103

## Checklist journauxdebord.com

- [ ] Fiche de l'application mise à jour (`version: 2.4.0`)
- [ ] Page publique vérifiée

---

## Notes pour le release manager

- Après merge, tagger le commit :
  `git tag -a v2.4.0 -m "Release v2.4.0"` puis `git push origin v2.4.0`.
- Créer la GitHub Release draft :
  `gh release create v2.4.0 --title "v2.4.0" --notes-file docs/v-2-4-0/RELEASE_NOTES.md --draft`
