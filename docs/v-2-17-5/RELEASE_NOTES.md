# RELEASE NOTES — cmem2_API v2.17.5

## Description courte

Correctif Stripe : réponse webhook enrichie (`received`/`skipped`) et statut premium fiable
sur les abonnements.

## Formats publiés

- [x] API

## Changements principaux

### Corrigé

- Webhook Stripe (`POST v2/billing/webhook`) : réponse expose `data.received=true`
  (événement inconnu, no-op) et `data.skipped=true` (rejeu d'un événement déjà traité).
- `stripe_subscriptions.is_premium` : nouvelle colonne dérivée automatiquement du statut
  d'abonnement — corrige un statut premium absent après mise à jour/annulation Stripe.

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Aucun artefact binaire — déploiement serveur direct.

## Instructions de déploiement rapides

```bash
# API — déploiement serveur (private/deploy.ps1 -Target prod)
# Migration SQL à appliquer manuellement avant/avec le déploiement :
#   docs/v-2-17-5/20260901_stripe_subscriptions_is_premium.sql

# Tag Git
git tag -a v2.17.5 -m "Release v2.17.5"
git push origin v2.17.5

# GitHub Release (sans artefacts joints)
gh release create v2.17.5 \
  --title "v2.17.5" \
  --notes-file docs/v-2-17-5/RELEASE_NOTES.md \
  --draft
```
