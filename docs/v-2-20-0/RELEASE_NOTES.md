# RELEASE NOTES — cmem2_API v2.20.0

## Description courte

Les limites de ressources (calendriers, contacts, membres de groupe, etc.) se règlent
maintenant par compte et par groupe, indépendamment du plan d'abonnement. Les rappels
courriel des événements récurrents sont envoyés pour chaque occurrence.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- Caps de ressources à trois niveaux : défaut, surcharge par groupe, surcharge par
  utilisateur (`null` = illimité). Administration ADMINISTRATEUR+ : `GET /caps/defaults`,
  `GET/PUT /users/{id}/caps`, `GET/PUT /groups/{id}/caps`.

### Modifié

- Tous les comptes reçoivent les caps par défaut (plus larges que l'ancien plan gratuit) ;
  les quotas ne dépendent plus du plan Stripe.
- Le nombre de membres d'un groupe est vérifié à l'ajout et à l'acceptation d'une invitation.
- **BREAKING** : un rappel d'événement d'un autre type que `EMAIL` est refusé (`422`,
  `REMINDER_CHANNEL_UNSUPPORTED`). L'import `.ics` reste permissif.

### Corrigé

- Rappels courriel des séries : un rappel par occurrence, occurrences annulées ignorées,
  occurrences déplacées rappelées à la bonne heure.
- Rappels saisis avec la clé `minutes` : maintenant envoyés.

> Détails complets : voir `CHANGELOG.md` et `docs/v-2-20-0/2.20.0_CLIENT.md`.

## Distribution des artefacts

Aucun binaire : API déployée sur le serveur (`private/deploy.ps1 -Target prod`).

| Format | Canal de distribution |
| - | - |
| API | `https://cmem2.journauxdebord.com` |

## Migration

`docs/v-2-20-0/20261109_resource_caps.sql` — appliquée en prod le 2026-11-10 avant le
déploiement du code. Schéma complet : `docs/v-2-20-0/build_DB-v-2.20.0.sql`.
