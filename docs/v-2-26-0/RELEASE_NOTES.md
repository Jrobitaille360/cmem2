# RELEASE NOTES — cmem2_API v2.26.0

## Description courte

Nettoyage des anciens rappels : les routes et champs remplacés par les rappels unifiés de la
v2.22.0 sont retirés.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Retiré (BREAKING)

- Anciennes préférences push (`/push/preferences`) et anciennes routes de file courriel
  (`/notifications/email*`, `/notifications/send-email`) : remplacées par
  `/notifications/defaults` et `/notifications/devices`.
- Champ `rappelMinutesAvant` des tâches de projet : remplacé par `notifications`.
- Adresse de rappel alternative `notification_email` : les rappels partent à l'adresse du
  compte.
- Tables et colonnes devenues inutiles supprimées de la base.

### Ajouté

- Rien.

### Corrigé

- Rien.

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Aucun binaire : API déployée sur le serveur par `private/deploy.ps1 -Target prod`.

## Instructions de déploiement rapides

Voir `docs/v-2-26-0/2.26.0_PRODUCTION.md` : déployer le code **avant** d'appliquer la
migration `20261120_remove_deprecated_reminders.sql` (export préalable).
