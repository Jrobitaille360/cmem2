# RELEASE NOTES — cmem2 API v2.10.0

## Description courte

Trois nouveaux piliers backend — Contacts, Liens croisés et Projets — plus l'extension
du tenant Stripe `cmemweb`. Le chemin d'occurrences de calendrier matérialisées est
déprécié au profit de l'expansion à la volée.

## Formats publiés

- [x] API

## Changements principaux

### Ajouté

- **Contacts** (`/contacts`) : CRUD, import/export vCard 4.0, cap de plan `max_contacts`.
- **Liens croisés** (`/links`) entre événements, tâches, journaux, projets et tâches de projet.
- **Projets** (`/projets`) : hiérarchie, dépendances FS/SS/FF/SF, round-trip JSON, export `.ics`.
- Support Stripe pour le tenant `cmemweb`.
- Upload de fichiers `.gpx`.

### Modifié

- **BREAKING** — occurrences de calendrier matérialisées retirées (`410 Gone`) ;
  utiliser `GET /calendars/{id}/events/occurrences/expand`.
- **BREAKING** — Google Play / AdMob désactivés pour `app_id=puzzle` (`410 PROVIDER_DISABLED`),
  Stripe devient l'unique fournisseur d'abonnement.

### Corrigé

- Les calendriers et tâches provisionnés par un projet n'apparaissent plus dans les
  endpoints génériques `/calendars`.

> Détails complets : voir `CHANGELOG.md`.

## Instructions de déploiement rapides

```bash
# API — déploiement serveur (prod + dev), PAS de composer sur le serveur
# Migrations SQL : voir docs/v-2-10-0/2.10.0_PRODUCTION.md

# Tag Git (après merge de la PR)
git tag -a v2.10.0 -m "Release v2.10.0"
git push origin v2.10.0

gh release create v2.10.0 \
  --title "v2.10.0" \
  --notes-file docs/v-2-10-0/RELEASE_NOTES.md \
  --draft
```
