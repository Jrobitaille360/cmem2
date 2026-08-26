# RELEASE NOTES — cmem2 API v2.9.0

## Description courte

Partage de calendrier avec des groupes, étiquettes de calendrier, rôle superadministrateur,
et documentation d'endpoints publique.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- Partage de calendrier avec un groupe entier (en plus du partage par utilisateur/email)
- Étiquettes (tags) de calendrier, partagées entre membres
- Corbeille récupérable pour événements, tâches et journaux supprimés
- Expansion d'occurrences récurrentes à la demande, sans dépendre du cache pré-calculé
- Rôle `SUPERADMINISTRATEUR` avec matrice d'autorité dédiée
- Documentation JSON des endpoints exposée publiquement (`GET /entrypoints`)
- Option pour masquer la question au joueur dans un quiz (vue hôte uniquement)
- Retrouver les comptes supprimés (`include_deleted`) et assigner manuellement le plan « Ami »

### Corrigé

- Paiement Stripe : la souscription n'était pas toujours enregistrée après un paiement réussi
- Suppression de compte bloquée pour les comptes connectés par code (OTP)
- Doublons `UID`/`DTSTAMP` dans les fichiers ICS exportés

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Cette release ne publie pas de binaire — déploiement serveur direct.

## Instructions de déploiement rapides

```bash
# API — voir docs/v-2-9-0/2.9.0_PRODUCTION.md pour la checklist complète
composer install --no-dev --optimize-autoloader

# Tag Git
git tag -a v2.9.0 -m "Release v2.9.0"
git push origin v2.9.0

# GitHub Release (sans artefacts joints)
gh release create v2.9.0 \
  --title "v2.9.0" \
  --notes-file docs/v-2-9-0/RELEASE_NOTES.md \
  --draft
```
