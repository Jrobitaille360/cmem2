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

## Étape 5 — Dossier de version

```powershell
mkdir docs\v-X-Y-Z
```

### 5a — Fichiers CLIENT et PRODUCTION

Créer `docs\v-X-Y-Z\X.Y.Z_CLIENT.md` :
- Changements visibles par les utilisateurs finaux
- BREAKING CHANGES en tête s'il y en a

Créer `docs\v-X-Y-Z\X.Y.Z_PRODUCTION.md` :
- Checklist déploiement : migrations SQL, `.env` à ajouter, crons à configurer
- PHP API : `composer install --no-dev --optimize-autoloader`
- Vérifications post-déploiement

### 5b — Templates PR et Release Notes

```powershell
# Ne jamais modifier les templates originaux dans PRIVATE-DATA
Copy-Item C:\code\PRIVATE-DATA\ancrage_versions\PR_BODY.md       docs\v-X-Y-Z\PR_BODY.md
Copy-Item C:\code\PRIVATE-DATA\ancrage_versions\RELEASE_NOTES.md docs\v-X-Y-Z\RELEASE_NOTES.md
```

Remplir `docs\v-X-Y-Z\PR_BODY.md` :
- Résumé de la release
- Types de changement cochés
- Liste des changements (tiré du CHANGELOG)
- Tests effectués (nombre / total)
- Références (PLAN, directives)
- Checklist avant merge

Remplir `docs\v-X-Y-Z\RELEASE_NOTES.md` (notes publiques, langage client).

### 5c — Migrations SQL

- Repérer les `YYYYMMDD_*.sql` dans `/docs/` et les déplacer dans `docs\v-X-Y-Z\`
- Construire `docs\v-X-Y-Z\build_DB-v-X-Y-Z.sql` : partir du `build_DB` de la version
  précédente et y intégrer les migrations dans la définition des tables
- Ne jamais modifier un `build_DB` d'une version antérieure déjà fixée

### 5d — PLAN_*.md

Déplacer tout `PLAN_*.md` associé à la release dans `docs\v-X-Y-Z\`.

## Étape 6 — README.md

Mettre à jour le badge de version et la date à la racine du projet.

## Étape 7 — Commit + push

```bash
git add .
git commit -m "chore: release vX.Y.Z"
git push
# Exécuter private/sync.ps1 s'il existe
if (Test-Path private/sync.ps1) { powershell private/sync.ps1 }
```

## Étape 8 — PR draft

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

## Étape 9 — Post-merge (tag + GitHub Release)

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

## Étape 10 — Post-release publique

Mettre à jour la fiche journauxdebord.com :

```bash
PUT https://cmem2.journauxdebord.com/items/{id}
# champs url_* non applicables = null
# mettre à jour : version, features
```

## Étape 11 — Résumé

Lister ce qui a été releasé : nouveaux endpoints, fixes, migrations SQL, nombre de tests.

---

> Référence étendue : `C:\code\PRIVATE-DATA\ancrage_versions\version_anchor.md`
