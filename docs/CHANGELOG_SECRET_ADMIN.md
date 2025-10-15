# Changelog - Endpoint Admin Secret

## 📅 13 octobre 2025

### ✨ Améliorations de l'endpoint `/secret-admin/procedures`

#### 🔧 Modifications du contrôleur (`SecretAdminController.php`)

**Améliorations de la méthode `listProcedures()` :**

1. **Réponse enrichie** :
   - Ajout du champ `count` pour indiquer le nombre de procédures disponibles
   - Ajout du champ `name` dans chaque procédure pour faciliter l'utilisation
   - Ajout de `warning` pour les procédures dangereuses (HIGH et EXTREME)
   - Descriptions plus détaillées pour certaines procédures

2. **Nouvelles informations dans la réponse** :
   ```json
   {
     "authentication_info": {
       "type": "Double authentification",
       "requirements": [
         "1. Token JWT valide avec rôle ADMINISTRATEUR",
         "2. Clé secrète admin (ADMIN_SECRET_KEY)"
       ]
     }
   }
   ```

3. **Structure de `usage` améliorée** :
   - Format plus clair avec `headers` et `body` séparés
   - Headers correctement structurés en JSON
   - Meilleure documentation pour l'utilisation

**Exemple de procédure dans la réponse :**
```json
{
  "name": "ResetData",
  "description": "Remet à zéro toutes les données en gardant la structure",
  "parameters": [],
  "danger_level": "HIGH",
  "warning": "ATTENTION : Cette procédure supprime toutes les données utilisateurs"
}
```

#### 📚 Mise à jour de la documentation (`ADMIN_SECRET_ENDPOINT.md`)

**Nouvelle section : 🔐 Sécurité - Double Authentification Requise**

- Clarification explicite que **deux niveaux d'authentification** sont requis :
  1. Token JWT avec rôle ADMINISTRATEUR
  2. Clé secrète admin (ADMIN_SECRET_KEY)

**Ajout de tous les exemples curl avec le header Authorization**
```bash
curl -X GET "https://cmem1.journauxdebord.com/secret-admin/procedures?admin_secret=..." \
  -H "Authorization: Bearer YOUR_JWT_ADMIN_TOKEN"
```

**Nouveau tableau des procédures** :
- Ajout d'une colonne "Avertissement" pour les procédures dangereuses
- Tri par niveau de danger (LOW → MEDIUM → HIGH → EXTREME)
- Icônes visuelles : ⚠️ pour HIGH, ⛔ pour EXTREME

**Section Sécurité enrichie** :
- Liste des 5 niveaux de protection
- Instructions détaillées pour obtenir un token JWT admin
- Exemple complet de connexion via l'API

**Section Réponses complète** :
- Réponse de succès détaillée avec exemple complet
- 3 types d'erreurs documentées :
  - Token JWT manquant ou invalide
  - Rôle insuffisant
  - Clé secrète invalide

**Nouvelle section : Notes importantes**
- 6 points clés à retenir
- Emphase sur la double authentification obligatoire
- Rappels de sécurité

### 🎯 Impact des changements

#### Pour les développeurs :
- ✅ Documentation beaucoup plus claire sur les exigences d'authentification
- ✅ Exemples complets et fonctionnels avec tous les headers requis
- ✅ Meilleure compréhension des procédures et de leur niveau de danger

#### Pour l'API :
- ✅ Réponse plus structurée et informative
- ✅ Champ `count` permet de valider que toutes les procédures sont disponibles
- ✅ Champ `name` dans chaque procédure évite les erreurs de typage
- ✅ Warnings explicites pour les procédures dangereuses

#### Pour la sécurité :
- ✅ Documentation claire de la double authentification
- ✅ Ordre des vérifications explicité (JWT puis clé secrète)
- ✅ Guide complet pour obtenir les credentials nécessaires

### 📊 Résumé des fichiers modifiés

| Fichier | Type | Changements |
|---------|------|-------------|
| `src/auth_groups/Controllers/SecretAdminController.php` | Code | Enrichissement de la réponse JSON, ajout de champs, amélioration des descriptions |
| `docs/ADMIN_SECRET_ENDPOINT.md` | Documentation | Réécriture complète avec clarifications sur la double authentification |

### 🔍 Tests à effectuer

Avant de déployer, tester :
1. ✅ Appel avec JWT valide + clé secrète valide → Succès
2. ✅ Appel sans JWT → Erreur 401
3. ✅ Appel avec JWT non-admin → Erreur 403
4. ✅ Appel avec JWT admin mais sans clé secrète → Erreur 403
5. ✅ Appel avec JWT admin + mauvaise clé secrète → Erreur 403
6. ✅ Vérifier que la réponse contient bien les nouveaux champs

### 📝 Notes pour le futur

- Les tests existants dans `tests/test_secret_admin.php` utilisent déjà la bonne méthode (JWT + clé secrète)
- La clé secrète actuelle : `Etzwsge!1*dh6TKHukndF8uvZ0mGERy2Kh5n3FGGHT0YjSA4AhTHqBfq2cTC$WGP`
- En production : `cmem1_admin_secret_2025_ultra_secure_key_do_not_share`
