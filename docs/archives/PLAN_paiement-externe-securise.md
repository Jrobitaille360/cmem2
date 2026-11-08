# PLAN — Système de paiement externe sécurisé

Date : 2026-09-23

> **STATUT : ABANDONNÉ (2026-11-08).** Fin de la commercialisation :
> plus aucun paiement, accès réservé aux personnes approuvées. Voir
> [PLAN_acces-sur-approbation.md](../PLAN_acces-sur-approbation.md). Paddle
> n'a jamais été intégré (aucun code). Document conservé comme
> historique (comparatif fournisseurs).

## Contexte

Suite à [PLAN_mise-de-cote-paiement.md](PLAN_mise-de-cote-paiement.md)
(abandonné) : décision inversée — les apps doivent conserver la
possibilité de s'abonner. Constat déclencheur : l'intégration actuelle
côté cmem2_API (`src/stripe/`, `src/playstore/`) est en mode simulation
(sandbox), aucun paiement réel n'a jamais été traité. Objectif : trouver
et intégrer un système de paiement externe et sécurisé pour remplacer
cette simulation, en gardant les abonnements fonctionnels pour les apps
concernées (cmem_web, jdb, XMGame — voir table des projets dans le plan
abandonné). Puzzle exclu de la portée depuis le 2026-10-04 (retiré du
Play Store, 0 abonné).

**Portée tranchée (2026-10-04)** :

- Scope demandé initialement par l'utilisateur : « les deux » (web +
  Play Store). **Précision technique** : Google impose par politique
  Play Store l'usage de Google Play Billing pour tout achat/abonnement
  numérique in-app sur Android — pas remplaçable par un fournisseur
  externe sans risque de rejet/retrait de l'app.
- **Mise à jour (2026-10-04)** : puzzle retiré du Play Store par
  l'utilisateur, 0 abonné actif. Puzzle sort donc entièrement de la
  portée de ce plan (pas de Play Billing à traiter, pas de web checkout
  non plus pour cette app). **Portée finale : web uniquement**,
  remplacement de la simulation Stripe pour **cmem_web, jdb, XMGame**.
  Si puzzle est republié un jour avec abonnement, reprendre Play Billing
  séparément à ce moment (hors de ce plan).
- Marché cible : n'importe où, n'importe quelle devise → privilégier un
  fournisseur qui gère la conformité fiscale internationale (TVA/GST)
  lui-même (modèle Merchant of Record) plutôt que de porter cette charge
  en interne.
- Modèle d'abonnement : garder tel quel (mensuel/annuel, essai gratuit,
  multi-tenant groupes cmem_web).
- Budget : pas de contrainte fixe — viser le meilleur rapport
  qualité/prix plutôt que le taux le plus bas ou le moins cher à tout
  prix.

## Ce qui est déjà en place

- Architecture API déjà pensée pour un fournisseur de paiement : module
  `src/stripe/` (Config, Controllers, Services, Routing) séparé du métier
  (`SubscriptionService.php`, `EntitlementService.php`) — le découplage
  service de paiement / entitlements existe déjà, ce qui facilite une
  substitution de fournisseur sans réécrire la logique d'accès.
- Module `src/playstore/` distinct pour les achats Android (hors
  portée — puzzle retiré du Play Store, 0 abonné, 2026-10-04).
- Cron d'expiration (`src/cron/expire_stripe.php`) et webhooks
  (`StripeController.php`) comme patron déjà éprouvé pour un nouveau
  fournisseur.
- UI côté cmem_web/jdb/XMGame déjà câblée sur les endpoints
  `/v2/billing/*` et `/v2/subscriptions/stripe/status` — un changement de
  fournisseur côté API peut rester transparent pour ces UI si le contrat
  d'API (routes, formats de réponse) est conservé.

## Améliorations à faire (en détail)

### Phase 0 — Cadrage (TERMINÉ 2026-10-04)

Portée validée (voir section ci-dessus) : web uniquement, n'importe où/
devise, modèle abonnement inchangé, viser meilleur rapport qualité/prix.

**Short-list fournisseurs (recherche 2026-10-04)** — tous Merchant of
Record (MoR) : gèrent eux-mêmes TVA/GST internationale, conformité
PCI-DSS déléguée, factures/remboursements — pertinent vu le marché
« n'importe où, n'importe quelle devise ».

| Fournisseur | Frais | Points forts | Points faibles |
| - | - | - | - |
| **Paddle** | 5 % + 0,50 $/transaction (prix public) | Pensé SaaS/abonnements, proration et facturation hybride solides, prix transparent, bonne doc API/webhooks | Frais plus élevés qu'un Stripe classique (non-MoR) |
| **FastSpring** | Tarif sur devis (non public) | Checkout très personnalisable, bon pour digital goods/jeux | Pricing opaque, à négocier |
| **Stripe Managed Payments** (nouveau, basé sur le rachat de Lemon Squeezy) | ≈ 3,5 % en plus des frais Stripe standards → effectif ≈ 6,4 % + 0,30 $ domestique, >8-10 % à l'international avec conversion devise | Reste dans l'écosystème Stripe déjà architecturé côté cmem2_API (moins de réécriture) | **Le plus cher du marché** ; disponible dans ~35 pays seulement (Amérique du Nord, Europe de l'Ouest, quelques marchés APAC) — ne couvre pas « n'importe où » |
| Lemon Squeezy | Comparable à Paddle, légèrement moins cher historiquement | MoR éprouvé, simple à intégrer | Stripe prépare sa migration vers Stripe Managed Payments (pas d'arrêt annoncé, mais convergence à prévoir — risque de migration forcée plus tard) |

**Recommandation** : **Paddle**. Meilleur compromis qualité/prix pour le
besoin exprimé : couverture mondiale réelle (contrairement à Stripe
Managed Payments limité à ~35 pays), prix public clair, conçu
spécifiquement pour les abonnements SaaS récurrents (correspond au
modèle mensuel/annuel + multi-tenant groupes déjà en place). Stripe
Managed Payments écarté : trop cher et couverture géographique
insuffisante pour un marché « n'importe où ». Lemon Squeezy écarté :
absorption prévue par Stripe Managed Payments, risque de migration
forcée à moyen terme.

- **Terminé quand** : fournisseur validé par l'utilisateur.
- **CONFIRMÉ (2026-10-04)** : Paddle retenu.

### Phase 1 — cmem2_API : module Paddle (serveur)

- **Actions** :
  - `composer require paddlehq/paddle-php-sdk` (SDK officiel — gère
    vérification de signature `Paddle-Signature` et parsing payload).
  - Créer `src/payment/` (nouveau module, remplace `src/stripe/` à
    terme) : `Config/PaddleConfig.php`, `Controllers/PaddleController.php`
    (webhooks), `Controllers/CheckoutController.php`,
    `Services/PaddleService.php`, `Services/PaddleSubscriptionService.php`,
    `Routing/PaddleRouteHandler.php`.
  - Variables d'env : `PADDLE_API_KEY`, `PADDLE_ENVIRONMENT`
    (sandbox/production), `PADDLE_WEBHOOK_SECRET` — jamais committées,
    `.env.example` à jour avec clés vides.
  - Webhooks à écouter : `subscription.created`, `subscription.updated`,
    `subscription.canceled`, `subscription.trialing`,
    `subscription.past_due`, `transaction.completed`.
  - Conserver le contrat d'API existant côté clients (routes
    `/v2/billing/checkout`, `/v2/billing/portal`,
    `/v2/subscriptions/*/status`) — traduire en interne vers Paddle
    (checkout Paddle = "transaction", portail = lien de gestion Paddle)
    pour ne pas casser cmem_web/jdb/XMGame.
  - Adapter `SubscriptionService.php` / `EntitlementService.php` pour
    lire le statut Paddle (`active`/`trialing`/`past_due`/`canceled`) au
    lieu du statut Stripe simulé.
  - Garder `src/stripe/` et `src/playstore/` en place tant que la
    migration n'est pas terminée (pas de suppression prématurée — même
    principe que le plan abandonné : découplage propre avant retrait).
- **Enjeux** : ne pas casser le contrat d'API pour les 3 projets
  consommateurs ; webhooks Paddle doivent être idempotents (mirroring en
  base, pas de double-traitement sur retry).
- **Tests** (Spec-First — tests avant code, voir protocole global) :
  nouveaux `private/tests/test_paddle_*.php` (checkout, webhooks,
  statut abonnement) écrits et en échec avant implémentation ; adapter/
  remplacer `test_stripe_v2.php`, `test_stripe_group_billing.php`,
  `test_stripe_webhooks.php`, `test_subscriptions.php`.
- **Terminé quand** : tests Paddle passent en sandbox Paddle (pas
  simulation interne — vrai sandbox fournisseur), routes `/v2/billing/*`
  inchangées côté contrat, webhooks vérifiés et idempotents.

### Phase 2 — Directives vers cmem_web, jdb, XMGame

- **Actions** : une fois Phase 1 stable en sandbox Paddle, créer une
  directive (skill `directive`) par projet consommateur si un
  changement UI est requis (ex. redirection checkout Paddle au lieu de
  Stripe Checkout si l'URL/format diffère) — sinon, si le contrat
  d'API est resté identique, aucune directive nécessaire.
- **Terminé quand** : chaque projet confirme fonctionner avec Paddle en
  sandbox.

### Phase 3 — Bascule production + fermeture ancienne simulation

- **Actions** : clés Paddle production, test d'un paiement réel à faible
  montant, puis retrait progressif de `src/stripe/`/`src/playstore/`
  (voir [PLAN_mise-de-cote-paiement.md](PLAN_mise-de-cote-paiement.md)
  pour la procédure de fermeture de compte Stripe si un compte réel avait
  été ouvert — ici sandbox uniquement donc pas de fermeture de compte
  nécessaire, juste retrait du code mort).
- **Terminé quand** : abonnements réels fonctionnels via Paddle, ancien
  code Stripe simulé retiré ou archivé.

## Maintenances à prévoir

- Si Stripe/Play Billing sandbox est conservé en parallèle pendant la
  transition (tests, comparaison), documenter clairement lequel est actif
  en prod vs test pour éviter toute confusion avec un vrai paiement.
- Sécurité : clés API du nouveau fournisseur en variables d'environnement
  uniquement (jamais committées), webhooks signés/vérifiés — mêmes règles
  que pour Stripe actuellement.

## Prochaine étape

Paddle confirmé. Démarrer Phase 1 (cmem2_API) : Spec-First — reformuler
critères d'acceptation testables, écrire tests `test_paddle_*.php` en
échec d'abord, puis implémenter le module `src/payment/`.

Sources (recherche 2026-10-04) :

- [Top 7 Merchant of Record Platforms 2026](https://dodopayments.com/blogs/best-merchant-of-record-platforms)
- [Paddle vs. FastSpring: The Ultimate 2026 Comparison](https://unibee.dev/blog/paddle-vs-fastspring-the-ultimate-comparison/)
- [Stripe Managed Payments Fees Explained](https://dodopayments.com/blogs/stripe-managed-payments-fees-explained)
- [Lemon Squeezy Acquired by Stripe: 2026](https://fungies.io/lemon-squeezy-stripe-acquisition-saas-founders-2026/)
- [Paddle Billing Integration — Implementation Guide](https://dev.to/arshan_nawaz/paddle-billing-integration-implementation-guide-25op)
- [Guide to Paddle Webhooks](https://hookdeck.com/webhooks/platforms/guide-to-paddle-webhooks-features-and-best-practices)
