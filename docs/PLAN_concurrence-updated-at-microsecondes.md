# PLAN — Précision microseconde sur `updated_at` (events / todos / journals)

## Décision (2026-08-14)

**Option C retenue — documenter la limite, aucune migration.** Analyse du risque réel :
le scénario visé par la directive (rejeu d'une file de mutations offline) sépare
toujours les écritures par un aller-retour réseau, jamais par moins d'une seconde. La
collision à la seconde près n'est reproduite que par des appels synchrones consécutifs
dans un même process de test — pas un cas d'usage client réel. Migration DB (Options A/B)
jugée disproportionnée pour un risque théorique ; réévaluer si un vrai cas de perte de
données est rapporté.

Limite documentée dans `docs/docs-api/ics/API_ICS_ENDPOINTS.json` (events/todos/journals) et
`docs/docs-api/projets/API_PROJETS_ENDPOINTS.json` (tâches), sur le header `If-Unmodified-Since`
des quatre routes concernées.

## Contexte

Directive `20260812_113000_cmem_web_vers_cmem2_API__offline-sync-versioning` (livrée en
v2.15.0, `CHANGELOG.md` § 2026-08-12 08:15) a ajouté `updatedAt` + garde `If-Unmodified-Since`
sur quatre routes (`PUT /calendars/{id}/events/{id}`, `PUT /calendars/{id}/todos/{id}`,
`PUT /calendars/{id}/journals/{id}`, `PATCH /projets/tasks/{id}`). La comparaison est
volontairement faite « par instant UTC (`strtotime`), pas par correspondance de chaîne
exacte » (directive, notes de complétion) — choix délibéré et documenté, aucune
incohérence entre directives.

Ce que la directive n'a pas couvert : `updated_at` est stocké en `TIMESTAMP`/`DATETIME`
MySQL, résolution **1 seconde**. `strtotime()` hérite de cette résolution. Deux écritures
sur la même ressource dans la même seconde produisent le même `updated_at` — un
`If-Unmodified-Since` périmé rejoué dans cette fenêtre passe la garde au lieu de
déclencher `409`, donc perte silencieuse possible si deux appareils écrivent à moins
d'une seconde d'écart.

Reproduit le 2026-08-14 en relançant `test_calendars.php` / `test_projets.php` (appels
consécutifs dans le même process de test, donc même seconde) :

- 7.5/7.6 (`calendar_events`), 16e.6d/16e.6e (`calendar_todos`), 16e.12v3/16e.12v4
  (`calendar_journals`), 3.7/3.7v4 (tâches projet, même table `calendar_todos` —
  `Task.php:19` pointe sur `calendar_todos`) : `updated_at` identique avant/après,
  rejeu de l'ancien `If-Unmodified-Since` accepté (`200` au lieu de `409`).

Note : `projets` tâches et `calendar` todos partagent la **même table physique**
(`calendar_todos`) via `Projets\Models\Task::$table`. Donc 3 tables à corriger, pas 4 :

| Table | Colonne actuelle | Fichier build (v2.15.0) |
| - | - | - |
| `calendar_events` | `updated_at TIMESTAMP` | `docs/v-2-15-0/build_DB-v-2.15.0.sql:163` |
| `calendar_todos` | `updated_at TIMESTAMP` | `docs/v-2-15-0/build_DB-v-2.15.0.sql:1228` |
| `calendar_journals` | `updated_at TIMESTAMP` | `docs/v-2-15-0/build_DB-v-2.15.0.sql:1266` |

## Ce qui est déjà en place

- `ConditionalRequest::enforce()` (`src/auth_groups/Utils/ConditionalRequest.php`) —
  point unique de comparaison, déjà appelé par les 4 routes.
- `DateHelper::toIso8601Utc()` — formatte `updated_at` en ISO 8601 pour la réponse
  (`CalendarEvent.php:245`, `CalendarTodo.php:292`, `CalendarJournal.php:254`).
- `409` renvoie déjà l'état serveur complet (`data.current`) — mécanique de résolution
  de conflit côté client déjà en place, seule la précision de comparaison est en cause.

## Options

### Option A — `updated_at` en `DATETIME(6)` (microseconde)

- **Migration** : `ALTER TABLE calendar_events MODIFY updated_at DATETIME(6) NOT NULL
  DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6);` (idem `calendar_todos`,
  `calendar_journals`).
- **Code** :
  - `DateHelper::toIso8601Utc()` doit préserver les microsecondes dans la sortie ISO 8601
    (`...T...ffffffZ` ou tronqué en millisecondes — à trancher).
  - `ConditionalRequest::enforce()` : `strtotime()` **tronque les fractions de seconde** —
    remplacer par un parsing `DateTime::createFromFormat` (ou comparaison de chaîne
    normalisée) qui conserve les microsecondes.
  - Tout autre lecteur de `updated_at` sur ces 3 tables (exports `.ics`, `last_modified`,
    tri, etc.) à vérifier pour tolérance au nouveau format.
- **Risque** : `DATETIME(6)` change le format brut retourné par un `SELECT *` existant
  (`2026-08-14 11:37:04.123456` au lieu de `...04`) — tout code qui parse la colonne brute
  (pas via `DateHelper`) doit être audité (`grep -rn "updated_at" src/ics src/projets`).
- **Effort** : moyen (migration simple, mais audit de tous les points de lecture de la
  colonne brute sur 3 tables).

### Option B — Colonne de version monotone (`INT UNSIGNED`, incrémentée en PHP)

- **Migration** : ajouter `version INT UNSIGNED NOT NULL DEFAULT 1` sur les 3 tables,
  incrémentée à chaque `UPDATE` applicatif (pas de `ON UPDATE` MySQL — incrément fait en
  PHP dans le même `UPDATE` que l'écriture).
- **Code** :
  - `ConditionalRequest::enforce()` compare `version` (entier) au lieu de `updated_at`
    (fiabilité totale, aucune question de résolution temporelle).
  - **Breaking pour le client** : `If-Unmodified-Since` est un header HTTP standard
    portant une date — passer à un entier de version demande soit un nouveau header
    (`If-Match` avec ETag = version ?), soit garder `If-Unmodified-Since` en façade mais
    le mapper en interne, ce qui complique le contrat déjà documenté et livré au client
    `cmem_web` (Playwright, offline queue déjà branchée sur `updatedAt`).
- **Effort** : plus élevé — casse le contrat déjà en prod côté `cmem_web`, demanderait une
  nouvelle directive inter-projet côté client.

### Option C — Ne rien changer, documenter la limite

- Ajouter une note dans `docs/docs-api/ics/GUIDE.md` / `docs/docs-api/projets/GUIDE.md` : la garde
  `If-Unmodified-Since` ne détecte pas deux écritures survenues dans la même seconde
  UTC. Acceptable si le flux réel (rejeu de file offline après reconnexion réseau) ne
  produit jamais deux écritures à moins d'une seconde d'écart en pratique — à confirmer
  avec `cmem_web`.
- **Effort** : nul, mais laisse un vrai lost-update possible en théorie.

## Recommandation

Option A — cohérente avec le contrat déjà livré (`If-Unmodified-Since` reste une date),
changement localisé (3 tables, 2 fichiers PHP + audit des lecteurs bruts), pas de
nouvelle directive côté client nécessaire.

## Phases d'implantation (si Option A retenue)

### Phase 1 — Migration SQL

- Actions : nouveau fichier `docs/YYYYMMDD_updated_at_microseconds.sql` avec les 3
  `ALTER TABLE ... MODIFY updated_at DATETIME(6) ...`. **Ne pas modifier**
  `docs/v-2-15-0/build_DB-v-2.15.0.sql` (version déjà fixée) — migration intégrée au
  prochain `build_DB-v-x-x-x.sql` lors du prochain ancrage de version.
- Enjeux : `ALTER TABLE` sur des tables en production potentiellement volumineuses —
  vérifier le volume de lignes avant d'exécuter en prod (verrouillage de table selon
  version MySQL/MariaDB).
- Tests : exécuter la migration sur dev-cmem2 d'abord, vérifier `DESCRIBE` sur les 3
  tables.
- Terminé quand : les 3 colonnes sont `DATETIME(6)` sur dev, aucune donnée existante
  perdue (`SELECT COUNT(*)` avant/après identique).

### Phase 2 — `DateHelper::toIso8601Utc()` + lecteurs bruts

- Actions : préserver les microsecondes dans le format ISO 8601 de sortie ; grep
  exhaustif des lecteurs de `updated_at` brut sur les 3 tables (`.ics` export,
  `last_modified`, tri par date) pour vérifier qu'aucun ne casse sur le nouveau format
  MySQL.
- Enjeux : ne pas régresser le format `updatedAt` déjà consommé par `cmem_web`
  (actuellement `...T...Z` sans fraction) — décider si on expose les microsecondes au
  client ou si on les garde en interne uniquement pour la comparaison serveur.
- Tests : `test_calendars.php`, `test_ics_journals_e2e.php`, `test_projets.php` en
  entier (pas seulement les cas conflictuels).
- Terminé quand : suite `cmem2_API` complète verte, format `updatedAt` client inchangé
  (sauf décision contraire assumée).

### Phase 3 — `ConditionalRequest::enforce()`

- Actions : remplacer `strtotime()` par un parsing conservant les microsecondes
  (`DateTime::createFromFormat('Y-m-d H:i:s.u', ...)` côté DB, comparaison avec le
  header client normalisé de la même façon).
- Enjeux : le client envoie `If-Unmodified-Since` au format reçu dans `updatedAt` — si
  Phase 2 ne change pas ce format (pas de microsecondes exposées), le serveur doit
  comparer sa propre valeur interne (microseconde) à une valeur externe (seconde) — deux
  sous-options à trancher avant d'écrire le code :
  1. Exposer les microsecondes au client (casse le format actuel, directive côté
     `cmem_web` nécessaire).
  2. Garder la comparaison seconde côté client mais ajouter une garde serveur
     supplémentaire (ex. verrou optimiste interne basé sur microseconde uniquement en
     cas d'égalité seconde) — coexistence des deux précisions.
- Tests : cas ciblés — deux écritures dans la même seconde, la seconde doit recevoir
  `409` si elle rejoue le `updatedAt` de la première (pas celui post-écriture).
- Terminé quand : `test_calendars.php` 7.6/16e.6e/16e.12v4 et `test_projets.php` 3.7v4
  passent même en écriture rapprochée (< 1s).

## STOP avant exécution

- Toute migration DB (Phase 1) — confirmation explicite requise avant `ALTER TABLE`,
  y compris sur dev-cmem2.
- Décision Phase 2/3 sur l'exposition ou non des microsecondes au client — impacte le
  contrat déjà livré à `cmem_web`, possible nouvelle directive inter-projet à créer
  avant implantation.
