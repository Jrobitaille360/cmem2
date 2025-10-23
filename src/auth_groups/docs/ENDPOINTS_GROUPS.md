# 👥 Endpoints Groups

> Endpoints de gestion des groupes

---

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
    "user_role": "member",
    "invitation_code": "ABC123XYZ",
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
