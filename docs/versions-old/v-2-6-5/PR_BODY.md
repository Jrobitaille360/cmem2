# Pull Request — cmem2_API v2.6.5

> **Projet :** cmem2_API (PHP API)
> **Description :** API principale cmem2, architecture plugin, auth, iCal

---

## Résumé

Release v2.6.5 : sync Google Play en temps réel sur subscription-status, traçabilité version déployée, rapport maintenance conditionnel.

## Type de changement

- [x] Correction de bug
- [x] Nouvelle fonctionnalité
- [ ] Refactoring / amélioration interne
- [ ] Documentation
- [ ] Performance
- [ ] Sécurité
- [ ] Base de données / migration

## Changements apportés

### Puzzle — subscription-status en temps réel

- **`GET /puzzle/auth/subscription-status`** — nouvel endpoint (device_token) : sync Google Play + fail-safe DB
- **`GET /subscription/status?app_id=puzzle`** — sync Google Play à chaque appel via `syncGooglePlayStatus()`
- **`docs/puzzle/API_PUZZLE_ENDPOINTS.json`** — v1.2.0

### Déploiement

- **`private/deploy.ps1`** — injecte `APP_COMMIT` + `APP_DEPLOYED_AT` dans `.env` distant

### Maintenance

- **`MaintenanceReport::send()`** — courriel uniquement si erreurs détectées

## Tests effectués

- [ ] Suite complète : tests/run_all_tests.php — 0 échec
- [ ] `GET /puzzle/auth/subscription-status` — réponse correcte, fail-safe validé
- [ ] `GET /subscription/status?app_id=puzzle` — sync Google Play vérifié dans logs
- [ ] Aucune régression sur les fonctionnalités existantes

## Checklist avant merge

- [x] `APP_VERSION=2.6.5` dans `.env.example`
- [x] `CHANGELOG.md` mis à jour — `[2.6.5] — 2026-05-14`
- [x] README badge mis à jour
- [x] Aucune clé/secret committée
- [x] Aucune migration SQL (schéma identique à v2.6.0)
- [ ] `composer install --no-dev` exécuté sur le serveur cible
- [ ] Endpoint `/health` répond correctement après déploiement
- [ ] Reviewer assigné
