# ENDPOINT ADMIN SECRET - NON DOCUMENTÉ PUBLIQUEMENT

## ⚠️ ATTENTION 
Cet endpoint est secret et ne doit PAS être documenté publiquement. Il est destiné uniquement aux administrateurs ayant accès à la clé secrète.

## Configuration

Dans le fichier `.env`, la clé secrète est définie :
```
ADMIN_SECRET_KEY=cmem1_admin_secret_2025_ultra_secure_key_do_not_share
```

## Endpoints disponibles

### 1. Lister les procédures disponibles

**🎯 Mode recommandé (compatible navigateurs) :**
```bash
GET /secret-admin/procedures?admin_secret={ADMIN_SECRET_KEY}
```

**Exemples avec curl :**
```bash
# Mode recommandé - compatible tous navigateurs
curl -X GET "https://cmem1.journauxdebord.com/secret-admin/procedures?admin_secret=cmem1_admin_secret_2025_ultra_secure_key_do_not_share"
```

### 2. Exécuter une procédure stockée

**🎯 Mode recommandé (compatible navigateurs) :**
```bash
POST /secret-admin/execute-procedure
Content-Type: application/json

Body:
{
  "admin_secret": "{ADMIN_SECRET_KEY}",
  "procedure": "nom_de_la_procedure",
  "parameters": []
}
```

**Exemples avec curl :**
```bash
# Mode recommandé - compatible tous navigateurs
curl -X POST "https://cmem1.journauxdebord.com/secret-admin/execute-procedure" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "cmem1_admin_secret_2025_ultra_secure_key_do_not_share",
    "procedure": "GeneratePlatformStats",
    "parameters": []
  }'

``

**Exemples d'autres procédures :**
```bash
# Nettoyer les anciennes statistiques (mode recommandé)
curl -X POST "https://cmem1.journauxdebord.com/secret-admin/execute-procedure" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "cmem1_admin_secret_2025_ultra_secure_key_do_not_share",
    "procedure": "CleanupOldStats",
    "parameters": []
  }'

# ATTENTION : Procédure dangereuse - Remet à zéro toutes les données
curl -X POST "https://cmem1.journauxdebord.com/secret-admin/execute-procedure" \
  -H "Content-Type: application/json" \
  -d '{
    "admin_secret": "cmem1_admin_secret_2025_ultra_secure_key_do_not_share",
    "procedure": "ResetData",
    "parameters": []
  }'
```

## Procédures stockées disponibles

| Procédure | Description | Niveau de danger |
|-----------|-------------|------------------|
| `ResetData` | Remet à zéro toutes les données en gardant la structure | HIGH |
| `ResetDatabase` | Recrée complètement la base de données | EXTREME |
| `GenerateAllStats` | Génère toutes les statistiques | LOW |
| `GenerateUserStats` | Génère les statistiques des utilisateurs | LOW |
| `GenerateGroupStats` | Génère les statistiques des groupes | LOW |
| `GeneratePlatformStats` | Génère les statistiques de la plateforme | LOW |
| `CleanupOldStats` | Nettoie les anciennes statistiques | MEDIUM |

## Sécurité

- Seules les procédures autorisées peuvent être exécutées
- Toutes les tentatives d'accès sont loggées
- Les tentatives avec une clé invalide sont loggées avec l'IP et le User-Agent

## Logs

Toutes les opérations sont tracées dans les logs avec :
- L'IP de la requête
- La procédure exécutée
- Les paramètres utilisés
- Le timestamp de l'exécution

## Réponses

### Succès
```json
{
  "success": true,
  "message": "Procédure exécutée avec succès",
  "timestamp": "2025-09-19 18:14:25",
  "data": {
    "procedure": "GeneratePlatformStats",
    "parameters": [],
    "result": {
      "success": true,
      "results": [],
      "affected_rows": 1
    },
    "executed_at": "2025-09-19 18:14:25"
  }
}
```

### Erreur d'authentification
```json
{
  "success": false,
  "message": "Accès non autorisé",
  "timestamp": "2025-09-19 18:14:25",
  "data": null
}
```