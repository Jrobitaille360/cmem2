# RELEASE NOTES — cmem2_API v2.17.3

## Description courte

Nouveauté : les rappels push peuvent maintenant afficher le titre réel de
l'événement, de la tâche, du contact ou de l'opportunité — sur option, désactivé par
défaut.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- Préférence opt-in `show_entity_detail` par kind de notification push (défaut
  désactivé — comportement générique inchangé pour les comptes existants). Activée,
  le titre du rappel devient le titre réel de l'entité au lieu du texte générique ;
  le corps du message reste toujours générique.

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
git tag -a v2.17.3 -m "Release v2.17.3"
git push origin v2.17.3

# GitHub Release (sans artefacts joints)
gh release create v2.17.3 \
  --title "v2.17.3" \
  --notes-file docs/v-2-17-3/RELEASE_NOTES.md \
  --draft
```

## Notes hotfix

N/A — release feature normale, pas un hotfix.
