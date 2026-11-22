# RELEASE NOTES — cmem2_API v2.26.1

## Description courte

Version de correctifs : l'échec d'envoi du code de connexion par courriel est désormais
signalé au client, et le cron des rappels ne remplit plus les journaux d'erreurs pendant une
panne de la base.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Corrigé

- Connexion par code (`POST /auth/send-code`, `POST /auth/restore-account`) : si le courriel
  ne peut pas être envoyé, l'API répond `500` « Erreur lors de l'envoi du code » au lieu
  d'annoncer un code qui n'arrivera jamais (#534).
- Envoi des codes de connexion rétabli en production (mot de passe SMTP de la boîte d'envoi
  corrigé, 2026-11-22).
- Cron des rappels : une panne de la base de données est journalisée en avertissement, sans
  trace, au lieu d'une erreur fatale à chaque minute ; aucun rappel perdu (#532).

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Aucun binaire : API déployée sur le serveur (`private/deploy.ps1 -Target prod`).

| Format | Canal de distribution |
| - | - |
| API | Déploiement serveur direct (`cmem2.journauxdebord.com`) |

## Instructions de déploiement rapides

```bash
git tag -a v2.26.1 -m "Release v2.26.1"
git push origin v2.26.1

gh release create v2.26.1 \
  --title "v2.26.1" \
  --notes-file docs/v-2-26-1/RELEASE_NOTES.md \
  --draft
```
