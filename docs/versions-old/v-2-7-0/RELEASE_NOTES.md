# RELEASE NOTES — cmem2 API v2.7.0

## Description courte

Refonte complète du domaine device + subscription : nouveau modèle multi-app séparant
Play Store et Stripe, support anonyme Android/Web/Windows, suppression des tables
`puzzle_devices` et `subscriptions` remplacées par cinq nouvelles tables spécialisées.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [X] API

## Changements principaux

### Ajouté

- Nouveaux modules : `src/playstore/`, `src/stripe/`, `src/access/`, `src/webdevice/`
- `POST /v2/devices/android/register` — JWT optionnel, support anonyme
- `POST /v2/devices/web/register` — JWT optionnel, support anonyme
- `GET|POST|DELETE /v2/devices/android/pseudonym` — pseudonyme par (user, app)
- `POST /v2/subscriptions/playstore/verify` — vérification achat Google Play via device_token
- `GET /v2/subscriptions/playstore/status` — statut Play Store via device_token
- `POST /v2/billing/checkout` — session Stripe Checkout
- `POST /v2/billing/portal` — session Stripe Billing Portal
- `POST /v2/billing/webhook` — webhook Stripe signé (prod migré)
- `GET /v2/access/status` — statut accès unifié multi-plateforme (Stripe uniquement avec JWT)
- Backup puzzle migré vers `android_devices`/`web_devices` (colonnes `backup_json`, `backup_saved_at`)
- Nouveaux crons : `expire_playstore.php`, `expire_stripe.php`

### Modifié

- `puzzle_shared` — FK `creator_id`/`partner_id` → `users` (était `puzzle_devices`)
- `puzzle_shared_pieces` — nouveau modèle d'état (state, held_by_id, prev_state, held_at, by_id)
- `puzzle_shared_events` — nouveau modèle d'état (state, held_by_id, by_id)
- `POST /auth/login` — champ `subscriptions` retiré de la réponse

### Supprimé

- Tables : `puzzle_devices`, `subscriptions`
- Routes legacy → 410 : `/puzzle/auth/register-device`, `/puzzle/auth/verify-subscription`, etc.
- Route legacy → 404 : `/stripe/webhook`
- Routes legacy → 410 : `/subscription/checkout`, `/subscription/portal`

> Détails complets : voir `CHANGELOG.md` — section `## [2.7.0]`.
> BREAKING CHANGES clients : voir `docs/v-2-7-0/2.7.0_CLIENT.md`.

## Distribution des artefacts

| Format | Canal de distribution |
| - | - |
| API PHP | Déploiement serveur direct — `git pull` + migrations SQL |

## Instructions de déploiement rapides

```bash
# Sur le serveur prod
git pull origin main
composer install --no-dev --optimize-autoloader
composer dump-autoload

# Migrations SQL (dans l'ordre)
mysql -u root -p < docs/v-2-7-0/20260523_v270_migration.sql
mysql -u root -p < docs/v-2-7-0/20260524_playstore_subscriptions_device_uuid.sql
mysql -u root -p < docs/v-2-7-0/20260529_backup_json_devices.sql

# Crontab — remplacer expire_subscriptions par :
# 10 3 * * * php /path/to/src/cron/expire_playstore.php
# 20 3 * * * php /path/to/src/cron/expire_stripe.php
```

```bash
# Tag Git (déjà fait)
# git tag -a v2.7.0 -m "Release v2.7.0"
# git push origin v2.7.0

# GitHub Release
gh release create v2.7.0 \
  --title "v2.7.0" \
  --notes-file docs/v-2-7-0/RELEASE_NOTES.md \
  --draft
```
