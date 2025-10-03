# API Collective Memories - Documentation

## 📋 Informations générales

**Nom** : Collective Memories API  
**Version** : 1.1.0  
**Description** : API REST pour l'application de mémoires collectives. Gestion utilisateurs, groupes, mémoires, fichiers, tags. Authentification JWT. SMTP intégré.  
**Base URL** : https://votre site/cmem1_API/  
**Documentation** : https://votre site/api/docs  
**Format** : JSON  
**Encodage** : UTF-8  
**Authentification** : JWT Bearer Token  
**CORS** : Ouvert (Access-Control-Allow-Origin: *)  

### 🔐 Types d'endpoints
- ⭐ **PUBLIC** : Accessible sans authentification
- 🔒 **USER** : Nécessite une authentification utilisateur
- 🔒 **ADMIN** : Nécessite des privilèges administrateur

### 📊 Statut système
✅ **API** : Opérationnelle  
✅ **Base de données** : Connectée (charset: utf8mb4)  
✅ **SMTP** : Intégré (votre serveur:587, TLS)  
✅ **Tests** : 100% de réussite sur tous les endpoints  
🕐 **Heure serveur** : Dynamique (ex: 2025-08-04 17:56:34)  

### 🔄 Dernières améliorations (Septembre 2025)
- ✅ **API REST complète** : Tous les endpoints avec méthodes HTTP appropriées (GET, POST, PUT, DELETE)
- ✅ **Sections validation complètes** : Règles de validation détaillées pour tous les endpoints
- ✅ **Error responses standardisées** : Codes d'erreur cohérents 
- ✅ **Conformité API_ENDPOINTS.json** : Documentation synchronisée avec la spécification officielle
- ✅ **Messages d'erreur en français** : Amélioration de l'expérience utilisateur
- ✅ **Sistema de tags avancé** : Gestion complète des tags avec associations multiples

## 📡 En-têtes HTTP

### Requêtes
```
Content-Type: application/json
Authorization: Bearer <token> (pour les routes protégées)
```

### Réponses
```
Content-Type: application/json; charset=utf-8
Access-Control-Allow-Origin: *
```

## 🎯 Exigences Frontend

- **Authentification** : JWT Bearer tokens dans l'en-tête Authorization  
- **Content-Type** : application/json pour les données POST/PUT  
- **CORS** : CORS ouvert (Access-Control-Allow-Origin: *)  
- **Uploads** : multipart/form-data pour les uploads (images, docs, audio, vidéo)  

## 📏 Limites système

### Pagination
- **Maximum** : 100 éléments par page

### Uploads
- **Images** : 5 MB maximum  
- **Documents** : 10 MB maximum  
- **Audio** : 20 MB maximum  
- **Vidéo** : 50 MB maximum  
- **Avatars** : 2 MB maximum  

### Rate Limiting
- **Limite** : 100 requêtes/heure/IP

### Types de fichiers supportés
- **Images** : JPEG, PNG, GIF, WebP  
- **Documents** : PDF, TXT, DOC, DOCX  
- **Audio** : MP3, WAV, OGG  
- **Vidéo** : MP4, AVI, MOV  

## ✅ Validation des données

L'API effectue une validation stricte des données d'entrée pour tous les endpoints qui en nécessitent :

### Types de validation
- **Champs requis** : Vérification de présence des données obligatoires
- **Format email** : Validation RFC pour les adresses email  
- **Longueur de chaînes** : Respect des limites min/max de caractères
- **Formats de dates** : Format YYYY-MM-DD strictement appliqué
- **Tokens** : Vérification de validité et d'expiration
- **Mots de passe** : Minimum 6 caractères requis

### Réponses de validation
En cas d'erreur de validation, l'API retourne un code **400** avec le détail des erreurs :
```json
{
  "success": false,
  "message": "Données de validation invalides", 
  "errors": {
    "field": ["Message de validation spécifique"]
  }
}
```

## 📧 Système d'emails

L'API intègre un système d'emails automatique avec SMTP sécurisé :

### Types d'emails automatiques
- **Email de bienvenue** : Envoyé automatiquement lors de l'inscription avec token de vérification  
- **Réinitialisation de mot de passe** : Avec token sécurisé  
- **Vérification d'email** : Token pour activer le compte  
- **Invitation de groupe** : Email avec lien d'acceptation  
- **Notification nouvelle mémoire** : Notification aux membres du groupe (si applicable)  

### Configuration SMTP
- **Serveur** : votre serveur:587  
- **Sécurité** : TLS encryption  
- **Templates** : HTML responsive  
- **Mode développement** : Emails loggés uniquement  
- **Serveur** : votre serveur:587  
- **Sécurité** : TLS encryption  
- **Templates** : HTML responsive  
- **Mode développement** : Emails loggés uniquement  

---

# 📡 Endpoints Public

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
    "version": "1.1.0",
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
    "authentication": "JWT Bearer Token",
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
    "message": "API Collective Memories opérationnelle",
    "timestamp": "2025-09-10 14:30:00",
    "version": "1.1.0",
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

**Note** : L'email doit être vérifié pour pouvoir se connecter
**Authentification** : ⭐ Non requise

**Données attendues** :
```json
{
  "email": "user@example.com",
  "password": "motdepasse123"
}
```

**Validation** :
- `email` : requis, email valide
- `password` : requis, 6 caractères minimum

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
- `400` : Données de validation invalides
- `401` : Identifiants invalides
- `403` : Compte désactivé ou email non vérifié (avec options d'action)
- `500` : Erreur interne du serveur

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

# 👥 Endpoints Users

## POST `/users/avatar`
**Description** : Mettre à jour son propre avatar

**Authentification** : 🔒 Utilisateur

**Données attendues** :
- Fichier : `avatar` (multipart/form-data)

**Validation** :
- `avatar` : requis, fichier image (JPEG, PNG, GIF), taille max 2MB

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Avatar mis à jour avec succès",
  "data": {
    "avatar_url": "/uploads/avatars/1_avatar.jpg",
    "user_id": 1
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `400` : Aucun fichier avatar uploadé
- `400` : Fichier avatar invalide
- `500` : Erreur lors de l'enregistrement du fichier avatar

---

## POST `/users/{id}/avatar`
**Description** : Mettre à jour l'avatar d'un autre utilisateur

**Authentification** : 🔒 Admin

**Données attendues** :
- Fichier : `avatar` (multipart/form-data)

**Validation** :
- `avatar` : requis, fichier image (JPEG, PNG, GIF), taille max 2MB
- `id` : requis, ID utilisateur valide

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Avatar mis à jour avec succès",
  "data": {
    "avatar_url": "/uploads/avatars/1_avatar.jpg",
    "user_id": 1
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `400` : Aucun fichier avatar uploadé
- `400` : Fichier avatar invalide
- `404` : Utilisateur non trouvé
- `500` : Erreur lors de l'enregistrement du fichier avatar

---

## PUT `/users/password`
**Description** : Changer son propre mot de passe

**Authentification** : 🔒 Utilisateur

**Données attendues** :
```json
{
  "current_password": "motDePasseActuel",
  "new_password": "nouveauMotDePasse123"
}
```

**Validation** :
- `current_password` : requis, mot de passe actuel
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
- `401` : Mot de passe actuel incorrect
- `404` : Utilisateur non trouvé
- `500` : Erreur lors du changement de mot de passe

---

## PUT `/users/{id}/password`
**Description** : Changer le mot de passe d'un utilisateur par un administrateur

**Authentification** : 🔒 Admin

**Données attendues** :
```json
{
  "new_password": "nouveauMotDePasse123"
}
```

**Validation** :
- `new_password` : requis, 6 caractères minimum
- `id` : requis, ID utilisateur valide

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Mot de passe changé avec succès"
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Utilisateur non trouvé
- `500` : Erreur lors du changement de mot de passe

---

## POST `/users/logout`
**Description** : Se déconnecter

**Authentification** : 🔒 Utilisateur

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Déconnexion réussie"
}
```

**Réponses d'erreur** :
- `401` : Utilisateur non authentifié
- `500` : Erreur serveur lors de la déconnexion

---

## GET `/users`
**Description** : Obtenir la liste de tous les utilisateurs

**Authentification** : 🔒 Admin

**Paramètres de requête** :
- `page` : numéro de page (optionnel, entier >= 1)
- `limite` : nombre d'éléments par page (optionnel, entier 1-100)

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Liste des utilisateurs",
  "data": {
    "users": [
      {
        "id": 1,
        "name": "Jean Dupont",
        "email": "user@example.com",
        "role": "UTILISATEUR",
        "email_verified": 1,
        "last_login": "2025-09-10 10:00:00",
        "created_at": "2025-01-01 10:00:00",
        "deleted_at": null
      }
    ],
    "total_users": 202,
    "page": 2,
    "limit": 30,
    "total_pages": 7
  }
}
```

**Réponses d'erreur** :
- `401` : Utilisateur non authentifié
- `403` : Accès non autorisé
- `500` : Erreur serveur lors de la récupération des utilisateurs

---

## GET `/users/me`
**Description** : Récupérer son propre profil

**Authentification** : 🔒 Utilisateur

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Profil récupéré",
  "data": {
    "id": 1,
    "name": "Jean Dupont",
    "email": "user@example.com",
    "role": "UTILISATEUR",
    "profile_image": "/uploads/avatars/1.jpg",
    "bio": "Historien passionné",
    "phone": "0600000002",
    "date_of_birth": "1990-02-15",
    "location": "Lyon",
    "email_verified": 1,
    "last_login": "2025-09-10 10:00:00",
    "created_at": "2025-01-01 10:00:00",
    "updated_at": "2025-09-10 10:00:00",
    "deleted_at": null
  }
}
```

**Réponses d'erreur** :
- `401` : Utilisateur non authentifié
- `404` : Utilisateur non trouvé
- `500` : Erreur serveur lors de la récupération de l'utilisateur

---

## GET `/users/{id}`
**Description** : Récupérer le profil d'un utilisateur

**Authentification** : 🔒 Admin

**Validation** :
- `id` : requis, ID utilisateur valide

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Profil récupéré",
  "data": {
    "id": 1,
    "name": "Jean Dupont",
    "email": "user@example.com",
    "role": "UTILISATEUR",
    "profile_image": "/uploads/avatars/1.jpg",
    "bio": "Historien passionné",
    "phone": "0600000002",
    "date_of_birth": "1990-02-15",
    "location": "Lyon",
    "email_verified": 1,
    "last_login": "2025-09-10 10:00:00",
    "created_at": "2025-01-01 10:00:00",
    "updated_at": "2025-09-10 10:00:00",
    "deleted_at": null
  }
}
```

**Réponses d'erreur** :
- `401` : Utilisateur non authentifié
- `403` : Accès non autorisé
- `404` : Utilisateur non trouvé
- `500` : Erreur serveur lors de la récupération de l'utilisateur

---

## PUT `/users/me`
**Description** : Modifier son propre profil

**Authentification** : 🔒 Utilisateur

**Données attendues** :
```json
{
  "name": "Jean Martin NOUVEAU",
  "email": "nouveau@email.com",
  "bio": "Nouvelle biographie...",
  "phone": "0611223344",
  "date_of_birth": "1990-01-15",
  "location": "Paris"
}
```

**Validation** :
- `name` : optionnel, 2-255 caractères
- `email` : optionnel, email valide, unique
- `bio` : optionnel, texte libre
- `phone` : optionnel, numéro de téléphone
- `date_of_birth` : optionnel, format YYYY-MM-DD
- `location` : optionnel, lieu de résidence

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Profil mis à jour avec succès",
  "data": {
    "id": 1,
    "name": "Jean Martin NOUVEAU",
    "email": "nouveau@email.com",
    "role": "UTILISATEUR",
    "profile_image": "avatars/123_avatar.jpg",
    "bio": "Nouvelle biographie...",
    "phone": "0611223344",
    "date_of_birth": "1990-01-15",
    "location": "Paris",
    "email_verified": 1,
    "last_login": "2025-09-10 10:00:00",
    "created_at": "2025-01-01 10:00:00",
    "updated_at": "2025-09-10 10:00:00",
    "deleted_at": null
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `404` : Utilisateur non trouvé
- `409` : Cet email est déjà utilisé
- `500` : Erreur lors de la mise à jour

---

## PUT `/users/{id}`
**Description** : Modifier le profil d'un utilisateur par l'administrateur

**Authentification** : 🔒 Admin

**Données attendues** :
```json
{
  "name": "Jean Martin NOUVEAU",
  "email": "nouveau@email.com",
  "bio": "Nouvelle biographie...",
  "phone": "0611223344",
  "date_of_birth": "1990-01-15",
  "location": "Paris"
}
```

**Validation** :
- `name` : optionnel, 2-255 caractères
- `email` : optionnel, email valide, unique
- `bio` : optionnel, texte libre
- `phone` : optionnel, numéro de téléphone
- `date_of_birth` : optionnel, format YYYY-MM-DD
- `location` : optionnel, lieu de résidence
- `id` : requis, ID utilisateur valide

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Profil mis à jour avec succès",
  "data": {
    "id": 1,
    "name": "Jean Martin NOUVEAU",
    "email": "nouveau@email.com",
    "role": "UTILISATEUR",
    "profile_image": "avatars/123_avatar.jpg",
    "bio": "Nouvelle biographie...",
    "phone": "0611223344",
    "date_of_birth": "1990-01-15",
    "location": "Paris",
    "email_verified": 1,
    "last_login": "2025-09-10 10:00:00",
    "created_at": "2025-01-01 10:00:00",
    "updated_at": "2025-09-10 10:00:00",
    "deleted_at": null
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Utilisateur non trouvé
- `409` : Cet email est déjà utilisé
- `500` : Erreur lors de la mise à jour

---

## DELETE `/users/me`
**Description** : Supprimer son propre compte (soft delete)

**Authentification** : 🔒 Utilisateur

**Données attendues** :
```json
{
  "password": "motDePasseActuel"
}
```

**Validation** :
- `password` : requis, mot de passe actuel

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Compte supprimé avec succès"
}
```

**Réponses d'erreur** :
- `400` : Mot de passe requis
- `401` : Mot de passe incorrect ou non authentifié
- `404` : Utilisateur non trouvé
- `500` : Erreur lors de la suppression

---

## DELETE `/users/{id}`
**Description** : Supprimer un compte (soft delete par défaut, ou hard delete)

**Authentification** : 🔒 Admin

**Données attendues** :
```json
{
  "force_delete": true
}
```

**Validation** :
- `force_delete` : optionnel, booléen pour suppression définitive
- `id` : requis, ID utilisateur valide

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Compte supprimé avec succès"
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Utilisateur non trouvé
- `500` : Erreur lors de la suppression

---

## POST `/users/{id}/restore`
**Description** : Restaurer un compte supprimé (soft)

**Authentification** : 🔒 Admin

**Validation** :
- `id` : requis, ID utilisateur valide

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Compte restauré avec succès"
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Utilisateur non trouvé ou non supprimé
- `500` : Erreur lors de la restauration

---

# 💾 Endpoints Memories

## GET `/memories`
**Description** : Lister les mémoires (publiques si non authentifié, toutes si authentifié)

**Authentification** : ⭐ Optionnelle

**Paramètres de requête** :
- `page` : numéro de page (optionnel, défaut 1)
- `page_size` : taille de page (optionnel, défaut 20)
- `search` : terme de recherche (optionnel)
- `group_id` : filtrer par groupe (optionnel)
- `is_private` : filtrer par visibilité (optionnel)
- `date_from` : date début (optionnel, format YYYY-MM-DD)
- `date_to` : date fin (optionnel, format YYYY-MM-DD)

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "memories": [
      {
        "id": 1,
        "title": "Ma première mémoire",
        "content": "Contenu abrégé...",
        "visibility": "public",
        "location": "Paris, France",
        "date": "2025-07-20",
        "user_id": 1,
        "creator_name": "Jean Dupont",
        "tags_count": 2,
        "files_count": 1,
        "created_at": "2025-07-27 14:00:00"
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

## POST `/memories`
**Description** : Créer une nouvelle mémoire

**Authentification** : 🔒 Utilisateur

**Données attendues** :
```json
{
  "title": "Titre de la mémoire",
  "content": "Contenu détaillé de la mémoire...",
  "visibility": "private",
  "location": "Paris, France",
  "latitude": 48.8566,
  "longitude": 2.3522,
  "date": "2025-07-27"
}
```

**Validation** :
- `title` : requis, 3-255 caractères
- `content` : optionnel, texte libre
- `visibility` : optionnel, par défaut 'private'
- `location` : optionnel, lieu de résidence
- `latitude` : optionnel, format décimal
- `longitude` : optionnel, format décimal
- `date` : optionnel, format YYYY-MM-DD

**Réponse succès (201)** :
```json
{
  "success": true,
  "message": "Mémoire créée avec succès",
  "data": {
    "id": 2,
    "title": "Titre de la mémoire",
    "content": "Contenu détaillé...",
    "visibility": "private",
    "location": "Paris, France",
    "latitude": 48.8566,
    "longitude": 2.3522,
    "date": "2025-07-27",
    "user_id": 1,
    "created_at": "2025-09-10 14:30:00",
    "updated_at": "2025-09-10 14:30:00"
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `401` : Authentification requise
- `500` : Erreur lors de la création

---

## GET `/memories/{id}`
**Description** : Récupérer une mémoire spécifique

**Authentification** : 🔒 Utilisateur

**Validation** :
- `id` : requis, ID mémoire valide

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "id": 1,
    "title": "Ma première mémoire",
    "content": "Contenu complet de la mémoire avec tous les détails...",
    "visibility": "public",
    "location": "Paris, France",
    "latitude": 48.8566,
    "longitude": 2.3522,
    "date": "2025-07-20",
    "user_id": 1,
    "creator_name": "Jean Dupont",
    "elements": [
      {
        "id": 1,
        "type": "image",
        "title": "Photo du lieu",
        "url": "/uploads/memories/image1.jpg"
      }
    ],
    "tags": [
      {
        "id": 1,
        "name": "Histoire",
        "color": "#3498db"
      }
    ],
    "created_at": "2025-07-27 14:00:00",
    "updated_at": "2025-07-27 14:00:00"
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé (mémoire privée)
- `404` : Mémoire non trouvée
- `500` : Erreur serveur

---

## PUT `/memories/{id}`
**Description** : Modifier une mémoire

**Authentification** : 🔒 Propriétaire ou Admin

**Données attendues** :
```json
{
  "title": "Nouveau titre",
  "content": "Nouveau contenu...",
  "is_private": true,
  "location": "Lyon, France",
  "latitude": 45.764,
  "longitude": 4.8357
}
```

**Validation** :
- `title` : optionnel, 3-255 caractères
- `content` : optionnel, texte libre
- `is_private` : optionnel, booléen
- `location` : optionnel, texte libre
- `latitude` : optionnel, format décimal
- `longitude` : optionnel, format décimal

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Mémoire mise à jour avec succès",
  "data": {
    "id": 1,
    "title": "Nouveau titre",
    "content": "Nouveau contenu...",
    "visibility": "private",
    "location": "Lyon, France",
    "latitude": 45.764,
    "longitude": 4.8357,
    "updated_at": "2025-09-10 14:35:00"
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Mémoire non trouvée
- `500` : Erreur lors de la mise à jour

---

## DELETE `/memories/{id}`
**Description** : Supprimer une mémoire

**Authentification** : 🔒 Propriétaire ou Admin

**Validation** :
- `id` : requis, ID mémoire valide

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Mémoire supprimée avec succès"
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Mémoire non trouvée
- `500` : Erreur lors de la suppression

---

## GET `/memories/my`
**Description** : Récupérer mes mémoires

**Authentification** : 🔒 Utilisateur

**Paramètres de requête** :
- `page` : numéro de page (optionnel, défaut 1)
- `page_size` : taille de page (optionnel, défaut 20)

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "memories": [
      {
        "id": 1,
        "title": "Ma première mémoire",
        "content": "Contenu abrégé...",
        "visibility": "private",
        "location": "Paris, France",
        "date": "2025-07-20",
        "tags_count": 2,
        "files_count": 1,
        "created_at": "2025-07-27 14:00:00"
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 12,
      "total_pages": 1
    }
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `500` : Erreur serveur

---

## GET `/memories/search`
**Description** : Rechercher dans les mémoires

**Authentification** : ⭐ Optionnelle

**Paramètres de requête** :
- `q` : terme recherché (requis)
- `page` : numéro de page (optionnel, défaut 1)
- `page_size` : taille de page (optionnel, défaut 20)

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "memories": [
      {
        "id": 1,
        "title": "Mémoire contenant le terme",
        "content": "Extrait avec le terme recherché en surbrillance...",
        "visibility": "public",
        "score": 0.95,
        "created_at": "2025-07-27 14:00:00"
      }
    ],
    "search_term": "terme recherché",
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 3,
      "total_pages": 1
    }
  }
}
```

**Réponses d'erreur** :
- `400` : Terme de recherche requis
- `500` : Erreur serveur

---

## POST `/memories/{id}/upload`
**Description** : Upload d'un fichier pour une mémoire

**Authentification** : 🔒 Propriétaire

**Données attendues** :
- Fichier : `file` (multipart/form-data)

**Validation** :
- `file` : requis, fichier valide selon les types autorisés
- `id` : requis, ID mémoire valide

**Réponse succès (201)** :
```json
{
  "success": true,
  "message": "Document uploadé et associé",
  "data": {
    "file_id": 15,
    "filename": "document.pdf",
    "file_url": "/uploads/memories/15/document.pdf",
    "file_type": "document",
    "file_size": 2048576,
    "memory_id": 1
  }
}
```

**Réponses d'erreur** :
- `400` : Aucun fichier valide uploadé
- `401` : Authentification requise
- `403` : Accès non autorisé (pas propriétaire de la mémoire)
- `404` : Mémoire non trouvée
- `413` : Fichier trop volumineux
- `415` : Type de fichier non supporté
- `500` : Erreur lors de l'upload
}
```

---

## POST `/memories/search`
**Description** : Rechercher dans les mémoires

**Authentification** : Optionnelle

**Données attendues** :
```json
{
  "q": "terme recherché",
  "page": 1,
  "page_size": 20
}
```

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {}
}
```

---

## POST `/memories/upload/{id}`
**Description** : Upload d'un fichier pour une mémoire

**Authentification** : Propriétaire de la mémoire

**Données attendues** :
- Fichier : `file` (multipart/form-data)

**Réponse succès (201)** :
```json
{
  "success": true,
  "message": "Document uploadé et associé",
  "data": {}
}
```

---

# 🧩 Endpoints Elements

## POST `/elements`
**Description** : Créer un élément (texte, image, audio, vidéo, document, etc.)

**Authentification** : 🔒 Utilisateur

**Données attendues** :
```json
{
  "media_type": "text",
  "content": "Texte ou URL du fichier",
  "title": "Titre de l'élément",
  "description": "Description optionnelle"
}
```

**Validation** :
- `media_type` : requis, text, image, audio, video, document, gpx, ical
- `title` : requis, 2-255 caractères
- `content` : optionnel, 5000 caractères max
- `description` : optionnel, texte libre

**Réponse succès (201)** :
```json
{
  "success": true,
  "message": "Élément créé avec succès",
  "data": {
    "id": 1,
    "media_type": "text",
    "title": "Titre de l'élément",
    "content": "Texte ou URL du fichier",
    "description": "Description optionnelle",
    "user_id": 1,
    "created_at": "2025-09-10 14:30:00",
    "updated_at": "2025-09-10 14:30:00"
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `401` : Authentification requise
- `500` : Erreur lors de la création

---

## GET `/elements`
**Description** : Lister/rechercher les éléments

**Authentification** : 🔒 Utilisateur

**Paramètres de requête** :
- `media_type` : filtrer par type (optionnel)
- `q` : terme de recherche (optionnel)
- `page` : numéro de page (optionnel, défaut 1)
- `page_size` : taille de page (optionnel, défaut 20)

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "elements": [
      {
        "id": 1,
        "media_type": "text",
        "title": "Titre de l'élément",
        "content": "Contenu abrégé...",
        "description": "Description",
        "user_id": 1,
        "creator_name": "Jean Dupont",
        "created_at": "2025-09-10 14:30:00"
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 15,
      "total_pages": 1
    }
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `500` : Erreur serveur

---

## GET `/elements/{id}`
**Description** : Récupérer un élément

**Authentification** : 🔒 Utilisateur

**Validation** :
- `id` : requis, ID élément valide

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "id": 1,
    "media_type": "text",
    "title": "Titre de l'élément",
    "content": "Contenu complet de l'élément...",
    "description": "Description détaillée",
    "user_id": 1,
    "creator_name": "Jean Dupont",
    "created_at": "2025-09-10 14:30:00",
    "updated_at": "2025-09-10 14:30:00"
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Élément non trouvé
- `500` : Erreur serveur

---

## PUT `/elements/{id}`
**Description** : Modifier un élément

**Authentification** : 🔒 Propriétaire ou Admin

**Données attendues** :
```json
{
  "title": "Nouveau titre",
  "content": "Nouveau contenu",
  "description": "Nouvelle description"
}
```

**Validation** :
- `title` : optionnel, 2-255 caractères
- `content` : optionnel, 5000 caractères max
- `description` : optionnel, texte libre

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Élément mis à jour avec succès",
  "data": {
    "id": 1,
    "media_type": "text",
    "title": "Nouveau titre",
    "content": "Nouveau contenu",
    "description": "Nouvelle description",
    "updated_at": "2025-09-10 14:35:00"
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Élément non trouvé
- `500` : Erreur lors de la mise à jour

---

## DELETE `/elements/{id}`
**Description** : Supprimer un élément

**Authentification** : 🔒 Propriétaire ou Admin

**Validation** :
- `id` : requis, ID élément valide

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Élément supprimé avec succès"
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Élément non trouvé
- `500` : Erreur lors de la suppression
}
```

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Élément mis à jour avec succès",
  "data": {}
}
```

---

## POST `/elements/delete/{id}`
**Description** : Supprimer un élément

**Authentification** : Propriétaire ou admin

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Élément supprimé avec succès"
}
```

---

# 🏷️ Endpoints Tags

## POST `/tags`
**Description** : Créer un tag

**Authentification** : 🔒 Utilisateur

**Données attendues** :
```json
{
  "name": "Histoire",
  "table_associate": "memories",
  "color": "#3498db"
}
```

**Validation** :
- `name` : requis, 1-100 caractères
- `table_associate` : optionnel, in('groups','memories','elements','files','all')
- `color` : optionnel, format #RRGGBB

**Réponse succès (201)** :
```json
{
  "success": true,
  "message": "Tag créé avec succès",
  "data": {
    "id": 1,
    "name": "Histoire",
    "table_associate": "memories",
    "color": "#3498db",
    "tag_owner": 1,
    "created_at": "2025-09-10 10:00:00",
    "updated_at": "2025-09-10 10:00:00",
    "deleted_at": null
  }
}
```

**Réponses d'erreur** :
- `400` : Données invalides
- `401` : Utilisateur non authentifié
- `500` : Erreur lors de la création

---

## GET `/tags`
**Description** : Lister/rechercher les tags

**Authentification** : 🔒 Utilisateur

**Paramètres de requête** :
- `q` : terme de recherche (optionnel)
- `table_associate` : filtrer par table (optionnel, in('groups','memories','elements','files','all'))
- `page` : numéro de page (optionnel, >= 1)
- `limit` : nombre d'éléments (optionnel, 1-50)

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Résultats de recherche",
  "data": {
    "tags": [
      {
        "id": 1,
        "name": "Histoire",
        "table_associate": "memories",
        "color": "#3498db",
        "tag_owner": 1,
        "usage_count": 5,
        "created_at": "2025-09-10 10:00:00"
      }
    ],
    "search_term": "Histoire",
    "table_associate": "memories",
    "page": 1,
    "limit": 20,
    "total": 5
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `400` : Paramètres de validation invalides
- `500` : Erreur serveur
- `table_associate` : optionnel, in('groups','memories','elements','files','all')
- `page` : optionnel, numéro de page >= 1
- `limit` : optionnel, nombre d'éléments 1-50

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Résultats de recherche",
  "data": {
    "tags": [],
    "search_term": "Histoire",
    "table_associate": "memories",
    "page": 1,
    "limit": 20
  }
}
```

---

## POST `/tags/get/{id}`
**Description** : Détail d'un tag

**Authentification** : Requise (propriétaire du tag ou admin)

**Validation** :
- `id` : requis, ID du tag numérique

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Détails du tag récupérés",
  "data": {
    "id": 1,
    "name": "Histoire",
    "table_associate": "memories",
    "color": "#3498db",
    "tag_owner": 1,
    "usage_count": 5,
    "created_at": "2025-08-26 10:00:00",
    "updated_at": "2025-08-26 10:00:00",
    "deleted_at": null
  }
}
```

**Réponses d'erreur** :
- `403` : Accès non autorisé
- `404` : Tag non trouvé

---

## POST `/tags/update/{id}`
**Description** : Modifier un tag

**Authentification** : Requise (propriétaire du tag ou admin)

**Données attendues** :
```json
{
  "name": "Nouveau nom",
  "table_associate": "memories",
  "color": "#3498db"
}
```

**Validation** :
- `id` : requis, ID du tag numérique
- `name` : optionnel, 1-100 caractères
- `table_associate` : optionnel, in('groups','memories','elements','files','all')
- `color` : optionnel, format #RRGGBB

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Tag modifié avec succès",
  "data": {
    "id": 1,
    "name": "Nouveau nom",
    "table_associate": "memories",
    "color": "#3498db",
    "tag_owner": 1,
    "created_at": "2025-08-26 10:00:00",
    "updated_at": "2025-08-26 10:30:00",
    "deleted_at": null
  }
}
```

---

## POST `/tags/delete/{id}`
**Description** : Supprimer un tag (soft delete)

**Authentification** : Requise (propriétaire du tag ou admin)

**Validation** :
- `id` : requis, ID du tag numérique

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Tag supprimé avec succès"
}
```

**Réponses d'erreur** :
- `409` : Impossible de supprimer ce tag car il est encore utilisé
  ```json
  {
    "success": false,
    "message": "Impossible de supprimer ce tag car il est encore utilisé",
    "data": {
      "usage_count": 3
    }
  }
  ```

---

## POST `/tags/restore/{id}`
**Description** : Restaurer un tag supprimé

**Authentification** : Requise (propriétaire du tag ou admin)

**Validation** :
- `id` : requis, ID du tag numérique

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Tag restauré avec succès"
}
```

**Réponses d'erreur** :
- `400` : Ce tag n'est pas supprimé
- `404` : Tag non trouvé

---

## POST `/tags/search`
**Description** : Rechercher des tags

**Authentification** : Requise

**Données attendues** :
```json
{
  "q": "terme recherché",
  "table_associate": "memories",
  "page": 1,
  "limit": 20
}
```

**Validation** :
- `q` : requis, terme de recherche
- `table_associate` : optionnel, in('groups','memories','elements','files','all')
- `page` : optionnel, numéro de page >= 1
- `limit` : optionnel, nombre d'éléments 1-20

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Résultats de recherche",
  "data": {
    "tags": [],
    "search_term": "Histoire",
    "table_associate": "memories",
    "page": 1,
    "limit": 20
  }
}
```

---

## POST `/tags/my-tags`
**Description** : Mes tags

**Authentification** : Requise

**Données attendues** :
```json
{
  "page": 1,
  "limit": 50
}
```

**Validation** :
- `page` : optionnel, numéro de page >= 1
- `limit` : optionnel, nombre d'éléments 1-50

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Liste des tags récupérée",
  "data": {
    "tags": [],
    "page": 1,
    "limit": 50,
    "user_id": 1
  }
}
```

---

## POST `/tags/by-table/{table_associate}`
**Description** : Tags par table associée

**Authentification** : Requise

**Données attendues** :
```json
{
  "page": 1,
  "limit": 50
}
```

**Validation** :
- `table_associate` : requis, in('groups','memories','elements','files','all')
- `page` : optionnel, numéro de page >= 1
- `limit` : optionnel, nombre d'éléments 1-50

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Tags récupérés pour memories",
  "data": {
    "tags": [],
    "table_associate": "memories",
    "page": 1,
    "limit": 50
  }
}
```

**Réponses d'erreur** :
- `400` : Table associée invalide

---

## POST `/tags/most-used`
**Description** : Tags les plus utilisés

**Authentification** : Requise

**Données attendues** :
```json
{
  "table_associate": "memories",
  "limit": 10
}
```

**Validation** :
- `table_associate` : optionnel, in('groups','memories','elements','files','all'), défaut 'memories'
- `limit` : optionnel, nombre d'éléments 1-50, défaut 10

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Tags les plus utilisés",
  "data": {
    "tags": [
      {
        "id": 1,
        "name": "Histoire",
        "table_associate": "memories",
        "color": "#3498db",
        "tag_owner": 1,
        "usage_count": 15,
        "created_at": "2025-08-26 10:00:00"
      }
    ],
    "table_associate": "memories",
    "limit": 10
  }
}
```

---

## POST `/tags/get-or-create`
**Description** : Obtenir ou créer un tag

**Authentification** : Requise

**Données attendues** :
```json
{
  "name": "Nouveau tag",
  "table_associate": "memories",
  "color": "#3498db"
}
```

**Validation** :
- `name` : requis, 1-100 caractères
- `table_associate` : optionnel, in('groups','memories','elements','files','all'), défaut 'memories'
- `color` : optionnel, format #RRGGBB, défaut '#3498db'

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Tag récupéré ou créé",
  "data": {
    "id": 1,
    "name": "Nouveau tag",
    "table_associate": "memories",
    "color": "#3498db",
    "tag_owner": 1,
    "created_at": "2025-08-26 10:00:00",
    "updated_at": "2025-08-26 10:00:00",
    "deleted_at": null
  }
}
```

---

## POST `/tags/user/{user_id}`
**Description** : Tags d'un utilisateur spécifique (admin seulement)

**Authentification** : Requise (admin)

**Données attendues** :
```json
{
  "page": 1,
  "limit": 50
}
```

**Validation** :
- `user_id` : requis, ID utilisateur numérique
- `page` : optionnel, numéro de page >= 1
- `limit` : optionnel, nombre d'éléments 1-50

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Liste des tags récupérée",
  "data": {
    "tags": [],
    "page": 1,
    "limit": 50,
    "user_id": 2
  }
}
```

**Réponses d'erreur** :
- `400` : ID utilisateur doit être numérique
- `403` : Accès non autorisé

---

---
---

## GET `/tags/{id}`
**Description** : Détail d'un tag

**Authentification** : 🔒 Utilisateur

**Validation** :
- `id` : requis, ID du tag numérique

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Détails du tag récupérés",
  "data": {
    "id": 1,
    "name": "Histoire",
    "table_associate": "memories",
    "color": "#3498db",
    "tag_owner": 1,
    "usage_count": 5,
    "created_at": "2025-09-10 10:00:00",
    "updated_at": "2025-09-10 10:00:00",
    "deleted_at": null
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Tag non trouvé
- `500` : Erreur serveur

---

## PUT `/tags/{id}`
**Description** : Modifier un tag

**Authentification** : 🔒 Propriétaire ou Admin

**Données attendues** :
```json
{
  "name": "Nouveau nom",
  "table_associate": "memories",
  "color": "#3498db"
}
```

**Validation** :
- `id` : requis, ID du tag numérique
- `name` : optionnel, 1-100 caractères
- `table_associate` : optionnel, in('groups','memories','elements','files','all')
- `color` : optionnel, format #RRGGBB

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Tag modifié avec succès",
  "data": {
    "id": 1,
    "name": "Nouveau nom",
    "table_associate": "memories",
    "color": "#3498db",
    "tag_owner": 1,
    "updated_at": "2025-09-10 10:30:00"
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Tag non trouvé
- `500` : Erreur lors de la mise à jour

---

## DELETE `/tags/{id}`
**Description** : Supprimer un tag (soft delete)

**Authentification** : 🔒 Propriétaire ou Admin

**Données attendues** :
```json
{
  "force_delete": true
}
```

**Validation** :
- `force_delete` : optionnel, booléen pour suppression définitive
- `id` : requis, ID du tag numérique

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Tag supprimé avec succès"
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Tag non trouvé
- `409` : Impossible de supprimer ce tag car il est encore utilisé
- `500` : Erreur lors de la suppression

---

## POST `/tags/{id}/restore`
**Description** : Restaurer un tag supprimé

**Authentification** : 🔒 Propriétaire ou Admin

**Validation** :
- `id` : requis, ID du tag numérique

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Tag restauré avec succès"
}
```

**Réponses d'erreur** :
- `400` : Ce tag n'est pas supprimé
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Tag non trouvé
- `500` : Erreur lors de la restauration

---

## GET `/tags/my-tags`
**Description** : Mes tags

**Authentification** : 🔒 Utilisateur

**Paramètres de requête** :
- `page` : numéro de page (optionnel, >= 1)
- `limit` : nombre d'éléments (optionnel, 1-50)

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Liste des tags récupérée",
  "data": {
    "tags": [
      {
        "id": 1,
        "name": "Histoire",
        "table_associate": "memories",
        "color": "#3498db",
        "usage_count": 5,
        "created_at": "2025-09-10 10:00:00"
      }
    ],
    "page": 1,
    "limit": 50,
    "user_id": 1,
    "total": 12
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `500` : Erreur serveur

---

## GET `/tags/by-table/{table_associate}`
**Description** : Tags par table associée

**Authentification** : 🔒 Utilisateur

**Validation** :
- `table_associate` : requis, in('groups','memories','elements','files','all')

**Paramètres de requête** :
- `page` : numéro de page (optionnel, >= 1)
- `limit` : nombre d'éléments (optionnel, 1-50)

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Tags récupérés pour memories",
  "data": {
    "tags": [
      {
        "id": 1,
        "name": "Histoire",
        "table_associate": "memories",
        "color": "#3498db",
        "usage_count": 5
      }
    ],
    "table_associate": "memories",
    "page": 1,
    "limit": 50,
    "total": 8
  }
}
```

**Réponses d'erreur** :
- `400` : Table associée invalide
- `401` : Authentification requise
- `500` : Erreur serveur

---

## GET `/tags/most-used`
**Description** : Tags les plus utilisés

**Authentification** : 🔒 Utilisateur

**Paramètres de requête** :
- `table_associate` : filtrer par table (optionnel, défaut 'memories')
- `limit` : nombre d'éléments (optionnel, 1-50, défaut 10)

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Tags les plus utilisés",
  "data": {
    "tags": [
      {
        "id": 1,
        "name": "Histoire",
        "table_associate": "memories",
        "color": "#3498db",
        "tag_owner": 1,
        "usage_count": 15,
        "created_at": "2025-09-10 10:00:00"
      }
    ],
    "table_associate": "memories",
    "limit": 10
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `500` : Erreur serveur

---

## POST `/tags/get-or-create`
**Description** : Obtenir ou créer un tag

**Authentification** : 🔒 Utilisateur

**Données attendues** :
```json
{
  "name": "Nouveau tag",
  "table_associate": "memories",
  "color": "#3498db"
}
```

**Validation** :
- `name` : requis, 1-100 caractères
- `table_associate` : optionnel, in('groups','memories','elements','files','all'), défaut 'memories'
- `color` : optionnel, format #RRGGBB, défaut '#3498db'

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Tag récupéré ou créé",
  "data": {
    "id": 1,
    "name": "Nouveau tag",
    "table_associate": "memories",
    "color": "#3498db",
    "tag_owner": 1,
    "created_at": "2025-09-10 10:00:00",
    "updated_at": "2025-09-10 10:00:00",
    "deleted_at": null
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `401` : Authentification requise
- `500` : Erreur lors de la création

---

## GET `/tags/user/{user_id}`
**Description** : Tags d'un utilisateur spécifique (admin seulement)

**Authentification** : 🔒 Admin

**Validation** :
- `user_id` : requis, ID utilisateur numérique

**Paramètres de requête** :
- `page` : numéro de page (optionnel, >= 1)
- `limit` : nombre d'éléments (optionnel, 1-50)

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Liste des tags récupérée",
  "data": {
    "tags": [
      {
        "id": 1,
        "name": "Histoire",
        "table_associate": "memories",
        "color": "#3498db",
        "usage_count": 5
      }
    ],
    "page": 1,
    "limit": 50,
    "user_id": 2,
    "total": 8
  }
}
```

**Réponses d'erreur** :
- `400` : ID utilisateur doit être numérique
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Utilisateur non trouvé
- `500` : Erreur serveur

---

## PUT `/tags/{tagId}/{item_id}`
**Description** : Associer ou dissocier un tag à un élément (mémoire, élément, fichier, groupe)

**Authentification** : 🔒 Utilisateur

**Données attendues** :
```json
{
  "table_associate": "memories"
}
```

**Validation** :
- `table_associate` : requis, in('groups','memories','elements','files')
- `tagId` : requis, ID de tag numérique, accessible par l'utilisateur
- `item_id` : requis, ID d'élément numérique, accessible par l'utilisateur

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Tag associé ou dissocié avec succès",
  "data": {
    "tag_id": 1,
    "item_id": 5,
    "table_associate": "memories",
    "action": "associated", // ou "dissociated"
    "association_count": 3
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `401` : Authentification requise
- `403` : Accès non autorisé à l'élément ou au tag
- `404` : Tag ou élément non trouvé
- `500` : Erreur lors de l'association

---

# 📁 Endpoints Files

## POST `/files`
**Description** : Upload d'un fichier générique

**Authentification** : 🔒 Utilisateur

**Données attendues** :
- Fichier : `file` (multipart/form-data)
- `description` : Description du fichier (optionnel)

**Validation** :
- `file` : requis, fichier valide selon les types autorisés
- `description` : optionnel, texte libre
- **Limites de taille** : Images: 5MB, Documents: 10MB, Audio: 20MB, Vidéo: 50MB
- **Types autorisés** : JPEG, PNG, GIF, WebP, PDF, TXT, DOC, DOCX, MP3, WAV, OGG, MP4, AVI, MOV

**Réponse succès (201)** :
```json
{
  "success": true,
  "message": "Fichier uploadé avec succès",
  "data": {
    "id": 15,
    "original_filename": "document.pdf",
    "file_url": "/uploads/files/15/document.pdf",
    "file_type": "document",
    "file_size": 2048576,
    "mime_type": "application/pdf",
    "description": "Description du fichier",
    "user_id": 1,
    "created_at": "2025-09-10 14:30:00"
  }
}
```

**Réponses d'erreur** :
- `400` : Aucun fichier valide uploadé
- `401` : Authentification requise
- `413` : Fichier trop volumineux
- `415` : Type de fichier non supporté
- `500` : Erreur lors de l'upload

---

## GET `/files/{id}`
**Description** : Télécharger un fichier

**Authentification** : 🔒 Utilisateur

**Validation** :
- `id` : requis, ID du fichier numérique

**Réponse succès (200)** :
Retourne le fichier binaire avec les en-têtes appropriés :
```
Content-Type: application/pdf (exemple)
Content-Disposition: attachment; filename="document.pdf"
Content-Length: 2048576
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Fichier non trouvé
- `500` : Erreur lors de la récupération

---

## GET `/files/{id}/info`
**Description** : Obtenir des informations sur un fichier

**Authentification** : 🔒 Utilisateur

**Validation** :
- `id` : requis, ID du fichier numérique

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "id": 15,
    "original_filename": "document.pdf",
    "file_url": "/uploads/files/15/document.pdf",
    "file_type": "document",
    "file_size": 2048576,
    "mime_type": "application/pdf",
    "description": "Description du fichier",
    "user_id": 1,
    "owner_name": "Jean Dupont",
    "created_at": "2025-09-10 14:30:00",
    "updated_at": "2025-09-10 14:30:00",
    "deleted_at": null
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Fichier non trouvé
- `500` : Erreur serveur

---

## DELETE `/files/{id}`
**Description** : Supprimer un fichier. Force_delete pour suppression définitive du fichier également

**Authentification** : 🔒 Propriétaire ou Admin

**Données attendues** :
```json
{
  "force_delete": true
}
```

**Validation** :
- `id` : requis, ID du fichier numérique
- `force_delete` : optionnel, booléen pour suppression physique du fichier

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Fichier supprimé avec succès"
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Fichier non trouvé
- `500` : Erreur lors de la suppression

---

## POST `/files/{id}/restore`
**Description** : Restaurer un fichier supprimé (softdelete)

**Authentification** : 🔒 Propriétaire ou Admin

**Validation** :
- `id` : requis, ID du fichier numérique

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Fichier restauré avec succès"
}
```

**Réponses d'erreur** :
- `400` : Ce fichier n'est pas supprimé
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Fichier non trouvé
- `500` : Erreur lors de la restauration

---

## GET `/files/user/{user_id}`
**Description** : Lister les fichiers d'un utilisateur

**Authentification** : 🔒 Propriétaire ou Admin

**Paramètres de requête** :
- `limit` : nombre d'éléments (optionnel, 1-100, défaut 20)

**Validation** :
- `user_id` : requis, ID utilisateur numérique
- `limit` : optionnel, nombre d'éléments 1-100, défaut 20

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "files": [
      {
        "id": 15,
        "original_filename": "document.pdf",
        "file_type": "document",
        "file_size": 2048576,
        "description": "Description",
        "created_at": "2025-09-10 14:30:00"
      }
    ],
    "user_id": 1,
    "limit": 20,
    "total": 5
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Utilisateur non trouvé
- `500` : Erreur serveur

---

# 👥 Endpoints Groups

## POST `/groups`
**Description** : Créer un groupe

**Authentification** : 🔒 Utilisateur

**Données attendues** :
```json
{
  "name": "Nom du groupe",
  "description": "Description du groupe",
  "visibility": "private",
  "max_members": 50
}
```

**Validation** :
- `name` : requis, 2-255 caractères
- `description` : optionnel, texte libre
- `visibility` : optionnel, in('public','private'), défaut 'private'
- `max_members` : optionnel, >0 <=1000, défaut 50

**Réponse succès (201)** :
```json
{
  "success": true,
  "message": "Groupe créé avec succès",
  "data": {
    "id": 1,
    "name": "Nom du groupe",
    "description": "Description du groupe",
    "visibility": "private",
    "max_members": 50,
    "current_members": 1,
    "owner_id": 1,
    "created_at": "2025-09-10 14:30:00"
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `401` : Authentification requise
- `500` : Erreur lors de la création

---

## GET `/groups/{id}`
**Description** : Détails d'un groupe

**Authentification** : 🔒 Membre, Admin, ou ⭐ Non (si public)

**Validation** :
- `id` : requis, ID de groupe valide

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Nom du groupe",
    "description": "Description détaillée du groupe",
    "visibility": "public",
    "max_members": 100,
    "current_members": 25,
    "owner_id": 1,
    "owner_name": "Jean Dupont",
    "created_at": "2025-09-01 10:00:00",
    "updated_at": "2025-09-10 10:00:00",
    "user_role": "member", // si authentifié et membre
    "invitation_code": "ABC123XYZ", // si admin du groupe
    "recent_memories": 5,
    "deleted_at": null
  }
}
```

**Réponses d'erreur** :
- `403` : Accès non autorisé (groupe privé)
- `404` : Groupe non trouvé
- `500` : Erreur serveur

---

## PUT `/groups/{id}`
**Description** : Modifier un groupe

**Authentification** : 🔒 Admin du groupe ou Admin

**Données attendues** :
```json
{
  "name": "Nouveau nom",
  "description": "Nouvelle description",
  "visibility": "public",
  "max_members": 75
}
```

**Validation** :
- `id` : requis, ID de groupe valide
- `name` : optionnel, 2-255 caractères
- `description` : optionnel, texte libre
- `visibility` : optionnel, in('public','private')
- `max_members` : optionnel, >0 <=1000

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Groupe modifié avec succès",
  "data": {
    "id": 1,
    "name": "Nouveau nom",
    "description": "Nouvelle description",
    "visibility": "public",
    "max_members": 75,
    "updated_at": "2025-09-10 14:35:00"
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Groupe non trouvé
- `500` : Erreur lors de la mise à jour

---

## DELETE `/groups/{id}`
**Description** : Supprimer un groupe

**Authentification** : 🔒 Admin du groupe ou Admin

**Données attendues** :
```json
{
  "force_delete": true
}
```

**Validation** :
- `id` : requis, ID de groupe valide
- `force_delete` : optionnel, booléen pour suppression définitive

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Groupe supprimé avec succès"
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Groupe non trouvé
- `500` : Erreur lors de la suppression

---

## POST `/groups/{id}/restore`
**Description** : Restaurer un groupe supprimé

**Authentification** : 🔒 Admin

**Validation** :
- `id` : requis, ID de groupe valide

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Groupe restauré avec succès"
}
```

**Réponses d'erreur** :
- `400` : Ce groupe n'est pas supprimé
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Groupe non trouvé
- `500` : Erreur lors de la restauration

---

## GET `/groups/user/{user_id}`
**Description** : Groupes d'un utilisateur

**Authentification** : 🔒 Utilisateur (soi-même) ou Admin

**Validation** :
- `user_id` : requis, ID utilisateur valide

**Paramètres de requête** :
- `page_size` : taille de page (optionnel, 1-100, défaut 20)

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "groups": [
      {
        "id": 1,
        "name": "Groupe Histoire",
        "description": "Description abrégée",
        "visibility": "public",
        "current_members": 25,
        "user_role": "member",
        "created_at": "2025-09-01 10:00:00"
      }
    ],
    "user_id": 1,
    "page_size": 20,
    "total": 3
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Utilisateur non trouvé
- `500` : Erreur serveur

---

## GET `/groups/my-groups`
**Description** : Mes groupes

**Authentification** : 🔒 Utilisateur

**Paramètres de requête** :
- `page_size` : taille de page (optionnel, entier 1-100)

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "groups": [
      {
        "id": 1,
        "name": "Mon Groupe",
        "description": "Description",
        "visibility": "private",
        "current_members": 15,
        "user_role": "admin",
        "invitation_code": "ABC123XYZ",
        "created_at": "2025-09-01 10:00:00"
      }
    ],
    "page_size": 20,
    "total": 5
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `500` : Erreur serveur

---

## GET `/groups/search`
**Description** : Rechercher des groupes

**Authentification** : 🔒 Utilisateur

**Paramètres de requête** :
- `q` : terme de recherche (optionnel)
- `visibility` : filtrer par visibilité (optionnel)
- `page_size` : taille de page (optionnel, 1-100)

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "groups": [
      {
        "id": 1,
        "name": "Groupe Public Recherché",
        "description": "Description correspondante",
        "visibility": "public",
        "current_members": 42,
        "created_at": "2025-09-01 10:00:00"
      }
    ],
    "search_term": "recherché",
    "page_size": 20,
    "total": 8
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `500` : Erreur serveur

---

## POST `/groups/{id}/invite`
**Description** : Inviter un utilisateur

**Authentification** : 🔒 Admin du groupe ou Admin

**Données attendues** :
```json
{
  "user_email": "user@example.com",
  "role": "member"
}
```

**Validation** :
- `id` : requis, ID de groupe valide
- `user_email` : requis, email valide d'un utilisateur existant
- `role` : optionnel, in('member','moderator','admin'), défaut 'member'

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Invitation envoyée avec succès",
  "data": {
    "invitation_id": 15,
    "group_id": 1,
    "invited_user_email": "user@example.com",
    "role": "member",
    "invited_by": 1,
    "status": "pending",
    "created_at": "2025-09-10 14:30:00"
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Groupe ou utilisateur non trouvé
- `409` : Utilisateur déjà membre du groupe
- `500` : Erreur lors de l'invitation

---

## POST `/groups/join`
**Description** : Rejoindre un groupe avec un code d'invitation

**Authentification** : 🔒 Utilisateur

**Données attendues** :
```json
{
  "code": "ABC123XYZ"
}
```

**Validation** :
- `code` : requis, chaîne de caractères (code d'invitation valide)

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Vous avez rejoint le groupe avec succès",
  "data": {
    "group_id": 1,
    "group_name": "Nom du groupe",
    "user_role": "member",
    "joined_at": "2025-09-10 14:30:00"
  }
}
```

**Réponses d'erreur** :
- `400` : Code d'invitation requis
- `401` : Authentification requise
- `404` : Code d'invitation invalide ou expiré
- `409` : Vous êtes déjà membre de ce groupe
- `422` : Groupe plein (limite de membres atteinte)
- `500` : Erreur lors de l'adhésion

---

## PUT `/groups/{group_id}/members/{user_id}`
**Description** : Mettre à jour le rôle d'un utilisateur dans un groupe

**Authentification** : 🔒 Admin du groupe ou Admin

**Données attendues** :
```json
{
  "role": "moderator"
}
```

**Validation** :
- `group_id` : requis, ID de groupe valide
- `user_id` : requis, ID utilisateur valide (membre du groupe)
- `role` : requis, in('member','moderator','admin')

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Rôle de l'utilisateur mis à jour avec succès",
  "data": {
    "group_id": 1,
    "user_id": 2,
    "user_name": "Marie Dubois",
    "old_role": "member",
    "new_role": "moderator",
    "updated_by": 1,
    "updated_at": "2025-09-10 14:30:00"
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Groupe ou utilisateur non trouvé
- `422` : Utilisateur pas membre du groupe
- `500` : Erreur lors de la mise à jour

---

## GET `/groups/my-invitations`
**Description** : Mes invitations en attente

**Authentification** : 🔒 Utilisateur

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "invitations": [
      {
        "id": 15,
        "group_id": 1,
        "group_name": "Groupe Histoire",
        "group_description": "Description du groupe",
        "invited_role": "member",
        "invited_by_name": "Jean Dupont",
        "status": "pending",
        "created_at": "2025-09-08 10:00:00",
        "expires_at": "2025-09-22 10:00:00"
      }
    ],
    "total": 2
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `500` : Erreur serveur

---

## POST `/groups/{id}/leave`
**Description** : Quitter un groupe. Le propriétaire ne peut pas quitter le groupe

**Authentification** : 🔒 Utilisateur

**Validation** :
- `id` : requis, ID de groupe valide

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Vous avez quitté le groupe avec succès"
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Le propriétaire ne peut pas quitter le groupe
- `404` : Groupe non trouvé
- `422` : Vous n'êtes pas membre de ce groupe
- `500` : Erreur lors de la sortie du groupe

---

## GET `/groups/{id}/members`
**Description** : Récupérer les membres d'un groupe

**Authentification** : 🔒 Membre ou Admin

**Validation** :
- `id` : requis, ID de groupe valide

**Paramètres de requête** :
- `page` : numéro de page (optionnel, >= 1)
- `limit` : nombre d'éléments (optionnel, 1-100)

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "members": [
      {
        "user_id": 1,
        "name": "Jean Dupont",
        "email": "jean@example.com",
        "role": "admin",
        "profile_image": "/uploads/avatars/1.jpg",
        "joined_at": "2025-09-01 10:00:00",
        "last_activity": "2025-09-10 09:00:00"
      }
    ],
    "group_id": 1,
    "total_members": 25,
    "page": 1,
    "limit": 20
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé (pas membre du groupe)
- `404` : Groupe non trouvé
- `500` : Erreur serveur

---

# 📊 Endpoints Stats

## POST `/stats/build`
**Description** : Générer toutes les statistiques de la plateforme

**Authentification** : 🔒 Admin

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Statistiques générées avec succès",
  "data": {
    "generation_time": "2025-09-10 14:30:00",
    "execution_time": "2.35 seconds",
    "stats_generated": {
      "platform_stats": true,
      "user_stats": 150,
      "group_stats": 25,
      "memory_stats": 450,
      "file_stats": 280
    }
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `500` : Erreur lors de la génération

---

## GET `/stats/platform`
**Description** : Récupérer les statistiques globales de la plateforme

**Authentification** : 🔒 Admin

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "users": {
      "total": 150,
      "active": 120,
      "new_this_month": 15
    },
    "groups": {
      "total": 25,
      "public": 10,
      "private": 15,
      "average_members": 8.5
    },
    "memories": {
      "total": 450,
      "public": 200,
      "private": 250,
      "this_month": 45
    },
    "files": {
      "total": 280,
      "total_size_mb": 1250.5,
      "by_type": {
        "images": 180,
        "documents": 65,
        "audio": 20,
        "video": 15
      }
    },
    "tags": {
      "total": 85,
      "most_used": "Histoire"
    },
    "generated_at": "2025-09-10 14:30:00"
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Statistiques non générées
- `500` : Erreur serveur

---

## GET `/stats/groups`
**Description** : Récupérer les statistiques par groupe

**Authentification** : 🔒 Admin

**Paramètres de requête** :
- `offset` : décalage pour la pagination (optionnel, défaut 0)
- `limit` : nombre d'éléments (optionnel, défaut 20)

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "groups": [
      {
        "group_id": 1,
        "group_name": "Groupe Histoire",
        "members_count": 25,
        "memories_count": 85,
        "files_count": 45,
        "activity_score": 8.7,
        "last_activity": "2025-09-10 12:00:00",
        "created_at": "2025-08-01 10:00:00"
      }
    ],
    "offset": 0,
    "limit": 20,
    "total": 25,
    "generated_at": "2025-09-10 14:30:00"
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Statistiques non générées
- `500` : Erreur serveur

---

## GET `/stats/users`
**Description** : Récupérer les statistiques par utilisateur

**Authentification** : 🔒 Admin

**Paramètres de requête** :
- `offset` : décalage pour la pagination (optionnel, défaut 0)
- `limit` : nombre d'éléments (optionnel, défaut 20)

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "users": [
      {
        "user_id": 1,
        "user_name": "Jean Dupont",
        "user_email": "jean@example.com",
        "memories_count": 25,
        "files_count": 15,
        "groups_count": 3,
        "tags_count": 12,
        "activity_score": 9.2,
        "last_activity": "2025-09-10 12:00:00",
        "account_created": "2025-01-01 10:00:00"
      }
    ],
    "offset": 0,
    "limit": 20,
    "total": 150,
    "generated_at": "2025-09-10 14:30:00"
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Statistiques non générées
- `500` : Erreur serveur

---

## GET `/stats/users/{id}`
**Description** : Récupère les statistiques d'un utilisateur

**Authentification** : 🔒 Utilisateur (soi-même) ou Admin

**Validation** :
- `id` : requis, ID utilisateur valide

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "user_id": 1,
    "user_name": "Jean Dupont",
    "statistics": {
      "memories_count": 25,
      "memories_public": 15,
      "memories_private": 10,
      "files_count": 15,
      "files_size_mb": 45.2,
      "groups_count": 3,
      "groups_owned": 1,
      "groups_member": 2,
      "tags_count": 12,
      "tags_created": 8,
      "activity_score": 9.2,
      "last_activity": "2025-09-10 12:00:00"
    },
    "account_created": "2025-01-01 10:00:00",
    "generated_at": "2025-09-10 14:30:00"
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `403` : Accès non autorisé
- `404` : Utilisateur non trouvé ou statistiques non générées
- `500` : Erreur serveur

---

## GET `/stats/my-stats`
**Description** : Statistiques de l'utilisateur connecté

**Authentification** : 🔒 Utilisateur

**Réponse succès (200)** :
```json
{
  "success": true,
  "data": {
    "user_id": 1,
    "statistics": {
      "memories_count": 25,
      "memories_public": 15,
      "memories_private": 10,
      "files_count": 15,
      "files_size_mb": 45.2,
      "groups_count": 3,
      "groups_owned": 1,
      "groups_member": 2,
      "tags_count": 12,
      "tags_created": 8,
      "activity_score": 9.2,
      "last_activity": "2025-09-10 12:00:00",
      "account_age_days": 252
    },
    "recent_activity": {
      "memories_this_week": 3,
      "files_this_week": 2,
      "groups_joined_this_month": 1
    },
    "account_created": "2025-01-01 10:00:00",
    "generated_at": "2025-09-10 14:30:00"
  }
}
```

**Réponses d'erreur** :
- `401` : Authentification requise
- `404` : Statistiques non générées pour cet utilisateur
- `500` : Erreur serveur

---

## 📝 Notes de fin de documentation

### Dernière mise à jour
**Date** : 10 septembre 2025  
**Version** : 1.1.0  
**Statut** : Documentation complète et à jour  

### Améliorations récentes
- ✅ Mise à jour complète selon API_ENDPOINTS.json  
- ✅ Correction des méthodes HTTP (GET, POST, PUT, DELETE)  
- ✅ Ajout des sections Files, Groups et Stats  
- ✅ Standardisation des réponses d'erreur  
- ✅ Amélioration des exemples de réponses  
- ✅ Ajout des validations complètes  
- ✅ Documentation des limites et contraintes  

### Support technique
Pour toute question technique ou assistance, contactez l'équipe de développement.

**Fin de la documentation API Collective Memories v1.1.0**
    "email": "nouveau@email.com",
    "role": "UTILISATEUR",
    "profile_image": "avatars/123_avatar.jpg",
    "bio": "Nouvelle biographie...",
    "phone": "0611223344",
    "date_of_birth": "1990-01-15",
    "location": "Paris",
    "email_verified": 1,
    "last_login": "2025-08-01 10:00:00",
    "created_at": "2025-01-01 10:00:00",
    "updated_at": "2025-08-01 10:00:00",
    "deleted_at": null
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `404` : Utilisateur non trouvé
- `409` : Cet email est déjà utilisé
- `500` : Erreur lors de la mise à jour

---

---