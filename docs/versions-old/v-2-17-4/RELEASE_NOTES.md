# RELEASE NOTES — cmem2 API v2.17.4

## Description courte

Corrige le texte des notifications push de rappel : la date/heure réelle de l'événement
ou de la tâche remplace le délai générique « dans X minutes ».

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Modifié

- Notifications push de rappel (`event`, `recurring`, `task_due`) : le corps affiche
  désormais l'heure début-fin d'un événement ponctuel, la plage de dates d'un événement
  journée entière ou multi-jours, ou la date/heure d'échéance d'une tâche — dans le fuseau
  du compte, au lieu du texte générique par délai.

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Aucun artefact binaire — API déployée directement sur le serveur.

## Instructions de déploiement rapides

```bash
# Tag Git
git tag -a v2.17.4 -m "Release v2.17.4"
git push origin v2.17.4

# GitHub Release (sans artefacts joints)
gh release create v2.17.4 \
  --title "v2.17.4" \
  --notes-file docs/v-2-17-4/RELEASE_NOTES.md \
  --draft

# Déploiement serveur
pwsh private/deploy.ps1
```
