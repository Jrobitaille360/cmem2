# RELEASE NOTES — cmem2_API v2.17.0

## Description courte

Suivi du temps par tâche (sessions start/stop, D3) et endpoint free/busy multi-membres pour la
planification de réunions de groupe. Ajuste aussi la forme du diff d'import de projets.

## Formats publiés

- [x] API

## Changements principaux

> Détails complets : voir `CHANGELOG.md`.

### Ajouté

- Suivi du temps par tâche — sessions start/stop (table `time_sessions`), un seul minuteur actif
  à la fois par usager (contrainte posée en base), `note` chiffrable de bout en bout
- `GET /freebusy?members=&start=&end=&app_id=cmemweb` — free/busy multi-membres d'un groupe

### Modifié

- `POST /projets/projects/{id}/import.json` (dry-run) — `aMettreAJour[]` expose un diff champ par
  champ par tâche au lieu de la tâche cible telle quelle

## Distribution des artefacts

Aucun artefact binaire — API PHP déployée directement sur le serveur.

## Instructions de déploiement rapides

```bash
# Migration SQL (avant déploiement du code)
mysql -u <user> -p <db> < docs/v-2-17-0/20260814_time_sessions.sql

# Déploiement du code
.\private\deploy.ps1 -Target dev.online
.\private\deploy.ps1 -Target prod

# Tag Git
git tag -a v2.17.0 -m "Release v2.17.0"
git push origin v2.17.0

# GitHub Release (sans artefacts joints)
gh release create v2.17.0 \
  --title "v2.17.0" \
  --notes-file docs/v-2-17-0/RELEASE_NOTES.md \
  --draft
```
