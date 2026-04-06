# Guide — Plugin Puzzle — Admin (puzzle_images_manager)

Version 1.0.0 · Base URL : `/puzzle/admin`

> Référence complète : [API_PUZZLE_ADMIN_MANAGER.json](API_PUZZLE_ADMIN_MANAGER.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [Flux complet — côté SPA admin](#flux-complet--c%C3%B4t%C3%A9-spa-admin)
- [Endpoints — Images](#endpoints--images)
- [Endpoints — Thèmes](#endpoints--th%C3%A8mes)
- [Formats de données](#formats-de-donn%C3%A9es)
- [Erreurs](#erreurs)
- [Migrations](#migrations)

---

## Vue d'ensemble

Le module admin du plugin Puzzle expose une API REST destinée au SPA React **puzzle_images_manager** (`http://localhost:5173` / `images_manager.journauxdebord.com`).

Il permet à un administrateur connecté de gérer :

- Le **carrousel d'images** : ajout, modification des labels i18n, tri, suppression
- Les **thèmes premium** : création avec miniature, association d'images, suppression

Toutes les routes sont protégées par un JWT cmem2 avec le rôle `ADMINISTRATEUR`.
Les images upload sont traitées côté serveur par GD (JPEG / PNG → JPEG, max 2000 px, miniature 400 px).

---

## Authentification

| Contexte | Mécanisme | Header |
| --- | --- | --- |
| Toutes les routes admin | JWT Bearer cmem2 | `Authorization: Bearer <jwt_token>` |

Le JWT s'obtient via `POST /auth/login` (endpoint core cmem2, hors scope de ce guide).
Le rôle `ADMINISTRATEUR` doit figurer dans le payload JWT — tout autre rôle retourne `403`.

### Codes d'erreur auth

| Code | Cause |
| --- | --- |
| 401 | Header `Authorization` absent ou JWT malformé |
| 403 | JWT valide mais rôle insuffisant (non `ADMINISTRATEUR`) |

---

## Flux complet — côté SPA admin

```txt
# 1. Se connecter (endpoint cmem2 core)
POST /auth/login
{ "email": "admin@example.com", "password": "..." }
→ { data: { token: "eyJ...", role: "ADMINISTRATEUR" } }

# 2. Lister les images du carrousel
GET /puzzle/admin/images
Authorization: Bearer eyJ...
→ { data: [...], meta: { total, page, per_page } }

# 3. Ajouter une image (multipart/form-data)
POST /puzzle/admin/images
Authorization: Bearer eyJ...
Content-Type: multipart/form-data
{ image: <file.jpg>, label_fr: "Coucher de soleil", status: "active" }
→ { data: { uid: "abc-...", full_path: "images/abc.jpg", thumb_path: "thumbs/abc.jpg" } }

# 4. Réordonner les images
PUT /puzzle/admin/images/reorder
Authorization: Bearer eyJ...
{ "order": ["uid-3", "uid-1", "uid-2"] }
→ { message: "Ordre mis à jour" }

# 5. Créer un thème (multipart/form-data)
POST /puzzle/admin/themes
Authorization: Bearer eyJ...
{ thumb: <miniature.jpg>, slug: "nature", label_fr: "Nature", label_en: "Nature" }
→ { data: { slug: "nature", label_fr: "Nature", ... } }

# 6. Assigner des images à un thème
PUT /puzzle/admin/themes/nature/images
Authorization: Bearer eyJ...
{ "image_uids": ["uid-1", "uid-3", "uid-7"] }
→ { message: "Images du thème mises à jour" }

# 7. Supprimer une image du carrousel
DELETE /puzzle/admin/images/{uid}
Authorization: Bearer eyJ...
→ 200 OK  (ou 409 si une session partagée active l'utilise)
```

---

## Endpoints — Images

| Méthode | Endpoint | Description |
| --- | --- | --- |
| GET | `/puzzle/admin/images` | Lister les images (paginé, filtre statut) |
| POST | `/puzzle/admin/images` | Ajouter une image (multipart) |
| PUT | `/puzzle/admin/images/reorder` | Réordonner le carrousel |
| PUT | `/puzzle/admin/images/{uid}` | Modifier label / statut |
| DELETE | `/puzzle/admin/images/{uid}` | Supprimer une image |

### GET /puzzle/admin/images

Paramètres query :

| Paramètre | Type | Défaut | Description |
| --- | --- | --- | --- |
| `page` | integer | 1 | Numéro de page |
| `per_page` | integer | 20 | Résultats par page |
| `status` | string | `all` | Filtre : `active` \| `inactive` \| `all` |

Réponse `200` :

```json
{
  "success": true,
  "data": [
    {
      "uid": "550e8400-e29b-41d4-a716-446655440000",
      "full_path": "images/550e8400.jpg",
      "thumb_path": "thumbs/550e8400.jpg",
      "sort_order": 1,
      "status": "active",
      "label_fr": "Coucher de soleil",
      "label_en": "Sunset",
      "label_es": null,
      "created_at": "2026-04-06T14:00:00Z"
    }
  ],
  "meta": {
    "total": 42,
    "page": 1,
    "per_page": 20,
    "total_pages": 3
  }
}
```

### POST /puzzle/admin/images

Corps `multipart/form-data` :

| Champ | Type | Requis | Description |
| --- | --- | --- | --- |
| `image` | file | oui | JPEG ou PNG, max 10 Mo |
| `label_fr` | string | oui | Label français |
| `label_en` | string | non | Label anglais |
| `label_es` | string | non | Label espagnol |
| `status` | string | non | `active` (défaut) ou `inactive` |

Réponse `201` :

```json
{
  "success": true,
  "message": "Image créée",
  "data": {
    "uid": "550e8400-e29b-41d4-a716-446655440000",
    "full_path": "images/550e8400.jpg",
    "thumb_path": "thumbs/550e8400.jpg",
    "sort_order": 5,
    "status": "active",
    "label_fr": "Coucher de soleil"
  }
}
```

### PUT /puzzle/admin/images/reorder

Corps JSON :

| Champ | Type | Requis | Description |
| --- | --- | --- | --- |
| `order` | string[] | oui | Tableau d'UIDs dans le nouvel ordre |

Réponse `200` : `{ "success": true, "message": "Ordre mis à jour" }`

### PUT /puzzle/admin/images/{uid}

Corps JSON (tous les champs sont optionnels) :

| Champ | Type | Description |
| --- | --- | --- |
| `label_fr` | string | Nouveau label français |
| `label_en` | string | Nouveau label anglais |
| `label_es` | string | Nouveau label espagnol |
| `status` | string | `active` ou `inactive` |

Réponse `200` : `{ "success": true, "message": "Image mise à jour" }`

Réponse `404` si l'UID est introuvable.

### DELETE /puzzle/admin/images/{uid}

Supprime l'image, ses traductions et ses fichiers physiques (full + thumb).

| Code | Cause |
| --- | --- |
| 200 | Suppression réussie |
| 404 | UID introuvable |
| 409 | Image utilisée dans une session partagée active (impossible de supprimer) |

---

## Endpoints — Thèmes

| Méthode | Endpoint | Description |
| --- | --- | --- |
| GET | `/puzzle/admin/themes` | Lister les thèmes |
| POST | `/puzzle/admin/themes` | Créer un thème (multipart) |
| PUT | `/puzzle/admin/themes/{slug}` | Modifier label / miniature |
| DELETE | `/puzzle/admin/themes/{slug}` | Supprimer un thème |
| PUT | `/puzzle/admin/themes/{slug}/images` | Remplacer la liste d'images du thème |

### GET /puzzle/admin/themes

Réponse `200` :

```json
{
  "success": true,
  "data": [
    {
      "slug": "nature",
      "thumb_path": "themes/nature.jpg",
      "label_fr": "Nature",
      "label_en": "Nature",
      "label_es": null,
      "image_count": 12,
      "created_at": "2026-04-06T14:00:00Z"
    }
  ]
}
```

### POST /puzzle/admin/themes

Corps `multipart/form-data` :

| Champ | Type | Requis | Description |
| --- | --- | --- | --- |
| `thumb` | file | oui | JPEG ou PNG, max 5 Mo — miniature thème (400 px) |
| `slug` | string | oui | Identifiant unique `^[a-z0-9_-]+$` |
| `label_fr` | string | oui | Label français |
| `label_en` | string | non | Label anglais |
| `label_es` | string | non | Label espagnol |

Codes :

| Code | Cause |
| --- | --- |
| 201 | Thème créé |
| 409 | Slug déjà existant |
| 422 | Slug invalide, label_fr manquant ou fichier absent/invalide |

### PUT /puzzle/admin/themes/{slug}

Corps `multipart/form-data` (tous les champs optionnels) :

| Champ | Type | Description |
| --- | --- | --- |
| `thumb` | file | Nouvelle miniature (remplace l'ancienne) |
| `label_fr` | string | Nouveau label français |
| `label_en` | string | Nouveau label anglais |
| `label_es` | string | Nouveau label espagnol |

Réponse `200` : `{ "success": true, "message": "Thème mis à jour" }`

### DELETE /puzzle/admin/themes/{slug}

Supprime le thème, ses traductions, ses associations et la miniature physique.

| Code | Cause |
| --- | --- |
| 200 | Suppression réussie |
| 404 | Slug introuvable |

### PUT /puzzle/admin/themes/{slug}/images

**Remplacement complet** de la liste d'images associées au thème.

Corps JSON :

| Champ | Type | Requis | Description |
| --- | --- | --- | --- |
| `image_uids` | string[] | oui | Tableau d'UIDs d'images à associer (peut être vide) |

Réponse `200` : `{ "success": true, "message": "Images du thème mises à jour" }`

---

## Formats de données

### Chemin d'image

Les champs `full_path` et `thumb_path` sont des chemins **relatifs** à `PUZZLE_UPLOAD_DIR`.
Le SPA peut les utiliser avec l'endpoint de livraison d'images existant :

```txt
GET /puzzle/image/{uid}   → image pleine résolution
GET /puzzle/thumb/{uid}   → miniature 400 px
GET /puzzle/thumb/theme/{slug}  → miniature du thème
```

### Statuts d'image

| Valeur | Description |
| --- | --- |
| `active` | Visible dans le carrousel app |
| `inactive` | Masquée (admin uniquement) |

---

## Erreurs

| Code HTTP | Signification |
| --- | --- |
| 400 | Requête malformée (JSON invalide) |
| 401 | Token absent ou JWT invalide |
| 403 | Rôle insuffisant |
| 404 | Ressource introuvable (UID / slug) |
| 409 | Conflit : slug déjà existant, ou image utilisée dans session active |
| 422 | Données invalides (champ requis manquant, format incorrect) |
| 500 | Erreur serveur (traitement GD, fichier inaccessible) |

---

## Migrations

La structure des tables admin est définie dans la migration initiale du plugin Puzzle :

- [migrations/001_puzzle_base.sql](migrations/001_puzzle_base.sql) — tables `puzzle_images`, `puzzle_image_translations`, `puzzle_themes`, `puzzle_theme_translations`, `puzzle_image_themes`
