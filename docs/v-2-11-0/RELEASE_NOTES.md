# RELEASE NOTES — cmem2 API v2.11.0

## Description courte

Les rappels arrivent maintenant dans le navigateur : notifications push web, réglables par
compte et respectueuses d'une plage « ne pas déranger ». Le pilier Contacts se complète côté
CRM — historique d'interactions, pipeline d'opportunités, documents rattachés et relances.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- **Notifications push web.** Un appareil s'abonne, les préférences se règlent par compte :
  type de rappel (événement, tâche, récurrence, suivi de contact), délai d'avance
  (5 min, 15 min, 1 h, 1 jour) et plage horaire de silence. Le push est **désactivé par
  défaut** — rien n'est envoyé sans activation explicite. Le contenu de la notification reste
  générique : aucun titre d'événement ni nom de contact n'y figure.
- **Fuseau horaire du compte.** Un usager peut déclarer son fuseau (ex. `Europe/Paris`).
  Ses rappels et sa plage de silence sont évalués dans ce fuseau, et non plus en heure de
  Montréal quand il n'a pas de calendrier.
- **Suivi commercial des contacts.** Opportunités rattachées à une fiche, visualisées en
  tableau Kanban par étape (prospect → qualifié → proposition → gagné / perdu), montant et
  date de clôture prévue.
- **Historique d'interactions.** Toutes les communications d'une fiche au même endroit :
  courriels envoyés depuis l'application et saisies manuelles (appel, note, rendez-vous, SMS).
- **Envoi de courriel depuis une fiche contact**, avec réponse dirigée vers l'adresse de
  l'usager et journalisation dans l'historique.
- **Relance de contact.** Une date de relance et son motif se posent sur la fiche ; la
  relance remonte ensuite dans les rappels push. Aucune relance n'est créée automatiquement.
- **Documents rattachés.** Fichiers et contacts peuvent être liés à n'importe quelle entité
  (événement, tâche, journal, projet, opportunité) ; supprimer l'un purge les liens de l'autre.

### Corrigé

- **Journaux applicatifs écrits au mauvais endroit.** Depuis le 22 juin 2026, un chemin de
  journalisation absolu était interprété comme relatif : les fichiers atterrissaient dans un
  dossier imbriqué, silencieusement. Corrigé, historique rapatrié, et une écriture refusée
  est désormais signalée au lieu d'être avalée.

> Aucun BREAKING CHANGE dans cette version.
> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Aucun binaire pour cette release — cmem2 API est déployée directement sur serveur.

| Format | Canal de distribution |
| - | - |
| API | Déploiement serveur (`private/deploy.ps1`, puis prod) |

## Instructions de déploiement rapides

```bash
# 1. Déployer le code ET le vendor/ régénéré (minishlink/web-push ^9 est nouveau)
# 2. Appliquer les 7 migrations SQL dans l'ordre de docs/v-2-11-0/2.11.0_PRODUCTION.md
# 3. Générer les clés VAPID sur la cible et les poser en .env
php src/push/generate_vapid.php
# 4. Ajouter le cron d'envoi push (*/5 * * * *)

# Tag Git (après merge de la PR)
git tag -a v2.11.0 -m "Release v2.11.0"
git push origin v2.11.0

# GitHub Release (sans artefacts joints)
gh release create v2.11.0 \
  --title "v2.11.0" \
  --notes-file docs/v-2-11-0/RELEASE_NOTES.md \
  --draft
```
