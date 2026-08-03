# RELEASE NOTES — cmem2_API v2.13.0

## Description courte

Le module de fichiers accepte davantage de formats (bureautique, images modernes), permet de
renommer un document et de lire ses étiquettes. Deux correctifs de sécurité touchent la façon dont
les fichiers déposés sont vérifiés et servis.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- Nouveaux formats acceptés au dépôt : présentations et documents bureautiques (`pptx`, `odt`,
  `ods`, `odp`), texte (`csv`, `md`, `rtf`) et images modernes (`heic`, `heif`, `avif`, `tiff`)
- Renommer un fichier et modifier sa description après l'envoi (`PATCH /files/{id}`) — le nom
  affiché change, le fichier stocké reste intact
- Lire les étiquettes d'un fichier (`GET /files/{id}/tags`), et les recevoir directement avec la
  liste des fichiers d'un usager — le filtre par étiquette devient possible côté client
- Les refus d'envoi portent un code exploitable par les applications (`FILE_TYPE_REFUSED`,
  `FILE_TOO_LARGE`, `FILE_NAME_INVALID`) plutôt qu'un simple message

### Modifié

- Plafond d'envoi porté à **100 Mo** (configurable par `FILES_MAX_UPLOAD_MB`). Les installateurs
  Windows de plus de 20 Mo peuvent maintenant être publiés
- Le type d'un fichier est déterminé par son contenu réel, plus par ce que l'application déclare.
  Des fichiers pouvaient être rangés dans la mauvaise catégorie (`media_type`)

### Corrigé

- **Un SVG déposé n'est plus affiché directement par le navigateur** : un SVG peut contenir du
  code exécutable, qui s'exécutait sur le domaine de l'API. Il est désormais toujours téléchargé.
  Pour l'afficher, utiliser la conversion en PNG déjà offerte (`GET /files/png-from-svg`)
- **Un fichier renommé pour tromper la validation est refusé** : l'extension et le contenu réel
  doivent concorder. Un script texte renommé en `.png` passait auparavant

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Aucun binaire dans cette release — déploiement serveur uniquement.

| Format | Canal de distribution |
| - | - |
| API | Déploiement serveur (`private/deploy.ps1 -Target prod`) |

## Instructions de déploiement rapides

```bash
# API — déploiement serveur
powershell private/deploy.ps1 -Target prod

# Tag Git (après merge de la PR)
git tag -a v2.13.0 -m "Release v2.13.0"
git push origin v2.13.0

# GitHub Release
gh release create v2.13.0 \
  --title "v2.13.0" \
  --notes-file docs/v-2-13-0/RELEASE_NOTES.md \
  --draft
```

## Notes hotfix (si applicable)

Sans objet — release ordinaire depuis `main`.
