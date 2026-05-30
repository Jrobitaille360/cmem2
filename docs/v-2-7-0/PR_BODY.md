# Release v2.7.0 — cmem2 API

## Résumé

Prépare la release `v2.7.0` de **cmem2 API**. Refonte complète du domaine
device + subscription : nouveau modèle multi-app, séparation Play Store / Stripe,
support device anonyme, suppression de `puzzle_devices` et `subscriptions`.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [X] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.7.0] — 2026-05-29`.

---

## Checklist commune

- [X] Version mise à jour — `APP_VERSION=2.7.0` dans `.env.example`
- [X] `CHANGELOG.md` mis à jour
- [X] `docs/v-2-7-0/PR_BODY.md` rempli et sauvegardé
- [X] `docs/v-2-7-0/RELEASE_NOTES.md` rempli et sauvegardé
- [X] `PLAN_refonte-device-subscription-v2.7.0.md` conservé dans `docs/`
- [ ] CI status checks green
- [ ] Reviewer assigné

---

## Checklist API PHP

- [X] `composer install --no-dev --optimize-autoloader` exécuté sur le serveur
- [X] Migrations SQL appliquées (20260523, 20260524, 20260529)
- [X] Endpoint `/health` répond correctement
- [X] `POST /v2/devices/android/register` sans JWT → 200 (prod validé 2026-05-29)
- [X] `POST /v2/devices/web/register` sans JWT → 200 (prod validé 2026-05-29)
- [X] `GET /v2/puzzle/carousel` avec device_token → 200 (prod validé 2026-05-29)
- [X] Routes legacy `/puzzle/auth/*` → 410 (prod validé 2026-05-29)
- [X] Route legacy `/stripe/webhook` → 404 (prod validé 2026-05-29)
- [X] Webhook Stripe prod → `https://cmem2.journauxdebord.com/v2/billing/webhook`
- [ ] `POST /v2/billing/webhook` reçoit événements Stripe (vérifier logs prod)
- [ ] Crontab mis à jour (expire_playstore + expire_stripe, retrait expire_subscriptions)

## Checklist journauxdebord.com

- [ ] Fiche `cmem2` mise à jour (`version`, `features`)
- [ ] Page publique vérifiée

---

## Notes pour le release manager

- Tag `v2.7.0` déjà créé et poussé : `git push origin v2.7.0`
- BREAKING CHANGES clients : voir `docs/v-2-7-0/2.7.0_CLIENT.md`
- Checklist déploiement complète : `docs/v-2-7-0/2.7.0_PRODUCTION.md`
