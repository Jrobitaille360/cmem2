# Guide — Plugin Items

Version 1.0.0 · Base URL : `/items`

> Référence complète : [API_ITEMS_ENDPOINTS.json](API_ITEMS_ENDPOINTS.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [Modèle de données](#modèle-de-données)
- [Règles d'accès](#règles-daccès)
- [Endpoints — CRUD](#endpoints--crud)
- [Endpoints — Catégories](#endpoints--catégories)
- [Endpoints — Partages](#endpoints--partages)
- [Filtres et pagination](#filtres-et-pagination)
- [Codes d'erreur](#codes-derreur)
- [Exemples complets](#exemples-complets)

---

## Vue d'ensemble

Le plugin Items est un gestionnaire générique d'items appartenant à un utilisateur.
Chaque item porte :

- un mode d'accès : `private`, `public` ou `share`
- une liste de catégories libres (tableau JSON)
- un blob JSON arbitraire (`json_item`) dont le schéma est libre

Les items `share` peuvent être partagés avec des utilisateurs nommés ;
les items `public` sont lisibles sans JWT (accès anonyme) et modifiables par tout utilisateur authentifié.

---

## Authentification

La plupart des endpoints exigent un JWT valide :

```http
Authorization: Bearer <jwt_token>
```

**Exception** : `GET /items/{id}` est accessible sans JWT si l'item a le mode `access=public`.
Tous les autres endpoints (`GET /items`, écriture, partages) requièrent un JWT.

Obtenir un token → `POST /auth/login`.

---

## Modèle de données

### Item

| Champ | Type | Description |
| - | - | - |
| `id` | integer | Identifiant unique |
| `owner_user_id` | integer | Propriétaire |
| `access` | string | `private` \| `public` \| `share` |
| `categories` | array | Tableau de chaînes ex. `["alpha","beta"]` |
| `json_item` | any | Contenu libre (objet, tableau, null…) |
| `created_at` | datetime | Date de création |
| `updated_at` | datetime | Date de dernière modification |

### Relation de partage (`share`)

| Champ | Type | Description |
| - | - | - |
| `user_id` | integer | Utilisateur ayant accès |
| `can_update` | 0 \| 1 | 0 = lecture seule, 1 = lecture + écriture |

---

## Règles d'accès

| Action | private | share | public |
| - | - | - | - |
| Lire | owner, admin | owner, admin, invités | **tout le monde (sans JWT)** |
| Modifier le contenu | owner, admin | owner, admin, invités `can_update=1` | tout JWT |
| Supprimer | owner, admin | owner, admin | owner, admin |
| Changer `access` | owner, admin | owner, admin | owner, admin |
| Gérer les invités | owner, admin | owner, admin | owner, admin |

---

## Endpoints — CRUD

### `GET /items`

Liste des items accessibles à l'utilisateur courant.

**Filtres (query string) :**

| Paramètre | Valeurs | Défaut | Description |
| - | - | - | - |
| `owner` | `me` \| `all` | `me` | `me` = items propres ; `all` = propres + partagés + publics |
| `access` | `private` \| `public` \| `share` | — | Filtre par mode d'accès |
| `category` | `alpha` ou `alpha,beta,gamma` | — | OR par défaut — voir aussi `category_match` |
| `category_match` | `any` \| `all` | `any` | `all` active le mode AND (item doit avoir toutes les valeurs) |
| `limit` | entier | 50 | Maximum 200 |
| `offset` | entier | 0 | Décalage pour la pagination |

**Réponse 200 :**

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 1,
        "owner_user_id": 42,
        "access": "share",
        "categories": ["alpha", "beta"],
        "json_item": { "titre": "Mon item" },
        "created_at": "2026-04-15 20:00:00",
        "updated_at": "2026-04-15 20:00:00"
      }
    ],
    "count": 1
  }
}
```

---

### `POST /items`

Créer un nouvel item (owner = utilisateur courant).

**Body JSON :**

| Champ | Type | Requis | Description |
| - | - | - | - |
| `access` | string | Non (défaut `private`) | `private` \| `public` \| `share` |
| `categories` | array | Non | Tableau de chaînes |
| `json_item` | any | Non | Contenu libre |

**Réponse 201 :**

```json
{
  "success": true,
  "data": { "item": { /* item complet */ } }
}
```

**Erreurs :**

- `422` — `access` invalide

---

### `GET /items/{id}`

Lire un item selon les règles d'accès.

**Réponse 200 :** item complet (mêmes champs que ci-dessus).

**Erreurs :** `403` accès refusé · `404` introuvable.

---

### `PUT /items/{id}`

Mettre à jour `categories` et/ou `json_item`.

> L'`access` n'est **pas** modifiable via cet endpoint — utiliser `PUT /items/{id}/access`.

**Body JSON :** un ou plusieurs de `categories`, `json_item`.

**Réponse 200 :** item mis à jour.

**Erreurs :** `403` · `404` · `422` (aucune donnée envoyée).

---

### `DELETE /items/{id}`

Soft-delete d'un item (owner ou admin uniquement).

**Réponse 204 :** aucun corps.

**Erreurs :** `403` · `404`.

---

## Endpoints — Catégories

### `GET /items/categories`

Retourne toutes les catégories distinctes des items accessibles à l'utilisateur courant,
triées alphabétiquement.

**Réponse 200 :**

```json
{
  "success": true,
  "data": { "categories": ["alpha", "beta", "common", "gamma"] }
}
```

---

### `GET /items/categories/{name}`

Retourne les items accessibles appartenant à la catégorie `name`.

Supporte les mêmes paramètres `limit` et `offset` que `GET /items`.

**Réponse 200 :**

```json
{
  "success": true,
  "data": { "items": [ /* ... */ ], "count": 2 }
}
```

Items vides → `count: 0`, code 200 (pas de 404).

---

## Endpoints — Partages

> Tous ces endpoints sont réservés au **propriétaire** ou à un **administrateur**.

### `PUT /items/{id}/access`

Changer le mode d'accès.

**Body JSON :** `{ "access": "private" | "public" | "share" }`

**Réponse 200 :** `{ "access": "share" }`

---

### `GET /items/{id}/shares`

Lister les utilisateurs ayant accès (accessible à tout utilisateur pouvant lire l'item).

**Réponse 200 :**

```json
{
  "success": true,
  "data": {
    "shares": [
      { "id": 1, "item_id": 5, "user_id": 10, "can_update": 1,
        "user_name": "Alice", "user_email": "alice@example.com" }
    ]
  }
}
```

---

### `POST /items/{id}/shares`

Ajouter un utilisateur à la liste de partage.

**Body JSON :**

| Champ | Type | Description |
| - | - | - |
| `user_id` | integer | Utilisateur cible (requis) |
| `can_update` | 0 \| 1 | Droit en écriture (défaut 0) |

**Réponse 201.**

**Erreurs :** `404` utilisateur introuvable · `422` user_id manquant ou propriétaire ciblé.

---

### `PUT /items/{id}/shares/{user_id}`

Modifier `can_update` d'un invité existant.

**Body JSON :** `{ "can_update": 1 }`

**Réponse 200.**

---

### `DELETE /items/{id}/shares/{user_id}`

Retirer un utilisateur de la liste de partage.

**Réponse 200.**

**Erreur :** `404` relation introuvable.

---

## Filtres et pagination

```http
GET /items?owner=all&category=alpha,beta&category_match=any&limit=20&offset=0
Authorization: Bearer <token>
```

```http
GET /items?category=alpha,common&category_match=all
```

(Retourne uniquement les items ayant **les deux** catégories.)

---

## Codes d'erreur

| Code | Signification |
| - | - |
| 200 | Succès |
| 201 | Créé |
| 204 | Supprimé (aucun corps) |
| 400 | Paramètre invalide (ex. id non numérique) |
| 401 | JWT absent ou invalide |
| 403 | Accès refusé (droits insuffisants) |
| 404 | Ressource introuvable |
| 405 | Méthode non autorisée |
| 422 | Validation échouée |

---

## Exemples complets

### Créer un item et le partager

```http
POST /items
Authorization: Bearer <token>
Content-Type: application/json

{
  "access": "share",
  "categories": ["projet", "2026"],
  "json_item": { "titre": "Rapport Q1", "statut": "brouillon" }
}
```

```http
PUT /items/7/access
Authorization: Bearer <token>
Content-Type: application/json

{ "access": "share" }
```

```http
POST /items/7/shares
Authorization: Bearer <token>
Content-Type: application/json

{ "user_id": 42, "can_update": 1 }
```

### Filtrer par catégories (OR)

```http
GET /items?owner=all&category=projet,rapport
Authorization: Bearer <token>
```

### Filtrer par catégories (AND)

```http
GET /items?owner=all&category=projet,2026&category_match=all
Authorization: Bearer <token>
```

### Lister toutes les catégories disponibles

```http
GET /items/categories
Authorization: Bearer <token>
```
