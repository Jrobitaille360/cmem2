# Configuration et Utilisation des Webhooks de Paiement

## 📋 Vue d'ensemble

Le système de webhooks permet d'automatiser la génération de licences après paiement via Stripe ou PayPal.

## 🔧 Configuration

### 1. Variables d'Environnement

Ajoutez ces variables dans votre fichier `.env.auth_groups` :

```bash
# Stripe
STRIPE_WEBHOOK_SECRET=whsec_votre_secret_stripe_ici

# PayPal
PAYPAL_WEBHOOK_ID=votre_webhook_id_paypal_ici
```

### 2. Modifications Base de Données

Exécutez ces commandes SQL pour ajouter les colonnes nécessaires :

```sql
-- Ajouter les colonnes de paiement à la table users
ALTER TABLE users 
ADD COLUMN payment_status ENUM('pending', 'paid', 'expired') DEFAULT 'pending',
ADD COLUMN license_expires_at DATETIME NULL,
ADD COLUMN payment_plan ENUM('basic', 'standard', 'premium', 'lifetime') DEFAULT 'basic',
ADD COLUMN payment_date DATETIME NULL;
```

## 🌐 Endpoints Webhook

### Webhook Générique
```
POST /webhook/payment
```

### Webhook Stripe
```
POST /webhook/stripe
```

### Webhook PayPal
```
POST /webhook/paypal
```

---

## 🔐 Configuration Stripe

### 1. Créer un Webhook sur Stripe

1. Allez sur [Stripe Dashboard](https://dashboard.stripe.com/webhooks)
2. Cliquez sur **"Add endpoint"**
3. URL du endpoint : `https://votre-domaine.com/webhook/stripe`
4. Sélectionnez les événements :
   - `checkout.session.completed`
   - `payment_intent.succeeded`
   - `customer.subscription.deleted`
5. Copiez le **Signing secret** (commence par `whsec_...`)
6. Ajoutez-le dans `.env.auth_groups` :
   ```bash
   STRIPE_WEBHOOK_SECRET=whsec_...
   ```

### 2. Payload Stripe

Lors de la création d'une session Stripe, ajoutez les métadonnées :

```javascript
// Frontend: Créer une session Stripe
const session = await stripe.checkout.sessions.create({
  payment_method_types: ['card'],
  line_items: [{
    price_data: {
      currency: 'eur',
      product_data: {
        name: 'Licence CMEM Standard',
      },
      unit_amount: 9900, // 99.00€
    },
    quantity: 1,
  }],
  mode: 'payment',
  success_url: 'https://votre-app.com/success',
  cancel_url: 'https://votre-app.com/cancel',
  metadata: {
    user_id: '123',  // ID de l'utilisateur
    plan: 'standard' // Plan choisi
  }
});
```

### 3. Test Stripe (Mode Test)

```bash
# Utiliser Stripe CLI pour tester localement
stripe listen --forward-to localhost/cmem2_API/webhook/stripe

# Déclencher un événement test
stripe trigger checkout.session.completed
```

---

## 💰 Configuration PayPal

### 1. Créer un Webhook sur PayPal

1. Allez sur [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/webhooks)
2. Cliquez sur **"Create Webhook"**
3. URL du endpoint : `https://votre-domaine.com/webhook/paypal`
4. Sélectionnez les événements :
   - `PAYMENT.SALE.COMPLETED`
   - `CHECKOUT.ORDER.APPROVED`
   - `BILLING.SUBSCRIPTION.CANCELLED`
5. Copiez le **Webhook ID**
6. Ajoutez-le dans `.env.auth_groups` :
   ```bash
   PAYPAL_WEBHOOK_ID=votre_webhook_id
   ```

### 2. Payload PayPal

Lors de la création d'un ordre PayPal, utilisez `custom_id` :

```javascript
// Frontend: Créer un ordre PayPal
paypal.Buttons({
  createOrder: function(data, actions) {
    return actions.order.create({
      purchase_units: [{
        description: 'Licence CMEM Standard',
        amount: {
          currency_code: 'EUR',
          value: '99.00'
        },
        custom_id: '123' // ID de l'utilisateur
      }]
    });
  }
}).render('#paypal-button-container');
```

---

## 📨 Événements Supportés

### Webhook Générique (`/webhook/payment`)

Format du payload :

```json
{
  "event": "payment.success",
  "user_id": 123,
  "plan": "standard"
}
```

**Événements supportés :**
- `payment.success` - Paiement réussi → Génère la licence
- `payment.completed` - Paiement complété → Génère la licence
- `payment.failed` - Paiement échoué → Log l'erreur
- `subscription.renewed` - Abonnement renouvelé → Renouvelle la licence
- `subscription.cancelled` - Abonnement annulé → Révoque la licence

### Webhook Stripe (`/webhook/stripe`)

**Événements supportés :**
- `checkout.session.completed` - Session complétée → Génère la licence
- `payment_intent.succeeded` - Paiement réussi → Génère la licence
- `customer.subscription.deleted` - Abonnement supprimé → Révoque la licence

### Webhook PayPal (`/webhook/paypal`)

**Événements supportés :**
- `PAYMENT.SALE.COMPLETED` - Vente complétée → Génère la licence
- `CHECKOUT.ORDER.APPROVED` - Ordre approuvé → Génère la licence
- `BILLING.SUBSCRIPTION.CANCELLED` - Abonnement annulé → Révoque la licence

---

## 🧪 Tests

### Test Manuel avec cURL

```bash
# Test webhook générique
curl -X POST https://votre-domaine.com/webhook/payment \
  -H "Content-Type: application/json" \
  -d '{
    "event": "payment.success",
    "user_id": 1,
    "plan": "standard"
  }'

# Test webhook Stripe (nécessite une vraie signature)
curl -X POST https://votre-domaine.com/webhook/stripe \
  -H "Content-Type: application/json" \
  -H "Stripe-Signature: t=1234567890,v1=signature_ici" \
  -d '{
    "type": "checkout.session.completed",
    "data": {
      "object": {
        "metadata": {
          "user_id": "1",
          "plan": "standard"
        }
      }
    }
  }'
```

### Vérifier les Logs

Les webhooks génèrent des logs dans `logs/auth_groups.log` :

```bash
# Voir les logs en temps réel
tail -f logs/auth_groups.log | grep -i webhook
```

Recherchez :
- ✅ `Webhook payment reçu`
- ✅ `Licence générée avec succès`
- ✅ `Email de licence envoyé`
- ❌ `Webhook payment: signature invalide`

---

## 🔒 Sécurité

### 1. Vérification des Signatures

**Stripe** : Les webhooks vérifient automatiquement la signature HMAC-SHA256.

**PayPal** : La vérification est implémentée (peut nécessiter le SDK PayPal pour une vérification complète).

### 2. Protection contre les Rejeux

Les webhooks Stripe incluent un timestamp dans la signature. Les requêtes de plus de 5 minutes sont rejetées.

### 3. HTTPS Obligatoire

En production, les webhooks doivent TOUJOURS utiliser HTTPS. Stripe et PayPal refuseront les URLs HTTP.

---

## 📊 Flux Complet de Paiement

```
1. Utilisateur choisit un plan sur votre site
   ↓
2. Frontend crée une session Stripe/PayPal avec metadata.user_id
   ↓
3. Utilisateur paie
   ↓
4. Stripe/PayPal envoie un webhook à votre API
   ↓
5. API vérifie la signature du webhook
   ↓
6. LicenseController génère une API Key
   ↓
7. Email envoyé à l'utilisateur avec la clé
   ↓
8. Utilisateur entre la clé dans l'app mobile
   ↓
9. App validée et fonctionnelle!
```

---

## 🐛 Dépannage

### Le webhook ne se déclenche pas

1. Vérifiez l'URL du webhook dans Stripe/PayPal Dashboard
2. Vérifiez que l'URL est accessible publiquement (pas localhost)
3. Vérifiez les logs de Stripe/PayPal pour voir si le webhook a été envoyé

### Erreur "Invalid signature"

1. Vérifiez que `STRIPE_WEBHOOK_SECRET` est correct
2. Assurez-vous d'utiliser le secret du bon environnement (test vs production)
3. Vérifiez que le payload n'est pas modifié avant vérification

### L'email n'est pas envoyé

1. Vérifiez la configuration `EmailService`
2. Vérifiez les logs : `grep -i "email" logs/auth_groups.log`
3. Testez l'envoi d'email indépendamment

### La licence n'est pas générée

1. Vérifiez que l'utilisateur existe : `user_id` dans metadata
2. Vérifiez les logs : `grep -i "licence" logs/auth_groups.log`
3. Vérifiez que la table `users` a les nouvelles colonnes

---

## 📚 Ressources

- [Documentation Stripe Webhooks](https://stripe.com/docs/webhooks)
- [Documentation PayPal Webhooks](https://developer.paypal.com/docs/api-basics/notifications/webhooks/)
- [Stripe CLI](https://stripe.com/docs/stripe-cli)
- [Tester les webhooks Stripe](https://stripe.com/docs/webhooks/test)

---

## 📞 Support

Pour toute question sur les webhooks :
- Email : support@cmem.com
- Logs : `logs/auth_groups.log`
- Documentation API : `docs/API_OVERVIEW.md`

---

**Version** : 1.0.0  
**Date** : 8 octobre 2025  
**Auteur** : CMEM Team
