# Modèle pub web-windows

## Modèle économique : Gratuit avec publicité & Premium (Web/Windows)

### Présentation des offres

Deux modes d’utilisation sont proposés :

- **Version gratuite** : accès complet aux fonctionnalités de base, avec affichage de publicités.

- **Version Premium** : suppression totale des publicités, accès à des options avancées (si disponibles), via abonnement mensuel ou annuel.

### Plateformes concernées

- **Application Web** (navigateur)

- **Application Windows** (logiciel dédié)

### Gestion des publicités (offre gratuite)

#### Web

- Intégration de publicités via un service tiers (ex : Google AdSense) ou module interne.

- Emplacements : bannières en haut/bas de page, interstitiels lors de certaines actions.

- Les publicités sont affichées uniquement pour les utilisateurs non-Premium.

#### Windows

- Module publicitaire intégré à l’interface (bannière, pop-up, etc.) si besoin.

- Les publicités sont désactivées automatiquement pour les utilisateurs Premium.

### Gestion des abonnements Premium

- Souscription possible depuis l’application (Web ou Windows).

- Paiement mensuel ou annuel via un prestataire sécurisé (ex : Stripe, PayPal, Microsoft Store…).

- Le statut Premium est vérifié côté serveur à chaque connexion.

- L’abonnement peut être résilié à tout moment ; l’accès Premium reste actif jusqu’à la fin de la période payée.

### Différences de fonctionnalités

| Offre | Publicités | Fonctionnalités avancées | Support prioritaire | Accès multiplateforme |
| --- | --- | --- | --- | --- |
| Gratuite | Oui | Non | Non | Oui |
| Premium (mensuel/an) | Non | Oui* | Oui* | Oui |

*Selon les options disponibles dans chaque application.

### Sécurité & gestion des droits

- Le statut Premium est stocké côté serveur et vérifié à chaque authentification.

- En cas d’expiration de l’abonnement, l’utilisateur repasse automatiquement en mode gratuit avec publicité.

---

## Guide d’installation — Application Web

### Prérequis

- Hébergement web compatible (PHP/MySQL ou autre selon backend)

- Nom de domaine configuré

- Accès à la console d’administration AdSense (pour la version gratuite)

### Étapes d’installation

1. **Déployer les fichiers de l’application sur le serveur web**

2. **Configurer la base de données** (importer les scripts SQL fournis)

3. **Configurer le domaine dans AdSense**

   - Créer un compte sur [adsense.google.com](https://adsense.google.com)
   - Lier le domaine, attendre l’approbation

4. **Ajouter le script AdSense dans le fichier HTML principal**

   - Exemple :

    ```html
    <head>
      <!-- ... contenu existant ... -->
      <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-XXXXXXXXXXXXXXXX" crossorigin="anonymous"></script>
    </head>
    ```

    Remplacer `ca-pub-XXXXXXXXXXXXXXXX` par votre Publisher ID.

5. **Intégrer les widgets publicitaires dans l’interface**

   - Utiliser un composant dédié (ex : `AdBannerWeb` sous Flutter Web)

6. **Configurer la gestion des abonnements Premium**

   - Intégrer un module de paiement (Stripe, PayPal, etc.)
   - Prévoir la gestion du statut utilisateur côté serveur

7. **Tester l’affichage des publicités et la bascule Premium**

### Notes

- Les utilisateurs Premium ne doivent jamais voir de publicité.

- Prévoir une page d’aide expliquant les différences entre les offres.

---

## Guide d’installation — Application Windows

### Prérequis

- Windows 10 ou supérieur

- Droits administrateur pour l’installation

- Accès internet (pour la gestion des pubs et abonnements)

### Étapes d’installation

1. **Télécharger l’installeur depuis le site officiel**

2. **Lancer l’installation et suivre les instructions**

3. **Au premier lancement, configurer le compte utilisateur**

4. **(Optionnel) Intégrer un module publicitaire**

   - Si besoin, utiliser une WebView pour afficher une bannière AdSense

   - Exemple d’intégration :

    ```dart
    // Charger une mini-page HTML contenant un bloc AdSense
    final controller = WebviewController();
    await controller.initialize();
    await controller.loadStringContent('''
      <html><body style="margin:0">
        <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-XXXXXXXXXXXXXXXX" crossorigin="anonymous"></script>
        <ins class="adsbygoogle" style="display:block" data-ad-client="ca-pub-XXXXXXXXXXXXXXXX" data-ad-slot="XXXXXXXXXX" data-ad-format="auto"></ins>
        <script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
      </body></html>
    ''');
    ```

    Remplacer les IDs par vos valeurs AdSense.

5. **Configurer la gestion des abonnements Premium**

   - Intégrer un module de paiement adapté (Microsoft Store, Stripe, etc.)
   - Vérifier le statut Premium à chaque lancement

6. **Tester la désactivation des publicités pour les Premium**

### Notes

- Si l’intégration publicitaire n’est pas souhaitée sur Windows, afficher un message d’incitation à passer Premium ou à soutenir le projet.

- Prévoir un système de mise à jour automatique ou une procédure de mise à jour simple.

---

## Flux utilisateur (Web & Windows)

1. L’utilisateur s’inscrit ou se connecte.

2. Par défaut, il accède à la version gratuite avec publicités.

3. Il peut souscrire à l’abonnement Premium à tout moment.

4. Après paiement, les publicités sont supprimées et les options Premium activées.

5. À l’expiration ou l’annulation de l’abonnement, retour automatique à la version gratuite.

---

## Points à surveiller

- Adapter la documentation à chaque application lors de leur création.

- Mettre à jour ce document en cas d’évolution des offres ou des modalités techniques.

---

## Plan d'implantation dans l'API CMEM2

### Existant dans l'API (base de travail)

| Composant | Fichier | État |
| --- | --- | --- |
| Modèle `Plan` (free/bronze/argent/platine) | `src/auth_groups/Models/Plan.php` | Existant |
| `PlanController` (list, get, assign) | `src/auth_groups/Controllers/PlanController.php` | Existant |
| `PlanRouteHandler` | `src/auth_groups/Routing/RouteHandlers/PlanRouteHandler.php` | Existant |
| Validation Google Play (puzzle) | `src/puzzle/Services/GooglePlayService.php` | Existant |
| `PuzzleDevice.updateSubscription()` | `src/puzzle/Models/PuzzleDevice.php` | Existant |
| Stripe (webhook pomo) | `src/pomo/Routing/PomoRouteHandler.php` | Prévu, non implémenté |

### Ce qui manque

| Composant | Priorité |
| --- | --- |
| Statut `show_ads` retourné par app dans le profil utilisateur | Haute |
| Service générique `SubscriptionService` (Web, Windows, Stripe) | Haute |
| Webhook Stripe (création / renouvellement / annulation) | Haute |
| Endpoint `POST /subscription/stripe/checkout` | Haute |
| Endpoint `POST /subscription/stripe/webhook` | Haute |
| Endpoint `GET /subscription/status?app_id=xxx` (statut Premium + show_ads par app) | Haute |
| Validation Apple App Store (Web/iOS) | Moyenne |
| Validation Microsoft Store (Windows) | Moyenne |
| Migration SQL : table `subscriptions` avec colonne `app_id` | Haute |

### Phase 1 — Base de données

Créer une migration SQL avec :

Un utilisateur peut être Premium pour une application et gratuit pour une autre.
Le statut Premium est donc stocké **par application** dans la table `subscriptions`,
non sur la table `users`.

```sql
-- Table des abonnements (pas de colonne is_premium sur users)
CREATE TABLE subscriptions (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED NOT NULL,
  app_id           VARCHAR(50) NOT NULL,           -- ex: 'puzzle', 'pomo', 'quiz'
  provider         ENUM('stripe','google_play','apple','microsoft') NOT NULL,
  product_id       VARCHAR(100) NOT NULL,
  purchase_token   VARCHAR(500) NULL,
  stripe_sub_id    VARCHAR(100) NULL,
  status           ENUM('active','cancelled','expired','past_due') NOT NULL DEFAULT 'active',
  plan             ENUM('monthly','yearly') NOT NULL,
  started_at       DATETIME NOT NULL,
  expires_at       DATETIME NOT NULL,
  cancelled_at     DATETIME NULL,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_app (user_id, app_id, provider),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

> La table `users` n'est **pas modifiée**. Le statut Premium est toujours calculé à la volée
> via `SELECT` sur `subscriptions` WHERE `user_id = ? AND app_id = ? AND status = 'active' AND expires_at > NOW()`.

### Phase 2 — Service générique

Créer `src/auth_groups/Services/SubscriptionService.php` :

- `activatePremium(int $userId, string $appId, array $data): void` — crée ou met à jour l'abonnement actif pour cette app
- `deactivatePremium(int $userId, string $appId): void` — passe l'utilisateur en mode gratuit pour cette app
- `checkAndExpireSubscriptions(): void` — tâche CRON pour expirer les abonnements dépassés (toutes apps)
- `getStatus(int $userId, string $appId): array` — retourne `{is_premium, show_ads, expires_at, provider}` pour une app donnée
- `getAllStatuses(int $userId): array` — retourne le statut Premium pour chaque app abonnée

### Phase 3 — Endpoints

#### Stripe

```text
POST /subscription/stripe/checkout   → crée une session Stripe Checkout, retourne l'URL de paiement
POST /subscription/stripe/webhook    → écoute les événements Stripe (checkout.completed, invoice.paid, customer.subscription.deleted)
```

#### Statut

```text
GET /subscription/status?app_id=xxx  → retourne {is_premium, show_ads, expires_at, provider, plan} pour l'app donnée
GET /subscription/status             → retourne le statut Premium pour toutes les apps de l'utilisateur
DELETE /subscription/cancel          → body: {app_id} — demande d'annulation pour une app (fin de période)
```

#### Google Play / Apple / Microsoft (via SDK client)

```text
POST /subscription/verify            → body: {app_id, provider, purchase_token, product_id}
                                       → valide côté serveur et active le premium pour l'app concernée
```

### Phase 4 — Intégration dans les réponses JWT

Ajouter un objet `subscriptions` dans la réponse de `POST /auth/login` et `GET /users/me`,
contenant le statut par application :

```json
{
  "user": {
    "id": 42,
    "name": "Alice"
  },
  "subscriptions": {
    "puzzle": { "is_premium": true,  "show_ads": false, "expires_at": "2027-04-09T00:00:00Z" },
    "pomo":   { "is_premium": false, "show_ads": true,  "expires_at": null },
    "quiz":   { "is_premium": false, "show_ads": true,  "expires_at": null }
  }
}
```

Chaque application lit son propre `show_ads` dans l'objet `subscriptions` pour décider
d'afficher ou non les publicités. Aucune donnée Premium d'une autre app n'est exposée.

### Phase 5 — CRON d'expiration

Ajouter dans `src/cron/` une tâche quotidienne :

- Parcourt les abonnements dont `expires_at < NOW()` et `status = 'active'`
- Passe leur `status` à `'expired'`
- Appelle `SubscriptionService::deactivatePremium($userId, $appId)` pour chaque ligne concernée
- Envoie un email de notification d'expiration (via `EmailService`), en précisant l'application concernée

### Ordre de réalisation recommandé

```text
Étape 1 → Migration SQL (table subscriptions avec app_id — pas de modif sur users)
Étape 2 → SubscriptionService (activate, deactivate, getStatus, getAllStatuses)
Étape 3 → GET /subscription/status?app_id=xxx et GET /subscription/status
Étape 4 → Stripe : POST /subscription/stripe/checkout + webhook
Étape 5 → Intégrer subscriptions{} dans /auth/login et /users/me
Étape 6 → CRON d'expiration (par app)
Étape 7 → POST /subscription/verify (Google Play, Apple, Microsoft)
```

---
