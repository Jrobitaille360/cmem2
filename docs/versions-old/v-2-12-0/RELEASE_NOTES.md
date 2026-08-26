# RELEASE NOTES — cmem2 API v2.12.0

## Description courte

Version de sécurité et de conformité : suppression de compte conforme à la Loi 25 (effacement
physique après 30 jours de grâce), correctif d'un jeton d'authentification qui restait valide après
suppression, et durcissement des parcours de mot de passe et de vérification de courriel.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- **Suppression de compte conforme Loi 25.** L'accès est coupé immédiatement, les données sont
  effacées définitivement après 30 jours. `DELETE /users/me` renvoie la date d'effacement prévue
  (`purge_scheduled_at`). La purge retire aussi les fichiers du disque, préserve les groupes
  partagés en transférant leur propriété, et conserve les parties de casse-tête chez le partenaire.
- **Restauration pendant le délai de grâce** : `POST /auth/restore-account` puis
  `/auth/restore-account/verify`, ou simplement une connexion réussie par mot de passe.
- **Le courriel redevient disponible après la purge** — une inscription neuve avec la même adresse
  fonctionne, sans reliquat.
- **Filet de sécurité Stripe** : un abonnement actif est annulé avant la suppression du compte ;
  si l'annulation échoue, la suppression est refusée. Aucun compte supprimé ne peut rester facturé.
- **Registre de modules activables** (`/modules`) : activation par plan, interrupteur usager,
  quota. Éteindre un module ne détruit aucune donnée.
- **Corbeille des fichiers** : lister les fichiers supprimés et les restaurer.

### Modifié

- **BREAKING** — Politique de mot de passe unique : 8 caractères minimum, une minuscule, une
  majuscule, un chiffre, un caractère spécial. Tous les mots de passe existants ont été invalidés ;
  chaque usager doit passer par « mot de passe oublié ».
- **BREAKING** — `POST /auth/send-code` renvoie `409` `ACCOUNT_PENDING_DELETION` sur un compte en
  attente d'effacement, avec la date de purge.
- **BREAKING** — `DELETE /users/me` peut renvoyer `409` `STRIPE_CANCEL_FAILED`.

### Corrigé

- **BREAKING (sécurité)** — Un jeton d'authentification émis avant la suppression d'un compte
  restait accepté jusqu'à 15 jours sur les routes `/auth/*` : `GET /auth/me` retournait notamment
  le profil complet d'un compte supprimé. Ces appels renvoient désormais `401`. Un changement de
  rôle prend également effet immédiatement.
- Le code de réinitialisation de mot de passe ne figure plus dans la réponse HTTP ; 6 chiffres,
  5 tentatives maximum, 5 demandes par 10 minutes.
- Le token de vérification de courriel ne figure plus dans aucune réponse HTTP ; un seul token
  actif par usager, 5 tentatives maximum, réponse générique unique.

> Détails complets : voir `CHANGELOG.md`.

## Distribution des artefacts

Aucun binaire pour cette version — déploiement serveur uniquement.

| Format | Canal de distribution |
| - | - |
| API | Déploiement serveur (`private/deploy.ps1 -Target prod`) |

## Instructions de déploiement rapides

```bash
# Déploiement (déjà effectué le 2026-08-02)
.\private\deploy.ps1 -Target prod

# Migrations : quatre fichiers dans docs/v-2-12-0/ (déjà appliquées sur dev et prod)
# Base neuve : docs/v-2-12-0/build_DB-v-2.12.0.sql

# Tag Git
git tag -a v2.12.0 -m "Release v2.12.0"
git push origin v2.12.0

# GitHub Release
gh release create v2.12.0 \
  --title "v2.12.0" \
  --notes-file docs/v-2-12-0/RELEASE_NOTES.md \
  --draft
```

## Migration côté client

| Changement | Action requise |
| - | - |
| Politique de mot de passe | Aligner la validation locale ; prévoir le parcours « mot de passe oublié » |
| `409 ACCOUNT_PENDING_DELETION` | Traiter le code sur `send-code` |
| `409 STRIPE_CANCEL_FAILED` | Afficher l'erreur sur l'écran de suppression de compte |
| `401 ACCOUNT_UNAVAILABLE` | Traiter comme une session expirée |
