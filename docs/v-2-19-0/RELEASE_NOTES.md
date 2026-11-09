# RELEASE NOTES — cmem2_API v2.19.0

## Description courte

L'accès à l'API passe sur approbation : chaque nouveau compte attend l'accord d'un
administrateur, qui choisit ensuite les modules ouverts à chaque personne.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### BREAKING CHANGES

- Nouveaux comptes en attente (`access_status = pending`) : toute route JWT répond
  `403 ACCOUNT_PENDING` (ou `ACCOUNT_SUSPENDED`), sauf `GET /auth/me`, `POST /auth/logout`,
  `DELETE /users/me`, et les fichiers publics pour un compte en attente.
- Disponibilité des modules = modules cochés par l'administrateur, plus le plan d'abonnement.

### Ajouté

- Approbation et suspension des comptes : `PUT /users/{id}/access-status`.
- Invitations : `POST/GET/DELETE /invitations` (courriel, modules, budget IA).
- Modules cochés : `GET/PUT /users/{id}/modules` ; défaut `projet`, `contacts`, `crm`, `ged`.
- `access_status` dans la connexion, `POST /auth/refresh`, `GET /auth/me`, et `GET /users`
  (filtrable).
- Courriels : admin à chaque inscription en attente, usager à son approbation.
- Comptes de test `e2e-pending@test.local` et `e2e-suspended@test.local` sur dev.

### Modifié

- Aucun module retiré aux comptes existants (modules dérivés de leur plan à la migration).

### Corrigé

- Tests instables `If-Unmodified-Since` (#413).

> Détails complets : voir `CHANGELOG.md` et `docs/v-2-19-0/2.19.0_CLIENT.md`.

## Distribution des artefacts

Aucun binaire : l'API est déployée directement sur le serveur.

| Format | Canal de distribution |
| - | - |
| API | `.\private\deploy.ps1 -Target prod` |

## Instructions de déploiement rapides

```bash
# 1. Migrations (avant le code) : docs/v-2-19-0/20261109_access_approval.sql
#    puis docs/v-2-19-0/20261109_user_module_grants.sql
# 2. ACCESS_ADMIN_NOTIFY_EMAIL active dans .env.prod
# 3. Déploiement : .\private\deploy.ps1 -Target prod

# Tag Git (après merge de la PR)
git tag -a v2.19.0 -m "Release v2.19.0"
git push origin v2.19.0

# GitHub Release
gh release create v2.19.0 \
  --title "v2.19.0" \
  --notes-file docs/v-2-19-0/RELEASE_NOTES.md \
  --draft
```
