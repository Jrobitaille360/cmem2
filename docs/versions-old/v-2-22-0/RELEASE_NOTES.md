# RELEASE NOTES — cmem2_API v2.22.0

## Description courte

Rappels unifiés : courriel et push suivent les mêmes règles, avec des défauts par type, une
liste de rappels par élément et un filtre par appareil. Un seul cron envoie les deux canaux,
et les rappels des tâches de projet partent enfin (contrat cmem_web #236, directive #487).

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- Champ `notifications` (`EMAIL` / `DISPLAY` + délai) sur événements, tâches, tâches de
  projet, contacts et opportunités.
- `GET|PUT /notifications/defaults` : rappels par défaut par type, plage silencieuse et
  titre réel optionnel valant pour les deux canaux.
- `GET|PUT|DELETE /notifications/devices` : types acceptés et plage silencieuse par appareil.
- Cron unifié courriel + push, idempotent par appareil, purge des appareils inactifs 90 jours.

### Modifié (BREAKING)

- `DISPLAY` accepté : `REMINDER_CHANNEL_UNSUPPORTED` remplacé par des `422` codés
  (`REMINDER_CHANNEL_UNKNOWN`, `REMINDER_LEAD_INVALID`, `REMINDER_DUPLICATE`, `REMINDER_TOO_MANY`).
- `notifications` relu normalisé partout, listing des événements compris.
- Courriel de rappel au titre générique par défaut ; ancienne file courriel retirée.

### Corrigé

- Rappels des tâches de projet envoyés (#439).
- `/users/me/notification-preferences` atteignait `/users/me` : désactiver les courriels de
  rappel n'avait aucun effet.

### Déprécié

- `rappelMinutesAvant`, `GET|PUT /push/preferences`, `GET /notifications/email` (retrait en v2.23.0).

> Détails complets : voir `CHANGELOG.md` et `docs/v-2-22-0/2.22.0_CLIENT.md`.

## Distribution des artefacts

Aucun binaire : API déployée sur le serveur (`private/deploy.ps1 -Target prod`).

## Déploiement

Ordre : migration de schéma, code, reprise des données — voir
`docs/v-2-22-0/2.22.0_PRODUCTION.md`.
