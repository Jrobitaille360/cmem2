# PLAN — Accessibilité au téléchargement des fichiers

**Date :** 2026-04-30
**Branche cible :** release/v2.4.0
**Auteur :** JRobitaille

---

## Contexte

Actuellement, le téléchargement d'un fichier (`GET /files/{id}`) est restreint au
propriétaire du fichier ou à un administrateur. Il n'existe aucune notion de
visibilité configurable : un utilisateur ne peut pas partager un fichier avec
d'autres utilisateurs authentifiés.

L'objectif est d'introduire un champ `accessibility` sur chaque fichier :

| Valeur | Qui peut télécharger |
| - | - |
| `public` | Tout utilisateur authentifié (JWT valide) |
| `private` | Uniquement le déposant (`uploaded_by`) ou un `administrateur` |

La valeur par défaut est `public` (comportement le plus ouvert entre utilisateurs
connectés, sans exposer les fichiers à l'extérieur de l'application).

---

## Ce qui est déjà en place

| Élément | État |
| - | - |
| Table `files` avec `uploaded_by` | ✅ |
| Vérification propriétaire/admin dans `download()` | ✅ (logique à étendre) |
| `getFileInfo()` retourne les métadonnées | ✅ |
| Upload accepte des paramètres optionnels (`folder`) | ✅ |
| Tests d'upload, download, delete dans `test_files.php` | ✅ |

---

## Améliorations à apporter

### 1. Base de données — colonne `accessibility`

- Ajouter `accessibility ENUM('public','private') NOT NULL DEFAULT 'public'` à la table `files`.
- Les fichiers existants héritent de `public` via la valeur par défaut.

### 2. Modèle — `File.php`

- **`create()`** : inclure `accessibility` dans l'INSERT.
- **`update()`** : permettre la mise à jour de `accessibility`.
- **`getByUserId()`** : inclure `accessibility` dans le SELECT de listing.
- **`findById()` (BaseModel)** : déjà appelé par le contrôleur — vérifier que le champ
  est bien retourné (SELECT `*` → oui, aucun changement requis).

### 3. Contrôleur — `FileController.php`

#### `upload($userId)`

- Accepter le paramètre optionnel `accessibility` (POST body).
- Valider : valeur doit être `public` ou `private` ; par défaut `public`.
- Passer la valeur au modèle lors de l'INSERT.

#### `download($fileId, $userId, $role)`

- Charger `accessibility` depuis la BDD.
- Nouvelle logique d'autorisation :
  - `private` → propriétaire ou administrateur (comportement actuel).
  - `public` → tout utilisateur authentifié (JWT valide suffit).
- Retourner `403` si la règle n'est pas respectée.

#### `getFileInfo($fileId, $userId, $role)`

- Inclure `accessibility` dans la réponse JSON.
- Même logique de visibilité que `download` (un utilisateur non autorisé à
  télécharger ne doit pas non plus voir les métadonnées d'un fichier `private`
  dont il n'est pas propriétaire).

#### Nouveau : `updateAccessibility($fileId, $userId, $role)`

- Route : `PATCH /files/{id}/accessibility`
- Corps : `{ "accessibility": "public"|"private" }`
- Autorisation : propriétaire ou administrateur.
- Réponse : `200` avec le fichier mis à jour.

### 4. Routeur — `FileRouteHandler.php`

- Ajouter la branche `PATCH /files/{id}/accessibility`.

### 5. Tests — `private/tests/test_files.php`

Nouvelles sections à ajouter après les tests existants :

#### Section A — Upload avec `accessibility`

| # | Cas | Résultat attendu |
| - | - | - |
| A1 | Upload sans paramètre `accessibility` | `accessibility=public` par défaut |
| A2 | Upload avec `accessibility=public` | `accessibility=public` |
| A3 | Upload avec `accessibility=private` | `accessibility=private` |
| A4 | Upload avec valeur invalide (`accessibility=secret`) | `422` |

#### Section B — Download selon accessibilité

| # | Acteur | Fichier | Résultat attendu |
| - | - | - | - |
| B1 | Autre utilisateur (non propriétaire) | `public` | `200` ✅ |
| B2 | Autre utilisateur (non propriétaire) | `private` | `403` ✅ |
| B3 | Propriétaire | `private` | `200` ✅ |
| B4 | Administrateur | `private` (d'un autre user) | `200` ✅ |
| B5 | Non authentifié | `public` | `401` ✅ |

#### Section C — `GET /files/{id}/info` selon accessibilité

| # | Acteur | Fichier | Résultat attendu |
| - | - | - | - |
| C1 | Autre utilisateur | `public` | `200`, champ `accessibility=public` |
| C2 | Autre utilisateur | `private` | `403` |
| C3 | Propriétaire | `private` | `200`, champ `accessibility=private` |

#### Section D — `PATCH /files/{id}/accessibility`

| # | Cas | Résultat attendu |
| - | - | - |
| D1 | Propriétaire change `public` → `private` | `200` |
| D2 | Propriétaire change `private` → `public` | `200` |
| D3 | Autre utilisateur tente de changer | `403` |
| D4 | Administrateur change pour n'importe quel fichier | `200` |
| D5 | Valeur invalide | `422` |

### 6. Documentation

- **`docs/auth_groups/GUIDE.md`** : ajouter `accessibility` aux tableaux des
  endpoints `POST /files`, `GET /files/{id}`, `GET /files/{id}/info`,
  `PATCH /files/{id}/accessibility`.
- **`docs/core/API_ENDPOINTS.json`** : ajouter/mettre à jour les entrées
  correspondantes avec le nouveau paramètre et la nouvelle route.

---

## Maintenances à prévoir

- Vérifier que `listByFolder()` (admin) inclut `accessibility` dans sa réponse.
- Vérifier que `getUserFiles()` inclut `accessibility` dans le listing paginé.
- S'assurer que la restauration d'un fichier soft-deleted conserve `accessibility`.

---

## Phases d'implantation

### Phase 1 — Migration SQL *(priorité 1)*

**Actions**

1. Créer `docs/20260430_files_accessibility.sql` :

   ```sql
   ALTER TABLE `files`
     ADD COLUMN `accessibility` ENUM('public','private') NOT NULL DEFAULT 'public'
     AFTER `uploaded_by`;
   ```

**Enjeux**

- Migration non destructive (valeur par défaut `public`).
- Aucune donnée existante n'est affectée.

**Tests**

- Exécuter la migration sur la BDD de dev et vérifier `DESCRIBE files`.

**Condition de fin**

- Colonne présente avec la bonne définition.

---

### Phase 2 — Modèle `File.php` *(priorité 2)*

**Actions**

1. Ajouter `accessibility` dans le tableau `$data` de `create()`.
2. Ajouter `accessibility` dans le `SET` de `update()`.
3. Vérifier que `getByUserId()` retourne `accessibility` (SELECT `*` → OK ou
   ajout explicite si besoin).

**Enjeux**

- Ne pas casser les appels existants (paramètre avec valeur par défaut `'public'`).

**Tests**

- Unitaire : créer un fichier avec `accessibility=private`, lire, vérifier.

**Condition de fin**

- `accessibility` correctement inséré et mis à jour en BDD.

---

### Phase 3 — Contrôleur `FileController.php` *(priorité 3)*

**Actions**

1. `upload()` : lire et valider `accessibility` depuis `$_POST`, défaut `public`.
2. `download()` : revoir la logique d'autorisation (voir § 3 ci-dessus).
3. `getFileInfo()` : ajouter `accessibility` à la réponse et appliquer la même
   règle de visibilité.
4. Créer `updateAccessibility()` : valider, vérifier propriétaire/admin, mettre à jour.

**Enjeux**

- Un `public` ne signifie pas « sans authentification » — le JWT reste obligatoire.
- `getFileInfo()` doit refuser l'accès si le fichier est `private` et que
  l'appelant n'est ni propriétaire ni admin.

**Tests**

- Tous les cas des Sections A, B, C, D du plan de tests.

**Condition de fin**

- Chaque cas de test passe avec le code HTTP attendu.

---

### Phase 4 — Routeur `FileRouteHandler.php` *(priorité 4)*

**Actions**

1. Ajouter le branchement `PATCH /files/{id}/accessibility` → `updateAccessibility()`.

**Enjeux**

- Ordre des routes : s'assurer que `/{id}/accessibility` ne masque pas d'autre route.

**Tests**

- Section D du plan de tests.

**Condition de fin**

- `PATCH /files/{id}/accessibility` répond correctement.

---

### Phase 5 — Tests `test_files.php` *(priorité 5)*

**Actions**

1. Ajouter les sections A, B, C, D après les tests existants.
2. Réutiliser les helpers `callNewApi` / `testNewResult` / `printNewSection`.
3. Créer un deuxième utilisateur dans la fixture si non déjà présent, pour tester
   l'accès inter-utilisateurs.

**Enjeux**

- Les tokens admin et user doivent être disponibles en début de section.
- Cleanup : supprimer les fichiers de test créés en fin de section.

**Condition de fin**

- `php private/tests/test_files.php` passe 100 % des assertions.

---

### Phase 6 — Documentation *(priorité 6)*

**Actions**

1. Mettre à jour `docs/auth_groups/GUIDE.md`.
2. Mettre à jour `docs/core/API_ENDPOINTS.json` (ou le fichier équivalent actif).

**Condition de fin**

- Chaque endpoint modifié ou ajouté est documenté avec paramètres et réponses.

---

## Résumé des fichiers touchés

| Fichier | Changement |
| - | - |
| `docs/20260430_files_accessibility.sql` | Nouveau — ALTER TABLE |
| `src/auth_groups/Models/File.php` | `create()`, `update()` |
| `src/auth_groups/Controllers/FileController.php` | `upload()`, `download()`, `getFileInfo()`, + `updateAccessibility()` |
| `src/auth_groups/Routing/RouteHandlers/FileRouteHandler.php` | Nouvelle route PATCH |
| `private/tests/test_files.php` | Sections A–D |
| `docs/auth_groups/GUIDE.md` | Mise à jour endpoints |
| `docs/core/API_ENDPOINTS.json` | Mise à jour + nouvelle route |
