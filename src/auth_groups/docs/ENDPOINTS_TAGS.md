# 🏷️ Endpoints Tags

> Endpoints de gestion des tags/étiquettes

---

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
    "action": "associated",
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
