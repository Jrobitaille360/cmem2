---
name: release
description: /release — Full Release Orchestrator
---

## Étape 0 — Déterminer la version

Lire `git log <last-tag>..HEAD --oneline` et `CHANGELOG.md`. Demander la version cible (vX.Y.Z)
si elle n'est pas fournie. Appliquer SemVer : feat → minor, fix only → patch.

## Étape 1 — Tests (abort on failure)

```bash
php private/tests/run_all_tests.php
```

0 échec requis avant de continuer.

## Étape 2 — Branche de release

```bash
git fetch origin
git checkout main
git checkout -b release/vX.Y.Z
git push -u origin release/vX.Y.Z
```

Si la branche existe déjà (release en cours), se placer dessus directement.

## Étape 3 — Bump version

PHP API :

```bash
# .env et .env.example
APP_VERSION=X.Y.Z
# composer.json : ne pas ajouter de champ "version" (déconseillé pour projet root)
```

Flutter : `pubspec.yaml` → `version: X.Y.Z+N`
Next.js : `package.json` → `"version": "X.Y.Z"`

## Étape 4 — CHANGELOG.md

- Fusionner toutes les entrées `## [Unreleased ...]` depuis le dernier tag en une seule
  entrée `## [X.Y.Z] — YYYY-MM-DD`
- Ajouter `## [Unreleased]` vide au-dessus
- Pas de doublons de titres `###` (règle MD024)

## Étape 5 — Dossier de version + templates

```powershell
# Créer le dossier
mkdir docs\v-X-Y-Z

# Copier les templates (ne jamais modifier les originaux)
Copy-Item C:\code\PRIVATE-DATA\ancrage_versions\PR_BODY.md       docs\v-X-Y-Z\PR_BODY.md
Copy-Item C:\code\PRIVATE-DATA\ancrage_versions\RELEASE_NOTES.md docs\v-X-Y-Z\RELEASE_NOTES.md
```

Remplir `docs\v-X-Y-Z\PR_BODY.md` avec :
- Résumé de la release
- Types de changement cochés
- Liste des changements (tiré du CHANGELOG)
- Tests effectués (nombre / total)
- Références (PLAN, directives)
- Checklist avant merge

Remplir `docs\v-X-Y-Z\RELEASE_NOTES.md` (notes publiques, langage client).

Déplacer tout `PLAN_*.md` associé à la release dans `docs\v-X-Y-Z\`.

## Étape 6 — Commit + push

```bash
git add .
git commit -m "chore: release vX.Y.Z"
git push
```

## Étape 7 — PR draft

```bash
gh pr create \
  --title "Release vX.Y.Z" \
  --body-file docs/v-X-Y-Z/PR_BODY.md \
  --base main \
  --head release/vX.Y.Z \
  --draft
```

Si `gh` non authentifié : fournir l'URL manuelle
`https://github.com/ORG/REPO/compare/main...release/vX.Y.Z`

## Étape 8 — Post-merge (tag + GitHub Release)

**Attendre confirmation que la PR est mergée avant de tagger.**

```bash
git checkout main && git pull
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin vX.Y.Z

gh release create vX.Y.Z \
  --title "vX.Y.Z" \
  --notes-file docs/v-X-Y-Z/RELEASE_NOTES.md \
  --draft
```

## Étape 9 — Résumé

Lister ce qui a été releasé : nouveaux endpoints, fixes, migrations SQL, nombre de tests.
