# RELEASE NOTES — cmem2 API v2.17.1

## Description courte

Corbeille (soft-delete + restauration) pour les contacts et les projets, sur le même
modèle que les événements, tâches et journaux du calendrier.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- `GET /contacts/deleted` — corbeille paginée des fiches contact soft-supprimées.
- `POST /contacts/{id}/restore` — restauration dans les 30 jours suivant la suppression.
- `GET /projets/projects/deleted` — corbeille paginée des projets soft-supprimés.
- `POST /projets/projects/{id}/restore` — restauration dans les 30 jours.
- `GET /projets/projects/{id}/tasks/deleted` — corbeille paginée des tâches d'un projet.
- `POST /projets/tasks/{id}/restore` — restauration dans les 30 jours.

### Modifié

- `DELETE /projets/projects/{id}` : suppression physique remplacée par un soft-delete.
  Les tâches du projet et son calendrier caché ne sont plus effacés à la suppression du
  projet — ils réapparaissent intacts si le projet est restauré.

### Corrigé

-

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

N/A pour cette release (API seulement, déploiement serveur direct).

## Instructions de déploiement rapides

```bash
# API — déploiement serveur
.\private\deploy.ps1 -Target prod

# Tag Git
git tag -a v2.17.1 -m "Release v2.17.1"
git push origin v2.17.1

# GitHub Release (sans artefacts joints)
gh release create v2.17.1 \
  --title "v2.17.1" \
  --notes-file docs/v-2-17-1/RELEASE_NOTES.md \
  --draft
```
