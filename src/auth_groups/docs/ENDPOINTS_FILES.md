# 📁 Endpoints Files

> Endpoints de gestion des fichiers

---

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
