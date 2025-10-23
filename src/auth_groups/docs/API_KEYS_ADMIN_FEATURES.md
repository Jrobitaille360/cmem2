# 👑 Fonctionnalités Administrateur API Keys

## 📋 Vue d'ensemble

Cette implémentation ajoute des fonctionnalités administrateur au système API Keys, permettant aux utilisateurs avec le rôle `ADMINISTRATEUR` de gérer les clés API d'autres utilisateurs.

## 🚀 Fonctionnalités implémentées

### ✅ Créer des clés API pour d'autres utilisateurs

**Endpoint:** `POST /api-keys`

**Exemple:**
```bash
curl -X POST http://localhost/cmem2_API/api-keys \
  -H "Authorization: Bearer $ADMIN_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 42,
    "name": "Clé pour utilisateur 42",
    "scopes": ["read", "write"],
    "environment": "production"
  }'
```

### ✅ Lister les clés d'un utilisateur spécifique

**Endpoint:** `GET /api-keys?user_id={id}`

**Exemple:**
```bash
curl -X GET "http://localhost/cmem2_API/api-keys?user_id=42" \
  -H "Authorization: Bearer $ADMIN_JWT_TOKEN"
```

### ✅ Consulter les détails de n'importe quelle clé

**Endpoint:** `GET /api-keys/{id}`

**Exemple:**
```bash
curl -X GET http://localhost/cmem2_API/api-keys/123 \
  -H "Authorization: Bearer $ADMIN_JWT_TOKEN"
```

### ✅ Révoquer les clés d'autres utilisateurs

**Endpoint:** `DELETE /api-keys/{id}`

**Exemple:**
```bash
curl -X DELETE http://localhost/cmem2_API/api-keys/123 \
  -H "Authorization: Bearer $ADMIN_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"reason": "Révoquée par admin pour sécurité"}'
```

### ✅ Régénérer les clés d'autres utilisateurs

**Endpoint:** `POST /api-keys/{id}/regenerate`

**Exemple:**
```bash
curl -X POST http://localhost/cmem2_API/api-keys/123/regenerate \
  -H "Authorization: Bearer $ADMIN_JWT_TOKEN"
```

## 🔐 Sécurité

### Vérifications implémentées

1. **Authentification JWT obligatoire** - Seuls les utilisateurs connectés peuvent utiliser ces fonctionnalités
2. **Vérification du rôle ADMINISTRATEUR** - Seuls les admins peuvent agir sur les clés d'autres utilisateurs  
3. **Validation de l'utilisateur cible** - Vérification que l'utilisateur cible existe avant création de clé
4. **Logging des actions** - Toutes les actions administrateur sont tracées
5. **Fallback sécurisé** - Les utilisateurs non-admin ne peuvent agir que sur leurs propres clés

### Messages d'erreur

- `403 - Privilèges administrateur requis pour créer une clé API pour un autre utilisateur`
- `403 - Privilèges administrateur requis pour lister les clés API d'un autre utilisateur`  
- `403 - Accès refusé - Privilèges administrateur requis`
- `404 - Utilisateur cible non trouvé`

## 📁 Fichiers modifiés

### Contrôleur principal
- `src/auth_groups/Controllers/ApiKeyController.php` - Toutes les méthodes mises à jour

### Documentation  
- `docs/ENDPOINTS_API_KEYS.md` - Section administrateur ajoutée avec exemples

### Tests
- `tests/api_keys/test_api_keys_admin.php` - Suite de tests complète pour les fonctionnalités admin

## 🧪 Tests

Exécuter la suite de tests administrateur :

```bash
php tests/api_keys/test_api_keys_admin.php
```

**Tests inclus :**
- ✅ Admin crée une clé pour un autre utilisateur
- ✅ Admin liste les clés d'un autre utilisateur  
- ✅ Admin consulte les détails d'une clé d'un autre utilisateur
- ✅ Utilisateur régulier ne peut pas créer de clé pour un autre (doit échouer)
- ✅ Utilisateur régulier ne peut pas lister les clés d'un autre (doit échouer)
- ✅ Admin révoque une clé d'un autre utilisateur

## 📖 Utilisation

### 1. Promouvoir un utilisateur administrateur

```sql
UPDATE users SET role = 'ADMINISTRATEUR' WHERE id = {user_id};
```

### 2. Créer une clé pour un utilisateur

```javascript
const response = await fetch('/api-keys', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${adminToken}`,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    user_id: 42,
    name: 'Integration Key',
    scopes: ['read', 'write'],
    environment: 'production'
  })
});
```

### 3. Lister les clés d'un utilisateur

```javascript
const response = await fetch(`/api-keys?user_id=42`, {
  headers: {
    'Authorization': `Bearer ${adminToken}`
  }
});
```

## 🔄 Rétrocompatibilité

- ✅ **Aucun changement breaking** - Toutes les fonctionnalités existantes continuent de fonctionner
- ✅ **Paramètres optionnels** - Le paramètre `user_id` est optionnel, défaut = utilisateur authentifié
- ✅ **Codes d'erreur cohérents** - Utilisation des codes HTTP standard (403 pour permissions, 404 pour non trouvé)

## 📈 Impact

Cette implémentation permet aux administrateurs de :

1. **Gérer centralement** toutes les clés API de l'organisation
2. **Dépanner rapidement** les problèmes d'accès des utilisateurs
3. **Auditer les permissions** et l'utilisation des clés
4. **Révoquer d'urgence** des clés compromises
5. **Provisionner automatiquement** des clés pour de nouveaux services

## 🚀 Prochaines étapes possibles

1. **Interface admin web** - Dashboard pour gérer graphiquement les clés
2. **Logs détaillés** - Traçabilité complète des actions administrateur
3. **Notifications** - Alertes lors de création/révocation de clés
4. **Bulk operations** - Création/révocation en masse
5. **Templates** - Modèles de clés prédéfinies pour différents types d'usage