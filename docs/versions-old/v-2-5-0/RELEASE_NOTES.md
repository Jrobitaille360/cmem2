# RELEASE NOTES — cmem2 API v2.5.0

## Description courte

Portail Stripe self-service pour la gestion d'abonnement et accessibilité
`grand-public` pour les fichiers (accès sans JWT).

## Formats publiés

- [x] API
- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)

## Changements principaux

### Ajouté

- `POST /subscription/portal` — ouvre le Stripe Billing Portal pour un utilisateur
  avec abonnement existant; retourne `{ portal_url }`
- Support du niveau d'accessibilité `grand-public` pour les fichiers :
  téléchargement et métadonnées accessibles sans JWT

### Corrigé

- `Subscription::upsert()` — `stripe_customer` préservé lors d'un
  `POST /subscription/verify` sans customer Stripe (COALESCE)

> Détails complets : voir `CHANGELOG.md`.

## Instructions de déploiement rapides

```bash
# Migration SQL
mysql -u root -p cmem2_db < docs/v-2-4-1/20260430_files_accessibility.sql

# Dépendances
composer install --no-dev --optimize-autoloader

# Tag Git (après merge de la PR)
git tag -a v2.5.0 -m "Release v2.5.0"
git push origin v2.5.0

# GitHub Release (draft)
gh release create v2.5.0 \
  --title "v2.5.0" \
  --notes-file docs/v-2-5-0/RELEASE_NOTES.md \
  --draft
```
