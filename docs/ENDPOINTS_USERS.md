# 👥 Endpoints Users

> Endpoints de gestion des utilisateurs

---

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
