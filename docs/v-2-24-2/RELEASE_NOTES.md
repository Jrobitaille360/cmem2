# RELEASE NOTES — cmem2_API v2.24.2

## Description courte

L'assistant se souvient de ce qu'il a fait d'un message à l'autre, et les projets ne
consomment plus de place de calendrier.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- Rien.

### Modifié

- Assistant en streaming : la conversation apparaît tout de suite ; une panne du service IA
  est signalée dans le flux (`error` puis `done`) plutôt que par une erreur HTTP `502`.

### Corrigé

- L'assistant ne nie plus avoir créé une tâche au message suivant : il connaît les actions
  des tours précédents.
- Les tâches créées par l'assistant arrivent toujours dans un calendrier visible.
- Créer ou supprimer un projet ne fait plus atteindre le quota de calendriers.

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Aucun binaire : API déployée sur le serveur par `private/deploy.ps1 -Target prod`.

## Instructions de déploiement rapides

Voir `docs/v-2-24-2/2.24.2_PRODUCTION.md` : aucune migration, déploiement du code seulement.
