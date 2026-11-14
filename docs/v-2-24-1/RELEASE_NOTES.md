# RELEASE NOTES — cmem2_API v2.24.1

## Description courte

Correctif du SMTP par usager : plus d'attente de plusieurs minutes quand le serveur SMTP de
l'usager est mal configuré.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- Rien.

### Modifié

- Rien.

### Corrigé

- `POST /assistant/smtp/test` et l'envoi des rappels par le SMTP de l'usager attendent
  10 secondes au plus par réponse du serveur (au lieu de 300).
- Port 465 avec `encryption` `tls` ou `none` : erreur `SMTP_TLS_FAILED` immédiate, avec un
  message explicite.

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Aucun binaire : API déployée sur le serveur par `private/deploy.ps1 -Target prod`.

## Instructions de déploiement rapides

Voir `docs/v-2-24-1/2.24.1_PRODUCTION.md` : aucune migration, déploiement du code seulement.
