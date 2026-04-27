# RELEASE NOTES — cmem2 API v2.4.0

## Description courte

Intégration Stripe (Checkout + webhook), champs d'abonnement étendus (essai, hybride),
auto-register OTP, migration Google Play vers subscriptionsv2, et maintenance centralisée par module.

## Formats publiés

- [ ] Android (Play Store — AAB)
- [ ] Web
- [ ] Windows (installateur)
- [x] API

## Changements principaux

### Ajouté

- **Stripe Checkout** — `POST /subscription/checkout` génère une session de paiement Stripe (JWT requis)
- **Webhook Stripe** — `POST /stripe/webhook` traite les événements Stripe avec vérification HMAC-SHA256
- **Champs abonnement** — `is_trial`, `trial_end`, `is_premium`, `show_ads` stockés en base ; `device_token` et `stripe_customer` pour le modèle hybride
- **Auto-register OTP** — `POST /auth/send-code` crée silencieusement le compte si l'email est inconnu (Option A)
- **Maintenance centralisée** — `src/cron/maintenance.php` exécute la purge de tous les modules (lock file, mode `--dry-run`, crontab `0 3 * * *`)

### Modifié

- **Google Play** — `GooglePlayService` migré vers API subscriptionsv2 (détection essai via `offerTags`, `user_id` via `obfuscatedExternalAccountId`)
- **Table `subscriptions`** — `user_id` nullable, contraintes hybrides `uq_user_app` / `uq_device_app`

### Corrigé

- `POST /subscription/verify` — `is_trial` et `trial_end` du body maintenant transmis à `activatePremium()`
- `POST /auth/send-code` — `password_hash` = bcrypt d'un token aléatoire (colonne `NOT NULL` respectée)

> Détails complets : voir `CHANGELOG.md` — section `## [2.4.0]`.

## Instructions de déploiement rapides

```bash
# 1. Migrations
mysql -u root -p cmem2 < docs/v-2-4-0/20260423_files_media_type_executable.sql
mysql -u root -p cmem2 < docs/v-2-4-0/20260426_subscriptions_trial.sql

# 2. Code
git pull origin main
composer install --no-dev --optimize-autoloader

# 3. Tag Git (après merge PR)
git tag -a v2.4.0 -m "Release v2.4.0"
git push origin v2.4.0

# 4. GitHub Release
gh release create v2.4.0 \
  --title "v2.4.0" \
  --notes-file docs/v-2-4-0/RELEASE_NOTES.md \
  --draft
```

## Notes hotfix (si applicable)

| Champ | Valeur |
| - | - |
| Version de base | `v2.3.1` |
| Branche source | `release/v2.4.0` |
| Commits cherry-pickés vers main | N/A |
