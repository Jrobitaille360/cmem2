# Architecture du Système API Keys

## 🏗️ Vue d'ensemble

```
┌─────────────────────────────────────────────────────────────┐
│                        CLIENT REQUEST                        │
│   (Browser, Mobile App, Server Script, CLI Tool, etc.)      │
└──────────────────┬──────────────────────────────────────────┘
                   │
                   ▼
          ┌────────────────────┐
          │  Authentication    │
          │    Headers         │
          ├────────────────────┤
          │ JWT Token:         │
          │  Authorization:    │
          │  Bearer eyJhbGc... │
          │        OR          │
          │ API Key:           │
          │  X-API-Key:        │
          │  ag_live_a1b2...   │
          └────────┬───────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────────┐
│                      index.php (Entry)                        │
└───────────────────────┬──────────────────────────────────────┘
                        │
                        ▼
┌──────────────────────────────────────────────────────────────┐
│                    Router.php                                 │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ Route Handlers:                                        │  │
│  │  - UserRouteHandler                                    │  │
│  │  - GroupRouteHandler                                   │  │
│  │  - FileRouteHandler                                    │  │
│  │  - ApiKeyRouteHandler ◄── NEW!                        │  │
│  │  - TagRouteHandler                                     │  │
│  │  - StatsRouteHandler                                   │  │
│  │  - PublicRouteHandler                                  │  │
│  └────────────────────────────────────────────────────────┘  │
└───────────────────────┬──────────────────────────────────────┘
                        │
         ┌──────────────┴──────────────┐
         │                             │
         ▼                             ▼
┌─────────────────┐          ┌─────────────────────┐
│  JWT Endpoints  │          │  API Key Endpoints  │
│  (Users, etc.)  │          │   (/api-keys)       │
└────────┬────────┘          └──────────┬──────────┘
         │                              │
         ▼                              ▼
┌──────────────────┐          ┌─────────────────────────┐
│ JWTAuthMiddleware│          │ ApiKeyRouteHandler      │
│                  │          │  ┌───────────────────┐  │
│ validate JWT     │          │  │ Routes:           │  │
│ get user         │          │  │ POST   /api-keys  │  │
│                  │          │  │ GET    /api-keys  │  │
└────────┬─────────┘          │  │ GET    /{id}      │  │
         │                    │  │ DELETE /{id}      │  │
         │                    │  │ POST   /{id}/regen│  │
         │                    │  └─────────┬─────────┘  │
         │                    └────────────┼────────────┘
         │                                 │
         └─────────────┬───────────────────┘
                       │
                       ▼
          ┌────────────────────────┐
          │ ApiKeyController       │
          │  ┌──────────────────┐  │
          │  │ create()         │  │
          │  │ list()           │  │
          │  │ get()            │  │
          │  │ revoke()         │  │
          │  │ regenerate()     │  │
          │  └────────┬─────────┘  │
          └───────────┼────────────┘
                      │
                      ▼
          ┌────────────────────────┐
          │ ApiKey Model           │
          │  ┌──────────────────┐  │
          │  │ generate()       │  │
          │  │ validate()       │  │
          │  │ revoke()         │  │
          │  │ checkRateLimit() │  │
          │  │ getStats()       │  │
          │  └────────┬─────────┘  │
          └───────────┼────────────┘
                      │
                      ▼
          ┌────────────────────────┐
          │   MySQL Database       │
          │  ┌──────────────────┐  │
          │  │ Table: api_keys  │  │
          │  │ - id             │  │
          │  │ - user_id        │  │
          │  │ - name           │  │
          │  │ - key_hash       │  │
          │  │ - scopes         │  │
          │  │ - rate_limit_*   │  │
          │  │ - expires_at     │  │
          │  │ - revoked_at     │  │
          │  │ - total_requests │  │
          │  │ - last_used_*    │  │
          │  └──────────────────┘  │
          └────────────────────────┘
```

---

## 🔄 Flux d'authentification

### Scénario A : Authentification par JWT (utilisateur)

```
User Login
    │
    ├─► POST /users/login
    │   {email, password}
    │
    ├─► UserController validates
    │
    ├─► JWT Token generated
    │   eyJhbGciOiJIUzI1NiIs...
    │
    └─► User stores token
        │
        └─► Subsequent requests:
            GET /groups
            Header: Authorization: Bearer eyJhbGci...
            │
            ├─► JWTAuthMiddleware validates
            │
            ├─► Extract user_id from token
            │
            └─► Request proceeds
```

### Scénario B : Création d'API Key

```
User has JWT Token
    │
    ├─► POST /api-keys
    │   Header: Authorization: Bearer <JWT>
    │   Body: {name, scopes, environment}
    │
    ├─► ApiKeyRouteHandler receives
    │
    ├─► JWTAuthMiddleware validates JWT
    │
    ├─► ApiKeyController.create()
    │   │
    │   ├─► Validate input
    │   │
    │   ├─► ApiKey::generate()
    │   │   │
    │   │   ├─► random_bytes(32) → 64 hex chars
    │   │   │
    │   │   ├─► Format: ag_{env}_{random}
    │   │   │   production: ag_live_a1b2c3...
    │   │   │   test:       ag_test_x1y2z3...
    │   │   │
    │   │   ├─► Hash: SHA-256(full_key)
    │   │   │
    │   │   ├─► Save to DB:
    │   │   │   - key_hash (SHA-256)
    │   │   │   - last_4 (visible)
    │   │   │   - scopes (JSON)
    │   │   │   - rate limits
    │   │   │
    │   │   └─► Return full key ONCE
    │   │
    │   └─► Response:
    │       {
    │         "key": "ag_live_a1b2c3...",  ◄── ONLY TIME SHOWN
    │         "id": 123,
    │         "scopes": ["read", "write"]
    │       }
    │
    └─► User MUST save key immediately
```

### Scénario C : Authentification par API Key

```
External Service/Script
    │
    ├─► GET /groups
    │   Header: X-API-Key: ag_live_a1b2c3...
    │
    ├─► Router → GroupRouteHandler
    │
    ├─► ApiKeyAuthMiddleware.authenticateFlexible()
    │   │
    │   ├─► Check for JWT? NO
    │   │
    │   ├─► Check for API Key? YES
    │   │   │
    │   │   ├─► Extract from header:
    │   │   │   - X-API-Key: ag_live_a1b2c3...
    │   │   │   OR
    │   │   │   - Authorization: Bearer ag_live_...
    │   │   │
    │   │   ├─► ApiKey::validate()
    │   │   │   │
    │   │   │   ├─► Hash input: SHA-256(ag_live_a1b2c3...)
    │   │   │   │
    │   │   │   ├─► Query DB by key_hash
    │   │   │   │
    │   │   │   ├─► Checks:
    │   │   │   │   ✓ Key exists?
    │   │   │   │   ✓ NOT revoked? (revoked_at IS NULL)
    │   │   │   │   ✓ NOT expired? (expires_at > NOW())
    │   │   │   │   ✓ Has required scope?
    │   │   │   │
    │   │   │   ├─► Rate limit check:
    │   │   │   │   ✓ Requests/minute < limit?
    │   │   │   │   ✓ Requests/hour < limit?
    │   │   │   │
    │   │   │   ├─► Update stats:
    │   │   │   │   - total_requests++
    │   │   │   │   - last_used_at = NOW()
    │   │   │   │   - last_used_ip = $_SERVER['REMOTE_ADDR']
    │   │   │   │
    │   │   │   └─► Return user_id
    │   │   │
    │   │   └─► Set $user context
    │   │
    │   └─► Request proceeds with user context
    │
    └─► GroupController executes
        │
        └─► Response with rate limit headers:
            X-RateLimit-Remaining: 45
            X-RateLimit-Reset: 2025-10-07 15:32:00
```

---

## 🗄️ Schéma de base de données

### Table : api_keys

```sql
┌──────────────────────────────────────────────────────────────┐
│                        api_keys                              │
├────────────────────┬─────────────────┬──────────────────────┤
│ Field              │ Type            │ Description           │
├────────────────────┼─────────────────┼──────────────────────┤
│ id                 │ INT UNSIGNED PK │ ID unique             │
│ user_id            │ INT UNSIGNED FK │ → users.id            │
│ name               │ VARCHAR(255)    │ Nom descriptif        │
│ key_prefix         │ VARCHAR(10)     │ ag_live / ag_test     │
│ key_hash           │ VARCHAR(255) UK │ SHA-256 de la clé     │
│ last_4             │ VARCHAR(4)      │ 4 derniers chars      │
│ scopes             │ JSON            │ ["read", "write"]     │
│ environment        │ ENUM            │ production / test     │
│ rate_limit_per_min │ INT UNSIGNED    │ Limite par minute     │
│ rate_limit_per_hour│ INT UNSIGNED    │ Limite par heure      │
│ total_requests     │ INT UNSIGNED    │ Compteur total        │
│ last_used_at       │ DATETIME        │ Dernière utilisation  │
│ last_used_ip       │ VARCHAR(45)     │ Dernière IP           │
│ expires_at         │ DATETIME        │ Date expiration       │
│ revoked_at         │ DATETIME        │ Date révocation       │
│ revoked_reason     │ VARCHAR(255)    │ Raison révocation     │
│ metadata           │ JSON            │ Métadonnées custom    │
│ notes              │ TEXT            │ Notes internes        │
│ created_at         │ DATETIME        │ Date création         │
│ updated_at         │ DATETIME        │ Dernière màj          │
└────────────────────┴─────────────────┴──────────────────────┘

Indexes:
  - PRIMARY KEY (id)
  - UNIQUE KEY (key_hash)
  - INDEX (user_id)
  - INDEX (key_prefix)
  - INDEX (environment)
  - INDEX (expires_at)
  - INDEX (revoked_at)
  - INDEX (created_at)
  - INDEX (last_used_at)
  
Foreign Keys:
  - user_id → users(id) ON DELETE CASCADE
```

---

## 📊 Cycle de vie d'une clé

```
  ┌─────────────┐
  │   CREATED   │  POST /api-keys
  │   (Active)  │  ← User creates key with JWT
  └──────┬──────┘
         │
         │ First use
         ▼
  ┌─────────────┐
  │    ACTIVE   │  Request avec X-API-Key header
  │  (In Use)   │  ← Stats updated on each use
  └──────┬──────┘
         │
         ├─────────────────┐
         │                 │
         │ Requests        │ Rate limit
         │ within limits   │ exceeded
         │                 │
         ▼                 ▼
  ┌─────────────┐   ┌──────────────┐
  │  VALIDATED  │   │ RATE_LIMITED │  HTTP 429
  │   (200 OK)  │   │  (Temporary) │  Too Many Requests
  └──────┬──────┘   └──────┬───────┘
         │                 │
         │                 │ Window resets
         │                 │
         │ ◄───────────────┘
         │
         ├──────────────────┬──────────────┐
         │                  │              │
         │ expires_at       │ Manual       │ continues
         │ reached          │ revocation   │ usage
         │                  │              │
         ▼                  ▼              │
  ┌─────────────┐    ┌──────────────┐    │
  │   EXPIRED   │    │   REVOKED    │    │
  │  (Auto)     │    │  (Manual)    │    │
  │  401 Error  │    │  401 Error   │    │
  └─────────────┘    └──────┬───────┘    │
         │                  │             │
         │                  │             │
         │                  ▼             │
         │          ┌──────────────┐      │
         │          │ REGENERATED  │ ◄────┘
         │          │  New key ID  │  POST /{id}/regenerate
         │          └──────┬───────┘
         │                 │
         │                 │
         ▼                 ▼
  ┌──────────────────────────┐
  │   CLEANUP_EXPIRED()      │  Automated procedure
  │   Scheduled job          │  Removes old expired keys
  └──────────────────────────┘
```

---

## 🎭 Scopes et permissions

```
┌─────────────────────────────────────────────────────────────┐
│                      SCOPE HIERARCHY                         │
└─────────────────────────────────────────────────────────────┘

  SCOPE: *          ┌──────────────────────────────────┐
  (All)             │  ALL PERMISSIONS                 │
                    │  - Read                          │
                    │  - Write (Create, Update)        │
                    │  - Delete                        │
                    │  - Admin endpoints               │
                    └──────────────────────────────────┘
                                  │
         ┌───────────────┬────────┴────────┬───────────────┐
         │               │                 │               │
         ▼               ▼                 ▼               ▼
  ┌───────────┐   ┌───────────┐   ┌──────────────┐  ┌──────────┐
  │   READ    │   │   WRITE   │   │    DELETE    │  │  ADMIN   │
  │           │   │           │   │              │  │          │
  │ GET only  │   │ READ +    │   │ WRITE +      │  │ All +    │
  │           │   │ POST      │   │ DELETE       │  │ Admin    │
  │           │   │ PUT       │   │              │  │ features │
  └───────────┘   └───────────┘   └──────────────┘  └──────────┘

Example combinations:
  ["read"]                  → GET only
  ["read", "write"]         → GET, POST, PUT
  ["read", "write", "delete"] → All except admin
  ["admin"]                 → Admin features only
  ["*"]                     → Everything
```

### Validation logic

```php
function hasScope($required, $apiKeyScopes) {
    // Wildcard (*) grants all
    if (in_array('*', $apiKeyScopes)) {
        return true;
    }
    
    // Check specific scope
    return in_array($required, $apiKeyScopes);
}

// Usage:
if (!hasScope('write', $apiKey['scopes'])) {
    return Response::error('Insufficient scope', null, 403);
}
```

---

## ⚡ Rate Limiting Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    RATE LIMIT SYSTEM                         │
└─────────────────────────────────────────────────────────────┘

API Key: ag_live_a1b2c3...
Limits:  60/min, 3600/hour

Request arrives
    │
    ▼
┌──────────────────────┐
│ Extract API Key      │
│ Get limits from DB:  │
│  - rate_limit_per_min│
│  - rate_limit_per_hr │
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐      ┌─────────────────────┐
│ Check Minute Window  │──────►│ In-Memory Counter   │
│ Current requests?    │      │ (Redis in prod)     │
│ < 60?                │      │                     │
└──────────┬───────────┘      │ Key: api_key_{id}_m │
           │                  │ TTL: 60 seconds     │
           │ YES              │ Value: 45           │
           ▼                  └─────────────────────┘
┌──────────────────────┐      ┌─────────────────────┐
│ Check Hour Window    │──────►│ In-Memory Counter   │
│ Current requests?    │      │                     │
│ < 3600?              │      │ Key: api_key_{id}_h │
└──────────┬───────────┘      │ TTL: 3600 seconds   │
           │                  │ Value: 1247         │
           │ YES              └─────────────────────┘
           ▼
┌──────────────────────┐
│ Increment Counters   │
│ - Minute: 45 → 46    │
│ - Hour: 1247 → 1248  │
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│ Add Response Headers │
│ X-RateLimit-Remaining│
│ X-RateLimit-Reset    │
└──────────┬───────────┘
           │
           ▼
     ┌─────────┐
     │ PROCEED │
     └─────────┘

IF LIMIT EXCEEDED:
           │ NO
           ▼
┌──────────────────────┐
│ HTTP 429             │
│ Too Many Requests    │
│                      │
│ Retry-After: 42      │
│ (seconds until reset)│
└──────────────────────┘
```

---

## 🔒 Sécurité et Hashing

```
┌─────────────────────────────────────────────────────────────┐
│              SECURITY: KEY GENERATION & STORAGE              │
└─────────────────────────────────────────────────────────────┘

GENERATION:
    random_bytes(32)
         │
         ▼
    64 hex characters
    a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8
         │
         ▼
    Add prefix based on environment
    ag_live_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8
         │
         ├─► SHOWN TO USER ONCE
         │   (Copy now or lose forever!)
         │
         └─► NEVER STORED IN PLAIN TEXT
                │
                ▼
         ┌─────────────────────────────┐
         │ Storage in Database:        │
         ├─────────────────────────────┤
         │ key_hash:                   │
         │   SHA256(full_key)          │
         │   = f3e8d92a1b4c...         │
         │                             │
         │ last_4:                     │
         │   substr(full_key, -4)      │
         │   = b8c9                    │
         │                             │
         │ ❌ full_key: NEVER STORED   │
         └─────────────────────────────┘

VALIDATION:
    User sends: ag_live_a1b2c3d4e5f6...
         │
         ▼
    hash_input = SHA256(ag_live_a1b2c3d4...)
    = f3e8d92a1b4c...
         │
         ▼
    SELECT * FROM api_keys 
    WHERE key_hash = 'f3e8d92a1b4c...'
         │
         ├─► FOUND? Continue checks
         │
         └─► NOT FOUND? 401 Unauthorized

DISPLAY (List view):
    ag_live_****b8c9
           ↑    ↑
           │    └─ last_4 from DB
           └────── Hidden with ****
```

---

## 📁 Structure des fichiers

```
cmem2_API/
│
├── src/auth_groups/
│   ├── Models/
│   │   └── ApiKey.php ◄───────────── Business logic
│   │
│   ├── Middleware/
│   │   └── ApiKeyAuthMiddleware.php ◄ Authentication
│   │
│   ├── Controllers/
│   │   └── ApiKeyController.php ◄──── CRUD operations
│   │
│   └── Routing/
│       ├── Router.php ◄────────────── Main router (modified)
│       └── RouteHandlers/
│           └── ApiKeyRouteHandler.php ◄ Endpoint handler
│
├── docs/
│   ├── create_table_api_keys.sql ◄──── Database schema
│   ├── ENDPOINTS_API_KEYS.md ◄───────── API documentation
│   ├── API_KEYS_IMPLEMENTATION.md ◄─── Technical guide
│   └── (other docs updated)
│
├── tests/
│   └── api_keys/
│       ├── test_api_keys_basic.php ◄── Test suite
│       └── README.md ◄──────────────── Test documentation
│
├── README.md ◄──────────────────────── Updated (v1.3.0)
├── CHANGELOG.md ◄───────────────────── Updated (v1.3.0)
└── API_KEYS_COMPLETE.md ◄───────────── Completion summary
```

---

## 🎯 Architecture Decisions

### 1. Pourquoi SHA-256 et pas bcrypt ?
- **SHA-256** : Hash rapide, idéal pour tokens (vitesse importante)
- **bcrypt** : Hash lent intentionnellement (mots de passe seulement)
- API keys doivent être validées rapidement sur chaque requête

### 2. Pourquoi 2 préfixes (ag_live, ag_test) ?
- **Séparation des environnements**
- Empêche utilisation accidentelle de clés test en prod
- Facilite le debugging (visuel immédiat)

### 3. Pourquoi afficher la clé une seule fois ?
- **Sécurité** : Force le stockage sécurisé immédiat
- Standard de l'industrie (AWS, GitHub, Stripe)
- Empêche copies multiples non contrôlées

### 4. Pourquoi scopes et pas rôles ?
- **Granularité** : Une clé = permissions spécifiques
- **Principe du moindre privilège**
- Flexibilité (même user peut avoir clés différentes)

### 5. Pourquoi rate limiting par clé ?
- **Protection contre abus**
- Isolement (une clé compromise n'affecte pas les autres)
- Différenciation (clés premium vs standard)

---

**AuthGroups API v1.3.0** - Architecture API Keys  
**Document technique** - Pour développeurs  
**Date** : 7 octobre 2025
