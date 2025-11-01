# Système de Plans et API Keys avec Confirmation Email

## Vue d'ensemble

Ce système implémente un workflow complet d'inscription d'utilisateurs avec génération automatique d'API keys, système de plans de paiement, et confirmation par email.

## Fonctionnalités implémentées

### 1. Plans de paiement

- **Free** : Gratuit, limitations de base
- **Bronze** : Plan d'entrée payant
- **Argent** : Plan recommandé avec bonnes fonctionnalités
- **Platine** : Plan premium avec toutes les fonctionnalités

### 2. Workflow d'inscription

```text
Inscription → API Key Free → Email confirmation → Extension temporaire → Invitation plans premium
```

### 3. Endpoints disponibles

#### GET /plans

Lister tous les plans disponibles (public)

```text
GET http://localhost/cmem2_API/src/auth_groups/index.php?endpoint=plans
```

#### GET /users/choose-plan?token=XXX

Afficher les plans disponibles via invitation

```text
GET http://localhost/cmem2_API/src/auth_groups/index.php?endpoint=users/choose-plan&token=invitation_token
```

#### POST /users/choose-plan

Sélectionner un plan premium

```text
POST http://localhost/cmem2_API/src/auth_groups/index.php?endpoint=users/choose-plan
Content-Type: application/json

{
    "token": "invitation_token",
    "plan": "bronze|argent|platine"
}
```

## Architecture technique

### Fichiers créés/modifiés

1. **Models/Plan.php** - Modèle pour la gestion des plans
2. **Controllers/PlanController.php** - Contrôleur pour les opérations de plans
3. **database/add_plan_system.sql** - Migration base de données
4. **Routing/RouteHandlers/PlanRouteHandler.php** - Gestionnaire de routes plans
5. **UserManagerController.php** - Modifié pour génération API keys automatique
6. **EmailService.php** - Ajout templates email avec plans
7. **UserRouteHandler.php** - Ajout routes de sélection de plans

### Base de données

#### Table `plans`

```sql
- id (int, auto-increment)
- name (varchar) - free, bronze, argent, platine
- display_name (varchar) - Nom d'affichage
- description (text) - Description du plan
- price (decimal) - Prix en CAD
- currency (varchar) - Devise (CAD)
- duration_days (int) - Durée en jours
- api_rate_limit (int) - Limite de requêtes API
- features (json) - Fonctionnalités du plan
- is_active (boolean)
- created_at, updated_at (timestamp)
```

#### Table `user_plan_history`

```sql
- id (int, auto-increment)
- user_id (int, FK vers users)
- plan_id (int, FK vers plans)
- started_at (timestamp)
- ended_at (timestamp, nullable)
- is_active (boolean)
```

#### Table `plan_invitations`

```sql
- id (int, auto-increment)
- user_id (int, FK vers users)
- invitation_token (varchar, unique)
- status (enum: pending, clicked, selected, expired)
- selected_plan (varchar, nullable)
- expires_at (timestamp)
- created_at, clicked_at, selected_at (timestamp)
```

## Workflow détaillé

### 1. Inscription d'utilisateur

```php
POST /users/register
{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "secure123"
}
```

**Actions automatiques :**

- Créer utilisateur dans table `users`
- Assigner plan "free" par défaut
- Générer API key limitée avec config du plan free
- Créer invitation dans `plan_invitations` avec token unique
- Envoyer email de confirmation avec API key et lien d'invitation

### 2. Confirmation d'email

```php
GET /users/confirm-email?token=confirmation_token
```

**Actions automatiques :**

- Marquer email comme confirmé
- Étendre temporairement les limites du plan free
- Envoyer email de rappel avec invitation à upgrader vers plan premium

### 3. Visualisation de l'invitation plan

```php
GET /users/choose-plan?token=invitation_token
```

**Réponse :**

```json
{
    "success": true,
    "message": "Invitation valide",
    "data": {
        "invitation": {
            "token": "abc123...",
            "user_name": "John Doe",
            "user_email": "john@example.com",
            "expires_at": "2024-01-15T12:00:00Z",
            "status": "clicked"
        },
        "available_plans": [
            {
                "id": 2,
                "name": "bronze",
                "display_name": "Plan Bronze",
                "description": "Plan d'entrée avec fonctionnalités essentielles",
                "price": 9.99,
                "currency": "CAD",
                "duration_days": 30,
                "api_rate_limit": 1000,
                "features": [...],
                "is_recommended": false
            },
            // ... autres plans
        ]
    }
}
```

### 4. Sélection d'un plan

```php
POST /users/choose-plan
{
    "token": "invitation_token",
    "plan": "argent"
}
```

**Actions automatiques :**

- Valider le token d'invitation
- Marquer l'invitation comme "selected"
- Enregistrer le plan choisi
- Retourner les informations du plan et prochaines étapes

**Réponse :**

```json
{
    "success": true,
    "message": "Plan sélectionné avec succès",
    "data": {
        "selected_plan": {
            "name": "argent",
            "display_name": "Plan Argent",
            "price": 19.99,
            "currency": "CAD",
            "features": [...]
        },
        "next_steps": {
            "message": "Merci d'avoir sélectionné le plan Argent !",
            "actions": {
                "payment": "Un email avec les instructions de paiement vous sera envoyé prochainement",
                "activation": "Votre nouveau plan sera activé dès le paiement confirmé",
                "current_plan": "Votre plan gratuit reste actif en attendant"
            }
        }
    }
}
```

## Configuration des plans

### Plan Free (Gratuit)

- API Rate Limit: 60 req/min
- Fonctionnalités: read, basic_write
- Durée: Illimitée (avec extensions temporaires)

### Plan Bronze (9.99 CAD/mois)

- API Rate Limit: 1000 req/min
- Fonctionnalités: read, write, file_upload
- Support: Email standard

### Plan Argent (19.99 CAD/mois) - **Recommandé**

- API Rate Limit: 5000 req/min
- Fonctionnalités: read, write, file_upload, advanced_features
- Support: Email prioritaire

### Plan Platine (39.99 CAD/mois)

- API Rate Limit: 20000 req/min
- Fonctionnalités: Toutes + custom_integrations
- Support: Support téléphonique

## Tests

Utilisez le fichier de test pour vérifier le fonctionnement :

```bash
php tests/plan_endpoints/test_plan_endpoints.php
```

## Prochaines étapes possibles

1. **Système de paiement** - Intégration Stripe/PayPal
2. **Dashboard utilisateur** - Interface pour gérer le plan actuel
3. **Factures** - Génération automatique de factures
4. **Analytiques** - Suivi d'utilisation des API keys par plan
5. **Notifications** - Alertes d'expiration de plan

## Sécurité

- Tokens d'invitation avec expiration automatique
- Validation stricte des paramètres
- Logs complets de toutes les actions
- Rate limiting par plan
- Authentification requise pour la plupart des endpoints
