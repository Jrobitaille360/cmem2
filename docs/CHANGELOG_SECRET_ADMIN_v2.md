# Changelog - Mise à Jour des Procédures Stockées

## 📅 13 octobre 2025 - Seconde mise à jour

### 🎯 Objectif
Synchroniser la liste des procédures dans l'API avec les procédures réellement disponibles dans les fichiers SQL de migration.

---

## 🔄 Changements Apportés

### 1. **Procédures Ajoutées** ✨

| Procédure | Description | Niveau |
|-----------|-------------|--------|
| `cleanup_expired_api_keys` | Révoque automatiquement les clés API expirées | LOW |
| `cleanup_expired_licenses` | Nettoie les licences expirées | MEDIUM |
| `get_license_status` | Récupère le statut de licence d'un utilisateur | LOW |

### 2. **Procédures Renommées** 🔄

| Ancien Nom | Nouveau Nom | Raison |
|------------|-------------|--------|
| `ResetData` | `ResetAuthGroupsData` | Correspond au nom réel dans SQL |
| `ResetDatabase` | `ResetAuthenticationGroups` | Correspond au nom réel dans SQL |

### 3. **Procédures Supprimées** ❌

| Procédure | Raison |
|-----------|--------|
| `GenerateAllStats` | N'existe pas dans les fichiers SQL - pas de procédure qui génère tout en une fois |

---

## 📊 Liste Complète des Procédures (9 au total)

### 🟢 Niveau LOW (4 procédures)
1. **GeneratePlatformStats** - Statistiques globales
2. **GenerateUserStats** - Statistiques par utilisateur
3. **GenerateGroupStats** - Statistiques par groupe
4. **cleanup_expired_api_keys** - Révocation automatique des clés API expirées

### 🟡 Niveau MEDIUM (2 procédures)
5. **CleanupOldStats** - Nettoyage des anciennes statistiques
6. **cleanup_expired_licenses** - Nettoyage des licences expirées

### 🟠 Niveau HIGH (1 procédure)
7. **ResetAuthGroupsData** - Suppression des données (garde la structure)

### 🔴 Niveau EXTREME (1 procédure)
8. **ResetAuthenticationGroups** - Recréation complète de la base

### 📋 Avec Paramètres (1 procédure)
9. **get_license_status** - Nécessite `p_user_id` (INT)

---

## 🔍 Détails des Nouvelles Procédures

### `cleanup_expired_api_keys`
**Fichier source :** `create_proc_reset_auth_groups.sql`

**Description :** Marque comme révoquées les clés API expirées non encore révoquées.

**SQL :**
```sql
UPDATE api_keys 
SET revoked_at = NOW(),
    revoked_reason = 'Expired automatically'
WHERE expires_at IS NOT NULL 
  AND expires_at < NOW() 
  AND revoked_at IS NULL;
```

**Usage API :**
```json
{
  "admin_secret": "...",
  "procedure": "cleanup_expired_api_keys",
  "parameters": []
}
```

---

### `cleanup_expired_licenses`
**Fichier source :** `migrate_license_system.sql`

**Description :** Nettoie les licences expirées et met à jour le statut des utilisateurs.

**Impact :** Modifie le champ `payment_status` des utilisateurs dont la licence a expiré.

**Usage API :**
```json
{
  "admin_secret": "...",
  "procedure": "cleanup_expired_licenses",
  "parameters": []
}
```

---

### `get_license_status`
**Fichier source :** `migrate_license_system.sql`

**Description :** Récupère le statut de licence d'un utilisateur spécifique.

**Paramètres requis :**
- `p_user_id` (INT) - ID de l'utilisateur

**Usage API :**
```json
{
  "admin_secret": "...",
  "procedure": "get_license_status",
  "parameters": [123]
}
```

**Exemple de réponse SQL :**
```sql
SELECT 
    u.id,
    u.name,
    u.email,
    u.payment_status,
    u.license_expires_at,
    u.payment_plan,
    CASE 
        WHEN u.license_expires_at < NOW() THEN 'expired'
        WHEN u.license_expires_at IS NULL THEN 'no_license'
        ELSE 'active'
    END AS license_status
FROM users u
WHERE u.id = p_user_id;
```

---

## 📝 Changements dans les Fichiers

### `SecretAdminController.php`

**Avant :** 7 procédures listées
**Après :** 9 procédures listées

**Nouvelles entrées ajoutées :**
```php
'cleanup_expired_api_keys' => [
    'name' => 'cleanup_expired_api_keys',
    'description' => 'Révoque automatiquement les clés API expirées',
    'parameters' => [],
    'danger_level' => 'LOW'
],
'cleanup_expired_licenses' => [
    'name' => 'cleanup_expired_licenses',
    'description' => 'Nettoie les licences expirées et met à jour le statut des utilisateurs',
    'parameters' => [],
    'danger_level' => 'MEDIUM',
    'note' => 'Système de licence - Modifie le payment_status des utilisateurs'
],
'get_license_status' => [
    'name' => 'get_license_status',
    'description' => 'Récupère le statut de licence d\'un utilisateur spécifique',
    'parameters' => [
        [
            'name' => 'p_user_id',
            'type' => 'INT',
            'required' => true,
            'description' => 'ID de l\'utilisateur'
        ]
    ],
    'danger_level' => 'LOW',
    'note' => 'Nécessite un paramètre user_id'
]
```

---

### `ADMIN_SECRET_ENDPOINT.md`

**Tableau mis à jour** avec :
- 9 procédures au lieu de 7
- Nouvelle colonne "Paramètres" pour indiquer les procédures nécessitant des arguments
- Section "Notes sur les procédures" avec détails sur chaque catégorie
- Exemples d'utilisation pour les nouvelles procédures

**Exemples ajoutés :**
```bash
# Nettoyer les clés API expirées
curl -X POST ".../secret-admin/execute-procedure" \
  -H "Authorization: Bearer JWT" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "...",
    "procedure": "cleanup_expired_api_keys",
    "parameters": []
  }'

# Obtenir le statut de licence (avec paramètre)
curl -X POST ".../secret-admin/execute-procedure" \
  -H "Authorization: Bearer JWT" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "...",
    "procedure": "get_license_status",
    "parameters": [123]
  }'
```

---

## 🎯 Impact sur l'API

### Réponse de `/secret-admin/procedures`

**Avant :**
```json
{
  "count": 7,
  "procedures": [...]
}
```

**Après :**
```json
{
  "count": 9,
  "procedures": [
    {
      "name": "cleanup_expired_api_keys",
      "description": "Révoque automatiquement les clés API expirées",
      "parameters": [],
      "danger_level": "LOW"
    },
    {
      "name": "get_license_status",
      "description": "Récupère le statut de licence d'un utilisateur",
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
    },
    ...
  ]
}
```

---

## 🧪 Tests à Effectuer

1. ✅ Vérifier que `/secret-admin/procedures` retourne bien 9 procédures
2. ✅ Tester `cleanup_expired_api_keys` sans paramètres
3. ✅ Tester `cleanup_expired_licenses` sans paramètres
4. ✅ Tester `get_license_status` avec un user_id valide
5. ✅ Tester `get_license_status` avec un user_id invalide
6. ✅ Vérifier que les anciennes procédures fonctionnent toujours
7. ✅ Vérifier que `ResetAuthGroupsData` et `ResetAuthenticationGroups` portent les bons noms

---

## 📚 Fichiers Modifiés

| Fichier | Type | Changements |
|---------|------|-------------|
| `SecretAdminController.php` | Code | +3 procédures, renommage de 2 procédures, suppression de 1 |
| `ADMIN_SECRET_ENDPOINT.md` | Documentation | Tableau complet, exemples, notes détaillées |
| `CHANGELOG_SECRET_ADMIN_v2.md` | Documentation | Ce fichier - historique des changements |

---

## 🔗 Sources des Procédures

Les procédures sont définies dans les fichiers SQL suivants :

1. **create_proc_reset_auth_groups.sql** (procédures principales)
   - ResetAuthenticationGroups
   - GeneratePlatformStats
   - GenerateGroupStats
   - GenerateUserStats
   - CleanupOldStats
   - cleanup_expired_api_keys

2. **create_proc_reset_auth_groups_data.sql**
   - ResetAuthGroupsData

3. **migrate_license_system.sql** (système de licences)
   - cleanup_expired_licenses
   - get_license_status

---

## ✅ Checklist de Validation

- [x] Toutes les procédures SQL sont listées dans l'API
- [x] Les noms correspondent exactement aux noms SQL
- [x] Les procédures avec paramètres sont correctement documentées
- [x] Les niveaux de danger sont appropriés
- [x] La documentation contient des exemples pour chaque type
- [x] Le count dans la réponse est correct (9)
- [ ] Tests exécutés avec succès
- [ ] Validation en environnement de développement

---

**Date :** 13 octobre 2025  
**Version :** 2.0  
**Statut :** ✅ Modifications complètes, synchronisées avec SQL
