# Guide de migration client — AuthGroups API v2.0.0

## Résumé

La version 2.0.0 remplace entièrement le système d'API keys par des **JWT Bearer tokens**.
Toutes les applications clientes doivent adapter leur méthode d'authentification.

---

## Changements cassants (breaking changes)

### 1. Suppression de l'en-tête `X-API-Key`

Avant (v1.x)

```http
GET /users/me
X-API-Key: ag_live_xxxxxxxxxxxxxxxx
```

Après (v2.0.0)

```http
GET /users/me
Authorization: Bearer eyJhbGciOiJIUzI1NiJ9...
```

---

### 2. Routes de connexion déplacées

| v1.x | v2.0.0 | Notes |
| ------ | -------- | ------- |
| `POST /users/login` | `POST /auth/login` | Body inchangé (email + password) |
| `POST /users/logout` | `POST /auth/logout` | Requiert `Authorization: Bearer` |
| *(inexistant)* | `POST /auth/send-code` | Nouveau : connexion par code OTP |
| *(inexistant)* | `POST /auth/verify-code` | Nouveau : vérifie le code OTP → JWT |
| *(inexistant)* | `POST /auth/refresh` | Nouveau : renouvelle le JWT via device token |

---

### 3. Réponse de `POST /auth/login` (anciennement `/users/login`)

**v1.x** — retournait notamment `api_key`

```json
{
  "data": {
    "user": { ... },
    "api_key": { "key": "ag_live_xxx", "scopes": [...] }
  }
}
```

**v2.0.0** — retourne un JWT

```json
{
  "data": {
    "token": "eyJhbGciOiJIUzI1NiJ9...",
    "token_type": "Bearer",
    "expires_at": "2026-04-06 14:00:00",
    "user": {
      "id": 1,
      "name": "Jean",
      "email": "jean@exemple.com",
      "role": "ADMINISTRATEUR"
    }
  }
}
```

---

### 4. Réponse de `POST /users/register`

**v1.x** — retournait une API key prête à l'emploi
**v2.0.0** — retourne uniquement les infos utilisateur + instructions

```json
{
  "data": {
    "user": { "id": 5, "name": "Marie", "email": "marie@exemple.com" },
    "next_steps": {
      "verify_email": "POST /users/verify-email avec le token reçu par email",
      "login": "POST /auth/login après vérification"
    },
    "auth_method": "jwt"
  }
}
```

> L'utilisateur doit vérifier son email AVANT de pouvoir se connecter.

---

### 5. Suppression des endpoints API keys (secret-admin)

Les routes suivantes ont été supprimées :

- `POST /secret-admin/api-keys`
- `GET /secret-admin/api-keys`
- `DELETE /secret-admin/api-keys/{id}`
- `POST /secret-admin/api-keys/{id}/regenerate`

---

## Flux d'authentification recommandés

### Connexion classique (email + mot de passe)

```txt
POST /auth/login
Body: { "email": "...", "password": "..." }

→ Stocker le token JWT (15 jours)
→ Ajouter à chaque requête : Authorization: Bearer {token}
```

### Connexion par code OTP (sans mot de passe)

```txt
1. POST /auth/send-code
   Body: { "email": "..." }
   → L'utilisateur reçoit un code à 6 chiffres par email (valide 15 min)

2. POST /auth/verify-code
   Body: { "email": "...", "code": "748291" }
   → Retourne le même JWT Bearer
```

### Renouvellement automatique sans re-login (device token)

Lors de la connexion, passer un `device_id` (UUID stable propre à l'appareil) :

```txt
POST /auth/login
Body: {
  "email": "...",
  "password": "...",
  "device_id": "550e8400-e29b-41d4-a716-446655440000",
  "device_name": "iPhone de Jean"
}

→ Réponse contient en plus :
  "device_token": "a3f8b2c1...",   // conserver de façon sécurisée
  "device_id": "550e8400-..."
```

Quand le JWT expire, renouveler sans credentials :

```txt
POST /auth/refresh
Body: { "device_id": "...", "device_token": "a3f8b2c1..." }
→ Nouveau JWT
```

---

## Checklist de migration

- [ ] Remplacer `X-API-Key: {key}` par `Authorization: Bearer {token}` dans toutes les requêtes
- [ ] Changer `POST /users/login` → `POST /auth/login`
- [ ] Changer `POST /users/logout` → `POST /auth/logout` (avec Bearer)
- [ ] Stocker le JWT (localStorage / SecureStorage) à la place de l'API key
- [ ] Implémenter la gestion d'expiration : intercepter les 401 et rediriger vers login
- [ ] (Optionnel) Implémenter le device token pour renouvellement automatique
- [ ] Supprimer tout code de création/gestion d'API keys côté client
- [ ] Mettre à jour la réponse de `/users/register` (plus de `api_key` dans la réponse)

---

## Codes d'erreur

| Code | Signification |
| ------ | -------------- |
| 400 | Données invalides (champ manquant, format incorrect) |
| 401 | JWT absent, invalide ou expiré |
| 403 | Accès refusé (email non vérifié, permissions insuffisantes) |
| 404 | Ressource introuvable |
| 500 | Erreur serveur |

Pour les erreurs 401 sur les endpoints protégés, le client doit présenter à nouveau le JWT valide ou rediriger l'utilisateur vers la page de connexion.

---

## Migration base de données

Exécuter **une seule fois** sur la base distante :

```txt
src/auth_groups/docs/MIGRATION_JWT.sql
```

Crée les tables `otp_codes` et `device_tokens`, et rend `api_key_id` nullable dans `user_sessions`.

---

## Exemple d'implémentation (JavaScript)

```javascript
// Connexion
const res = await fetch('/auth/login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ email, password })
});
const { data } = await res.json();
localStorage.setItem('jwt', data.token);

// Requête authentifiée
const token = localStorage.getItem('jwt');
const profile = await fetch('/users/me', {
  headers: { 'Authorization': `Bearer ${token}` }
});

// Logout
await fetch('/auth/logout', {
  method: 'POST',
  headers: { 'Authorization': `Bearer ${token}` }
});
localStorage.removeItem('jwt');
```
