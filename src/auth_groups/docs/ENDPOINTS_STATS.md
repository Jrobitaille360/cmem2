# 📊 Endpoints Stats

> Endpoints de statistiques et analytiques

---

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
