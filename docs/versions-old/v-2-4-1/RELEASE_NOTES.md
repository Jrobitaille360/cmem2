# RELEASE NOTES — cmem2 API v2.4.1

## Description courte

Ajout du champ `accessibility` sur les fichiers uploadés : contrôle d'accès fin (public/private)
et endpoint `PATCH /files/{id}/accessibility` pour modifier l'accessibilité après upload.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- Champ `accessibility` (`public` | `private`, défaut `private`) sur la table `files`
- Endpoint `PATCH /files/{id}/accessibility` — propriétaire ou administrateur
- Paramètre FormData `accessibility` sur `POST /files` (validation 422 si valeur invalide)

### Modifié

- `GET /files/{id}` (download) — retourne 403 si `private` et appelant non-propriétaire/non-admin
- `GET /files/{id}/info` — même règle d'accessibilité que le téléchargement
- `GET /files/user/{user_id}` — inclut désormais `accessibility` dans la réponse

### Corrigé

-

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Les binaires **ne sont pas joints** à la GitHub Release.

| Format | Canal de distribution |
| - | - |
| API | Déploiement serveur direct |

## Instructions de déploiement rapides

```bash
# 1. Appliquer la migration SQL
mysql -u root -p cmem2_db < docs/v-2-4-1/20260430_files_accessibility.sql

# 2. Installer les dépendances (sans dev)
composer install --no-dev --optimize-autoloader

# 3. Vérifier le health check
curl https://your-api/health

# Tag Git
git tag -a v2.4.1 -m "Release v2.4.1"
git push origin v2.4.1

# GitHub Release
gh release create v2.4.1 \
  --title "v2.4.1" \
  --notes-file docs/v-2-4-1/RELEASE_NOTES.md \
  --draft
```
