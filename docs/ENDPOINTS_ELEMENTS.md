# 🧩 Endpoints Elements

> Endpoints de gestion des éléments multimédia

---

## POST `/elements`
**Description** : Créer un élément (texte, image, audio, vidéo, document, GPX, iCal)

**Authentification** : 🔒 JWT Bearer Token requis

**Données attendues** :
```json
{
  "title": "Titre de l'élément",
  "content": "Contenu de l'élément",
  "media_type": "text",
  "visibility": "private",
  "filename": "nom_fichier.ext"
}
```

**Validation** :
- `title` : requis, string, max 255 caractères
- `content` : optionnel, string, max 10000 caractères  
- `media_type` : optionnel, enum: text,image,audio,video,document,gpx,ical (défaut: text)
- `visibility` : optionnel, enum: private,shared,public (défaut: private)
- `filename` : optionnel, string, max 255 caractères

**Réponse succès (201)** :
```json
{
  "success": true,
  "message": "Élément créé avec succès",
  "data": {
    "id": 47,
    "title": "Titre de l'élément",
    "content": "Contenu de l'élément", 
    "media_type": "text",
    "visibility": "private",
    "filename": "nom_fichier.ext",
    "owner_id": 13,
    "created_at": "2025-09-11 09:00:45",
    "updated_at": "2025-09-11 09:00:45"
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides
- `401` : Token JWT manquant ou invalide
- `500` : Erreur serveur lors de la création

---

## GET `/elements`
**Description** : Lister les éléments avec filtrage et pagination

**Authentification** : 🔒 JWT Bearer Token requis

**Paramètres de requête** :
- `media_type` : filtrer par type (text,image,audio,video,document,gpx,ical)
- `q` : terme de recherche dans titre/contenu
- `page` : numéro de page (défaut: 1)
- `limit` : nombre d'éléments par page (défaut: 20, max: 100)

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Liste des éléments récupérée",
  "data": {
    "elements": [
      {
        "id": 47,
        "title": "Titre de l'élément",
        "content": "Contenu...",
        "media_type": "text",
        "visibility": "public",
        "filename": null,
        "owner_id": 13,
        "created_at": "2025-09-11 09:00:45",
        "updated_at": "2025-09-11 09:00:45"
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 20,
      "total": 1,
      "total_pages": 1
    },
    "media_type": "text"
  }
}
```

**Réponses d'erreur** :
- `400` : Type de média invalide
- `401` : Token JWT manquant ou invalide
- `500` : Erreur serveur

---

## GET `/elements/{id}`
**Description** : Récupérer un élément spécifique

**Authentification** : 🔒 JWT Bearer Token requis

**Paramètres d'URL** :
- `id` : ID numérique de l'élément

**Permissions** :
- Propriétaire : accès complet
- Autres utilisateurs : seulement éléments publics
- Administrateur : accès complet

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Élément récupéré",
  "data": {
    "id": 47,
    "title": "Titre de l'élément",
    "content": "Contenu complet de l'élément...",
    "media_type": "text",
    "visibility": "public",
    "filename": null,
    "owner_id": 13,
    "created_at": "2025-09-11 09:00:45",
    "updated_at": "2025-09-11 09:00:45"
  }
}
```

**Réponses d'erreur** :
- `400` : ID invalide
- `401` : Token JWT manquant ou invalide
- `403` : Accès non autorisé (élément privé)
- `404` : Élément non trouvé
- `500` : Erreur serveur

---

## PUT `/elements/{id}`
**Description** : Modifier un élément existant

**Authentification** : 🔒 JWT Bearer Token requis (propriétaire ou admin)

**Paramètres d'URL** :
- `id` : ID numérique de l'élément

**Données attendues** :
```json
{
  "title": "Nouveau titre",
  "content": "Nouveau contenu",
  "media_type": "image",
  "visibility": "public",
  "filename": "nouveau_fichier.jpg"
}
```

**Validation** :
- `title` : optionnel, string, max 255 caractères
- `content` : optionnel, string, max 10000 caractères
- `media_type` : optionnel, enum: text,image,audio,video,document,gpx,ical
- `visibility` : optionnel, enum: private,shared,public
- `filename` : optionnel, string, max 255 caractères

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Élément mis à jour avec succès",
  "data": {
    "id": 47,
    "title": "Nouveau titre",
    "content": "Nouveau contenu",
    "media_type": "image",
    "visibility": "public",
    "filename": "nouveau_fichier.jpg",
    "owner_id": 13,
    "created_at": "2025-09-11 09:00:45",
    "updated_at": "2025-09-11 09:15:32"
  }
}
```

**Réponses d'erreur** :
- `400` : Données de validation invalides ou ID invalide
- `401` : Token JWT manquant ou invalide
- `403` : Accès non autorisé (pas propriétaire)
- `404` : Élément non trouvé
- `500` : Erreur serveur lors de la mise à jour

---

## DELETE `/elements/{id}`
**Description** : Supprimer un élément (soft delete)

**Authentification** : 🔒 JWT Bearer Token requis (propriétaire ou admin)

**Paramètres d'URL** :
- `id` : ID numérique de l'élément

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Élément supprimé avec succès"
}
```

**Réponses d'erreur** :
- `400` : ID invalide
- `401` : Token JWT manquant ou invalide
- `403` : Accès non autorisé (pas propriétaire)
- `404` : Élément non trouvé
- `500` : Erreur serveur lors de la suppression

---

## GET `/elements/search`
**Description** : Rechercher des éléments par titre ou contenu

**Authentification** : 🔒 JWT Bearer Token requis

**Paramètres de requête** :
- `q` : terme de recherche (requis)
- `limit` : nombre de résultats (optionnel, défaut: 20, max: 100)

**Exemple d'appel** :
```
GET /elements/search?q=test&limit=10
```

**Réponse succès (200)** :
```json
{
  "success": true,
  "message": "Résultats de recherche",
  "data": [
    {
      "id": 47,
      "title": "Test Element",
      "content": "Test content",
      "media_type": "text",
      "visibility": "public",
      "filename": null,
      "owner_id": 13,
      "owner_name": "Test User",
      "created_at": "2025-09-11 09:00:45",
      "updated_at": "2025-09-11 09:00:45"
    }
  ]
}
```

**Réponses d'erreur** :
- `400` : Terme de recherche requis
- `401` : Token JWT manquant ou invalide
- `500` : Erreur serveur lors de la recherche

---

## 🔐 Gestion des Permissions

### Visibilité des éléments :
- **`private`** : Visible uniquement par le propriétaire
- **`shared`** : Visible par les membres des groupes ayant accès via les mémoires
- **`public`** : Visible par tous les utilisateurs authentifiés

### Règles d'accès :
1. **Propriétaire** : Accès complet (lecture, modification, suppression)
2. **Administrateur** : Accès complet à tous les éléments
3. **Autres utilisateurs** : Accès en lecture selon la visibilité

---

## 📝 Types de Médias Supportés

| Type | Description | Extensions courantes |
|------|-------------|---------------------|
| `text` | Texte simple, markdown | .txt, .md |
| `image` | Images | .jpg, .png, .gif, .webp |
| `audio` | Fichiers audio | .mp3, .wav, .ogg |
| `video` | Fichiers vidéo | .mp4, .avi, .mov |
| `document` | Documents | .pdf, .doc, .docx |
| `gpx` | Données GPS | .gpx |
| `ical` | Calendrier | .ics |

---

## ✨ Fonctionnalités Implémentées

- ✅ CRUD complet (Create, Read, Update, Delete)
- ✅ Recherche textuelle dans titre et contenu
- ✅ Filtrage par type de média
- ✅ Pagination des résultats
- ✅ Gestion des permissions selon la visibilité
- ✅ Validation complète des données
- ✅ Authentification JWT obligatoire
- ✅ Soft delete (les éléments supprimés sont marqués, pas physiquement supprimés)
- ✅ Support de 7 types de médias différents
