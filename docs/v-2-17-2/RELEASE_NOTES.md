# RELEASE NOTES — cmem2_API v2.17.2

## Description courte

Correctif : les liens d'invitation à un groupe envoyés par courriel pointaient vers le
mauvais domaine et échouaient au clic.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Corrigé

- Le lien d'invitation à un groupe (courriel) pointait vers le domaine de l'API au lieu du
  site client, et en méthode GET alors que la route n'accepte que POST — le clic échouait
  avec un message trompeur (« Utilisateur non authentifié »). Le lien pointe maintenant
  vers le bon domaine.

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

| Format | Canal de distribution |
| - | - |
| API | Déploiement serveur direct (dev-cmem2 + prod) |

## Instructions de déploiement rapides

```bash
# API — déjà déployée sur dev-cmem2 lors de la préparation de cette release ;
# déploiement prod via private/deploy.ps1 -Target prod après merge

# Tag Git
git tag -a v2.17.2 -m "Release v2.17.2"
git push origin v2.17.2

# GitHub Release (sans artefacts joints)
gh release create v2.17.2 \
  --title "v2.17.2" \
  --notes-file docs/v-2-17-2/RELEASE_NOTES.md \
  --draft
```

## Notes hotfix

N/A — release patch normale, pas un hotfix.
