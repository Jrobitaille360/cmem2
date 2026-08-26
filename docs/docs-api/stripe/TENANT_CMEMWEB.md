# Tenant `cmemweb` (client cmem_web)

<!-- markdownlint-disable MD013 -->

## Contexte

Le client `cmem_web` transmettait `app_id='cmem'` sur les appels multi-tenant (facturation Stripe,
plans, caps). Nom trop proche du backend `cmem2_API` — risque de confusion. Décision **2026-07-22** :
renommer le tenant en **`cmemweb`**.

Greenfield confirmé : aucun abonné Stripe réel sous `'cmem'` au moment du renommage — aucune
migration de données requise.

## Voie retenue : **alias** (option b)

`cmemweb` devient l'identifiant **primaire**. `cmem` reste accepté comme **alias legacy** (souplesse
si un client résiduel l'utilisait encore). Aucun renommage destructif des variables `STRIPE_PRICE_CMEM_*`.

### Ce qui a changé côté `cmem2_API`

| Élément | Changement |
| - | - |
| `.env` / `.env.example` | Ajout de `STRIPE_PRICE_CMEMWEB_MONTHLY` / `STRIPE_PRICE_CMEMWEB_YEARLY` (mêmes price IDs que `CMEM_*`) |
| `EntitlementService` | Constante `CMEM_APP_IDS = ['cmemweb','cmem']` ; résolution du plan via `findByUserAndApps()` (accepte les deux) |
| `StripeSubscription` | Nouvelle méthode `findByUserAndApps(int $userId, array $appIds)` — priorité au statut actif puis au plus récent |

### Ce qui n'a PAS eu besoin de changer

- **Checkout / portail / statut / annulation / webhook** : déjà agnostiques à `app_id` (passthrough).
  Le webhook stocke l'`app_id` reçu dans les métadonnées Stripe (`cmemweb`), résolu tel quel.
- **`GET /plans`** : la liste vient de la table DB `plans`, indépendante de `app_id`. `?app_id=cmemweb`
  et `?app_id=cmem` renvoient donc un résultat identique.
- **Défaut serveur** : reste `puzzle` pour les appels sans `app_id`.

## Dépendance externe (jdb)

Les URLs Stripe `success_url` / `cancel_url` / `return_url` pointent vers
`https://journauxdebord.com/{app_id}/subscription/...`. Avec `app_id='cmemweb'`, les pages doivent
exister sous le chemin `/cmemweb/subscription/*` côté jdb (directive séparée).

## Price IDs

En greenfield, `STRIPE_PRICE_CMEMWEB_*` réutilise les mêmes produits/prix Stripe que `CMEM_*`. Si un
jour les deux tenants doivent diverger, il suffit de leur assigner des price IDs distincts dans `.env`.
