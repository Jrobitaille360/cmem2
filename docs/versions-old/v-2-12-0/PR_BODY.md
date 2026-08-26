# Release v2.12.0 — cmem2 API

## Résumé

Prépare la release `v2.12.0` de **cmem2 API**. Cette PR fige l'état du dépôt pour produire les
artefacts de publication et poursuivre le développement ensuite.

Version à dominante **sécurité et conformité** : suppression de compte conforme Loi 25 (purge
physique après 30 jours), quatre correctifs de sécurité sur l'authentification, plus le registre
de modules activables et la corbeille des fichiers.

Le code est **déjà déployé** sur dev et sur prod, et les quatre migrations sont déjà appliquées
sur les deux cibles.

## Formats publiés dans cette release

- [ ] Android — AAB (Play Store)
- [ ] Web — Flutter / Next.js / PHP
- [ ] Windows — Installateur Inno Setup
- [x] API — Déploiement serveur

## Changelog (résumé)

Voir `CHANGELOG.md` — section `## [2.12.0] — 2026-08-02`.

### BREAKING CHANGES

1. **Politique de mot de passe unique** (8 car. + maj/min/chiffre/spécial) — tous les hachages
   existants invalidés, passage obligé par « mot de passe oublié ».
2. **`POST /auth/send-code`** renvoie `409` `ACCOUNT_PENDING_DELETION` sur un compte en délai de
   grâce, là où il répondait `200` générique (et `500` en pratique, sur violation d'unicité).
3. **`DELETE /users/me`** peut renvoyer `409` `STRIPE_CANCEL_FAILED` — la suppression est refusée
   si un abonnement Stripe actif ne peut pas être annulé.
4. **Un JWT émis avant une suppression de compte n'est plus accepté** → `401`
   `ACCOUNT_UNAVAILABLE`.

### Sécurité

- **JWT accepté après suppression de compte** : `JwtAuthMiddleware` validait signature et
  expiration sans consulter la base ; les routes `/auth/*` l'appellent directement au lieu de
  passer par `AuthService`. `GET /auth/me` retournait le profil complet d'un compte supprimé,
  jusqu'à 15 jours. Corrigé.
- Réinitialisation de mot de passe : code retiré des réponses, 6 chiffres, limites de tentatives.
- Vérification de courriel : token retiré des réponses, un seul token actif, limites de tentatives.
- Politique de mot de passe unique et invalidation de tous les mots de passe.

### Ajouté

- Suppression de compte Loi 25 : purge physique à 30 jours, `purge_scheduled_at`, filet Stripe,
  restauration par OTP ou mot de passe, libération du courriel après purge, archive comptable
  anonymisée.
- Registre de modules activables `/modules` (gating par plan, interrupteur usager, quota).
- Corbeille des fichiers : `GET /files/user/{user_id}?deleted=`.

## Checklist commune

- [x] Version mise à jour (`.env`, `.env.example`, `README.md`)
- [x] `CHANGELOG.md` mis à jour
- [x] `docs/v-2-12-0/PR_BODY.md` rempli et sauvegardé
- [x] `docs/v-2-12-0/RELEASE_NOTES.md` rempli et sauvegardé
- [x] `PLAN_*.md` associés déplacés dans `docs/v-2-12-0/`
- [x] Migrations pendantes déplacées dans `docs/v-2-12-0/` et intégrées à
      `build_DB-v-2.12.0.sql` (vérifié sur base jetable : 80 tables)
- [ ] Reviewer assigné

## Checklist API PHP

- [x] `composer install --no-dev --optimize-autoloader` exécuté sur les serveurs
- [x] Migrations SQL appliquées (dev et prod)
- [x] Endpoint `/health` répond `200`
- [x] Suite de tests complète : **2245 / 2245**

## Notes pour le release manager

- Après merge, tagger le commit :
  `git tag -a v2.12.0 -m "Release v2.12.0"` puis `git push origin v2.12.0`.
- **Le tag `v2.11.0` n'a jamais été posé** alors que la PR #8 a été mergée le 2026-07-27. À
  décider : le poser rétroactivement sur `5fc941d`, ou considérer 2.11.0 comme non publiée.
