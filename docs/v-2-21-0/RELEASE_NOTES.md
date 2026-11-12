# RELEASE NOTES — cmem2_API v2.21.0

## Description courte

Fin de la commercialisation : Stripe, Google Play et l'ancien catalogue de plans sont
retirés. L'accès dépend uniquement de l'approbation du compte et des modules cochés par
l'admin (plan #269, Phases 4 et 6).

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Modifié (BREAKING)

- `GET /v2/access/status` : accès dérivé de l'approbation du compte, toutes plateformes ;
  `sources` = une entrée `approval` avec les modules cochés.
- Puzzle : premium = appareil lié à un compte approuvé.
- `plan` (`/auth/me`, `/modules`) : `ami` ou `free` seulement.
- `POST /users/verify-email` : `{ email_verified: true }`, plus de courriel d'invitation
  aux plans payants.

### Retiré (BREAKING)

- Routes `/v2/billing/*`, `/v2/subscriptions/*`, `/subscription/*`, `/plans`,
  `/users/choose-plan` → `404`.
- Code `src/stripe/`, `src/playstore/`, crons d'expiration, variables `STRIPE_*`.
- Tables `stripe_subscriptions`, `stripe_processed_events`, `playstore_subscriptions`,
  `subscriptions`, `plans`, `plan_invitations` ; colonnes `users.plan_id`,
  `plan_expires_at`, `plan_auto_renew`.

### Inchangé

- `/v2/devices/*` (déplacé dans le module WebDevice, même contrat).

> Détails complets : voir `CHANGELOG.md` et `docs/v-2-21-0/2.21.0_CLIENT.md`.

## Distribution des artefacts

API seulement : déploiement serveur direct (`private/deploy.ps1 -Target prod`).

## Instructions de déploiement rapides

Voir `docs/v-2-21-0/2.21.0_PRODUCTION.md` — code d'abord, migrations ensuite.
