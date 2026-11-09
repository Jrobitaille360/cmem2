# RELEASE NOTES — cmem2_API v2.19.1

## Description courte

Correctif : un identifiant d'appareil mal formé à la connexion est refusé proprement, sans
gaspiller le code reçu par courriel.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Corrigé

- `POST /auth/login`, `POST /auth/verify-code`, `POST /auth/restore-account/verify` : un
  `device_id` non UUID répond `422` avec `errors.device_id` au lieu d'un `500`. Le code OTP
  n'est plus consommé : le client peut réessayer avec le même code.

> Détails complets : voir `CHANGELOG.md` et `docs/v-2-19-1/2.19.1_CLIENT.md`.

## Distribution des artefacts

Aucun binaire : l'API est déployée directement sur le serveur.

| Format | Canal de distribution |
| - | - |
| API | `.\private\deploy.ps1 -Target prod` |
