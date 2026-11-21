# RELEASE NOTES — cmem2_API v2.25.0

## Description courte

L'API sert le jeu « Je sais quand » : paquets de cartes, parties multijoueur au tour par tour
arbitrées par le serveur, et administration des illustrations des cartes.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- Paquets de cartes chronologiques pour le mode solo.
- Parties multijoueur au tour par tour (2 à 6 joueurs, sans compte) : salon à code de
  6 caractères, le serveur garde secrètes les dates des cartes en main jusqu'à la pose.
- Administration des paquets : import, illustration et crédit des cartes avec historique
  des modifications, liste des cartes illustrées sans crédit.

### Modifié

- Rien.

### Corrigé

- Rien.

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Aucun binaire : API déployée sur le serveur par `private/deploy.ps1 -Target prod`.

## Instructions de déploiement rapides

Voir `docs/v-2-25-0/2.25.0_PRODUCTION.md` : appliquer la migration
`20261118_jesaisquand.sql` avant de déployer le code.
