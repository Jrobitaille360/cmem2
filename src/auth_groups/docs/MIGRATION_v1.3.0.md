# Migration vers API Keys (v1.3.0)

## 📋 Guide de migration

Ce guide vous aide à déployer le système API Keys sur une instance existante d'AuthGroups API.

---

## ⚠️ Avant de commencer

### Pré-requis

- ✅ AuthGroups API v1.2.0+ installée et fonctionnelle
- ✅ Accès MySQL avec privilèges CREATE TABLE
- ✅ PHP 8.0+
- ✅ Composer installé
- ✅ Backup de la base de données recommandé

### Backup de sécurité

```bash
# Backup complet de la base de données
mysqldump -u root -p cmem2_db > backup_pre_v1.3.0_$(date +%Y%m%d).sql

# Vérifier le backup
ls -lh backup_pre_v1.3.0_*.sql
```

---

## 🚀 Étapes de migration

### Étape 1 : Mettre à jour les fichiers

Tous les fichiers PHP sont déjà en place si vous avez cloné/pull le repo. Vérifiez leur présence :

```bash
# Vérifier les nouveaux fichiers
ls -l src/auth_groups/Models/ApiKey.php
ls -l src/auth_groups/Middleware/ApiKeyAuthMiddleware.php
ls -l src/auth_groups/Controllers/ApiKeyController.php
ls -l src/auth_groups/Routing/RouteHandlers/ApiKeyRouteHandler.php

# Router.php doit avoir été modifié
grep -n "ApiKeyRouteHandler" src/auth_groups/Routing/Router.php
```

**Résultat attendu :**

```text
Tous les fichiers doivent exister
Router.php doit contenir:
  - use AuthGroups\Routing\RouteHandlers\ApiKeyRouteHandler;
  - 'api-keys' => new ApiKeyRouteHandler()
```

---

### Étape 2 : Créer la table `api_keys`

```bash
# Se connecter à MySQL
mysql -u root -p

# Ou directement exécuter le script
mysql -u root -p cmem2_db < docs/create_table_api_keys.sql
```

**Vérification :**

```sql
USE cmem2_db;

-- Vérifier la table
SHOW TABLES LIKE 'api_keys';

-- Vérifier la structure
DESCRIBE api_keys;

-- Vérifier les indexes
SHOW INDEXES FROM api_keys;

-- Vérifier les vues
SHOW FULL TABLES WHERE Table_type = 'VIEW';

-- Vérifier les procédures
SHOW PROCEDURE STATUS WHERE Db = 'cmem2_db';
```

**Résultat attendu :**

- Table `api_keys` créée avec 20 colonnes
- 8 indexes présents
- Vue `active_api_keys` créée
- Vue `api_keys_stats_by_user` créée
- Procédure `cleanup_expired_api_keys` créée

---

### Étape 3 : Vérifier l'autoload Composer

```bash
# Régénérer l'autoload si nécessaire
composer dump-autoload

# Vérifier qu'il n'y a pas d'erreurs
composer validate
```

---

### Étape 4 : Tester l'API

#### Test 1 : Health check

```bash
curl http://localhost/cmem2_API/health
```

**Résultat attendu :**

```json
{
  "success": true,
  "data": {
    "status": "healthy",
    "database": "connected"
  }
}
```

#### Test 2 : Endpoint API Keys accessible

```bash
# D'abord, obtenir un JWT token
curl -X POST http://localhost/cmem2_API/users/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "your_email@example.com",
    "password": "your_password"
  }'

# Utiliser le token pour tester l'endpoint
curl -X GET http://localhost/cmem2_API/api-keys \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

**Résultat attendu :**

```json
{
  "success": true,
  "data": {
    "api_keys": [],
    "total": 0
  }
}
```

---

### Étape 5 : Exécuter les tests automatisés

```bash
# Test complet du système
php tests/api_keys/test_api_keys_basic.php
```

**Résultat attendu :**

```text
╔════════════════════════════════════════════════════════════╗
║         TESTS API KEYS - AuthGroups API v1.3.0            ║
╚════════════════════════════════════════════════════════════╝

✅ Réussis: 21
❌ Échoués: 0
📊 Total:   21

🎉 Tous les tests sont passés avec succès!
```

---

### Étape 6 : Configurer le nettoyage automatique (optionnel)

Créer un cron job pour nettoyer les clés expirées automatiquement :

```bash
# Éditer crontab
crontab -e

# Ajouter cette ligne (exécution quotidienne à 2h du matin)
0 2 * * * mysql -u root -p'PASSWORD' cmem2_db -e "CALL cleanup_expired_api_keys();" >> /var/log/api_keys_cleanup.log 2>&1
```

Ou via script PHP :

```bash
# Créer le script
cat > scripts/cleanup_api_keys.php << 'EOF'
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/loader.php';

use AuthGroups\Models\ApiKey;

try {
    $count = ApiKey::cleanupExpired();
    echo "[" . date('Y-m-d H:i:s') . "] Nettoyage terminé: $count clé(s) supprimée(s)\n";
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Erreur: " . $e->getMessage() . "\n";
}
EOF

# Rendre exécutable
chmod +x scripts/cleanup_api_keys.php

# Tester
php scripts/cleanup_api_keys.php

# Ajouter au cron
# 0 2 * * * /usr/bin/php /path/to/cmem2_API/scripts/cleanup_api_keys.php >> /var/log/api_keys_cleanup.log 2>&1
```

---

## 🔍 Vérification post-migration

### Checklist complète

- [ ] **Base de données**

  ```sql
  SELECT COUNT(*) FROM information_schema.tables 
  WHERE table_schema = 'cmem2_db' AND table_name = 'api_keys';
  -- Doit retourner : 1
  ```

- [ ] **Fichiers PHP**

  ```bash
  find src/auth_groups -name "*ApiKey*" -type f
  # Doit lister 4 fichiers
  ```

- [ ] **Routing**

  ```bash
  grep -c "ApiKeyRouteHandler" src/auth_groups/Routing/Router.php
  # Doit retourner : 2 (use + array)
  ```

- [ ] **Tests**

  ```bash
  php tests/api_keys/test_api_keys_basic.php
  # Doit passer sans erreurs
  ```

- [ ] **Documentation**

  ```bash
  ls docs/ENDPOINTS_API_KEYS.md
  ls docs/API_KEYS_IMPLEMENTATION.md
  ls docs/API_KEYS_ARCHITECTURE.md
  # Tous doivent exister
  ```

---

## 🔄 Rollback (si problème)

### Option 1 : Rollback complet

```bash
# Restaurer le backup
mysql -u root -p cmem2_db < backup_pre_v1.3.0_YYYYMMDD.sql

# Retirer les nouveaux fichiers (si ajoutés manuellement)
rm src/auth_groups/Models/ApiKey.php
rm src/auth_groups/Middleware/ApiKeyAuthMiddleware.php
rm src/auth_groups/Controllers/ApiKeyController.php
rm src/auth_groups/Routing/RouteHandlers/ApiKeyRouteHandler.php

# Restaurer l'ancien Router.php depuis git
git checkout HEAD~1 src/auth_groups/Routing/Router.php
```

### Option 2 : Rollback table uniquement

```sql
-- Si seulement la table pose problème
DROP TABLE IF EXISTS api_keys;
DROP VIEW IF EXISTS active_api_keys;
DROP VIEW IF EXISTS api_keys_stats_by_user;
DROP PROCEDURE IF EXISTS cleanup_expired_api_keys;
```

---

## 🐛 Dépannage

### Problème : Table déjà existante

**Erreur :**

```text
ERROR 1050 (42S01): Table 'api_keys' already exists
```

**Solution :**

```sql
-- Vérifier s'il y a des données
SELECT COUNT(*) FROM api_keys;

-- Si table vide, la supprimer
DROP TABLE api_keys;

-- Puis recréer
SOURCE docs/create_table_api_keys.sql;
```

---

### Problème : Class not found ApiKey

**Erreur :**

```text
PHP Fatal error: Class 'AuthGroups\Models\ApiKey' not found
```

**Solution :**

```bash
# Régénérer l'autoload
composer dump-autoload

# Vérifier les namespaces
grep -n "namespace AuthGroups" src/auth_groups/Models/ApiKey.php
```

---

### Problème : Route /api-keys returns 404

**Erreur :**

```json
{
  "success": false,
  "error": {
    "message": "Route not found"
  }
}
```

**Solution :**

```bash
# Vérifier Router.php
grep -A5 "routeHandlers" src/auth_groups/Routing/Router.php

# Doit contenir :
# 'api-keys' => new ApiKeyRouteHandler()

# Vérifier les imports
grep "use.*ApiKeyRouteHandler" src/auth_groups/Routing/Router.php
```

---

### Problème : Tests échouent

**Solution :**

```bash
# Vérifier la connexion DB
mysql -u root -p -e "SELECT 1 FROM cmem2_db.api_keys LIMIT 1;"

# Vérifier l'URL de base dans test_base.php
grep "localhost/cmem2_API" tests/test_base.php

# Vérifier les logs PHP
tail -f /var/log/apache2/error.log  # ou
tail -f /xampp/apache/logs/error.log
```

---

## 📊 Monitoring post-migration

### Requêtes utiles

```sql
-- Nombre total de clés par environnement
SELECT environment, COUNT(*) as total
FROM api_keys
GROUP BY environment;

-- Clés actives vs révoquées
SELECT 
  CASE WHEN revoked_at IS NULL THEN 'Active' ELSE 'Revoked' END as status,
  COUNT(*) as total
FROM api_keys
GROUP BY status;

-- Top 10 clés les plus utilisées
SELECT name, total_requests, last_used_at
FROM api_keys
WHERE revoked_at IS NULL
ORDER BY total_requests DESC
LIMIT 10;

-- Clés expirant dans les 7 prochains jours
SELECT name, user_id, expires_at, 
  DATEDIFF(expires_at, NOW()) as days_remaining
FROM api_keys
WHERE expires_at IS NOT NULL
  AND expires_at > NOW()
  AND expires_at < DATE_ADD(NOW(), INTERVAL 7 DAY)
ORDER BY expires_at ASC;
```

---

## 🎯 Prochaines étapes

### Recommandations

1. **Créer quelques clés de test**
   - Une clé avec scope `read` uniquement
   - Une clé avec scopes `read`, `write`
   - Une clé avec scope `*` (all)

2. **Tester l'authentification**
   - Avec header `X-API-Key`
   - Avec header `Authorization: Bearer`

3. **Monitorer les performances**
   - Vérifier le temps de réponse
   - Surveiller les stats d'usage

4. **Documenter pour votre équipe**
   - Partager `ENDPOINTS_API_KEYS.md`
   - Former sur les best practices

5. **Planifier la rotation des clés**
   - Politique de renouvellement (ex: tous les 90 jours)
   - Procédure de régénération

---

## 📞 Support

En cas de problème :

1. **Vérifier les logs**

   ```bash
   tail -f /var/log/apache2/error.log
   ```

2. **Activer le mode debug PHP**

   ```php
   // Dans config/environment.php
   define('DEBUG_MODE', true);
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   ```

3. **Consulter la documentation**
   - `docs/ENDPOINTS_API_KEYS.md`
   - `docs/API_KEYS_IMPLEMENTATION.md`
   - `docs/API_KEYS_ARCHITECTURE.md`

4. **Créer une issue GitHub**
   - Décrire le problème
   - Inclure les logs d'erreur
   - Spécifier la version PHP/MySQL

---

## ✅ Migration réussie

Si tous les tests passent, la migration est complète. Vous pouvez maintenant :

1. ✅ Créer des API keys via l'endpoint
2. ✅ Les utiliser pour authentifier vos intégrations
3. ✅ Gérer le cycle de vie des clés (liste, révocation, régénération)
4. ✅ Monitorer l'usage via les statistiques

---

**AuthGroups API v1.3.0** - Guide de migration  
**Date** : 7 octobre 2025  
**Status** : Production Ready  

**Félicitations ! 🎉**  
Votre système API Keys est maintenant opérationnel.
