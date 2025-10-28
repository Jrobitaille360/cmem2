# 🔒 Mise à Jour de Sécurité v1.3.0 - API Keys Obligatoires

## ⚠️ AVERTISSEMENT IMPORTANT

Cette mise à jour introduit des **changements incompatibles** majeurs qui nécessitent une migration obligatoire.

## 🚨 Changements Critiques

### 1. API Keys Obligatoires pour TOUS les appels

**AVANT v1.3.0** ✗

```bash
# Login direct sans API key
curl -X POST /users/login \
  -H "Content-Type: application/json" \
  -d '{"email": "user@example.com", "password": "pass"}'
```

**DEPUIS v1.3.0** ✅

```bash
# API key OBLIGATOIRE pour login
curl -X POST /users/login \
  -H "Content-Type: application/json" \
  -H "X-API-Key: ag_live_abc123..." \
  -d '{"email": "user@example.com", "password": "pass"}'
```

### 2. Gestion des API Keys Centralisée

**AVANT v1.3.0** ✗

```bash
# Anciens endpoints (maintenant HTTP 410 Gone)
POST /api-keys
GET /api-keys
DELETE /api-keys/{id}
```

**DEPUIS v1.3.0** ✅

```bash
# Nouveaux endpoints sécurisés (admin uniquement)
POST /secret-admin/api-keys
GET /secret-admin/api-keys
DELETE /secret-admin/api-keys/{id}
```

### 3. Double Authentification Requise

Pour gérer les API keys, vous devez maintenant avoir :

1. **Token JWT Administrateur** (`Authorization: Bearer <jwt_admin>`)
2. **Clé Secrète Admin** (`X-Admin-Secret: <ADMIN_SECRET_KEY>`)

## 🎯 Impact sur Votre Application

### Applications Existantes

**TOUTES** vos applications existantes vont **cesser de fonctionner** après la mise à jour car :

- ❌ Les logins sans API key retourneront HTTP 401
- ❌ Les anciens endpoints API keys retourneront HTTP 410
- ❌ Aucun appel API ne fonctionnera sans API key valide

### Applications Mobile/Frontend

```javascript
// AVANT - Broken après v1.3.0
fetch('/users/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password })
});

// APRÈS - Requis depuis v1.3.0
const API_KEY = 'ag_live_abc123...'; // Votre API key

fetch('/users/login', {
    method: 'POST',
    headers: { 
        'Content-Type': 'application/json',
        'X-API-Key': API_KEY  // OBLIGATOIRE
    },
    body: JSON.stringify({ email, password })
});
```

### Scripts Serveur/Backend

```php
// AVANT - Broken après v1.3.0
$response = $client->post('/users/login', [
    'json' => ['email' => $email, 'password' => $password]
]);

// APRÈS - Requis depuis v1.3.0
$response = $client->post('/users/login', [
    'headers' => [
        'X-API-Key' => 'ag_live_abc123...'  // OBLIGATOIRE
    ],
    'json' => ['email' => $email, 'password' => $password]
]);
```

## 🚀 Plan de Migration

### Phase 1 : Préparation (AVANT le déploiement)

1. **Sauvegarde complète**

   ```bash
   mysqldump -u root -p cmem2_db > backup_pre_v1.3.0.sql
   ```

2. **Configuration ADMIN_SECRET_KEY**

   ```php
   // Dans config/environment.php
   define('ADMIN_SECRET_KEY', 'votre-cle-secrete-admin-super-longue-et-aleatoire');
   ```

3. **Test sur environnement de développement**

### Phase 2 : Déploiement

1. **Mise à jour du code**

   ```bash
   git pull origin main
   composer install
   ```

2. **Création de la première API key**

   ```bash
   php bootstrap_create_first_api_key.php
   ```

3. **Sauvegarde de l'API key** (CRITIQUE!)

   ```text
   API Key générée: ag_live_abc123def456...
   ```

### Phase 3 : Migration des Applications

1. **Mise à jour de toutes vos applications** pour inclure l'API key
2. **Test complet** de tous les endpoints
3. **Formation des équipes** sur le nouveau système

## 🔑 Gestion des API Keys

### Créer une nouvelle API key (Admin)

```bash
curl -X POST /secret-admin/api-keys \
  -H "Authorization: Bearer <jwt_admin_token>" \
  -H "X-Admin-Secret: <ADMIN_SECRET_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Production Mobile App",
    "scopes": ["read", "write"],
    "expires_in_days": 365
  }'
```

### Lister les API keys (Admin)

```bash
curl -X GET /secret-admin/api-keys \
  -H "Authorization: Bearer <jwt_admin_token>" \
  -H "X-Admin-Secret: <ADMIN_SECRET_KEY>"
```

### Révoquer une API key (Admin)

```bash
curl -X DELETE /secret-admin/api-keys/{id} \
  -H "Authorization: Bearer <jwt_admin_token>" \
  -H "X-Admin-Secret: <ADMIN_SECRET_KEY>"
```

## 📊 Codes d'Erreur Nouveaux

| Code | Message | Cause | Solution |
|------|---------|-------|---------|
| 401 | "API key required" | Aucune API key fournie | Ajouter header `X-API-Key` |
| 401 | "Invalid API key" | API key invalide/expirée | Utiliser une API key valide |
| 410 | "Gone" | Ancien endpoint API keys | Utiliser `/secret-admin/api-keys/*` |
| 403 | "Admin secret required" | Clé admin manquante | Ajouter header `X-Admin-Secret` |

## 🎯 Points de Contrôle

### ✅ Checklist de Migration

- [ ] Backup de la base de données effectué
- [ ] ADMIN_SECRET_KEY configuré
- [ ] Première API key créée avec bootstrap
- [ ] API key sauvegardée en sécurité
- [ ] Toutes les applications mises à jour
- [ ] Tests complets effectués
- [ ] Équipes formées sur le nouveau système

### ⚠️ Rollback d'Urgence

Si la migration échoue, vous pouvez restaurer l'ancienne version :

```bash
# Restaurer la base de données
mysql -u root -p cmem2_db < backup_pre_v1.3.0.sql

# Revenir à l'ancienne version du code
git checkout v1.2.0
```

## 📞 Support

En cas de problème pendant la migration :

1. **Documentation** : [MIGRATION_v1.3.0.md](MIGRATION_v1.3.0.md)
2. **API Spec** : [API_ENDPOINTS_v1_3_0.json](API_ENDPOINTS_v1_3_0.json)
3. **Logs** : Vérifiez `src/logs/app.log` et `src/logs/error.log`

---

**🔒 Cette mise à jour renforce considérablement la sécurité de votre API, mais nécessite une migration soigneuse de toutes vos applications.**
