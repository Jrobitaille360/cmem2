# ENDPOINT ADMIN SECRET - NON DOCUMENTÉ PUBLIQUEMENT

## ⚠️ ATTENTION 
Cet endpoint est secret et ne doit PAS être documenté publiquement. Il est destiné uniquement aux administrateurs ayant accès à la clé secrète.

## 🔐 Sécurité - Double Authentification Requise

**Ces endpoints nécessitent deux niveaux d'authentification :**

1. **Token JWT valide** avec le rôle `ADMINISTRATEUR`
   - Obtenu via l'endpoint `/users/login`
   - Doit être inclus dans le header `Authorization: Bearer {TOKEN}`

2. **Clé secrète admin** (ADMIN_SECRET_KEY)
   - Définie dans le fichier `.env`
   - Valeur actuelle : `cmem1_admin_secret_2025_ultra_secure_key_do_not_share`
   - Passée en query parameter ou dans le body JSON selon l'endpoint

## Configuration

Dans le fichier `.env`, la clé secrète est définie :
```
ADMIN_SECRET_KEY=cmem1_admin_secret_2025_ultra_secure_key_do_not_share
```

## Endpoints disponibles

### 1. Lister les procédures disponibles

**Requête :**
```
GET /secret-admin/procedures?admin_secret={ADMIN_SECRET_KEY}
Authorization: Bearer {JWT_TOKEN}
```

**Exemple avec curl :**
```bash
curl -X GET "https://cmem1.journauxdebord.com/secret-admin/procedures?admin_secret=cmem1_admin_secret_2025_ultra_secure_key_do_not_share" \
  -H "Authorization: Bearer YOUR_JWT_ADMIN_TOKEN"
```

**Réponse de succès :**
```json
{
  "success": true,
  "message": "Procédures disponibles récupérées avec succès",
  "timestamp": "2025-10-13 14:30:00",
  "data": {
    "count": 9,
    "procedures": [
      {
        "name": "ResetAuthGroupsData",
        "description": "Remet à zéro toutes les données du module authentification/groupes en gardant la structure",
        "parameters": [],
        "danger_level": "HIGH",
        "warning": "ATTENTION : Cette procédure supprime toutes les données utilisateurs, groupes, fichiers et tags"
      },
      {
        "name": "GeneratePlatformStats",
        "description": "Génère les statistiques globales de la plateforme",
        "parameters": [],
        "danger_level": "LOW"
      },
      {
        "name": "get_license_status",
        "description": "Récupère le statut de licence d'un utilisateur spécifique",
        "parameters": [
          {
            "name": "p_user_id",
            "type": "INT",
            "required": true,
            "description": "ID de l'utilisateur"
          }
        ],
        "danger_level": "LOW",
        "note": "Nécessite un paramètre user_id"
      }
    ],
    "authenticated_admin": {
      "user_id": 1,
      "email": "admin@example.com",
      "role": "ADMINISTRATEUR"
    },
    "authentication_info": {
      "type": "Double authentification",
      "requirements": [
        "1. Token JWT valide avec rôle ADMINISTRATEUR",
        "2. Clé secrète admin (ADMIN_SECRET_KEY)"
      ]
    },
    "usage": {
      "endpoint": "/secret-admin/execute-procedure",
      "method": "POST",
      "headers": {
        "Authorization": "Bearer {JWT_TOKEN}",
        "Content-Type": "application/json"
      },
      "body": {
        "admin_secret": "{ADMIN_SECRET_KEY}",
        "procedure": "nom_de_la_procedure",
        "parameters": []
      }
    }
  }
}
```

### 2. Exécuter une procédure stockée

**Requête :**
```
POST /secret-admin/execute-procedure
Authorization: Bearer {JWT_TOKEN}
Content-Type: application/json

Body:
{
  "admin_secret": "{ADMIN_SECRET_KEY}",
  "procedure": "nom_de_la_procedure",
  "parameters": []
}
```

**Exemple avec curl :**
```bash
curl -X POST "https://cmem1.journauxdebord.com/secret-admin/execute-procedure" \
  -H "Authorization: Bearer YOUR_JWT_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "cmem1_admin_secret_2025_ultra_secure_key_do_not_share",
    "procedure": "GeneratePlatformStats",
    "parameters": []
  }'
```

**Exemples d'autres procédures :**

```bash
# Générer toutes les statistiques
curl -X POST "https://cmem1.journauxdebord.com/secret-admin/execute-procedure" \
  -H "Authorization: Bearer YOUR_JWT_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "cmem1_admin_secret_2025_ultra_secure_key_do_not_share",
    "procedure": "GenerateUserStats",
    "parameters": []
  }'

# Nettoyer les anciennes statistiques
curl -X POST "https://cmem1.journauxdebord.com/secret-admin/execute-procedure" \
  -H "Authorization: Bearer YOUR_JWT_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "cmem1_admin_secret_2025_ultra_secure_key_do_not_share",
    "procedure": "CleanupOldStats",
    "parameters": []
  }'

# Nettoyer les clés API expirées
curl -X POST "https://cmem1.journauxdebord.com/secret-admin/execute-procedure" \
  -H "Authorization: Bearer YOUR_JWT_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "cmem1_admin_secret_2025_ultra_secure_key_do_not_share",
    "procedure": "cleanup_expired_api_keys",
    "parameters": []
  }'

# Obtenir le statut de licence d'un utilisateur (avec paramètre)
curl -X POST "https://cmem1.journauxdebord.com/secret-admin/execute-procedure" \
  -H "Authorization: Bearer YOUR_JWT_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "cmem1_admin_secret_2025_ultra_secure_key_do_not_share",
    "procedure": "get_license_status",
    "parameters": [123]
  }'

# ATTENTION : Procédure dangereuse - Remet à zéro toutes les données
curl -X POST "https://cmem1.journauxdebord.com/secret-admin/execute-procedure" \
  -H "Authorization: Bearer YOUR_JWT_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "cmem1_admin_secret_2025_ultra_secure_key_do_not_share",
    "procedure": "ResetAuthGroupsData",
    "parameters": []
  }'

# DANGER EXTRÊME : Recrée toute la base de données
curl -X POST "https://cmem1.journauxdebord.com/secret-admin/execute-procedure" \
  -H "Authorization: Bearer YOUR_JWT_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "cmem1_admin_secret_2025_ultra_secure_key_do_not_share",
    "procedure": "ResetAuthenticationGroups",
    "parameters": []
  }'
```

## Procédures stockées disponibles

| Procédure | Description | Paramètres | Niveau de danger | Avertissement |
|-----------|-------------|------------|------------------|---------------|
| `GeneratePlatformStats` | Génère les statistiques globales de la plateforme | - | LOW | - |
| `GenerateUserStats` | Génère les statistiques individuelles pour chaque utilisateur | - | LOW | - |
| `GenerateGroupStats` | Génère les statistiques pour chaque groupe | - | LOW | - |
| `cleanup_expired_api_keys` | Révoque automatiquement les clés API expirées | - | LOW | - |
| `get_license_status` | Récupère le statut de licence d'un utilisateur | `p_user_id` (INT) | LOW | Nécessite un paramètre |
| `CleanupOldStats` | Nettoie les anciennes statistiques (garde les 100 derniers snapshots) | - | MEDIUM | - |
| `cleanup_expired_licenses` | Nettoie les licences expirées et met à jour le statut des utilisateurs | - | MEDIUM | Modifie payment_status |
| `ResetAuthGroupsData` | Remet à zéro toutes les données du module authentification/groupes | - | HIGH | ⚠️ Supprime toutes les données utilisateurs |
| `ResetAuthenticationGroups` | Recrée complètement la base de données | - | EXTREME | ⛔ Toute la base sera recréée |

### 📝 Notes sur les procédures

#### Procédures de statistiques (LOW)
- **GeneratePlatformStats** : Calcule total_users, active_users_7d, active_users_30d, total_groups, total_tags, total_files, total_storage_mb, etc.
- **GenerateUserStats** : Crée des snapshots individuels pour chaque utilisateur (groupes créés/rejoints, tags créés, fichiers uploadés)
- **GenerateGroupStats** : Génère des snapshots pour chaque groupe (nombre de membres, tags, fichiers)
- **cleanup_expired_api_keys** : Marque automatiquement les clés API expirées comme révoquées

#### Procédures de maintenance (MEDIUM)
- **CleanupOldStats** : Garde les 100 derniers snapshots de statistiques globales et supprime ceux de plus de 30 jours
- **cleanup_expired_licenses** : Met à jour le statut de paiement des utilisateurs dont la licence a expiré

#### Procédures dangereuses (HIGH/EXTREME)
- **ResetAuthGroupsData** : Supprime toutes les données mais garde la structure de la base
- **ResetAuthenticationGroups** : DROP et CREATE de toutes les tables (⚠️ DONNÉES PERDUES)

#### Procédures avec paramètres
- **get_license_status** : Nécessite `p_user_id` (INT)
  ```json
  {
    "admin_secret": "...",
    "procedure": "get_license_status",
    "parameters": [123]
  }
  ```

## Sécurité

### Niveaux de protection :

1. **Authentification JWT** : Le token doit être valide et associé à un compte ADMINISTRATEUR
2. **Clé secrète** : La clé ADMIN_SECRET_KEY doit être fournie
3. **Procédures autorisées** : Seules les procédures listées peuvent être exécutées
4. **Logging complet** : Toutes les tentatives d'accès sont enregistrées
5. **Traçabilité** : Les tentatives avec clé invalide sont loggées avec l'IP et le User-Agent

### Comment obtenir un token JWT admin :

```bash
# 1. Connexion avec un compte administrateur
curl -X POST "https://cmem1.journauxdebord.com/users/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@example.com",
    "password": "votre_mot_de_passe"
  }'

# La réponse contient le token dans data.token
# Utilisez ce token dans le header Authorization: Bearer {TOKEN}
```

## Logs

Toutes les opérations sont tracées dans les logs avec :
- L'ID et l'email de l'administrateur authentifié
- L'IP de la requête
- La procédure exécutée
- Les paramètres utilisés
- Le timestamp de l'exécution
- Le résultat de l'opération

## Réponses

### Succès (execute-procedure)
```json
{
  "success": true,
  "message": "Procédure exécutée avec succès",
  "timestamp": "2025-10-13 18:14:25",
  "data": {
    "procedure": "GeneratePlatformStats",
    "parameters": [],
    "result": {
      "success": true,
      "results": [],
      "affected_rows": 1
    },
    "executed_at": "2025-10-13 18:14:25"
  }
}
```

### Erreur - Token JWT manquant ou invalide
```json
{
  "success": false,
  "message": "Token d'authentification requis",
  "timestamp": "2025-10-13 18:14:25",
  "data": null
}
```

### Erreur - Rôle insuffisant
```json
{
  "success": false,
  "message": "Privilèges administrateur requis",
  "timestamp": "2025-10-13 18:14:25",
  "data": null
}
```

### Erreur - Clé secrète invalide
```json
{
  "success": false,
  "message": "Clé secrète admin invalide",
  "timestamp": "2025-10-13 18:14:25",
  "data": null
}
```

## Notes importantes

- **Double authentification** : Les deux authentifications (JWT + clé secrète) sont obligatoires
- **Ordre des vérifications** : JWT d'abord, puis la clé secrète
- **Rôle requis** : Le token JWT doit appartenir à un utilisateur avec le rôle `ADMINISTRATEUR`
- **Expiration du token** : Pensez à renouveler votre token JWT avant qu'il n'expire
- **Sécurité de la clé** : Ne partagez JAMAIS la clé ADMIN_SECRET_KEY
- **Logging** : Toutes les tentatives d'accès sont tracées, même les échecs