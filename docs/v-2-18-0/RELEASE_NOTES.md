# RELEASE NOTES — cmem2_API v2.18.0

## Description courte

Le module IA est désormais plafonné par un budget mensuel en dollars (par personne et global)
au lieu d'un nombre d'appels, et tourne sur le modèle le moins cher (Claude Haiku 5.5).

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### BREAKING CHANGES

- `POST /ai/summarize` : `data.quota` remplacé par `data.budget`
  `{spent_usd, limit_usd, reset_at}` ; `429 AI_QUOTA_EXCEEDED` remplacé par
  `429 AI_BUDGET_EXCEEDED` avec `errors.scope` (`user` ou `global`).
- `GET /modules`, `PATCH /modules/ia` : `ia` renvoie `quota: null` et un champ `budget`.

### Ajouté

- `GET /ai/usage` : dépense IA du mois, budget, date de remise à zéro.
- Budget de 10 $ par mois et par personne (administrateurs compris), plafond global de 30 $.
- Comptes de test IA à code OTP fixe sur dev (inactifs en prod).

### Modifié

- Résumé IA sur Claude Haiku 5.5 sans réflexion : environ 0,0001 $ par résumé.

### Corrigé

- Aucun.

> Détails complets : voir `CHANGELOG.md` et `docs/v-2-18-0/2.18.0_CLIENT.md`.

## Distribution des artefacts

Aucun binaire : l'API est déployée directement sur le serveur.

| Format | Canal de distribution |
| - | - |
| API | `.\private\deploy.ps1 -Target prod` |

## Instructions de déploiement rapides

```bash
# Migration déjà appliquée sur prod (docs/v-2-18-0/20261108_ai_budget.sql)
# Déploiement : .\private\deploy.ps1 -Target prod

# Tag Git (après merge de la PR)
git tag -a v2.18.0 -m "Release v2.18.0"
git push origin v2.18.0

# GitHub Release
gh release create v2.18.0 \
  --title "v2.18.0" \
  --notes-file docs/v-2-18-0/RELEASE_NOTES.md \
  --draft
```
