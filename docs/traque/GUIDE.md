# Guide — Plugin Traque

Version 1.0.0 · Base URL : `/traque`

> Référence complète : [API_TRAQUE_ENDPOINTS.json](API_TRAQUE_ENDPOINTS.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Authentification](#authentification)
- [Création du personnage](#création-du-personnage)
- [Monstres](#monstres)
- [Combat](#combat)
- [Joueur — profil et actions](#joueur--profil-et-actions)
- [Journal, achievements, bestiaire](#journal-achievements-bestiaire)
- [Leaderboard](#leaderboard)
- [Codes d'erreur](#codes-derreur)

---

## Vue d'ensemble

Le plugin Traque est un jeu de chasse aux monstres géolocalisé (type D&D) :
combat au tour par tour, monstres avec respawn, biomes détectés via Overpass OSM,
achievements, bestiaire et classements.

| Domaine | Endpoints |
| --- | --- |
| Monstres | `GET /traque/monsters/nearby`, `POST /traque/monsters/{id}/respawn` |
| Combat | `POST /traque/combat/start`, `/attack`, `/flee` |
| Joueur | `POST /traque/players/create`, `GET /traque/players/check-name`, `GET /traque/players/me`, `POST .../me/levelup`, `POST .../me/rest`, `PUT .../me/settings`, `POST .../me/push-token` |
| Progression | `GET /traque/players/me/journal`, `.../achievements`, `.../bestiary` |
| Classement | `GET /traque/leaderboard` |

### Format d'erreur

Toutes les erreurs retournent le code machine dans `errors.error` :

```json
{ "success": false, "message": "Consentement GPS requis", "errors": { "error": "gps_consent_required" } }
```

---

## Authentification

**JWT Bearer obligatoire sur tous les endpoints** `/traque/*` :

```http
Authorization: Bearer {jwt_token}
```

L'inscription passe par le core : `POST /users/register` avec le champ `birthdate`
(`YYYY-MM-DD`) — optionnel en général, mais **obligatoire pour jouer à Traque**
(16 ans ou plus, sinon `422 age_restriction`).

---

## Création du personnage

### POST /traque/players/create

Crée le personnage du joueur connecté (**une seule fois** — `409 character_exists` ensuite).

```json
{
  "character_name": "Thorin le Sombre",
  "class": "ranger",
  "race": "elf",
  "gps_consent": true,
  "stat_distribution": {
    "stat_for": 10, "stat_dex": 14, "stat_con": 11,
    "stat_int": 10, "stat_sag": 11, "stat_cha": 10
  }
}
```

| Champ | Contraintes |
| --- | --- |
| `character_name` | requis, max 50 chars |
| `class` | `warrior` \| `mage` \| `ranger` \| `cleric` \| `rogue` |
| `race` | `human` \| `elf` \| `dwarf` \| `half_orc` |
| `gps_consent` | doit être `true` — sinon `422 gps_consent_required` |
| `stat_distribution` | somme des deltas depuis 10 = exactement 6 ; chaque stat 10–18 |

Bonus raciaux appliqués côté serveur :

| Race | Bonus |
| --- | --- |
| `elf` | `stat_dex` +1 |
| `dwarf` | `stat_con` +1 |
| `half_orc` | `stat_for` +1 |
| `human` | +1 point de compétence au niveau 1 |

Réponse `201` : objet joueur complet (`player_id`, stats, `hp_max`, `gems`,
`location_visibility`, `pvp_enabled`…).

### GET /traque/players/check-name

Vérifie la disponibilité d'un nom avant création : `?name=Thorin` → `{ available: true }`.

---

## Monstres

### GET /traque/monsters/nearby

Retourne les monstres vivants dans un rayon donné, avec scaling de niveau.

```http
GET /traque/monsters/nearby?lat=45.5123&lng=-73.6123&radius=500
Authorization: Bearer {jwt_token}
X-Player-Level: 7
```

| Paramètre | Détail |
| --- | --- |
| `lat`, `lng` | float, requis |
| `radius` | int, optionnel — défaut 500 m, max 2000 m |
| Header `X-Player-Level` | int 1–20 — niveau courant du joueur, repli 1 si absent |

Notes :

- Les monstres `is_alive=0` sont exclus.
- Scaling : `level_eff = level_base × (player_level / level_base)` arrondi.
- `biome` détecté via Overpass OSM au spawn/respawn :
  `forest` \| `peak` \| `water` \| `cemetery` \| `worship` \| `industrial` \| `urban` (défaut).

Réponse `200` : tableau de monstres (`id`, `name`, `asset_key`, `level`, `lat`, `lng`,
`hp_max`, `hp_current`, `ac`, `damage_dice`, `xp_reward`, `behavior_type`, `biome`,
`is_boss`, `special_attack`, `save_dc`, `save_stat`).

### POST /traque/monsters/{id}/respawn

Marque le monstre comme mort et fixe `respawn_at = NOW() + 6 h`.
Réponse `200` : `{ id, is_alive: false, respawn_at }`. `404` si monstre introuvable.

---

## Combat

Le client lance ses dés localement ; le serveur **valide, applique les dégâts et
calcule la contre-attaque**.

### POST /traque/combat/start

```json
{ "monster_id": 2 }
```

Réponse `201` : `{ session_id, monster: {...}, player: {...} }`.

| Erreur | Signification |
| --- | --- |
| 404 `player_not_found` | Personnage pas encore créé |
| 409 `monster_dead` | Attendre le respawn |
| 409 `session_already_active` | Session déjà ouverte — `data.session_id` retourné pour reconnexion |

### POST /traque/combat/attack

```json
{
  "session_id": 99,
  "attack_roll": 17,
  "damage_roll": 9,
  "stat_mod": 2
}
```

Réponse `200` :

```json
{
  "hit": true,
  "damage_dealt": 9,
  "monster_hp_current": 75,
  "monster_dead": false,
  "counter_attack": { "hit": true, "attack_roll": 14, "damage_dealt": 8, "player_hp_current": 24 },
  "player_dead": false,
  "log": [ { "actor": "player", "action": "attack", "roll": 17, "result": 9, "text": "..." } ],
  "xp_earned": 0,
  "new_achievements": null,
  "new_level": null
}
```

Cas particuliers :

- **Victoire** (`monster_dead=true`) : `counter_attack=null`, `xp_earned > 0`,
  `new_achievements` tableau ou null, `new_level` int ou null.
- **Défaite** (`player_dead=true`) : PV restaurés à 30 % de `hp_max`, session `status=defeat`.

| Erreur | Signification |
| --- | --- |
| 400 | `session_id`, `attack_roll` ou `damage_roll` manquant |
| 404 | Session introuvable ou appartient à un autre joueur |
| 409 | Session déjà terminée |
| 422 | `attack_roll` hors [1,20] ou `damage_roll` \< 0 |

### POST /traque/combat/flee

```json
{ "session_id": 99, "dex_roll": 14, "stat_dex": 12 }
```

Succès si `dex_roll + floor((stat_dex − 10) / 2) >= 10`.
Échec : `fled=false` + `counter_attack` du monstre.

---

## Joueur — profil et actions

### GET /traque/players/me

Profil complet : identité, classe/race, niveau, XP (`xp_next_level`), PV, stats,
`skill_points_available`, `gems`, préférences. `404` si personnage pas encore créé.

### POST /traque/players/me/levelup

Monte de niveau si XP suffisant (appelé automatiquement après victoire, ou manuellement).
Réponse : `{ new_level, hp_max, hp_gained, skill_points_available, new_achievements }`
ou `{ already_max: true }` si XP insuffisant ou niveau 20 atteint.

### POST /traque/players/me/rest

Repos du personnage — soigne et déclenche un cooldown.

| `?type=` | Effet | Cooldown |
| --- | --- | --- |
| `active` (défaut) | Soigne 50 % des PV manquants (min 1) | 30 min |
| `full` | PV au maximum | 4 h |

Retourne le profil complet mis à jour avec `rest_available_at` (ISO 8601 UTC).
`409 rest_cooldown` si le repos n'est pas encore disponible.

### PUT /traque/players/me/settings

```json
{ "location_visibility": "group", "pvp_enabled": true }
```

`location_visibility` : `all` \| `group` \| `friends`. Retourne le profil complet mis à jour.

### POST /traque/players/me/push-token

Enregistre le token FCM pour notifications push :
`{ "fcm_token": "...", "device_id": "..." }` → `{ registered: true }`.

---

## Journal, achievements, bestiaire

| Endpoint | Contenu |
| --- | --- |
| `GET /traque/players/me/journal` | 50 dernières entrées : `{ monster_name, monster_level, outcome: victory\|defeat\|fled, xp_earned, occurred_at }` |
| `GET /traque/players/me/achievements` | Tous les achievements : `{ slug, title_fr, description_fr, icon_key, unlocked, unlocked_at }` |
| `GET /traque/players/me/bestiary` | Monstres vaincus (kills ≥ 1) avec lore : `{ monster_id, name, asset_key, lore, kills, first_kill_at }` |

---

## Leaderboard

### GET /traque/leaderboard

```http
GET /traque/leaderboard?type=class&value=ranger&limit=20
```

| Paramètre | Détail |
| --- | --- |
| `type` | requis — `global` \| `biome` \| `class` |
| `value` | requis si `type=biome` ou `class` (ex. `forest`, `ranger`) |
| `limit` | optionnel — défaut 20, max 100 |

Réponse `200` : `[ { rank, player_id, display_name, level, xp, class } ]`.

---

## Codes d'erreur

| Code machine | HTTP | Contexte |
| --- | --- | --- |
| `age_restriction` | 422 | Inscription — moins de 16 ans |
| `gps_consent_required` | 422 | Création personnage sans consentement GPS |
| `invalid_stat_distribution` | 422 | Somme des deltas ≠ 6 ou stat hors 10–18 |
| `character_name_required` | 422 | Nom de personnage manquant |
| `character_exists` | 409 | Personnage déjà créé |
| `player_not_found` | 404 | Personnage pas encore créé |
| `monster_dead` | 409 | Combat contre un monstre mort |
| `session_already_active` | 409 | Session de combat déjà ouverte |
| `rest_cooldown` | 409 | Repos pas encore disponible (`rest_available_at`) |
