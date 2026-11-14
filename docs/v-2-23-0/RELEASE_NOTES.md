# RELEASE NOTES — cmem2_API v2.23.0

## Description courte

Nouveau module Assistant : un chat avec Claude qui crée et modifie les tâches et leurs
rappels, consulte l'agenda, et ne supprime rien sans confirmation de l'usager.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- `POST /assistant/chat` : réponse JSON ou en streaming (SSE), avec la date, l'heure et le
  fuseau de l'usager pour interpréter « demain », « mardi prochain », « dans 15 jours ».
- Consentement Loi 25 obligatoire avant le premier envoi de données à Claude
  (`/assistant/consent`).
- Historique des conversations, consultable et supprimable par l'usager.
- Outils : lister, créer et modifier des tâches, poser des rappels courriel ou push,
  consulter l'agenda. Les tâches chiffrées ne sont jamais lues.
- Suppressions proposées par l'assistant, confirmées ou refusées par l'usager
  (`/assistant/actions/{id}/confirm|reject`).
- Module `assistant` coché par personne, budget IA en dollars commun avec le résumé IA
  (`GET /assistant/usage`).

### Modifié

- `GET /modules` liste 9 modules (clé `assistant` ajoutée).

### Corrigé

- Texte des rappels : délai exact, date et motif des relances (#501).
- CORS : en-tête `If-Unmodified-Since` autorisé (#508).

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

API seulement : déploiement serveur direct (`private/deploy.ps1 -Target prod`), aucun binaire.

## Instructions de déploiement rapides

```bash
# 1. Schéma (avant le code) : docs/v-2-23-0/20261114_module_assistant.sql
# 2. Code : .\private\deploy.ps1 -Target prod
# 3. Vérifier : GET /health → version 2.23.0
```
