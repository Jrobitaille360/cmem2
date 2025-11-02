# Refactorisation du Système d'Authentification

## Date: 1 novembre 2025

## Objectif

Nettoyer et simplifier la séquence login/logout en supprimant la logique JWT inutile et en centralisant l'authentification basée sur API Key + sessions.

## Règles Appliquées

### 1. Login

- **Requis**: `api_key`, `email`, `password`
- **Action**: Crée une entrée dans la table `user_sessions`
- **Endpoint**: `POST /users/login`

### 2. Utilisation des Endpoints Authentifiés

- **Requis**: Session active dans `user_sessions`
- **Vérification**: Via `AuthService::authenticate()` qui vérifie:
  - API Key valide
  - Session active pour l'utilisateur et l'API Key

### 3. Logout

- **Action**: Met fin à la session dans `user_sessions`
- **Endpoint**: `POST /users/logout`
- **Comportement**:
  - Si API Key fournie: termine la session spécifique
  - Sinon: termine toutes les sessions de l'utilisateur

## Modifications Effectuées

### Fichiers Modifiés

#### 1. `UserManagerController.php`

**Changements:**

- ✅ Méthode `loginAuthenticate()` renommée en `authenticate()`
- ✅ Suppression du logout automatique avant login
- ✅ Suppression de la méthode `silentLogout()`
- ✅ Simplification de la méthode `logout()`
- ✅ Login crée maintenant une session via `UserSessionService::createSession()`
- ✅ Logout termine la session via `UserSessionService::endSession()`

#### 2. `UserController.php`

**Changements:**

- ✅ Méthode `authenticate()` mise à jour pour appeler `userManagerController->authenticate()`

#### 3. `AuthService.php`

**Changements:**

- ✅ Suppression de la méthode `validateToken()` (JWT)
- ✅ Suppression de la méthode `generateToken()` (JWT)
- ✅ Suppression de l'import `ValidTokenService`
- ✅ Méthode `authenticate()` vérifie maintenant la session active via `UserSessionService::hasActiveSession()`
- ✅ Documentation mise à jour

#### 4. `ApiKeyAuthMiddleware.php`

**Changements:**

- ✅ Méthode `authenticateFlexible()` simplifiée - ne gère plus JWT
- ✅ Suppression du fallback JWT
- ✅ Ne retourne que des données d'authentification par API Key

#### 5. `BaseRouteHandler.php`

**Changements:**

- ✅ Méthode `updateUserActivity()` corrigée pour utiliser `$user['user_id']`
- ✅ Gestion d'erreur améliorée

#### 6. `PublicRouteHandler.php`

**Changements:**

- ✅ Documentation mise à jour (suppression référence JWT)
- ✅ Méthode `handleLoginWithApiKey()` simplifiée
- ✅ Section authentification dans `/help` mise à jour

#### 7. `SecretAdminRouteHandler.php`

**Changements:**

- ✅ Commentaires mis à jour (suppression référence JWT)

## Architecture Finale

```text
┌─────────────────────────────────────────────────────────────┐
│                         LOGIN FLOW                           │
├─────────────────────────────────────────────────────────────┤
│ 1. Client envoie: API_KEY + email + password                │
│ 2. Validation API Key (ApiKeyAuthMiddleware)                │
│ 3. Validation email/password (User::authenticate)           │
│ 4. Création session (UserSessionService::createSession)     │
│ 5. Retour: session_id + user info                           │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                    AUTHENTICATED REQUEST                     │
├─────────────────────────────────────────────────────────────┤
│ 1. Client envoie: API_KEY dans header                       │
│ 2. BaseRouteHandler vérifie auth (requiresAuth=true)        │
│ 3. AuthService::authenticate() vérifie:                     │
│    - API Key valide                                          │
│    - Session active (UserSessionService::hasActiveSession)  │
│ 4. Mise à jour activité (UserSessionService::updateActivity)│
│ 5. Exécution de la requête                                  │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                        LOGOUT FLOW                           │
├─────────────────────────────────────────────────────────────┤
│ 1. Client envoie: API_KEY dans header                       │
│ 2. Identification session via API Key                       │
│ 3. Fin de session (UserSessionService::endSession)          │
│ 4. Retour: nombre de sessions terminées                     │
└─────────────────────────────────────────────────────────────┘
```

## Points Clés

### ✅ Ce qui a été Retiré

- Logique JWT (génération, validation, tokens)
- ValidTokenService (remplacé par UserSessionService)
- Logout automatique avant login
- Authentification hybride JWT/API Key

### ✅ Ce qui Reste

- Authentification par API Key uniquement
- Gestion de sessions via `user_sessions` table
- Vérification de session active pour les endpoints protégés
- Rate limiting sur API Keys
- Logs de sécurité

### ✅ Centralisation

Toutes les méthodes `authenticate()` sont maintenant centralisées:

- **UserController**: Point d'entrée principal
- **UserManagerController**: Implémentation de login
- **AuthService**: Validation d'authentification pour endpoints protégés
- **ApiKeyAuthMiddleware**: Validation des API Keys

## Base de Données

### Table Utilisée: `user_sessions`

```sql
Colonnes principales:
- id (PK)
- user_id (FK vers users)
- api_key_id (FK vers api_keys)
- login_at (timestamp)
- logout_at (timestamp, nullable)
- last_activity_at (timestamp)
- expires_at (timestamp)
- is_active (boolean)
- ip_address
- user_agent
```

## Tests Recommandés

1. ✅ Test de login avec API Key valide + credentials valides
2. ✅ Test de login sans API Key (doit échouer)
3. ✅ Test d'accès à endpoint protégé sans session (doit échouer)
4. ✅ Test d'accès à endpoint protégé avec session active (doit réussir)
5. ✅ Test de logout (vérifier que session est terminée)
6. ✅ Test d'accès après logout (doit échouer)

## Compatibilité Arrière

⚠️ **BREAKING CHANGES**:

- Les anciens tokens JWT ne sont plus acceptés
- Tous les endpoints nécessitent maintenant une API Key
- Le login nécessite une session active pour utiliser les endpoints

## Fichiers à Supprimer (optionnel)

Si ValidTokenService n'est plus utilisé ailleurs:

- `src/auth_groups/Services/ValidTokenService.php`

## Notes Importantes

1. **Sécurité**: L'authentification est maintenant plus stricte avec la vérification obligatoire de session
2. **Performance**: Moins de vérifications JWT = meilleure performance
3. **Simplicité**: Un seul mécanisme d'authentification = code plus simple
4. **Traçabilité**: Les sessions dans `user_sessions` offrent un meilleur audit trail

## Prochaines Étapes Recommandées

1. Tester le système avec les cas d'usage réels
2. Mettre à jour la documentation API publique
3. Informer les clients de l'API des changements
4. Supprimer ValidTokenService si non utilisé ailleurs
5. Mettre à jour les tests automatisés
