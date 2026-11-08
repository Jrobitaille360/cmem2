# PLAN — Mise de côté de l'abonnement/achat payant

Date : 2026-09-22

> **STATUT : ABANDONNÉ (2026-09-23).** Changement de direction : les apps
> doivent conserver un abonnement possible. L'intégration Stripe/Play
> Store actuelle est confirmée en mode simulation (sandbox, aucun paiement
> réel traité) — nouvelle direction : chercher un système de paiement
> externe et sécurisé pour remplacer cette simulation. Voir
> [PLAN_paiement-externe-securise.md](PLAN_paiement-externe-securise.md).
> Les 4 directives liées (#229 cmem_web, #230 jdb, #231 puzzle, #232
> XMGame) sont CANCELLED. Le reste de ce document est conservé comme
> historique/référence (procédure de fermeture de comptes notamment,
> potentiellement réutilisable si le nouveau fournisseur remplace Stripe
> et que le compte Stripe legacy doit être fermé plus tard).

## Contexte

Décision : suspendre toute possibilité d'abonnement/achat payant (Stripe +
Google Play Billing/subscriptions) sur l'ensemble des projets c:/code, sans
retirer le code. Approche retenue : **feature flag OFF**, réversible.

Projets concernés (détectés par grep `stripe`/billing dans le code) :

| Projet | Rôle billing | Action |
| - | - | - |
| cmem2_API | API centrale — modules `src/stripe/`, `src/playstore/`, `src/auth_groups` (Subscription*) | Source de vérité — désactivation ici en premier |
| cmem_web | UI checkout/portal Stripe (`src/api/billing.ts`, `e2e/checkout-stripe.spec.ts`) | Masquer UI paiement, directive |
| jdb | Consomme `GET /v2/subscriptions/stripe/status` | Masquer UI paiement, directive |
| puzzle | Achat premium via API cmem2 (`purchase_service.dart`, plans Stripe) | Masquer écran achat, directive |
| XMGame | `createCheckoutSession` / `createPortalSession` (Stripe) | Masquer UI paiement, directive |

Autres projets (kestyon, pomodoro, puzzle_images_manager, traque, traque_admin) :
aucune référence billing trouvée — pas concernés.

## Ce qui est déjà en place

- Modules Stripe complets côté API : `Controllers/StripeController.php`,
  `Services/StripeService.php`, `Services/StripeSubscriptionService.php`,
  webhooks (`test_stripe_webhooks.php`), cron d'expiration
  (`src/cron/expire_stripe.php`).
- Module Play Store subscriptions côté API : `src/playstore/` (device link,
  subscription verify, `GooglePlayService.php`).
- UI de checkout/portal côté cmem_web, jdb, XMGame consommant ces endpoints.
- Achat premium in-app côté puzzle (Flutter), vérifié via l'API (pas de lib
  `in_app_purchase` native détectée — passe par cmem2_API).

## Améliorations à faire (en détail)

### 1. cmem2_API — flag global de désactivation

- Ajouter un flag d'environnement (ex. `.env` `BILLING_ENABLED=false`,
  `.env.example` à jour) lu par :
  - `StripeRouteHandler.php` / `PlaystoreRouteHandler.php` — retourner
    `503 Service Unavailable` (ou `403` avec code `BILLING_DISABLED`) sur
    tous les endpoints stripe/playstore de création (checkout, portal,
    achat) — **garder actifs** les endpoints de lecture de statut
    (`GET .../status`) pour ne pas casser les clients existants qui
    vérifient un abonnement déjà payé.
  - `SubscriptionService.php` / `StripeSubscriptionService.php` /
    `PlaystoreSubscriptionService.php` — ne plus créer de nouvel
    abonnement ; conserver la logique de lecture/expiration pour les
    abonnements existants.
  - `src/cron/expire_stripe.php` — garder actif (gère l'expiration des
    abonnés existants, indépendant du flag).
- Ne pas toucher aux webhooks Stripe entrants (`StripeController.php`) tant
  que le compte Stripe reste ouvert — nécessaires pour les événements sur
  abonnements existants (annulation, échec de paiement) jusqu'à la
  fermeture du compte.
- Documenter le flag dans `docs/entrypoints.md`.

### 2. cmem_web

- Masquer les points d'entrée UI checkout/upgrade (`useGroupBilling.ts`,
  pages liées) derrière le même flag (exposé via un endpoint de config ou
  variable de build).
- `e2e/checkout-stripe.spec.ts` : marquer `skip` avec commentaire renvoyant
  à ce plan, pas de suppression.

### 3. jdb

- Masquer l'affichage du statut/CTA abonnement Stripe dans les pages
  d'apps externes (`externalApps.ts`, appels `api.ts` ligne ~388) — garder
  l'appel de lecture de statut pour les comptes déjà abonnés, cacher tout
  bouton menant à un nouvel achat.

### 4. puzzle

- `purchase_screen.dart` : remplacer l'écran d'achat par un état "achat
  temporairement indisponible" (pas de suppression du code) quand
  `isPremium == false` et pas d'abonnement existant.
- `purchase_service.dart` : garder la vérification pour les utilisateurs
  déjà premium (`isPremium`), bloquer uniquement le déclenchement d'un
  nouvel achat.

### 5. XMGame

- `billing.ts` (`createCheckoutSession`, `createPortalSession`) : garder
  `createPortalSession` actif (gestion abonnement existant), désactiver
  côté UI l'entrée vers `createCheckoutSession` (nouveau paiement).

## Maintenances à prévoir

- Cron `expire_stripe.php` et équivalent Play Store : rester actifs tant
  que des abonnés existants subsistent, même flag OFF.
- Webhooks Stripe : monitorer jusqu'à fermeture du compte (voir section
  fermeture ci-dessous).
- Revoir CHANGELOG.md de chaque projet concerné lors de l'activation du
  flag.

## Phases d'implantation (ordre de priorité)

### Phase 1 — cmem2_API : flag + endpoints de création désactivés

- **Actions** : ajouter `BILLING_ENABLED`, brancher le flag dans
  `StripeRouteHandler`/`PlaystoreRouteHandler`, garder lecture de statut
  active.
- **Enjeux** : ne pas casser les vérifications d'abonnement existant
  (risque : couper l'accès premium à des payants actifs).
- **Tests** : `private/tests/test_stripe_v2.php`,
  `test_stripe_group_billing.php`, `test_playstore.php`,
  `test_subscriptions.php` — adapter pour couvrir le cas flag OFF (create
  → 503, status → 200).
- **Terminé quand** : tests passent, aucun endpoint de création
  n'accepte de requête flag OFF, endpoints de statut inchangés.

### Phase 2 — Directives vers cmem_web, jdb, puzzle, XMGame

- **Actions** : créer une directive (skill `directive`) par projet
  destinataire, pointant vers ce plan, décrivant précisément le point
  d'entrée UI à masquer (section correspondante ci-dessus).
- **Enjeux** : chaque projet reste maître de son implantation UI ; ne pas
  imposer d'implémentation, seulement le comportement attendu.
- **Tests** : à la charge de chaque projet destinataire.
- **Terminé quand** : les 4 tasks directive créées, taguées `directive`,
  `from-cmem2_API`.

### Phase 3 — Vérification zéro abonné actif

- **Actions** : requête Stripe Dashboard (ou API) — lister abonnements
  `active`/`trialing` ; requête Google Play Console — lister abonnements
  actifs. Attendre expiration naturelle ou contacter les abonnés restants.
- **Enjeux** : fermer un compte avec abonnés actifs coupe leur accès sans
  préavis — risque légal/réputationnel.
- **Tests** : export de la liste d'abonnements actifs, doit être vide (ou
  liste confirmée traitée) avant Phase 4.
- **Terminé quand** : 0 abonnement `active`/`trialing` côté Stripe et Play
  Console, confirmé par export daté.

### Phase 4 — Fermeture des comptes (STOP — confirmation explicite requise)

- Procédure manuelle ci-dessous (fermeture Stripe + Google Play Console).
  Ne pas exécuter sans confirmation explicite de l'utilisateur au moment
  venu (action irréversible, hors du contrôle de l'agent — passe par les
  dashboards externes, pas d'API de fermeture de compte).

## Procédure de fermeture des comptes (manuelle, non automatisable)

### Stripe

1. **Prérequis avant de fermer :**
   - Aucun abonnement `active`/`trialing` (Dashboard → Abonnements, filtrer
     par statut).
   - Aucun paiement en attente de règlement (payouts en transit).
   - Aucun litige (dispute) ouvert.
   - Solde du compte à 0 $ (retirer le solde restant vers le compte
     bancaire relié).
2. **Fermeture** : Dashboard Stripe → Paramètres → Compte → faire défiler
   jusqu'à « Fermer votre compte » (ou contacter le support Stripe si
   l'option n'apparaît pas — certains comptes avec historique nécessitent
   une demande manuelle).
3. Une fois fermé : les clés API sont révoquées, les webhooks cesseront de
   répondre — retirer les endpoints webhook côté cmem2_API à ce moment
   (pas avant).
4. Conserver un export des transactions/factures (obligations
   comptables/fiscales) avant fermeture — Stripe purge l'accès après un
   délai.

### Google Play Console (compte développeur)

1. **Prérequis avant de fermer :**
   - Aucun abonnement actif sur les apps publiées (Play Console →
     Monétisation → Abonnements, par app).
   - Envisager de dépublier les apps plutôt que fermer tout le compte, si
     d'autres apps non liées au paiement y restent publiées (**puzzle**
     notamment) — fermer le compte développeur supprime **toutes** les
     apps qui y sont listées, pas seulement le paiement.
2. **Fermeture** : Play Console → Paramètres → Paramètres du compte →
   Fermer le compte. Google impose un délai d'attente et prévient que
   c'est irréversible (nouvel enregistrement = nouveaux frais + nouvelle
   review).
3. **Alternative plus sûre** si d'autres apps restent en usage : ne fermer
   **que** le rôle marchand (désactiver les produits d'abonnement dans
   Play Console → Monétisation, sans fermer tout le compte développeur).

> Point d'attention : le compte Play Console sert aussi à puzzle (app
> gratuite/premium publiée, indépendante de cette décision paiement) —
> vérifier avant fermeture totale si d'autres apps y sont publiées, sinon
> préférer l'alternative (désactiver uniquement la monétisation).
