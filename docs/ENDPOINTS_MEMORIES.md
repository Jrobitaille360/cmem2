# 💾 Endpoints Memories

> Endpoints de gestion des mémoires collectives

---

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

---

## POST `/memories/search`
**Description** : Recherche avancée dans les mémoires (POST)

**Authentification** : ⭐ Optionnelle

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
  "data": {
    "memories": [
      {
        "id": 1,
        "title": "Mémoire trouvée",
        "content": "Extrait pertinent...",
        "score": 0.95
      }
    ]
  }
}
```

---

## POST `/memories/upload/{id}`
**Description** : Upload alternatif d'un fichier pour une mémoire

**Authentification** : 🔒 Propriétaire de la mémoire

**Données attendues** :
- Fichier : `file` (multipart/form-data)

**Réponse succès (201)** :
```json
{
  "success": true,
  "message": "Document uploadé et associé",
  "data": {
    "file_id": 15,
    "filename": "document.pdf"
  }
}
```

---
