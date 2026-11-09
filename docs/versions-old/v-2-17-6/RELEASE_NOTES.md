# RELEASE NOTES — cmem2_API v2.17.6

## Description courte

Import JSON de projet fiable : les liens (`dependsOn`, `parentId`) des tâches à id numérique
temporaire ne sont plus perdus, et les fichiers mal formés sont refusés explicitement.

## Formats publiés

- [x] API

## Changements principaux

### Corrigé

- Import JSON de projet : un `id` numérique absent du projet cible (ex. `9101`) est remappé
  comme un id texte — ses `dependsOn` et `parentId` étaient perdus sans erreur. `9101` et
  `"9101"` sont la même clé. Un id existant reste une mise à jour (aller-retour inchangé).
- Validation : `422` (rien écrit) si `dependsOn` n'est pas un tableau d'objets
  `{taskId, type, lagDays}`, si `type` est hors `FS|SS|FF|SF`, si `lagDays` n'est pas entier,
  si un id est en double, ou si une référence sort du fichier et du projet cible.

### Ajouté

- Dry-run : `liens` (`[{de, vers, type}]`) et `doublonsTitre` (avertissement de réimport).
  Le confirm renvoie aussi `liens` (nombre).
- `docs/entrypoints.md` : section « Tâches de projet — contrat et import JSON ».

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Aucun artefact binaire — déploiement serveur direct.

## Instructions de déploiement rapides

```bash
# API — déploiement serveur (private/deploy.ps1 -Target prod)
# Aucune migration SQL.

# Tag Git
git tag -a v2.17.6 -m "Release v2.17.6"
git push origin v2.17.6

# GitHub Release (sans artefacts joints)
gh release create v2.17.6 \
  --title "v2.17.6" \
  --notes-file docs/v-2-17-6/RELEASE_NOTES.md \
  --draft
```
