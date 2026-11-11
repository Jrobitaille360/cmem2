# Guide — Plugin Puzzle — Admin (puzzle_images_manager)

Version 1.0.3 · Base URL : `/puzzle/admin`

> Référence complète : [API_PUZZLE_ADMIN_MANAGER.json](API_PUZZLE_ADMIN_MANAGER.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [Livraison des images](#livraison-des-images)
- [Flux complet — côté SPA admin](#flux-complet--côté-spa-admin)
- [Endpoints — Images](#endpoints--images)
- [Endpoints — Thèmes](#endpoints--thèmes)
- [Formats de données](#formats-de-données)
- [Erreurs](#erreurs)
- [Migrations](#migrations)

---

## Vue d'ensemble

Le module admin du plugin Puzzle expose une API REST destinée au SPA React **puzzle_images_manager** (`http://localhost:5173` / `images_manager.journauxdebord.com`).

Il permet à un administrateur connecté de gérer :

- Le **carrousel d'images** : ajout, modification des labels i18n, tri, suppression
- Les **thèmes premium** : création avec miniature, association d'images, suppression

Toutes les routes sont protégées par un JWT cmem2 avec le rôle `ADMINISTRATEUR`.
Les images uploadées sont traitées côté serveur par GD (JPEG / PNG → JPEG, max 2000 px, miniature 400 px).

---

## Authentification

| Contexte | Mécanisme | Header |
| --- | --- | --- |
| Toutes les routes `/puzzle/admin/*` | JWT Bearer cmem2 | `Authorization: Bearer <jwt_token>` |

Le JWT s'obtient via `POST /auth/login` (endpoint core cmem2, hors scope de ce guide).
Le rôle `ADMINISTRATEUR` doit figurer dans le payload JWT — tout autre rôle retourne `403`.

### Codes d'erreur auth

| Code | Cause |
| --- | --- |
| 401 | Header `Authorization` absent ou JWT malformé |
| 403 | JWT valide mais rôle insuffisant (non `ADMINISTRATEUR`) |

---

## Livraison des images

Les fichiers physiques sont dans `uploads/puzzle/` qui est bloqué par `.htaccess`.
Le SPA admin doit passer par les routes de livraison protégées par JWT :

```txt
GET /puzzle/admin/thumb/{uid}         → miniature 400 px d'une image
GET /puzzle/admin/image/{uid}         → image pleine résolution
GET /puzzle/admin/thumb/theme/{slug}  → miniature du thème
```

Ces URLs sont directement retournées dans les champs `thumb_url` et `full_url` de toutes les réponses.

> **Attention** : les routes `/puzzle/thumb/{uid}` et `/puzzle/image/{uid}` (sans `/admin/`) exigent un `device_token` d'appareil mobile et retourneront `401` si appelées avec un JWT admin.

---

## Flux complet — côté SPA admin

```txt
# 1. Se connecter (endpoint cmem2 core)
POST /auth/login
{ "email": "admin@example.com", "password": "..." }
→ { success: true, data: { token: "eyJ...", user: { role: "ADMINISTRATEUR" } } }

# 2. Lister les images du carrousel
GET /puzzle/admin/images
Authorization: Bearer eyJ...
→ { success: true, data: [...], pagination: { total, page, per_page, last_page } }
  Chaque image : { uid, thumb_url, full_url, is_carousel, sort_order, status, translations: { fr, en, es }, created_at }

# 3. Ajouter une image (multipart/form-data)
POST /puzzle/admin/images
Authorization: Bearer eyJ...
Content-Type: multipart/form-data
{ image: <file.jpg>, label_fr: "Coucher de soleil", status: "active" }
→ { success: true, data: { uid: "abc-...", thumb_url: "http://.../puzzle/admin/thumb/abc-...", full_url: "...", status: "active", translations: { fr: "Coucher de soleil", en: null, es: null } } }

# 4. Réordonner les images
PUT /puzzle/admin/images/reorder
Authorization: Bearer eyJ...
{ "order": [ { "uid": "uid-3", "sort_order": 1 }, { "uid": "uid-1", "sort_order": 2 }, { "uid": "uid-2", "sort_order": 3 } ] }
→ { success: true, message: "Ordre mis à jour", data: { updated: 3 } }

# 5. Créer un thème (multipart/form-data)
POST /puzzle/admin/themes
Authorization: Bearer eyJ...
{ thumb: <miniature.jpg>, slug: "nature", label_fr: "Nature", label_en: "Nature" }
→ { success: true, data: { slug: "nature", thumb_url: "http://.../puzzle/admin/thumb/theme/nature", sort_order: 1, status: "active", image_count: 0, translations: { fr: "Nature", en: "Nature", es: null } } }

# 6. Assigner des images à un thème
PUT /puzzle/admin/themes/nature/images
Authorization: Bearer eyJ...
{ "image_uids": ["uid-1", "uid-3", "uid-7"] }
→ { success: true, message: "Images du thème mises à jour", data: { count: 3 } }

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
| PUT | `/puzzle/admin/images/{uid}` | Modifier label / statut / carrousel |
| DELETE | `/puzzle/admin/images/{uid}` | Supprimer une image |

### GET /puzzle/admin/images

Paramètres query :

| Paramètre | Type | Défaut | Description |
| --- | --- | --- | --- |
| `page` | integer | 1 | Numéro de page |
| `per_page` | integer | 50 | Résultats par page (min 10, max 100) |
| `status` | string | `all` | Filtre : `active` \| `inactive` \| `all` |

Réponse `200` :

```json
{
  "success": true,
  "message": "Images chargées",
  "data": [
    {
      "uid": "550e8400-e29b-41d4-a716-446655440000",
      "thumb_url": "http://localhost/cmem2_API/puzzle/admin/thumb/550e8400-e29b-41d4-a716-446655440000",
      "full_url": "http://localhost/cmem2_API/puzzle/admin/image/550e8400-e29b-41d4-a716-446655440000",
      "is_carousel": true,
      "sort_order": 1,
      "status": "active",
      "translations": {
        "fr": "Coucher de soleil",
        "en": "Sunset"
      },
      "created_at": "2026-04-06 14:00:00"
    }
  ],
  "pagination": {
    "total": 42,
    "page": 1,
    "per_page": 50,
    "last_page": 1
  },
  "timestamp": "2026-04-06 14:00:00"
}
```

> `translations` ne contient que les langues renseignées (clés absentes si label non défini).
> `is_carousel` indique si l'image apparaît dans le carrousel de l'app mobile.

### POST /puzzle/admin/images

Corps `multipart/form-data` :

| Champ | Type | Requis | Description |
| --- | --- | --- | --- |
| `image` | file | oui | JPEG ou PNG, max 10 Mo |
| `label_fr` | string | oui | Label français |
| `label_en` | string | non | Label anglais |
| `label_es` | string | non | Label espagnol |
| `status` | string | non | `active` (défaut) ou `inactive` |
| `sort_order` | integer | non | Position dans le carrousel ; auto-calculé si absent |

Réponse `201` :

```json
{
  "success": true,
  "message": "Image créée",
  "data": {
    "uid": "550e8400-e29b-41d4-a716-446655440000",
    "thumb_url": "http://localhost/cmem2_API/puzzle/admin/thumb/550e8400-e29b-41d4-a716-446655440000",
    "full_url": "http://localhost/cmem2_API/puzzle/admin/image/550e8400-e29b-41d4-a716-446655440000",
    "status": "active",
    "translations": {
      "fr": "Coucher de soleil",
      "en": null,
      "es": null
    }
  }
}
```

Erreur `422` :

```json
{
  "success": false,
  "message": "Le label français est obligatoire",
  "errors": [
    { "field": "label_fr", "code": "required", "message": "Le label français est obligatoire." }
  ]
}
```

### PUT /puzzle/admin/images/reorder

Corps JSON :

| Champ | Type | Requis | Description |
| --- | --- | --- | --- |
| `order` | object[] | oui | Tableau d'objets `{ uid: string, sort_order: integer }` |

Exemple :

```json
{
  "order": [
    { "uid": "550e8400-e29b-41d4-a716-446655440000", "sort_order": 1 },
    { "uid": "7a2e9f3b-1234-4abc-8def-000000000001", "sort_order": 2 }
  ]
}
```

Réponse `200` :

```json
{ "success": true, "message": "Ordre mis à jour", "data": { "updated": 2 } }
```

> Les UIDs non reconnus ou sans `sort_order` sont ignorés silencieusement.

### PUT /puzzle/admin/images/{uid}

Corps JSON (tous les champs sont optionnels) :

| Champ | Type | Description |
| --- | --- | --- |
| `label_fr` | string | Nouveau label français |
| `label_en` | string | Nouveau label anglais |
| `label_es` | string | Nouveau label espagnol |
| `status` | string | `active` ou `inactive` |
| `sort_order` | integer | Nouvelle position dans le carrousel |
| `is_carousel` | boolean | Inclure (`true`) ou exclure (`false`) du carrousel |

Réponse `200` — retourne l'objet image complet après modification :

```json
{
  "success": true,
  "message": "Image mise à jour",
  "data": {
    "uid": "550e8400-e29b-41d4-a716-446655440000",
    "thumb_url": "http://localhost/cmem2_API/puzzle/admin/thumb/550e8400-e29b-41d4-a716-446655440000",
    "full_url": "http://localhost/cmem2_API/puzzle/admin/image/550e8400-e29b-41d4-a716-446655440000",
    "is_carousel": true,
    "sort_order": 2,
    "status": "active",
    "translations": { "fr": "Coucher de soleil", "en": "Sunset" },
    "created_at": "2026-04-06 14:00:00"
  }
}
```

Réponse `404` si l'UID est introuvable.

### DELETE /puzzle/admin/images/{uid}

Supprime l'image, ses traductions et ses fichiers physiques (full + thumb).

| Code | Cause |
| --- | --- |
| 200 | `{ "success": true, "message": "Image supprimée." }` |
| 404 | UID introuvable |
| 409 | Image utilisée dans une session partagée active (impossible de supprimer) |

---

## Endpoints — Thèmes

| Méthode | Endpoint | Description |
| --- | --- | --- |
| GET | `/puzzle/admin/themes` | Lister les thèmes |
| POST | `/puzzle/admin/themes` | Créer un thème (multipart) |
| PUT | `/puzzle/admin/themes/{slug}` | Modifier label / miniature / statut |
| DELETE | `/puzzle/admin/themes/{slug}` | Supprimer un thème |
| PUT | `/puzzle/admin/themes/{slug}/images` | Remplacer la liste d'images du thème |

### GET /puzzle/admin/themes

Réponse `200` :

```json
{
  "success": true,
  "message": "Thèmes chargés",
  "data": [
    {
      "slug": "nature",
      "thumb_url": "http://localhost/cmem2_API/puzzle/admin/thumb/theme/nature",
      "sort_order": 1,
      "status": "active",
      "image_count": 12,
      "translations": {
        "fr": "Nature",
        "en": "Nature"
      }
    }
  ],
  "timestamp": "2026-04-06 14:00:00"
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
| `status` | string | non | `active` (défaut) ou `inactive` |
| `sort_order` | integer | non | Position ; auto-calculé si absent |

Réponse `201` :

```json
{
  "success": true,
  "message": "Thème créé",
  "data": {
    "slug": "nature",
    "thumb_url": "http://localhost/cmem2_API/puzzle/admin/thumb/theme/nature",
    "sort_order": 1,
    "status": "active",
    "image_count": 0,
    "translations": { "fr": "Nature", "en": "Nature", "es": null }
  }
}
```

| Code | Cause |
| --- | --- |
| 201 | Thème créé |
| 409 | `{ "success": false, "message": "Ce slug existe déjà", "errors": [...] }` |
| 422 | Slug invalide, `label_fr` manquant ou fichier absent/invalide |

### PUT /puzzle/admin/themes/{slug}

Corps `multipart/form-data` (tous les champs optionnels) :

| Champ | Type | Description |
| --- | --- | --- |
| `thumb` | file | Nouvelle miniature (remplace l'ancienne) |
| `label_fr` | string | Nouveau label français |
| `label_en` | string | Nouveau label anglais |
| `label_es` | string | Nouveau label espagnol |
| `status` | string | `active` ou `inactive` |
| `sort_order` | integer | Nouvelle position |

Réponse `200` — retourne l'objet thème complet après modification :

```json
{
  "success": true,
  "message": "Thème mis à jour",
  "data": {
    "slug": "nature",
    "thumb_url": "http://localhost/cmem2_API/puzzle/admin/thumb/theme/nature",
    "sort_order": 1,
    "status": "active",
    "image_count": 12,
    "translations": { "fr": "Nature", "en": "Nature" }
  }
}
```

### DELETE /puzzle/admin/themes/{slug}

Supprime le thème, ses traductions, ses associations et la miniature physique.

| Code | Cause |
| --- | --- |
| 200 | `{ "success": true, "message": "Thème supprimé." }` |
| 404 | Slug introuvable |

### PUT /puzzle/admin/themes/{slug}/images

**Remplacement complet** de la liste d'images associées au thème.

Corps JSON :

| Champ | Type | Requis | Description |
| --- | --- | --- | --- |
| `image_uids` | string[] | oui | Tableau d'UIDs d'images à associer (peut être vide `[]`) |

Réponse `200` :

```json
{ "success": true, "message": "Images du thème mises à jour", "data": { "count": 3 } }
```

> `count` = nombre d'images effectivement associées. UIDs invalides ou introuvables sont ignorés silencieusement.

---

## Formats de données

### Champ is_carousel

`is_carousel` (boolean) indique si l'image apparaît dans le **carrousel de sélection de l'app mobile**.
Une image peut être `status: active` mais `is_carousel: false` — elle est alors disponible uniquement via les thèmes.

### Statuts

| Valeur | Description |
| --- | --- |
| `active` | Visible (carrousel ou thème selon `is_carousel`) |
| `inactive` | Masquée pour les joueurs (admin uniquement) |

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
