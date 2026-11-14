# RELEASE NOTES — cmem2_API v2.24.0

## Description courte

Les rappels par courriel peuvent maintenant partir par le serveur SMTP de l'usager (module
Assistant), avec un test de connexion et un repli automatique sur le SMTP de Journaux de Bord.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- `GET|PUT|DELETE /assistant/smtp` : serveur SMTP personnel de l'usager. Mot de passe chiffré
  côté serveur, jamais renvoyé.
- `POST /assistant/smtp/test` : vérifie connexion, STARTTLS et authentification sans envoyer de
  courriel, avec des erreurs lisibles (hôte injoignable, TLS refusé, authentification refusée).
  Option `send_to_self` pour recevoir un vrai courriel de test. 5 essais par 10 minutes.
- Rappels courriel envoyés par le SMTP de l'usager (HTML + texte), repli automatique sur le
  SMTP système en cas d'échec.
- Adresses privées, locales ou réservées refusées comme serveur SMTP.

### Modifié

- Aucun changement pour les usagers sans SMTP personnel.

### Corrigé

- Rien.

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Aucun binaire : API déployée sur le serveur par `private/deploy.ps1 -Target prod`.

## Instructions de déploiement rapides

Voir `docs/v-2-24-0/2.24.0_PRODUCTION.md` : `SMTP_ENCRYPTION_KEY` dans `.env.prod`, migration
`20261114_user_smtp_settings.sql`, puis déploiement du code.
