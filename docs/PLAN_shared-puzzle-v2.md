# Plan — Refonte SharedPuzzle v2

Source : `C:\code\puzzle\docs\API_shared_puzzle.md` (directive client Flutter)

## Contexte

La directive client remplace le modèle `move` (coordonnées absolues + `locked` booléen)
par un modèle en 4 états de pièce (`tray → held → floating|locked`), avec coordonnées
normalisées (0.0–1.0), un mécanisme TTL sur les pièces tenues et deux endpoints distincts
(`pick` / `drop`).

---

## Ce qui est déjà en place

| Élément | État actuel |
| - | - |
| `GET /puzzle/shared` | Fonctionnel — champs à renommer |
| `POST /puzzle/shared` | Fonctionnel — champs à renommer |
| `GET /puzzle/shared/{uid}/state` | Retourne toutes les pièces (doit filtrer `tray`) |
| `POST /puzzle/shared/{uid}/move` | À remplacer par `pick` + `drop` |
| `GET /puzzle/shared/{uid}/events` | Format à migrer |
| `POST /puzzle/shared/{uid}/leave` | Compatible |
| `DELETE /puzzle/shared/{uid}` | Doit retourner 204 au lieu de 200 |
| Table `puzzle_shared_pieces` | Manque `state`, `held_by_id`, `prev_state`, `held_at`, `by_id` |
| Table `puzzle_shared_events` | Manque `state`, `held_by_id`, `by_id` |
| Table `puzzle_shared` | Manque statut `complete` |

---

## Améliorations à faire

### Phase 1 — Migration DB

**Table `puzzle_shared_pieces`**

- Ajouter `state ENUM('tray','floating','locked','held') NOT NULL DEFAULT 'tray'`
- Ajouter `held_by_id INT UNSIGNED NULL` (FK → `puzzle_devices.id`)
- Ajouter `prev_state ENUM('tray','floating') NOT NULL DEFAULT 'tray'` (retour TTL)
- Ajouter `held_at DATETIME NULL` (timestamp de la prise — TTL 30 s)
- Ajouter `by_id INT UNSIGNED NULL` (FK → `puzzle_devices.id`, dernier poseur)

**Table `puzzle_shared_events`**

- Ajouter `state ENUM('tray','floating','locked','held') NOT NULL DEFAULT 'floating'`
- Ajouter `held_by_id INT UNSIGNED NULL`
- Ajouter `by_id INT UNSIGNED NULL`
- Les colonnes `x`, `y` deviennent `NULL`-ables (état `tray` ou `held`)

**Table `puzzle_shared`**

- Modifier `status ENUM('active','archived','complete')`

### Phase 2 — Renommage de champs (GET et POST /puzzle/shared)

| Champ actuel | Champ cible |
| - | - |
| `partner_pseudonym` (entrée POST) | `partner_pseudo` |
| `partner_pseudonym` (sortie) | `partner_pseudo` |
| (absent) | `creator_pseudo` |
| (absent) | `status` |

Erreur supplémentaire à POST : `409 ALREADY_IN_GAME` si une partie active existe
déjà entre les deux devices.

### Phase 3 — GET /puzzle/shared/{uid}/state

- Retourner **seulement** les pièces dont `state ≠ tray`
- Ajouter `state`, `held_by`, `by` à chaque pièce
- `x`, `y` → `null` pour `held`
- `partner_active` basé sur `last_seen_at` (fenêtre 30 s selon directive)

### Phase 4 — Nouveaux endpoints pick / drop

**`POST /puzzle/shared/{uid}/pick`**

- Valider : pièce non `locked` (→ 423), non `held` par l'autre joueur (→ 409)
- Transition : `tray|floating → held`
- Sauvegarder `prev_state`, `held_by_id`, `held_at = NOW()`
- Insérer événement `state=held`
- Retourner `{ piece_id, state: "held", held_by }`

**`POST /puzzle/shared/{uid}/drop`**

- Paramètres : `piece_id`, `x`, `y`, `rotation`, `to_tray`
- Valider : la pièce doit être `held` par le device courant
- Logique snap côté serveur : si distance à la cible ≤ 15 % de la largeur de pièce → `locked`
  - Cible normalisée : `x_cible = col / nbCols`, `y_cible = row / nbRows`
  - `nbCols = nbRows = sqrt(piece_count)` (grille carrée)
- Transitions :
  - `to_tray = true` → `tray` (x, y = null)
  - snap réussi → `locked` (x, y = position cible exacte)
  - sinon → `floating` (x, y fournis)
- Mettre à jour `completion` si snap
- Insérer événement
- Retourner `{ piece_id, state, x, y, rotation, locked, event_id }`

### Phase 5 — TTL (pièces abandonnées)

- Fenêtre : 30 secondes depuis `held_at`
- Déclenchement : **opportuniste** à chaque appel `GET /events` (pas de cron)
- Logique : `SELECT * FROM puzzle_shared_pieces WHERE state = 'held' AND held_at < NOW() - INTERVAL 30 SECOND`
- Pour chaque pièce expirée : remettre à `prev_state`, effacer `held_by_id`, `held_at`
- Insérer un événement TTL (`state = prev_state`, `by = null`)

### Phase 6 — GET /puzzle/shared/{uid}/events

- Format event mis à jour : `state`, `held_by` (pour `held`), `by`, `x`/`y` nullable
- Inclure events du joueur courant (echo pour réconciliation)
- Inclure events TTL générés par Phase 5

### Phase 7 — Nettoyage

- Retirer `POST /puzzle/shared/{uid}/move` du routeur et du contrôleur
- `DELETE /puzzle/shared/{uid}` → retourner `204` (corps vide)
- `leave` → relâcher les pièces `held` du joueur avant d'archiver

### Phase 8 — Mise à jour des tests

- `test_puzzle_share.php` : adapter aux nouveaux champs, couvrir `pick`/`drop`/TTL
- Documenter `API_PUZZLE_ENDPOINTS.json`

---

## Maintenances à prévoir

- Migration `001_puzzle_base.sql` → créer `002_puzzle_pieces_state.sql`
- Données existantes en production : `UPDATE puzzle_shared_pieces SET state = 'tray'` (déjà le défaut)
- La fenêtre TTL (30 s) et la tolérance snap (15 %) → mettre dans `puzzle_config.php` comme constantes

---

## Phases d'implantation par ordre de priorité

---

### Phase 1 — Migration DB `002_puzzle_pieces_state.sql`

**Actions**

1. Créer `docs/puzzle/migrations/002_puzzle_pieces_state.sql`
2. Ajouter les colonnes à `puzzle_shared_pieces` et `puzzle_shared_events`
3. Modifier l'enum `status` de `puzzle_shared`
4. Appliquer la migration en dev

**Enjeux**

- Migration non destructive (colonnes ajoutées, pas supprimées)
- Les données existantes reçoivent les valeurs `DEFAULT` — aucun impact

**Tests nécessaires**

- Vérifier que les colonnes existent après migration
- Vérifier que `SELECT * FROM puzzle_shared_pieces` retourne les nouvelles colonnes

**Conditions de fin**

- Migration appliquée sans erreur
- `DESCRIBE puzzle_shared_pieces` montre `state`, `held_by_id`, `prev_state`, `held_at`, `by_id`

---

### Phase 2 — Renommage champs + ALREADY_IN_GAME

**Actions**

1. `SharedController::createShared` : lire `partner_pseudo` au lieu de `partner_pseudonym`,
   vérifier unicité de la paire (creator + partner, statut active → 409), retourner le nouveau format
2. `SharedPuzzle::listActiveForDevice` : renommer `partner_pseudonym` → `partner_pseudo`,
   ajouter `creator_pseudo`, `status`
3. `SharedController::listShared` : rien à changer (clé `games` déjà correcte)

**Enjeux**

- Casser la compatibilité ascendante — le client Flutter doit être à la version qui attend le nouveau format

**Tests nécessaires**

- POST avec `partner_pseudo` → 201
- POST doublon → 409 `ALREADY_IN_GAME`
- GET liste → champs `creator_pseudo`, `partner_pseudo`, `status` présents

**Conditions de fin**

- Tests Phase 2 passent à 100 %

---

### Phase 3 — Nouveau modèle état des pièces (state + pick/drop)

**Actions**

1. Ajouter `SharedPuzzle::pickPiece(sharedId, pieceId, deviceId)` → transition vers `held`
2. Ajouter `SharedPuzzle::dropPiece(sharedId, pieceId, deviceId, x, y, rotation, toTray)` → transition avec snap
3. Adapter `SharedPuzzle::getPieces` → retourner `state`, `held_by`, `by`, ne pas inclure `tray`
4. Adapter `SharedPuzzle::insertPieces` → initialiser `state = 'tray'`
5. Ajouter `SharedController::pick`, `SharedController::drop`
6. Retirer `SharedController::move`

**Enjeux**

- Logique snap : `sqrt(piece_count)` doit être un entier → valider à la création
- Coordonnées normalisées : le client passe 0.0–1.0, le serveur stocke en FLOAT

**Tests nécessaires**

- `pick` → 200, pièce en `held`
- `pick` pièce `locked` → 423
- `pick` pièce déjà tenue par l'autre → 409
- `drop` → `floating` (hors snap)
- `drop` dans zone snap → `locked`, completion mis à jour
- `drop to_tray` → `tray`
- `getState` → pièces `tray` absentes de la liste

**Conditions de fin**

- Tous les tests Phase 3 passent
- `move` supprimé du routeur

---

### Phase 4 — TTL + events format

**Actions**

1. `SharedPuzzle::expireHeldPieces(sharedId)` : expire les `held` > 30 s, insère events TTL
2. Appeler `expireHeldPieces` en début de `getPartnerEvents`
3. Adapter format des events : `state`, `held_by`, `by`, `x`/`y` nullable
4. `leave` : appeler `releaseHeldPieces(sharedId, deviceId)` avant `archive()`

**Enjeux**

- TTL opportuniste (pas de cron) : un joueur peut ne jamais réclamer les événements TTL
  si les deux joueurs quittent l'app — acceptable, les pièces restent `held` jusqu'au prochain poll

**Tests nécessaires**

- Injecter une pièce `held` avec `held_at` > 30 s → prochain GET events la libère
- `leave` avec pièce `held` → pièce relâchée avant archivage

**Conditions de fin**

- Tests Phase 4 passent
- `PUZZLE_HELD_TTL_SECONDS` défini dans `puzzle_config.php`

---

### Phase 5 — Nettoyage final + 204 DELETE + doc

**Actions**

1. `DELETE /puzzle/shared/{uid}` → 204 (corps vide)
2. `PUZZLE_SNAP_TOLERANCE` et `PUZZLE_HELD_TTL_SECONDS` dans `puzzle_config.php`
3. Mettre à jour `docs/puzzle/API_PUZZLE_ENDPOINTS.json`
4. Créer migration `docs/puzzle/migrations/002_puzzle_pieces_state.sql`
5. Relancer `test_puzzle_share.php` → 100 %

**Conditions de fin**

- Tous tests passent
- JSON endpoints à jour
- Migration SQL créée
