# Guide de Référence Rapide - API Keys v1.3.0

> **Commandes essentielles pour la gestion sécurisée des API Keys**

## 🚨 Nouvelle Architecture v1.3.0

- **Tous les logins nécessitent maintenant une API key**
- **Nouveaux endpoints secret-admin pour la gestion centralisée**  
- **Double authentification : JWT Admin + ADMIN_SECRET_KEY**

---

## 🚀 Configuration Rapide

### Variables d'environnement

```bash
export ADMIN_JWT="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
export ADMIN_SECRET="your_secret_admin_key"
export API_BASE="http://localhost/cmem2_API"
```

### Headers Admin (Secret-Admin)

`bash
-H "Authorization: Bearer $ADMIN_JWT"
-H "X-Admin-Secret: $ADMIN_SECRET"
-H "Content-Type: application/json"
`

---

## 🔧 Gestion Administrative (Secret-Admin)

### Créer une API Key

#### Production

curl -X POST $API_BASE/secret-admin/api-keys \
  -H "Authorization: Bearer $ADMIN_JWT" \
  -H "X-Admin-Secret: $ADMIN_SECRET" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 1,
    "name": "Production Integration",
    "scopes": ["read", "write"],
    "environment": "production",
    "expires_in_days": 90,
    "rate_limit_per_minute": 60
  }'

#### Test/Développement

curl -X POST $API_BASE/secret-admin/api-keys \
  -H "Authorization: Bearer $ADMIN_JWT" \
  -H "X-Admin-Secret: $ADMIN_SECRET" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 1,
    "name": "Test Integration",
    "scopes": ["*"],
    "environment": "test",
    "expires_in_days": 7
  }'

### Lister les API Keys

`bash

#### Toutes les clés

curl -X GET $API_BASE/secret-admin/api-keys \
  -H "Authorization: Bearer $ADMIN_JWT" \
  -H "X-Admin-Secret: $ADMIN_SECRET"

#### Avec filtres

curl -X GET "$API_BASE/secret-admin/api-keys?environment=production&status=active" \
  -H "Authorization: Bearer $ADMIN_JWT" \
  -H "X-Admin-Secret: $ADMIN_SECRET"
`

### Révoquer une API Key

`bash
curl -X DELETE $API_BASE/secret-admin/api-keys/45 \
  -H "Authorization: Bearer $ADMIN_JWT" \
  -H "X-Admin-Secret: $ADMIN_SECRET" \
  -H "Content-Type: application/json" \
  -d '{
    "reason": "Clé compromise - révocation de sécurité"
  }'
`

---

## 📱 Utilisation Client

### Login avec API Key (OBLIGATOIRE)

`bash

#### Tous les logins nécessitent une API key

curl -X POST $API_BASE/users/login \
  -H "Content-Type: application/json" \
  -H "X-API-Key: ag_live_votre_cle_ici" \
  -d '{
    "email": "<user@example.com>",
    "password": "password123"
  }'
`

### Utiliser une API Key

`bash

#### Méthode 1 : Header X-API-Key (recommandé)

curl -X GET $API_BASE/groups \
  -H "X-API-Key: ag_live_votre_cle_ici"

#### Méthode 2 : Authorization Bearer

curl -X GET $API_BASE/groups \
  -H "Authorization: Bearer ag_live_votre_cle_ici"
`

---

Guide mis à jour pour cmem2 API v1.3.0 - Octobre 2025
