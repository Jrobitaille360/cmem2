# Plan — Items Manager (plugin `items`)

## Vue d'ensemble

Plugin générique de gestion d'items : chaque item appartient à un utilisateur,
porte un accès configurable (`private` / `public` / `share`), des catégories
libres et un blob JSON arbitraire. Des utilisateurs tiers peuvent se voir
accorder lecture et/ou écriture sur un item partagé.

---

## 1. Schéma de base de données

### Ce qui est déjà en place

- Table `users` avec `id` et `role` (ADMINISTRATEUR / UTILISATEUR).
- Authentification JWT : `$user['user_id']`, `$user['role']`.
- `BaseModel` avec soft-deletes (`deleted_at`), `getDb()` PDO singleton.

### Table `items`

| Colonne | Type | Notes |
| - | - | - |
| `id` | INT UNSIGNED AUTO_INCREMENT PK | |
| `owner_user_id` | INT UNSIGNED NOT NULL | FK → `users.id` |
| `access` | ENUM('private','public','share') NOT NULL DEFAULT 'private' | |
| `categories` | JSON NULL | Tableau de chaînes `["a","b"]` |
| `json_item` | LONGTEXT NULL | Blob arbitraire — stocké tel quel |
| `created_at` | DATETIME NOT NULL DEFAULT NOW() | |
| `updated_at` | DATETIME NOT NULL DEFAULT NOW() ON UPDATE NOW() | |
| `deleted_at` | DATETIME NULL | Soft-delete |

Index : `owner_user_id`, `access`, `deleted_at`.

### Table `item_user_access`

Relations de partage pour les items `share` **et** surcharges explicites
sur les items `public`.

| Colonne | Type | Notes |
| - | - | - |
| `id` | INT UNSIGNED AUTO_INCREMENT PK | |
| `item_id` | INT UNSIGNED NOT NULL | FK → `items.id` ON DELETE CASCADE |
| `user_id` | INT UNSIGNED NOT NULL | FK → `users.id` ON DELETE CASCADE |
| `can_update` | TINYINT(1) NOT NULL DEFAULT 0 | 1 = lecture + écriture |
| `created_at` | DATETIME NOT NULL DEFAULT NOW() | |

Index unique : `(item_id, user_id)`.

---

## 2. Règles de contrôle d'accès

| Action | private | share | public |
| - | - | - | - |
| Lire | owner, admin | owner, admin, user listé | tout utilisateur JWT |
| Mettre à jour le contenu | owner, admin | owner, admin, user listé avec `can_update=1` | tout utilisateur JWT (sauf révocation explicite envisageable) |
| Supprimer | owner, admin | owner, admin | owner, admin |
| Changer `access` | owner, admin | owner, admin | owner, admin |
| Gérer la liste `item_user_access` | owner, admin | owner, admin | owner, admin |

---

## 3. Endpoints

Préfixe : `/items`

### Items CRUD

| Méthode | URI | Qui | Description |
| - | - | - | - |
| `GET` | `/items` | JWT requis | Liste des items accessibles (propres + partagés + publics) |
| `POST` | `/items` | JWT requis | Créer un item (owner = user courant) |
| `GET` | `/items/{id}` | selon règles | Lire un item |
| `PUT` | `/items/{id}` | selon règles | Mettre à jour `categories` et/ou `json_item` |
| `DELETE` | `/items/{id}` | owner, admin | Soft-delete |

**Filtres sur `GET /items` :**

- `?access=private|public|share` — filtrer par mode d'accès
- `?category=a` ou `?category=a,b,c` — items dont la liste contient **au moins une** des valeurs (OR) ; plusieurs valeurs séparées par virgule
- `?category_match=all` — passer en mode AND (item doit contenir **toutes** les valeurs)
- `?owner=me` (défaut) | `?owner=all` — propres items vs. tous accessibles
- `?limit=50&offset=0` — pagination

### Endpoints catégories

| Méthode | URI | Qui | Description |
| - | - | - | - |
| `GET` | `/items/categories` | JWT requis | Liste toutes les catégories distinctes des items accessibles, triées alphabétiquement |
| `GET` | `/items/categories/{name}` | JWT requis | Items appartenant à la catégorie `name` (alias propre de `GET /items?category={name}`) |

### Gestion des partages (owner ou admin uniquement)

| Méthode | URI | Description |
| - | - | - |
| `PUT` | `/items/{id}/access` | Changer le mode (`access`) |
| `GET` | `/items/{id}/shares` | Lister les utilisateurs dans `item_user_access` |
| `POST` | `/items/{id}/shares` | Ajouter un utilisateur (body : `user_id`, `can_update`) |
| `PUT` | `/items/{id}/shares/{user_id}` | Modifier `can_update` pour un utilisateur |
| `DELETE` | `/items/{id}/shares/{user_id}` | Retirer un utilisateur |

---

## 4. Architecture du plugin

Structure calquée sur le plugin `puzzle` :

```text
src/items/
├── plugin.json
├── autoloader.php
├── Controllers/
│   ├── ItemController.php       # CRUD principal
│   └── ItemShareController.php  # Gestion des partages
├── Models/
│   ├── Item.php                 # BaseModel — table items
│   └── ItemUserAccess.php       # BaseModel — table item_user_access
├── Routing/
│   └── ItemRouteHandler.php     # extends BaseRouteHandler
└── Services/
    └── ItemAccessService.php    # Logique de vérification d'accès centralisée
```

**`plugin.json`**

```json
{
  "name": "items",
  "version": "1.0.0",
  "routePrefix": "items",
  "routeHandler": "Items\\Routing\\ItemRouteHandler",
  "autoloader": "src/items/autoloader.php"
}
```

---

## 5. Améliorations et maintenances à prévoir

### Améliorations

- Pagination sur `GET /items` (paramètres `limit` / `offset`).
- Recherche plein texte dans `json_item` (MySQL `JSON_SEARCH` ou `LIKE`).
- Historique des modifications (`item_audit_log` optionnel).
- Accès en lecture seule explicite : `can_update = 0` dans `item_user_access`
  pour les items `public` (permettrait de bloquer un user spécifique).

### Maintenances

- Index MySQL à surveiller : `categories` (JSON — envisager un index généré
  si le filtrage est fréquent).
- Nettoyage périodique des lignes soft-deleted (cron existant à brancher).
- Documenter les clés attendues dans `json_item` par consumer (hors scope API).

---

## 6. Phases d'implantation

### Phase 1 — Base de données et migrations

#### Actions

1. Créer `docs/items/migrations/001_items_base.sql` :
   - Table `items`
   - Table `item_user_access`
   - Index et clés étrangères

#### Enjeux

- Valider que la FK `owner_user_id → users.id` est compatible avec le moteur
  existant (InnoDB, utf8mb4).
- Décider si `json_item` est `JSON` natif ou `LONGTEXT` (flexibilité vs.
  validation MySQL). Recommandation : `LONGTEXT` pour rester cohérent avec
  `puzzle_shared`.

#### Tests

- Insérer manuellement des lignes de test dans les deux tables.
- Vérifier que `ON DELETE CASCADE` sur `item_user_access.item_id` fonctionne.

#### Conditions de fin de phase

- Migration applicable sans erreur sur l'environnement de développement.
- Les deux tables sont visibles et fonctionnelles.

---

### Phase 2 — Models

#### Actions

1. `Item.php` — méthodes :
   - `findById(int $id)` — avec soft-delete guard
   - `findAccessibleByUser(int $userId, array $filters)` — LEFT JOIN
     `item_user_access`, filtre `access`/`category`/`owner`
   - `create(array $data): int` — retourne l'id inséré
   - `update(int $id, array $data): void`
   - `softDelete(int $id): void`
   - `findDistinctCategories(int $userId): array` — retourne un tableau trié
     de toutes les catégories distinctes des items accessibles à l'utilisateur
2. `ItemUserAccess.php` — méthodes :
   - `findByItem(int $itemId): array`
   - `findByItemAndUser(int $itemId, int $userId): ?array`
   - `upsert(int $itemId, int $userId, int $canUpdate): void`
   - `delete(int $itemId, int $userId): void`

#### Enjeux

- La requête `findAccessibleByUser` est la plus complexe :
  filtrer `private` (owner), `share` (JOIN), `public` (tout le monde) en
  une seule requête efficace.
- `categories` : stocker en JSON.
  - Filtre **OR** (une valeur) : `JSON_CONTAINS(categories, JSON_QUOTE(?))`.
  - Filtre **OR** (plusieurs valeurs) : `JSON_CONTAINS(categories, JSON_QUOTE(?)) OR JSON_CONTAINS(…)`.
  - Filtre **AND** : autant de `AND JSON_CONTAINS(…)` que de valeurs.
  - Agrégation pour `findDistinctCategories` : `JSON_TABLE` (MySQL 8+) ou
    extraction applicative si la version MySQL est antérieure.

#### Tests

- Tests manuels des requêtes SQL directement en CLI MySQL.

#### Conditions de fin de phase

- Chaque méthode retourne le résultat attendu pour les cas nominal et vide.
- Le filtre multi-catégories OR et AND produit les bons résultats.

---

### Phase 3 — Service d'accès (`ItemAccessService`)

#### Actions

1. `canRead(array $user, array $item): bool`
2. `canUpdate(array $user, array $item): bool`
3. `canDelete(array $user, array $item): bool`
4. `canManageShares(array $user, array $item): bool`

#### Enjeux

- Centraliser toute la logique pour éviter la duplication dans les controllers.
- Passer l'objet item **et** la relation `item_user_access` pour éviter
  les N+1 (chercher la relation une seule fois dans le controller).

#### Tests

- Tester chaque combinaison `access × role × relation` manuellement ou
  avec des assertions en CLI.

#### Conditions de fin de phase

- Le service couvre toutes les combinaisons définies dans le tableau d'accès
  (§ 2).

---

### Phase 4 — Controllers et routing

#### Actions

1. `ItemController.php` :
   - `list(array $user)` — `GET /items`
   - `create(array $user)` — `POST /items`
   - `show(array $user, int $id)` — `GET /items/{id}`
   - `update(array $user, int $id)` — `PUT /items/{id}`
   - `delete(array $user, int $id)` — `DELETE /items/{id}`
   - `listCategories(array $user)` — `GET /items/categories`
   - `byCategory(array $user, string $name)` — `GET /items/categories/{name}`
2. `ItemShareController.php` :
   - `listShares(array $user, int $id)` — `GET /items/{id}/shares`
   - `changeAccess(array $user, int $id)` — `PUT /items/{id}/access`
   - `addShare(array $user, int $id)` — `POST /items/{id}/shares`
   - `updateShare(array $user, int $id, int $targetUserId)` — `PUT /items/{id}/shares/{user_id}`
   - `removeShare(array $user, int $id, int $targetUserId)` — `DELETE /items/{id}/shares/{user_id}`
3. `ItemRouteHandler.php` — extends `BaseRouteHandler` :
   - `requiresAuth = true`
   - Parse l'URI en segments : `items / {id|categories} / {shares|name} / {user_id}`
   - Priorité : `categories` avant `{id}` pour éviter la collision de routes
   - Dispatch vers les deux controllers

#### Enjeux

- Enregistrer le handler dans le `PluginManager` via `plugin.json`.
- Valider les inputs (id entier, `access` dans l'enum, `user_id` existant)
  avant d'appeler le model.
- Retourner `json_item` et `categories` déjà décodés côté PHP dans les
  réponses JSON.
- Pour `?category=a,b,c` : éclater la valeur par virgule côté PHP, nettoyer
  les espaces, puis construire la clause SQL dynamiquement (paramètres liés,
  jamais d'interpolation directe).
- Gérer la collision `GET /items/categories` vs `GET /items/{id}` en testant
  le segment avant de tenter un cast en entier.

#### Tests

- Appels curl / Postman sur chaque endpoint avec différents rôles (owner,
  user partagé, admin, user sans accès).
- Vérifier les codes HTTP attendus : 200, 201, 204, 400, 401, 403, 404.

#### Conditions de fin de phase

- Tous les endpoints documentés au § 3 répondent correctement.
- Les contrôles d'accès bloquent les requêtes non autorisées.

---

### Phase 5 — Tests (`private/tests_mine/test_items.php`)

#### Actions

Créer `private/tests_mine/test_items.php` en suivant le patron de
`test_puzzle_share.php` (include `test_new_base.php`, `callNewApi`,
helpers DB directs via PDO).

Scénarios à couvrir dans l'ordre du flux réel :

1. **Préparation** — connexion admin + création de deux comptes utilisateur
   de test (userA = owner, userB = user tiers) via DB ou endpoint.
2. **Sécurité de base** — appel sans token → 401 ; token invalide → 401.
3. **`POST /items`** — userA crée trois items :
   - item_private (`access=private`, catégorie `["test","alpha"]`)
   - item_share (`access=share`, catégorie `["test"]`)
   - item_public (`access=public`, catégorie `["beta"]`)
4. **`GET /items`** — userA voit ses trois items ; userB ne voit que
   item_public (aucun partage encore).
5. **`GET /items/{id}`** — userA lit chaque item (200) ; userB tente
   item_private → 403 ; userB lit item_public → 200.
6. **`PUT /items/{id}`** — userA met à jour item_private → 200 ;
   userB tente → 403 ; userB met à jour item_public → 200.
7. **Gestion des partages** :
   - userB tente `PUT /items/{id}/access` sur item_share → 403.
   - userA `POST /items/{id}/shares` — ajoute userB avec `can_update=0`.
   - `GET /items/{id}/shares` — vérifie la présence de userB.
   - userB lit item_share → 200 ; userB tente PUT → 403.
   - userA `PUT /items/{id}/shares/{userB_id}` → `can_update=1`.
   - userB met à jour item_share → 200.
   - userA `DELETE /items/{id}/shares/{userB_id}` → userB retombe à 403.
8. **Changement d'`access`** — userA passe item_share en `private` →
   userB (sans relation) → 403.
9. **Admin** — admin change l'`access` d'un item appartenant à userA → 200 ;
   admin supprime un item → 204.
10. **Filtres sur `GET /items`** :
    - `?access=public` — retourne uniquement les items publics accessibles.
    - `?owner=all` — retourne items propres + partagés + publics.
11. **Catégories** :
    - Créer des items avec catégories variées : `["alpha","common"]`,
      `["beta","common"]`, `["gamma"]`.
    - `GET /items/categories` → tableau trié `["alpha","beta","common","gamma"]`
      (selon les items accessibles à l'utilisateur courant).
    - `GET /items/categories/common` → retourne les deux items portant `common`.
    - `GET /items/categories/inexistante` → tableau vide `[]`, code 200.
    - `GET /items?category=alpha` → 1 item.
    - `GET /items?category=alpha,beta` (OR) → 2 items.
    - `GET /items?category=alpha,common&category_match=all` (AND) → 1 item
      (celui qui a les deux).
    - `GET /items?category=alpha,gamma&category_match=all` (AND) → 0 item.
    - Vérifier que userB ne voit pas les catégories d'items `private` de userA.
12. **`DELETE /items/{id}`** — userB tente de supprimer item_public → 403 ;
    userA supprime son propre item → 204 ; re-lecture → 404.
13. **Nettoyage** — suppression directe via PDO de tous les items et
    `item_user_access` créés durant les tests ; suppression des comptes de
    test si créés en base.

#### Enjeux

- Réutiliser les helpers `callNewApi` et `printResult` de `test_new_base.php`.
- Obtenir les JWTs de userA et userB en appelant l'endpoint d'authentification
  existant (ne pas hard-coder en dehors des credentials admin).
- Le nettoyage doit s'exécuter même en cas d'échec intermédiaire (bloc
  `finally` ou appel systématique en fin de script).

#### Conditions de fin de phase

- `php private/tests_mine/test_items.php` s'exécute sans échec.
- Tous les scénarios du tableau d'accès (§ 2) sont verts.
- Aucune donnée de test ne subsiste en base après exécution.

---

### Phase 6 — Documentation et entrypoints

#### Actions

1. Ajouter les nouvelles routes dans `docs/core/API_ENDPOINTS_v2_0_0.json`.
2. Créer `docs/items/migrations/001_items_base.sql` définitif.
3. Mettre à jour `CHANGELOG.md`.
4. Commit + push.

#### Enjeux

- Respecter le format JSON existant des entrypoints clients.

#### Conditions de fin de phase

- Le fichier JSON entrypoints est à jour et validé.
- Le changelog reflète la nouvelle fonctionnalité.
