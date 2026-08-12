# RELEASE NOTES — cmem2_API v2.15.0

## Description courte

Six directives inter-projets livrées : livraison push Android fiable, effacement explicite des
champs texte du calendrier, code OTP fixe pour les tests `jdb`, socle des rôles de jeu Traque, et
un nouveau proxy IA de résumé d'agenda.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- **Rôles de jeu Traque** (`gm`, `traque_admin`) : trois endpoints admin (`grant`, `revoke`,
  `log`), promotion automatique de Maître de Jeu au niveau 15
- **Proxy IA** (`POST /ai/summarize`) : résumé d'agenda sur une période, gating par module `ia`,
  quota décompté avant l'appel modèle, clé Anthropic serveur uniquement
- **`AUTH_TEST_CODE`** : code OTP fixe en développement, pour débloquer la suite Playwright de
  `jdb` — inactif hors `APP_ENV=development`

### Modifié

- `PUT /calendars/{cid}/events/{eid}` distingue désormais « champ absent » (inchangé) de « `null` »
  (effacement explicite) sur `location`, `description`, `color`, `recurrence_rule`
- Occurrence de calendrier : `modified_location` / `modified_description` distinguent désormais
  « hérite du parent » (`NULL`), « effacé » (`''`) et « remplacé » (texte)
- Notifications push web envoyées avec `Urgency: high` — livraison Android nettement plus rapide
  sous App Standby / Doze

### Corrigé

- Absence de l'entête `Urgency` sur le push web, qui pouvait retarder la livraison Android de
  plusieurs minutes à heures
- Lecture des occurrences : une chaîne vide sur `modified_location` / `modified_description` était
  traitée comme « non modifié » au lieu de « effacé »

> Détails complets : voir `CHANGELOG.md` et `docs/v-2-15-0/2.15.0_CLIENT.md`.

## Distribution des artefacts

Aucun binaire dans cette release — elle ne concerne que l'API, déployée directement sur le serveur.

| Format | Canal de distribution |
| - | - |
| API | `private/deploy.ps1 -Target prod` → cmem2.journauxdebord.com |

## Instructions de déploiement rapides

```bash
# Migrations SQL — voir docs/v-2-15-0/2.15.0_PRODUCTION.md pour la checklist complète
# À appliquer AVANT le déploiement du code

# Tag Git
git tag -a v2.15.0 -m "Release v2.15.0"
git push origin v2.15.0

# GitHub Release (sans artefacts joints)
gh release create v2.15.0 \
  --title "v2.15.0" \
  --notes-file docs/v-2-15-0/RELEASE_NOTES.md \
  --draft
```

## Notes hotfix

Sans objet — release ordinaire depuis `main`.
