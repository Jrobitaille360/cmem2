# 📡 Endpoints Public

> Endpoints accessibles sans authentification

## 🚨 Changements de sécurité v1.3.0

**⚠️ BREAKING CHANGE** : Depuis la version 1.3.0, **toutes** les connexions nécessitent une API key valide.

### Impact sur les endpoints publics

- **`/users/login`** : API key maintenant **obligatoire**
- **Autres endpoints** : Restent accessibles sans authentification
- **Migration** : Les applications doivent intégrer une API key pour les connexions

### Obtention d'une API key

Les API keys sont désormais gérées exclusivement via les endpoints `/secret-admin/api-keys/*` avec authentification renforcée. Consultez le [Guide Secret-Admin](./API_KEYS_SECRET_ADMIN_GUIDE.md) pour plus de détails.

---

## GET `/`

**Description** : Obtenir des informations sur l'API

**Authentification** : ⭐ Non requise

**Réponse succès (200)** :

```json
{
  "success": true,
  "message": "API_info",
  "data": {
    "name": "Collective Memories API",
    "version": "1.3.0",
    "description": "API REST pour l'application de mémoires collectives",
    "status": "Opérationnelle",
    "server_time": "2025-09-10 17:56:34",
    "database": {
      "status": "Connectée",
      "charset": "utf8mb4"
    },
    "email": {
      "smtp": true,
      "server": "votre serveur:587",
      "security": "TLS"
    }
  }
}
```

**Réponses d'erreur** :

- `500` : Erreur serveur critique

---

## GET `/help`

**Description** : Fourni de l'aide sur les endpoints de l'API

**Authentification** : ⭐ Non requise

**Réponse succès (200)** :

```json
{
  "success": true,
  "message": "help",
  "data": {
    "endpoints": { /* Liste complète des endpoints disponibles */ },
    "authentication": "JWT Bearer Token + API Key (obligatoire v1.3.0)",
    "base_url": "https://votre site/cmem1_API/"
  }
}
```

**Réponses d'erreur** :

- `500` : Erreur serveur lors de la génération de l'aide

---

## GET `/health`

**Description** : Vérifier le statut de l'API

**Authentification** : ⭐ Non requise

**Réponse succès (200)** :

```json
{
  "success": true,
  "message": "health_status",
  "data": {
    "status": "OK",
    "message": "Connexion réussie avec API key",
    "timestamp": "2025-09-10 14:30:00",
    "version": "1.3.0",
    "database": "Connectée",
    "smtp": "Fonctionnel"
  }
}
```

**Réponses d'erreur** :

- `500` : Erreur serveur lors de la vérification du statut
- `503` : Service temporairement indisponible

---

## POST `/users/register`

**Description** : Créer un nouveau compte utilisateur

**Authentification** : ⭐ Non requise

**Données attendues** :

```json
{
  "name": "Jean Dupont",
  "email": "user@example.com",
  "password": "motdepasse123",
  "bio": "Historien passionné",
  "phone": "0600000002",
  "date_of_birth": "1990-02-15",
  "location": "Lyon"
}
```

**Validation** :

- `name` : requis, 2-255 caractères
- `email` : requis, email valide, unique
- `password` : requis, 6 caractères minimum
- `bio` : optionnel, texte libre
- `phone` : optionnel, numéro de téléphone
- `date_of_birth` : optionnel, format YYYY-MM-DD
- `location` : optionnel, lieu de résidence

**Réponse succès (201)** :

```json
{
  "success": true,
  "message": "Nouvel utilisateur créé. Un email de vérification a été envoyé.",
  "data": {
    "user": {
      "id": 1,
      "name": "Jean Dupont",
      "email": "user@example.com",
      "role": "UTILISATEUR",
      "email_verified": 0,
      "created_at": "2025-09-10 14:30:00"
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
  }
}
```

**Réponses d'erreur** :

- `400` : Données invalides
- `409` : Cet email est déjà utilisé, peut-être désactivé. Vous devez vous connecter ou le réactiver
- `500` : Erreur lors de la création de l'utilisateur

---

## POST `/users/login`

**Description** : Se connecter

**⚠️ IMPORTANT v1.3.0** : API key maintenant **obligatoire** pour tous les logins
**Note** : L'email doit être vérifié pour pouvoir se connecter
**Authentification** : ⭐ Non requise (mais API key obligatoire)

**Données attendues** :

```json
{
  "email": "user@example.com",
  "password": "motdepasse123",
  "api_key": "ak_live_..."
}
```

**Validation** :

- `email` : requis, email valide
- `password` : requis, 6 caractères minimum
- `api_key` : **requis depuis v1.3.0**, clé API valide

**Réponse succès (200)** :

```json
{
  "success": true,
  "message": "Connexion réussie",
  "data": {
    "user": {
      "id": 1,
      "name": "Jean Dupont",
      "email": "user@example.com",
      "role": "UTILISATEUR",
      "email_verified": 1,
      "last_login": "2025-09-10 14:30:00"
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
  }
}
```

**Réponses d'erreur** :

- `400` : Données de validation invalides ou API key manquante
- `401` : Identifiants invalides
- `403` : Compte désactivé, email non vérifié, ou API key invalide
- `410` : API key requise (migration v1.3.0)
- `500` : Erreur interne du serveur

**Exemple de réponse API key manquante (410)** :

```json
{
  "success": false,
  "error": "API_KEY_REQUIRED",
  "message": "Une API key est requise pour se connecter depuis la v1.3.0",
  "data": {
    "code": "API_KEY_REQUIRED",
    "migration_guide": "Consultez le guide de migration v1.3.0",
    "endpoints": {
      "secret_admin": "/secret-admin/api-keys/",
      "documentation": "/docs/API_KEYS_SECRET_ADMIN_GUIDE.md"
    },
    "version": "1.3.0"
  }
}
```

**Exemple de réponse API key invalide (403)** :

```json
{
  "success": false,
  "error": "API_KEY_INVALID",
  "message": "L'API key fournie est invalide ou expirée",
  "data": {
    "code": "API_KEY_INVALID",
    "api_key_prefix": "ak_live_...",
    "suggestions": [
      "Vérifier que l'API key est correcte",
      "Vérifier que l'API key n'est pas expirée",
      "Contacter l'administrateur pour une nouvelle API key"
    ]
  }
}
```

**Exemple de réponse email non vérifié (403)** :

```json
{
  "success": false,
  "error": "Email non vérifié",
  "data": {
    "code": "EMAIL_NOT_VERIFIED",
    "message": "Votre adresse email n'a pas encore été vérifiée. Veuillez vérifier votre boîte de réception.",
    "actions": {
      "resend_verification": {
        "endpoint": "/public/users/resend-verification",
        "method": "POST",
        "params": ["email"]
      },
      "verify_email": {
        "endpoint": "/public/users/verify-email",
        "method": "POST",
        "params": ["token"]
      }
    },
    "user_email": "user@example.com"
  }
}
```

---

## POST `/users/request-password-reset`

**Description** : Demander une réinitialisation de mot de passe (par email)

**Authentification** : ⭐ Non requise

**Données attendues** :

```json
{
  "email": "user@example.com"
}
```

**Validation** :

- `email` : requis, email valide

**Réponse succès (200)** :

```json
{
  "success": true,
  "message": "Si cet email existe, un lien de réinitialisation a été envoyé"
}
```

**Réponses d'erreur** :

- `400` : Données de validation invalides
- `404` : Utilisateur non trouvé
- `500` : Erreur lors de la génération du token ou de l'envoi de l'email

---

## POST `/users/reset-password`

**Description** : Changer le mot de passe avec un token

**Authentification** : ⭐ Non requise

**Données attendues** :

```json
{
  "token": "dsfélg...",
  "new_password": "nouveauMotDePasse123"
}
```

**Validation** :

- `token` : requis, token valide non expiré
- `new_password` : requis, 6 caractères minimum

**Réponse succès (200)** :

```json
{
  "success": true,
  "message": "Mot de passe changé avec succès"
}
```

**Réponses d'erreur** :

- `400` : Données de validation invalides
- `404` : Token non trouvé, invalide ou expiré
- `404` : Utilisateur non trouvé
- `500` : Erreur lors du changement de mot de passe

---

## POST `/users/resend-verification`

**Description** : Renvoyer l'email de vérification pour un compte non vérifié

**Authentification** : ⭐ Non requise

**Données attendues** :

```json
{
  "email": "user@example.com"
}
```

**Validation** :

- `email` : requis, email valide

**Réponse succès (200)** :

```json
{
  "success": true,
  "message": "Un nouvel email de vérification a été envoyé à votre adresse",
  "data": {
    "email": "user@example.com",
    "expires_in": "24 heures"
  }
}
```

**Réponses d'erreur** :

- `400` : Données de validation invalides ou email déjà vérifié
- `404` : Aucun compte associé à cette adresse email
- `500` : Erreur lors de l'envoi de l'email

---

## POST `/users/verify-email`

**Description** : Vérifier l'adresse email avec un token

**Authentification** : ⭐ Non requise

**Données attendues** :

```json
{
  "token": "ajsdfhkasdf"
}
```

**Validation** :

- `token` : requis, token valide

**Réponse succès (200)** :

```json
{
  "success": true,
  "message": "Email vérifié avec succès"
}
```

**Réponses d'erreur** :

- `404` : Utilisateur non trouvé ou token invalide
- `500` : Erreur lors de la vérification

---

## GET `/groups/public`

**Description** : Lister les groupes publics

**Authentification** : ⭐ Non requise

**Paramètres de requête** :

- `q` : terme recherché (optionnel, 2-255 caractères)
- `page` : numéro de page (optionnel, entier >= 1)
- `page_size` : taille de page (optionnel, entier 1-100)

**Réponse succès (200)** :

```json
{
  "success": true,
  "data": {
    "groups": [
      {
        "id": 1,
        "name": "Groupe Public",
        "description": "Description du groupe",
        "visibility": "public",
        "member_count": 15,
        "created_at": "2025-09-01 10:00:00"
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 45,
      "total_pages": 3
    }
  }
}
```

**Réponses d'erreur** :

- `400` : Paramètres de validation invalides
- `500` : Erreur serveur lors de la récupération

---
