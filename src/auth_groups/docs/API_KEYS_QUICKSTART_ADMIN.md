# 🚀 Guide de Démarrage Rapide - Système API Keys Sécurisé

## ⚡ Actions Immédiates Requises

### 1. Vérifier la Configuration

```bash
# Dans votre fichier .env ou configuration
ADMIN_SECRET_KEY=votre_cle_secrete_admin_forte_et_unique
JWT_SECRET=votre_jwt_secret_existant
```

### 2. Premier Administrateur

Assurez-vous d'avoir au moins un utilisateur avec le rôle `ADMINISTRATEUR` :

```sql
UPDATE users SET role = 'ADMINISTRATEUR' WHERE email = 'admin@votre-domaine.com';
```

### 3. Obtenir un JWT Admin

Connectez-vous avec un compte administrateur pour obtenir un JWT :

```bash
curl -X POST http://votre-domaine/users/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@votre-domaine.com","password":"motdepasse"}'
```

⚠️ **ATTENTION** : Cette étape nécessite temporairement de désactiver la validation API key pour le premier login admin.

## 🔑 Créer Votre Première API Key

### Commande cURL

```bash
curl -X POST http://votre-domaine/secret-admin/api-keys \
  -H "Authorization: Bearer VOTRE_JWT_ADMIN" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "votre_cle_secrete_admin_forte_et_unique",
    "user_id": 1,
    "name": "Première API Key Système",
    "scopes": ["read", "write"],
    "environment": "production",
    "expires_in_days": 365,
    "notes": "Première clé créée pour le nouveau système"
  }'
```

### Réponse Attendue

```json
{
  "success": true,
  "data": {
    "api_key": {
      "key": "ag_live_a1b2c3d4e5f6g7h8..."
    }
  }
}
```

⚠️ **IMPORTANT** : Sauvegardez immédiatement cette clé ! Elle ne sera plus jamais affichée.

## 🔧 Activer la Sécurité Complète

Une fois votre première API key créée, le système de sécurité complet peut être activé.

### Test de Fonctionnement

```bash
# Test login avec API key (devrait réussir)
curl -X POST http://votre-domaine/users/login \
  -H "X-API-Key: ag_live_votre_cle_ici" \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'

# Test login sans API key (devrait échouer avec 401)
curl -X POST http://votre-domaine/users/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'
```

## 📋 Commandes d'Administration Courantes

### Lister Toutes les API Keys

```bash
curl -X GET "http://votre-domaine/secret-admin/api-keys?admin_secret=votre_cle" \
  -H "Authorization: Bearer VOTRE_JWT_ADMIN"
```

### Créer une Clé pour un Utilisateur Spécifique

```bash
curl -X POST http://votre-domaine/secret-admin/api-keys \
  -H "Authorization: Bearer VOTRE_JWT_ADMIN" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "votre_cle",
    "user_id": 123,
    "name": "Clé pour Application Mobile",
    "scopes": ["read"],
    "environment": "production",
    "expires_in_days": 90
  }'
```

### Révoquer une Clé Compromise

```bash
curl -X DELETE http://votre-domaine/secret-admin/api-keys/45 \
  -H "Authorization: Bearer VOTRE_JWT_ADMIN" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "votre_cle",
    "reason": "Clé compromise - révocation d urgence"
  }'
```

### Régénérer une Clé

```bash
curl -X POST http://votre-domaine/secret-admin/api-keys/45/regenerate \
  -H "Authorization: Bearer VOTRE_JWT_ADMIN" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "votre_cle"
  }'
```

## 🛡️ Sécurité en Production

### Variables d'Environnement Critiques

```bash
# OBLIGATOIRE - Changez cette valeur en production !
ADMIN_SECRET_KEY=generez_une_cle_aleatoire_de_64_caracteres_minimum

# Recommandé - Restriction IP pour admin
ADMIN_ALLOWED_IPS=192.168.1.100,203.0.113.1

# Logs de sécurité
LOG_ADMIN_ACCESS=true
LOG_ADMIN_ACTIONS=true
```

### Rotation des Clés Recommandée

- **API Keys de production** : Tous les 90 jours
- **API Keys de test/dev** : Tous les 30 jours
- **ADMIN_SECRET_KEY** : Tous les 6 mois

## 🚨 Migration des Clients Existants

### 1. Communication aux Équipes

Informez vos équipes que **tous les logins nécessitent maintenant une API key**.

### 2. Distribution des API Keys

Créez des API keys pour chaque client/application :

```bash
# Pour chaque équipe/application
curl -X POST http://votre-domaine/secret-admin/api-keys \
  -H "Authorization: Bearer VOTRE_JWT_ADMIN" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "votre_cle",
    "user_id": USER_ID_DE_LEQUIPE,
    "name": "API Key - Équipe Frontend",
    "scopes": ["read", "write"],
    "environment": "production"
  }'
```

### 3. Mise à Jour du Code Client

Les équipes doivent ajouter le header `X-API-Key` à toutes leurs requêtes de login.

## 📊 Surveillance

### Logs à Surveiller

```bash
# Tentatives de login sans API key
grep "API_KEY_REQUIRED" /var/log/cmem2_api.log

# Utilisations d'API keys invalides
grep "INVALID_API_KEY" /var/log/cmem2_api.log

# Activité d'administration
grep "secret admin" /var/log/cmem2_api.log
```

### Métriques Importantes

- Nombre de requêtes bloquées sans API key
- Tentatives d'utilisation d'API keys invalides
- Fréquence de création/révocation d'API keys

## 🆘 Procédures d'Urgence

### En cas de Compromission d'API Key

1. **Identifier la clé compromise**
2. **Révoquer immédiatement** :

   ```bash
   curl -X DELETE http://votre-domaine/secret-admin/api-keys/ID_CLE \
     -H "Authorization: Bearer JWT_ADMIN" \
     -H "Content-Type: application/json" \
     -d '{"admin_secret": "votre_cle", "reason": "URGENCE - Clé compromise"}'
   ```

3. **Créer une nouvelle clé** pour le client affecté
4. **Analyser les logs** pour identifier les usages malveillants

### En cas de Perte d'ADMIN_SECRET_KEY

1. **Générer une nouvelle clé** forte
2. **Mettre à jour** la variable d'environnement
3. **Redémarrer** l'application
4. **Informer** tous les administrateurs

## ✅ Checklist de Déploiement

- [ ] Configuration ADMIN_SECRET_KEY unique et forte
- [ ] Au moins un utilisateur ADMINISTRATEUR configuré
- [ ] Première API key créée et testée
- [ ] Ancien endpoint /api-keys retourne bien HTTP 410
- [ ] Login sans API key retourne bien HTTP 401
- [ ] Login avec API key valide fonctionne
- [ ] Logs de sécurité activés
- [ ] Équipes informées et API keys distribuées
- [ ] Documentation partagée avec les développeurs
- [ ] Tests de non-régression passés

---

## 📞 Support d'Urgence

En cas de problème critique :

1. **Consultez les logs** : `/var/log/cmem2_api.log`
2. **Vérifiez la configuration** : Variables d'environnement
3. **Testez les endpoints** : Utilisez le script de test fourni
4. **Documentez le problème** : Logs, étapes de reproduction
5. **Contactez l'équipe technique** avec tous les détails

**⚠️ RAPPEL** : Ce système de sécurité est conçu pour être **permanent**. Assurez-vous que la migration est complète avant d'activer en production.
