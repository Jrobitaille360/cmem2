# CalDAV avec API Keys - Documentation

## Résumé

Le système CalDAV de cmem2_API supporte maintenant l'authentification par API Keys en plus de l'authentification par session.

## État de l'implémentation

✅ **Support API Key dans CalDAV IMPLÉMENTÉ**

Le module CalDAV peut maintenant authentifier les utilisateurs via:
1. **API Keys** (recommandé pour les clients CalDAV externes)
2. **Sessions PHP** (pour les navigateurs web)
3. **Mode test** (pour le développement)

## Modifications effectuées

### 1. `CalDAVRouteHandler.php`

**Fichier**: `src/ics/Routing/RouteHandlers/CalDAVRouteHandler.php`

**Changements**:
- ✅ Ajout de la méthode `getApiKeyFromRequest()` pour extraire les API Keys des headers
- ✅ Modification de `getUserIdFromRequest()` pour vérifier les API Keys en priorité
- ✅ Support de deux méthodes d'authentification API Key:
  - Header `X-API-Key: ag_live_xxxxx` ou `X-API-Key: ag_test_xxxxx`
  - Header `Authorization: Bearer ag_live_xxxxx` ou `Authorization: Bearer ag_test_xxxxx`
- ✅ Validation complète via `ApiKey::validate()` qui vérifie:
  - Existence de la clé
  - Non-révocation
  - Non-expiration
  - Rate limiting

### 2. Script de test

**Fichier**: `tests_new/test_caldav_with_apikey.php`

Un script de test complet a été créé pour vérifier:
- ✅ Génération d'une API Key de test
- ✅ Requête OPTIONS (découverte des capacités CalDAV)
- ✅ Requête PROPFIND (liste des calendriers)
- ✅ Route API JSON `/service-info`
- ✅ Rejet des clés invalides (HTTP 401)
- ✅ Support Authorization: Bearer

## Comment utiliser CalDAV avec API Keys

### Étape 1: Créer une API Key

```php
use AuthGroups\Models\ApiKey;

$apiKeyResult = ApiKey::generate(
    $userId,
    'Mon client CalDAV',
    [
        'scopes' => ['read', 'write'],
        'environment' => ApiKey::ENV_PRODUCTION,
        'rate_limit_per_minute' => 60,
        'metadata' => ['description' => 'Thunderbird CalDAV']
    ]
);

$apiKey = $apiKeyResult['key']; // ag_live_xxxxxxxxxxxx
```

### Étape 2: Configurer le client CalDAV

**URL du serveur CalDAV**:
```
https://votre-domaine.com/cmem2_API/caldav/
```

**Méthode 1: Header X-API-Key** (recommandé)
```http
GET /cmem2_API/caldav/ HTTP/1.1
Host: votre-domaine.com
X-API-Key: ag_live_xxxxxxxxxxxxxxxxxxxx
```

**Méthode 2: Authorization Bearer**
```http
GET /cmem2_API/caldav/ HTTP/1.1
Host: votre-domaine.com
Authorization: Bearer ag_live_xxxxxxxxxxxxxxxxxxxx
```

### Étape 3: Configurer dans les clients populaires

#### Thunderbird

1. Aller dans Calendrier > Nouveau calendrier > Sur le réseau
2. Format: CalDAV
3. URL: `https://votre-domaine.com/cmem2_API/caldav/`
4. **Important**: Thunderbird ne supporte pas nativement les API Keys
   - Utiliser un proxy local ou
   - Configurer l'authentification Basic Auth avec API Key comme mot de passe

#### Evolution (Linux)

1. Calendrier > Nouveau > CalDAV
2. URL: `https://votre-domaine.com/cmem2_API/caldav/`
3. Dans les paramètres avancés, ajouter les headers personnalisés

#### iOS Calendar

iOS ne supporte pas les headers personnalisés pour CalDAV.
**Solution**: Utiliser l'authentification par session via navigateur web.

#### Android DAVx5

DAVx5 supporte les headers personnalisés:
1. Nouveau compte > Login avec URL et identifiants
2. URL de base: `https://votre-domaine.com/cmem2_API/caldav/`
3. Aller dans Paramètres > Headers HTTP personnalisés
4. Ajouter: `X-API-Key: ag_live_xxxxxxxxxxxx`

## Tests manuels avec cURL

### Test 1: OPTIONS (Découverte)

```bash
curl -X OPTIONS \
  https://votre-domaine.com/cmem2_API/caldav/ \
  -H "X-API-Key: ag_live_xxxxxxxxxxxx" \
  -v
```

Réponse attendue:
```
HTTP/1.1 200 OK
DAV: 1, 2, calendar-access, calendar-schedule
Allow: OPTIONS, GET, PUT, DELETE, PROPFIND, REPORT, MKCALENDAR, LOCK, UNLOCK, PROPPATCH
```

### Test 2: PROPFIND (Liste des calendriers)

```bash
curl -X PROPFIND \
  https://votre-domaine.com/cmem2_API/caldav/ \
  -H "X-API-Key: ag_live_xxxxxxxxxxxx" \
  -H "Content-Type: application/xml" \
  -H "Depth: 1" \
  -d '<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:resourcetype />
    <d:displayname />
    <c:calendar-description />
  </d:prop>
</d:propfind>'
```

Réponse attendue:
```
HTTP/1.1 207 Multi-Status
Content-Type: application/xml

<?xml version="1.0" encoding="utf-8"?>
<d:multistatus xmlns:d="DAV:">
  ...
</d:multistatus>
```

### Test 3: Service Info (API JSON)

```bash
curl https://votre-domaine.com/cmem2_API/caldav/service-info \
  -H "X-API-Key: ag_live_xxxxxxxxxxxx" \
  -H "Accept: application/json"
```

## Sécurité

### API Keys vs Sessions

| Critère | API Keys | Sessions |
|---------|----------|----------|
| **Utilisation** | Clients externes, scripts | Navigateurs web |
| **Révocation** | Immédiate | Expire automatiquement |
| **Rate Limiting** | Oui (configurable) | Non |
| **Audit** | Traces détaillées | Traces limitées |
| **Multi-device** | Une clé par device | Session unique |

### Bonnes pratiques

1. **Environnements séparés**
   - `ag_live_` pour la production
   - `ag_test_` pour le développement

2. **Scopes minimaux**
   ```php
   // Calendrier en lecture seule
   'scopes' => ['read']
   
   // Calendrier en lecture/écriture
   'scopes' => ['read', 'write']
   ```

3. **Rate limiting approprié**
   ```php
   'rate_limit_per_minute' => 60,  // 1 requête/seconde
   'rate_limit_per_hour' => 3600    // Max horaire
   ```

4. **Expiration des clés**
   ```php
   'expires_in_days' => 365  // 1 an
   ```

5. **Révocation immédiate**
   ```php
   ApiKey::revoke($keyId, $userId);
   ```

## Flux d'authentification

```mermaid
graph TD
    A[Client CalDAV] --> B{Header présent?}
    B -->|X-API-Key| C[Extraire API Key]
    B -->|Authorization Bearer| D{Préfixe ag_?}
    D -->|Oui| C
    D -->|Non| E[Vérifier Session]
    C --> F[ApiKey::validate]
    F -->|Valide| G[Retourner user_id]
    F -->|Invalide/Révoquée| H[HTTP 401]
    E -->|Session valide| G
    E -->|Pas de session| H
    G --> I[CalDAVServer::handleRequest]
```

## Debugging

### Activer les logs

Les logs sont automatiquement générés dans:
- `logs/app.log` - Logs généraux
- Rechercher: "CalDAV: Authentification"

### Exemple de log réussi

```
[2024-11-07 10:30:15] INFO: CalDAV: Authentification API Key réussie
{
  "user_id": 1,
  "api_key_id": 42
}
```

### Exemple de log échoué

```
[2024-11-07 10:30:20] WARNING: CalDAV: API Key invalide ou révoquée
{
  "api_key_prefix": "ag_live_1234567890..."
}
```

## Tests automatisés

Exécuter le script de test:

```bash
php tests_new/test_caldav_with_apikey.php
```

Le script teste:
1. ✅ Génération d'API Key
2. ✅ OPTIONS (capacités CalDAV)
3. ✅ PROPFIND (liste des calendriers)
4. ✅ GET /service-info (API JSON)
5. ✅ Rejet des clés invalides
6. ✅ Authorization: Bearer
7. ✅ Révocation de la clé

## Troubleshooting

### Erreur: "API Key manquante"

**Cause**: Le header n'est pas envoyé correctement.

**Solutions**:
1. Vérifier que le header `X-API-Key` est présent
2. Vérifier que votre serveur web ne filtre pas les headers personnalisés
3. Pour Apache, ajouter dans `.htaccess`:
   ```apache
   SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
   ```

### Erreur: "Clé API invalide"

**Cause**: La clé n'existe pas ou est mal formée.

**Solutions**:
1. Vérifier le format: `ag_live_` ou `ag_test_` + 64 caractères
2. Vérifier dans la base de données: `SELECT * FROM api_keys WHERE key_hash = SHA2('votre_clé', 256)`

### Erreur: "Clé API révoquée ou expirée"

**Cause**: La clé a été révoquée ou a dépassé sa date d'expiration.

**Solutions**:
1. Générer une nouvelle clé
2. Vérifier: `SELECT revoked_at, expires_at FROM api_keys WHERE id = ?`

### Erreur: "Rate limit dépassé" (HTTP 429)

**Cause**: Trop de requêtes en peu de temps.

**Solutions**:
1. Attendre que le compteur se réinitialise
2. Vérifier les headers de réponse:
   ```
   X-RateLimit-Remaining: 0
   X-RateLimit-Reset: 2024-11-07T10:31:00Z
   ```
3. Augmenter le rate limit pour cette clé

## Prochaines étapes (optionnel)

- [ ] Support Basic Auth avec API Key (pour compatibilité avec clients legacy)
- [ ] Proxy WebDAV pour clients ne supportant pas les headers personnalisés
- [ ] Génération automatique de fichiers de configuration pour clients
- [ ] Interface web pour gérer les API Keys CalDAV
- [ ] Support des calendriers publics (sans authentification)

## Conclusion

✅ **CalDAV fonctionne maintenant avec les API Keys**

Le système est prêt pour:
- Synchronisation avec clients CalDAV externes
- Intégrations machine-to-machine
- Scripts automatisés
- Applications mobiles/desktop

Pour toute question ou problème, consulter les logs ou exécuter le script de test.
