# Plan d'implantation — Gestion de projet + iCalendar

note : **cmem2_API (backend PHP) ↔ cmem-web (frontend JS)**

> Document pilote unique et autoportant. Décisions arrêtées en Phase 0.
>
> **Orientations retenues :** format d'échange principal = **JSON natif** (le contrat
> de tâche §6, sérialisé tel quel). Round-trip trivial via l'`id` cmem2 — aucun champ
> personnalisé, aucun mapping. `.ics` = **VEVENT** secondaire (vue calendrier
> universelle). CSV = optionnel. Schéma = **extension des tables iCal existantes** +
> table de dépendances. Cas B (abonnement externe) = **stand-by**.
>
> **Note historique :** une version antérieure ciblait MS Project XML (MSPDI) en
> bidirectionnel. Abandonné : pas de client réel exigeant l'interop desktop Gantt.
> JSON élimine tout le risque round-trip (identité, préservation de champ, mapping
> priorité/lag) sans fermer aucune porte — un exporteur MSPDI/.gan reste ajoutable
> par-dessus le modèle JSON si un besoin réel apparaît.

---

## 0. Décisions arrêtées (Phase 0)

| # | Question | Décision |
| - | - | - |
| 1 | Format d'échange prioritaire | **JSON natif** (contrat §6). Ni MSPDI ni .gan tant qu'aucun client ne réclame l'interop desktop. |
| 2 | Conserver hiérarchie + dépendances | **Oui, les deux** → JSON les porte nativement (`parentId` + `dependsOn[]`). |
| 3 | Aller simple ou aller-retour | **Round-trip** → JSON, identité par `id` cmem2 (aucun hack). |
| 4 | Schéma de données | Placeholders à aligner ; contraintes d'intégrité §1.4. |
| 5 | Nouvelles tables ou réutilisation | **Étendre les tables iCal** (colonnes) **+ table `task_dependencies`**. Pomo hors sujet. |
| 6 | Fuseau horaire | `.ics` en **UTC** ; JSON en **ISO 8601** (UTC, suffixe `Z`). ⚠ **Exception journée entière** : les champs date-only (`allDay=true`, échéance seule) sont des **dates flottantes** — la valeur `AAAA-MM-JJ` est figée à la création/saisie et ne doit **jamais** repasser par une conversion de fuseau (pas d'aller-retour via un instant UTC). Voir §9.5. |
| 7 | `.ics` VTODO ou VEVENT | **VEVENT** (plus universel ; perte de la sémantique tâche — voir §10). |

---

## 1. Modèle relationnel des tâches ⭐

Deux relations **distinctes et orthogonales**.

### 1.1 Hiérarchie — `parentId` (0 ou 1)

« Fait partie de ». Arbre : au plus **un** parent. Colonne auto-référencée.

### 1.2 Dépendances — `dependsOn[]` (0 à n)

« Ne peut commencer qu'après ». Graphe orienté : **plusieurs** prédécesseurs.
Chaque entrée : `{ taskId, type: 'FS'|'SS'|'FF'|'SF', lagDays? }`
(`FS` Fin→Début par défaut ; `lagDays` = décalage, négatif = avance).
Relation plusieurs-à-plusieurs → **table dédiée**.

### 1.3 Indépendance

Une sous-tâche peut dépendre d'une tâche ailleurs dans l'arbre.

### 1.4 Intégrité (backend)

- Hiérarchie = **arbre** : un parent, aucun cycle.
- Dépendances = **DAG** : aucun cycle (sinon ordonnancement insoluble).
- Vérifs applicatives (la BD ne sait pas imposer l'acyclicité seule).

---

## 2. Motif

Gestion de projet (projets → tâches, hiérarchie, dépendances, progression) dans
cmem2, exploitable : **en interne** dans cmem-web (Cas A), et en **échange
round-trip** via **JSON natif** (le contrat §6 exporté puis ré-importé sans perte).
`.ics` (VEVENT) offre une vue calendrier secondaire ; CSV, un export d'appoint.

Principe : **réutiliser l'existant** (tables + module iCal de cmem2 ; archi à
plugins). cmem-web garde une logique **découplée de la vue**.

---

## 3. Architecture

```txt
┌─────────────────────────────┐         ┌──────────────────────────────────┐
│  cmem-web (JS)              │  JWT    │  cmem2_API (PHP) — plugin projets │
│  ProjetsClient (agnostique) │────────▶│   • Endpoints REST CRUD          │
│   • listes + calendrier (A) │         │   • Export JSON  ─┐              │
│   • export/import JSON       │◀────────│   • Import JSON  ─┘ round-trip   │
│   • export .ics / CSV (opt.) │  JSON   │   • Export .ics (VEVENT)         │
└─────────────────────────────┘ .ics    │   • [⏸ calendar.ics + feed_token]│
                                         │  Tables iCal étendues + task_deps │
                                         └──────────────────────────────────┘
        (⏸ STAND-BY : abonnement Google Agenda / Apple via feed_token)
```

Portée actuelle : tout au **JWT** (CRUD, exports, import). Le `feed_token` reste
réservé au Cas B.

---

## 4. Schéma de données (extension des tables iCal)

Approche : **ajouter des colonnes** à la table iCal existante (les entrées
deviennent des tâches) **+ une table de dépendances**. Aucune table Pomo impliquée.

**Table des tâches (table iCal existante, colonnes ajoutées) :**

```table
id                PK (déjà présent — sert d'identité round-trip JSON)
project_id        FK projet
parent_id         FK -> tâches.id   (hiérarchie, nullable, un seul parent)
title, description, dtstart, due    (déjà iCal ou ajoutés)
all_day           bool
status            ENUM(NEEDS-ACTION, IN-PROCESS, COMPLETED, CANCELLED)
priority          INT 0..9
percent_complete  INT 0..100
completed_at, created_at, updated_at
sequence          INT
```

**Table `task_dependencies` (nouvelle) — dépendances plusieurs-à-plusieurs :**

```table
task_id        FK -> tâches.id
depends_on_id  FK -> tâches.id
type           ENUM(FS, SS, FF, SF)  default 'FS'
lag_days       INT default 0
PRIMARY KEY (task_id, depends_on_id)
```

**Contraintes :** FK ON DELETE approprié ; unicité (task_id, depends_on_id) ;
vérifs applicatives d'acyclicité (arbre pour `parent_id`, DAG pour les dépendances).

---

## 5. Enjeux et risques

| Enjeu | Risque | Mitigation |
| - | - | - |
| **Identité round-trip** | Sans identité stable, le ré-import duplique au lieu de mettre à jour. | `id` cmem2 porté nativement dans le JSON (§9). Aucun champ personnalisé requis. |
| **Suppression au ré-import** | Auto-suppression des tâches absentes du fichier = perte de données. | Ne **jamais** supprimer à l'import ; signaler les orphelins. |
| **Conflits d'édition** | Modif des deux côtés → écrasement silencieux. | v1 « fichier gagne » ; fusion fine plus tard. |
| **Cycles** | Cycle parenté/dépendance → ordonnancement insoluble. | Valider arbre + DAG (§1.4). |
| **Perte VEVENT** | VEVENT n'a ni case à cocher ni `PERCENT-COMPLETE`. | Reporter statut + % dans `DESCRIPTION` ; `STATUS:CANCELLED` si annulé (§10). |
| **Interop iCal** | CRLF, échappement, pliage 75 octets ; accents FR. | Directive §8 (D5). |
| **Validation JSON** | Payload malformé / champs manquants au ré-import. | Schéma strict + validation applicative avant fusion (§9.3). |
| **Déni de service import** | Payload `import.json` sans limite (taille, nb tâches) → mémoire/CPU. | Plafonner taille requête + nb tâches ; rejeter (413/422) avant `planifier()`. |
| **`task_dependencies` PK sans `type`** | Une seule relation par paire `(task_id, depends_on_id)` — pas de FS+SS simultané. | Mineur, pas bloquant. Surveiller si un cas réel exige plusieurs types entre les deux mêmes tâches ; sinon aucune action. |
| **`GraphValidator` O(n²)** | Parcours parent depuis chaque tâche (§Annexe D) → coûteux sur gros projets. | Mineur, pas bloquant v1. Surveiller si perf pose problème (ex. projets à centaines de tâches) ; optimiser (mémo/visited global) seulement si mesuré. |

---

## 6. Contrat d'API (cmem2 ↔ cmem-web)

```txt
GET    /plugins/projets/projects
POST   /plugins/projets/projects
GET    /plugins/projets/projects/{id}
PATCH  /plugins/projets/projects/{id}
DELETE /plugins/projets/projects/{id}
GET    /plugins/projets/projects/{id}/tasks
POST   /plugins/projets/projects/{id}/tasks
PATCH  /plugins/projets/tasks/{id}
DELETE /plugins/projets/tasks/{id}

# --- Échange JSON (round-trip, JWT) ---
GET    /plugins/projets/projects/{id}/export.json    # projet + tâches (contrat complet)
POST   /plugins/projets/projects/{id}/import.json    # ré-import -> renvoie un diff

# --- Échange MSPDI (round-trip, JWT) ---
GET    /plugins/projets/projects/{id}/export.xml     # MS Project (MSPDI)
POST   /plugins/projets/projects/{id}/import.xml     # ré-import MSPDI -> renvoie un diff

# --- Échange GanttProject (round-trip, JWT) ---
GET    /plugins/projets/projects/{id}/export.gan     # GanttProject (.gan)
POST   /plugins/projets/projects/{id}/import.gan     # ré-import .gan -> renvoie un diff

# --- Export secondaire (JWT) ---
GET    /plugins/projets/projects/{id}/export.ics     # calendrier VEVENT (export seul)
# CSV : côté cmem-web (Annexe A), optionnel

# --- ⏸ STAND-BY (Cas B) ---
# GET  /plugins/projets/projects/{id}/calendar.ics?feed_token=...
```

> **Écart d'implémentation (2026-07-21) :** le routeur cmem2_API dispatche sur le
> premier segment d'URL comme nom de contrôleur (pas de préfixe `/plugins/`) — les
> routes réelles sont `/projets/projects/...` et `/projets/tasks/{id}` (contrôleur
> `projets`), pas `/plugins/projets/...` ci-dessus. Voir
> `docs/projets/API_PROJETS_ENDPOINTS.json` pour le contrat exact servi.

**Contrat d'une tâche :**
`id, title, description, status, priority, percentComplete, dtstart, due, allDay,
completedAt, createdAt, updatedAt, sequence, parentId, dependsOn[], assignee, url,
categories[], rappelMinutesAvant`
où `dependsOn[] = [{ taskId, type, lagDays }]`.

Statuts : `NEEDS-ACTION` / `IN-PROCESS` / `COMPLETED` / `CANCELLED`.

**Contrat d'export/import JSON (`export.json` / `import.json`) :**

```json
{
  "project": { "id": 1, "name": "…", "createdAt": "…", "updatedAt": "…" },
  "tasks": [ { …contrat de tâche ci-dessus… } ],
  "exportedAt": "2026-07-20T14:30:00Z",
  "schemaVersion": 1
}
```

Round-trip : chaque tâche porte son `id`. Tâche sans `id` (créée hors ligne) →
INSERT. `id` reconnu → UPDATE. Aucun mapping, aucune traduction de valeur.

---

## 7. (réservé)

---

## 8. Directive — Sérialiseur `VEVENT` (export `.ics` secondaire)

> Une tâche → un **événement** de calendrier. Les règles de sérialisation
> (échappement, pliage, CRLF, identité, UTC) sont identiques à celles d'un VTODO.
> Code : **Annexe B**. (Le variant VTODO reste disponible si un jour un export
> « appli de tâches » est voulu.)

### D1. Conteneur

```txt
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//journauxdebord//cmem2 projets//FR
CALSCALE:GREGORIAN
METHOD:PUBLISH
  ... VEVENT ...
END:VCALENDAR
```

### D2. Résolution tâche → événement

- `dtstart` + `due` → `DTSTART`=dtstart, `DTEND`=due (événement qui couvre la période).
- `due` seul (échéance) → **événement journée entière** sur la date d'échéance
  (`DTSTART;VALUE=DATE`, `DTEND;VALUE=DATE` = jour+1, fin exclusive).
- `dtstart` seul → événement ponctuel à `dtstart`.
- Aucune date → **tâche ignorée** (un VEVENT exige `DTSTART`).

### D3. Propriétés `VEVENT`

| Propriété | Source | Notes |
| - | - | - |
| `UID` / `DTSTAMP` / `SEQUENCE` | `id` / génération / version | Identité (D6). |
| `SUMMARY` | `title` | Préfixe `✓` si `COMPLETED`. |
| `DESCRIPTION` | `description` + statut + `Progression: X%` | **Compense l'absence de %/case à cocher.** |
| `DTSTART` / `DTEND` | dates résolues (D2) | UTC, ou `VALUE=DATE` si journée entière. |
| `STATUS` | statut | `CANCELLED` si annulé, sinon `CONFIRMED`. |
| `PRIORITY` | priorité | 0–9. |
| `CATEGORIES` | tags | Séparateur `,`. |
| `RELATED-TO` (×N) | `parentId` + `dependsOn[]` | Multiple. Hiérarchie `RELTYPE=PARENT` ; dépendances `RELTYPE` temporel (RFC 9253, support variable). D7. |
| `URL` | lien cmem-web | URI. |
| `VALARM` | rappel | `TRIGGER;RELATED=START:-PT{n}M`. |

### D4. Types de valeur

DATE-TIME → UTC `Ymd\THis\Z` ; journée entière → `VALUE=DATE:AAAAMMJJ` ;
PRIORITÉ Haute=1 / Normale=5 / Basse=9 / Non définie=0.

### D5. Règles critiques ⚠️

**a)** `CRLF` partout. **b)** Échappement TEXTE (§3.3.11) : `\`→`\\` (premier), `;`→`\;`,
`,`→`\,`, saut→`\n` ; pas `:` ; valeurs TEXT seulement. **c)** Pliage (§3.1) : ≤ **75
octets** UTF-8 ; continuation `CRLF`+espace ; ne pas couper un caractère multi-octet.
**d)** Échappement **avant** pliage.

### D6. Identité

`UID` **immuable** (`evt-{id}@cmem.journauxdebord.com`) ; `SEQUENCE` incrémenté.

### D7. Relations (`RELATED-TO`, multiple)

Hiérarchie : `RELATED-TO;RELTYPE=PARENT:...`. Dépendances : `RELATED-TO;RELTYPE=
FINISHTOSTART|STARTTOSTART|FINISHTOFINISH|STARTTOFINISH` (+`GAP=PnD`), RFC 9253,
support client mince → l'ordonnancement « fiable » vit dans le JSON natif.

### D8. Fuseau / HTTP / pièges / acceptation

UTC (`Z`) ; journées entières en `VALUE=DATE`. `Content-Type: text/calendar;
charset=utf-8`, `attachment`, JWT. Vérifs : `\r\n` partout, accents échappés+pliés,
`UID` stable, tâche sans date ignorée. Acceptation : validateur iCal 0 erreur,
import Thunderbird/Google OK.

---

## 9. JSON — échange round-trip (format principal)

### 9.1 Identité round-trip — `id` natif

Chaque tâche exportée porte son `id` cmem2 dans le JSON. C'est **la clé** du
ré-import. Aucun champ personnalisé, aucun format tiers, aucune préservation à
valider : l'`id` est un champ de premier ordre du contrat (§6), impossible à perdre.

### 9.2 Export — `GET /export.json`

Sérialise le projet + toutes ses tâches selon le contrat §6, tel quel. Hiérarchie
via `parentId`, dépendances via `dependsOn[]`. Aucun mapping de valeur (pas de
priorité 0–1000, pas de LinkLag en dixièmes de minute). Enveloppe §6 avec
`schemaVersion` pour l'évolution future.

### 9.3 Import — `POST /import.json` — reconstruction + fusion

Étapes :

1. Valider le payload (schéma strict : types, statuts, priorités 0–9, cohérence des
   références `parentId` / `dependsOn[].taskId`).
2. **Hiérarchie** : `parentId` déjà explicite — aucune reconstruction depuis un
   niveau/ordre. Vérifier l'arbre (un parent, pas de cycle).
3. **Dépendances** : `dependsOn[]` déjà explicite — vérifier le DAG.
4. **Fusion** (upsert) :
   - `id` reconnu → **UPDATE** ;
   - tâche sans `id` (ou `id` inconnu) → **INSERT** (créée hors ligne) ;
   - tâche cmem2 absente du fichier → **conservée**, signalée comme orpheline
     (jamais supprimée automatiquement) ;
   - conflit → « fichier gagne » (v1) ; comparer `updatedAt` pour un merge fin plus tard.
5. Renvoyer un **diff** (à créer / à mettre à jour / orphelins) que l'utilisateur
   valide avant écriture.

### 9.4 Références vers tâches nouvelles

Une tâche créée hors ligne (sans `id`) peut être pointée par `parentId` /
`dependsOn[]` d'une autre. Utiliser un **id temporaire** (`"tmp-xxx"`) dans le
payload ; le backend insère d'abord, puis résout les références temporaires en `id`
réels (2ᵉ passe). Contrat : tout `id` non numérique = temporaire.

### 9.5 Dates journée entière — flottantes, pas d'aller-retour UTC ⚠️

**Risque identifié 2026-07-20 :** un instant UTC (`...T00:00:00Z`) ne représente pas
la même date calendrier partout. Si `due` (échéance seule, `allDay=true`) est stocké
comme instant UTC puis reformaté via un fuseau (client, export `.ics`), le jour peut
décaler de ±1 selon le fuseau de lecture — piège classique iCal.

**Règle :** pour toute tâche `allDay=true`, la valeur date (`AAAA-MM-JJ`) est
**flottante** de bout en bout :

- Stockage (colonne `due`/`dtstart`, §4) : type `DATE` (pas `DATETIME`/`TIMESTAMP`)
  quand `all_day=true` — aucune notion de fuseau à la source.
- JSON (§6) : sérialiser `"2026-08-01"` (sans heure ni `Z`) quand `allDay=true` ;
  le suffixe `Z`/heure ne s'applique qu'aux tâches timées.
- `.ics` (§8, D2/D4) : `VALUE=DATE:20260801` directement depuis la chaîne, **sans**
  passer par `DateTimeImmutable` en tz UTC puis `->format()` (annule le risque que
  `dateOnly()`, Annexe B, hérite malgré son commentaire « floating »).
- Toute lecture/écriture applicative traite ce champ comme texte de date, jamais
  comme instant converti.

**Terminé quand :** un test round-trip (créer tâche allDay le 1er août, fuseau
serveur ≠ UTC, export `.ics` + JSON) affiche `2026-08-01` partout, sans décalage.

---

## 10. Exports secondaires

- **`.ics` (VEVENT)** — vue calendrier universelle. Perte de la sémantique tâche
  (`%`, case à cocher) ; statut + progression reportés dans `DESCRIPTION`. §8, Annexe B.
- **MSPDI (`.xml`)** — interop MS Project / ProjectLibre / GanttProject. **Round-trip**
  (export + ré-import). Hiérarchie via `OutlineLevel`, dépendances via `PredecessorLink`,
  identité par `id` cmem2 porté dans `Text1` (« CmemId »). §14, Annexes E (export) + G (import).
- **GanttProject (`.gan`)** — format natif GanttProject, plus léger que MSPDI. **Round-trip.**
  Hiérarchie par imbrication `<task>`, dépendances `<depend>`, identité par attribut `id`.
  §14, Annexes F (export) + H (import).
- **CSV (optionnel)** — export d'appoint SaaS ; « à plat » (dépendances/hiérarchie
  approximées par colonnes). Généré côté cmem-web. Annexe A.

---

## 11. Phases d'implantation

- **Phase 0 — Schéma.** ✅ **Terminé 2026-07-21** (backend cmem2_API). Figer le schéma §4
  et le contrat JSON §6/§9. (Plus de spike round-trip : l'identité JSON est native.)
- **Phase 1 — Backend CRUD.** ✅ **Terminé 2026-07-21** (backend cmem2_API). Extension
  des tables iCal + `task_dependencies` ; endpoints §6 ; auth JWT ; **validation arbre/DAG**.
- **Phase 2 — Affichage interne cmem-web (Cas A).** ⏳ Non démarré — autre projet
  (cmem-web) ; à traiter via directive inter-projet. `ProjetsClient` + contrôleur ;
  listes + calendrier.
- **Phase 3 — Export/Import JSON (round-trip).** ✅ **Terminé 2026-07-21** (backend
  cmem2_API). `export.json` + `import.json` (dry-run) + `import.json/confirm` (écriture) :
  sérialisation du contrat, validation, fusion upsert + diff, résolution des ids
  temporaires. **Acceptation :** export → édition → ré-import met à jour sans doublon ;
  nouvelles tâches insérées ; orphelins signalés ; cycles rejetés. — validé par
  `private/tests/test_projets.php` (59/59).
- **Phase 4 — Export `.ics` VEVENT.** ✅ **Terminé 2026-07-21** (backend cmem2_API).
  Annexe B. **Acceptation :** validateur iCal 0 erreur ; import Thunderbird/Google OK
  — structure VEVENT/CRLF/pliage/UID validée par tests ; import client réel (Thunderbird/
  Google) restant à confirmer manuellement.
- **Phase 5 — Export CSV (optionnel).** ⏳ Non démarré — autre projet (cmem-web).
- **Phase 6 — ⏸ Cas B (abonnement externe).** `feed_token` + `calendar.ics`.
- **Phase 7 — 🔮 Round-trip interop desktop Gantt (rebaissé, voir note).** MSPDI (`.xml`) et
  GanttProject (`.gan`), **export + import** par-dessus le modèle JSON (§14). Sous-phases :
  **7a** spike identité ⚠️ (préservation `CmemId`), **7b** export, **7c** import (fusion
  upsert + diff, réutilise `JsonRoundTrip`). **Acceptation :** cycle export → édition
  desktop → ré-import sans doublon.
  > **Note (2026-07-20) :** rebaissé au même rang que la Phase 8 — ne pas coder tant
  > qu'aucun client réel ne réclame l'interop desktop. La « Note historique » (préambule)
  > a justement écarté MSPDI pour le risque round-trip (identité, mapping priorité/lag,
  > §14.2/14.4, codes `.gan` non confirmés) ; la Phase 7 réintroduit ce risque tel quel.
  > Les annexes E-H restent comme référence prête à l'emploi si le besoin apparaît.
- **Phase 8 — 🔮 CalDAV / enrichissements.** sabre/dav ; `RRULE` ; assignations.

---

## 12. Détail des phases — cmem2_API (backend PHP)

Plugin `src/projets/` sur l'archi à plugins existante (namespace `Projets\`,
enregistrement via `PluginManager`). Structure standard du repo :
`Controllers/ · Models/ · Services/ · Routing/`.

### API-Phase 0 — Schéma & migration SQL — ✅ Terminé 2026-07-21

> Migration `docs/20260721_projets_taches.sql` appliquée sur dev-cmem2 et prod. Plugin
> `src/projets/` (`Projets\`) actif, découvert automatiquement (pas de clé `.env PLUGINS` —
> `PluginManager` scanne `src/*/plugin.json`).

- **Actions :**
  - Écrire la migration `docs/YYYYMMDD_projets_taches.sql` : colonnes ajoutées à la
    table iCal (§4) + création `task_dependencies` + `projects`.
  - Figer le contrat de tâche §6 et l'enveloppe JSON §6/§9 comme référence.
  - Activer le plugin dans `.env` (clé `PLUGINS`).
- **Enjeux :** ne jamais toucher un `build_DB-v-x-x-x.sql` publié ; migration pendante
  dans `docs/` jusqu'au prochain bump (règle CLAUDE.md). `ON DELETE` des FK à décider
  (cascade sur `task_dependencies`, `SET NULL` sur `parent_id`).
- **Tests :** appliquer la migration sur base de dev vierge ; vérifier FK + index +
  unicité `(task_id, depends_on_id)`.
- **Terminé quand :** migration s'applique sans erreur ; schéma relu et figé ; plugin
  chargé au boot sans casser les tests existants (`run_all_tests.php` vert).

### API-Phase 1 — CRUD projets & tâches — ✅ Terminé 2026-07-21

> `ProjectController` + `TaskController`, `Project` + `Task` (Models). `calendar_id`
> résolu par calendrier caché auto-provisionné 1:1 par projet (au lieu de rendre la
> colonne nullable — ne touche pas au module `ics`). `GraphValidator` branché en
> transaction sur POST/PATCH tâche (rollback + 422 si cycle).

- **Actions :**
  - `ProjectController` + `TaskController` ; `ProjectModel` + `TaskModel` (PDO préparé).
  - Endpoints §6 (projects CRUD, tasks CRUD sous projet, PATCH/DELETE tâche par id).
  - Auth JWT sur toutes les routes ; scoping par propriétaire/groupe.
  - `GraphValidator` (Annexe D) branché sur POST/PATCH : rejeter cycle arbre/DAG (422).
  - Réponse au format standard `{ success, message, data, errors }`.
- **Enjeux :** validation des `parentId`/`dependsOn[].taskId` (existence + même projet) ;
  cohérence statut ↔ `percentComplete` (COMPLETED ⇒ 100) ; codes HTTP corrects
  (404/409/422).
- **Tests :** `private/tests/test_projets.php` (cURL réel, pattern `test_new_base.php`) :
  CRUD complet, refus cycle, refus dépendance inter-projet, refus tâche sans titre,
  auth manquante → 401.
- **Terminé quand :** tous les endpoints §6 répondent ; suite `test_projets.php` verte ;
  aucun cycle acceptable ; docs `docs/projets/API_PROJETS_ENDPOINTS.json` créé.

### API-Phase 2 — (frontend — voir §13, pas de travail backend)

- Rien côté API. Les endpoints CRUD de la Phase 1 suffisent à l'affichage interne.

### API-Phase 3 — Export/Import JSON (round-trip) — ✅ Terminé 2026-07-21

> `JsonRoundTrip::export()/planifier()`. Écart au contrat §6 : import scindé en deux
> routes plutôt qu'un flag `confirm` dans le body — `POST import.json` = diff seul
> (rien écrit), `POST import.json/confirm` = applique (même payload), plus explicite
> pour un dry-run réseau. Transaction PDO + résolution `tmp-*` + `GraphValidator` avant
> commit, rollback complet si cycle.

- **Actions :**
  - `JsonRoundTrip` (Annexe C) : `export()` et `planifier()`.
  - `GET /export.json` : projet + tâches sérialisés (contrat §6), enveloppe + `schemaVersion`.
  - `POST /import.json` : valider schéma, produire le **diff** (aCreer / aMettreAJour /
    orphelins) **sans écrire** ; l'écriture n'a lieu qu'après confirmation du client.
  - Résolution des ids temporaires (§9.4) : INSERT d'abord, carte `tmp-* → id réel`,
    réécriture `parentId`/`dependsOn[]`, **puis** `GraphValidator` avant commit.
  - Tout dans une **transaction** PDO ; rollback si validation échoue.
- **Enjeux :** ne jamais supprimer une tâche absente du fichier (orpheline conservée) ;
  « fichier gagne » v1 ; payload malformé → 422 avec `errors[]` clairs ; injection —
  aucune (pas de XML, `json_decode` strict) ; **plafonner taille payload et nb de
  tâches** (413/422) avant `planifier()` — sinon DoS via fichier énorme (§5).
- **Tests :** round-trip complet (export → modif du JSON → import → diff attendu) ;
  tâche `tmp-*` insérée et référencée ; orphelin signalé non supprimé ; cycle introduit
  dans le payload → rejet ; `schemaVersion` incompatible → 422.
- **Terminé quand :** export → édition → ré-import met à jour sans doublon ; nouvelles
  tâches insérées et reliées ; orphelins signalés ; cycles rejetés ; tests verts.

### API-Phase 4 — Export `.ics` VEVENT — ✅ Terminé 2026-07-21

> `VEventSerializer` (namespace `Projets\Ical\`). Import réel Thunderbird/Google encore
> à confirmer manuellement — la suite `test_projets.php` valide CRLF, pliage, `UID`
> stable, tâche sans date exclue, structure VCALENDAR/VEVENT.

- **Actions :** `VEventSerializer` (Annexe B) ; `GET /export.ics` ;
  `Content-Type: text/calendar; charset=utf-8`, `attachment`, JWT.
- **Enjeux :** CRLF partout ; échappement **avant** pliage 75 octets ; accents FR ;
  tâche sans date ignorée ; `UID` stable.
- **Tests :** `test_projets_ics.php` : validateur iCal 0 erreur ; import Thunderbird/Google ;
  vérifier pliage sur titre accentué long ; journée entière (`VALUE=DATE`, fin exclusive).
- **Terminé quand :** validateur 0 erreur ; import client réel OK ; tests verts.

### API-Phase 5 — (CSV — voir §13, généré côté cmem-web, pas de travail backend)

- Rien côté API : le CSV se génère depuis les données déjà servies par `export.json` / `tasks`.

### API-Phase 6 — ⏸ Cas B (abonnement externe, stand-by)

- **Actions (différées) :** colonne `feed_token` ; `GET /calendar.ics?feed_token=` sans JWT ;
  rotation/révocation du token.
- **Enjeux :** endpoint public → fuite de données si token faible ; rate-limit ;
  `feed_token` cryptographiquement fort, révocable.
- **Terminé quand :** hors scope actuel — à planifier si le Cas B est activé.

### API-Phase 7a — 🔮 Spike identité round-trip MSPDI / `.gan` ⚠️ (rebaissé)

> **Rebaissé au rang 🔮 (§11) — ne pas démarrer sans demande client confirmée.**

**Bloquant (si activée). À faire AVANT d'écrire les importeurs.** Le round-trip vers un format
tiers ne survit que si l'identité cmem2 (`CmemId`) est **préservée par l'outil externe
à l'enregistrement**. Sinon le ré-import duplique au lieu de mettre à jour.

- **Actions :** exporter un projet test → ouvrir dans la cible → enregistrer → ré-ouvrir
  → vérifier que l'identité + les liens survivent. Pour **chaque** cible :
  - MSPDI : MS Project, ProjectLibre — `Text1`/CmemId préservé ?
  - `.gan` : GanttProject — `id` de tâche stable ? sinon custom property `CmemId`
    (`<taskproperty>` / `customPropertyDefinition`).
- **Terminé quand :** pour chaque outil visé, on sait si l'identité survit à un cycle
  ouvrir→sauver→rouvrir. Résultat consigné par outil (survit / ne survit pas).
- **Repli si l'identité NE survit pas (implémenté en 7c, détail §14.5).** L'import reste possible mais bascule en
  **mode « nouveau projet »** : aucune correspondance tentée, toutes les tâches sont
  créées (INSERT) dans un **projet neuf** avec de nouveaux `CmemId`. Évite la
  duplication silencieuse dans le projet d'origine. Le round-trip « mise à jour en
  place » n'est offert que pour les outils qui préservent `CmemId`.

### API-Phase 7b — Export MSPDI + `.gan`

- **Actions :**
  - `MSPDIExporter` (Annexe E) : projet + tâches → MS Project XML. Hiérarchie
    `OutlineLevel` + ordre (parents avant enfants, DFS) ; dépendances `PredecessorLink`
    (0..n) ; `id` cmem2 dans `Text1` aliasé « CmemId » ; priorité 0–9 → 0–1000 ;
    `lagDays` → `LinkLag`.
  - `GanttProjectExporter` (Annexe F) : projet + tâches → `.gan`. Hiérarchie par
    **imbrication** `<task>` ; dépendances `<depend id type>` ; `complete` =
    `percentComplete` ; `CmemId` en custom property ; couleurs par statut (optionnel).
  - `GET /export.xml` et `GET /export.gan` ; `Content-Type: application/xml`,
    `attachment`, JWT. Sérialisation `DOMDocument` (échappement auto).
- **Enjeux :** mapping priorité/`lagDays`/types de lien (§14) ; dates MSPDI local sans
  fuseau vs `.gan` en `Y-m-d` ; tâche sans date → jalon ; ordre topologique parents avant
  enfants.
- **Tests :** `test_projets_export_mspdi.php` / `test_projets_export_gan.php` : XML bien
  formé ; ouverture réelle ProjectLibre / GanttProject ; hiérarchie ≥3 niveaux ;
  dépendances `FS`/`SS` ; `CmemId` par tâche ; priorité mappée ; `complete` reflète `%`.
- **Terminé quand :** MSPDI s'ouvre dans MS Project/ProjectLibre, `.gan` dans
  GanttProject, avec hiérarchie + dépendances + progression correctes ; tests verts.

### API-Phase 7c — Import MSPDI + `.gan` (round-trip)

- **Actions :**
  - `MSPDIImporter` (Annexe G) : parse le XML, reconstruit la hiérarchie depuis
    `OutlineLevel` + ordre (pile), les dépendances depuis `PredecessorLink`, résout
    l'identité via `CmemId`.
  - `GanttProjectImporter` (Annexe H) : parse le `.gan`, hiérarchie depuis
    l'imbrication `<task>`, dépendances depuis `<depend>`, identité via `id`/custom prop.
  - Réutiliser la **fusion upsert + diff** de `JsonRoundTrip` (Annexe C) : les deux
    importeurs produisent le même format `{ aCreer, aMettreAJour, orphelins }`, écriture
    en transaction seulement après confirmation client.
  - `POST /import.xml` et `POST /import.gan` → renvoient un diff.
  - **Deux modes d'import** (détectés automatiquement, voir §14.5) :
    - **Mode « mise à jour »** — le fichier contient des `CmemId` reconnus → fusion
      upsert dans le projet cible (UPDATE / INSERT / orphelins).
    - **Mode « nouveau projet »** — aucun `CmemId` (outil qui ne préserve pas le champ,
      §7a) → créer un **projet neuf**, toutes les tâches en INSERT avec de nouveaux
      `CmemId`, hiérarchie/dépendances reconstruites par les identités **internes** du
      fichier (UID MSPDI / id `.gan`), pas par `CmemId`. Aucune écriture dans le projet
      d'origine.
- **Enjeux :**
  - **Identité** (dépend de 7a) : `CmemId` reconnu → UPDATE ; absent → INSERT (tâche
    créée dans l'outil) ; tâche cmem2 absente du fichier → **conservée**, orpheline
    signalée, jamais supprimée. Si **aucun** `CmemId` dans tout le fichier → bascule en
    mode « nouveau projet » (repli §7a).
  - **Conflit** : « fichier gagne » v1.
  - **Cycles** : valider arbre + DAG (`GraphValidator`, Annexe D) **après** reconstruction,
    avant commit.
  - **Mapping inverse** : priorité 0–1000 → 0–9, `LinkLag` → `lagDays`, codes lien → FS/SS/FF/SF.
  - **Tâches nouvelles sans CmemId** : insérer d'abord, 2ᵉ passe pour relier parents/deps
    qui les pointent (même logique que §9.4).
- **Tests :** `test_projets_import_mspdi.php` / `test_projets_import_gan.php` :
  export → édition externe → ré-import met à jour sans doublon ; nouvelle tâche insérée ;
  orphelin signalé non supprimé ; cycle rejeté ; identité résolue ; mapping inverse correct.
- **Terminé quand :** round-trip complet pour chaque outil validé en 7a : export →
  éditer dans l'outil desktop → ré-import → diff correct → base à jour sans doublon ;
  tests verts.

### API-Phase 8 — 🔮 Futur

- CalDAV (sabre/dav), `RRULE` (récurrence), assignations multi-utilisateurs.

---

## 13. Détail des phases — cmem-web (frontend JS)

`ProjetsClient` (Annexe A) = couche d'accès **agnostique à la vue** ; les contrôleurs
UI la consomment. Aucune logique métier dans la vue.

### WEB-Phase 0 — (schéma — dépend de l'API, pas de travail frontend)

- Attendre le contrat §6 figé (API-Phase 0). Peut commencer les stubs `ProjetsClient`
  contre un mock.

### WEB-Phase 1 — Client d'accès `ProjetsClient`

- **Actions :**
  - Intégrer `projets-client.js` (Annexe A) : CRUD, `exporterJSON`, `importerJSON`,
    `exporterCSV`, `telechargerFichier`.
  - Injection `baseUrl` + `getToken` (JWT existant de cmem-web) ; gestion `ProjetsError`.
- **Enjeux :** réutiliser le mécanisme d'auth/token existant ; ne pas dupliquer
  la config `baseUrl`.
- **Tests :** unitaires (mock `fetchImpl`) : chaque méthode appelle la bonne route/verbe ;
  `changerStatut(TERMINEE)` force `percentComplete: 100` ; erreur HTTP → `ProjetsError`
  avec `status`/`payload`.
- **Terminé quand :** couverture des méthodes ; erreurs typées ; aucune dépendance
  à la vue dans le client.

### WEB-Phase 2 — Affichage interne (Cas A) : listes + calendrier

- **Actions :**
  - Contrôleur `ProjetsController` (UI) consommant `ProjetsClient`.
  - **Vue liste/arbre** : hiérarchie via `parentId` (indentation), badges statut/priorité,
    barre `percentComplete`.
  - **Vue calendrier** : placer les tâches par `dtstart`/`due` (réutiliser le composant
    calendrier iCal existant de cmem-web si présent).
  - Actions inline : créer/éditer/supprimer tâche, changer statut, glisser dépendances.
- **Enjeux :** rendu de l'arbre (récursion) + affichage des dépendances (flèches/liste) ;
  état local vs refetch ; garder la logique dans le contrôleur, pas la vue.
- **Tests :** rendu d'un arbre à ≥3 niveaux ; tâche sans date absente du calendrier ;
  changement de statut reflété sans reload ; interaction création → POST correct.
- **Terminé quand :** un projet réel s'affiche (arbre + calendrier) ; CRUD complet
  depuis l'UI ; dépendances visibles.

### WEB-Phase 2b — Vue Gantt (présentation graphique) ⭐

Diagramme de Gantt : représentation temporelle des tâches (barres), hiérarchie,
dépendances et progression. Vue de **présentation** consommant `ProjetsClient` —
aucune logique métier dans le rendu.

- **Actions :**
  - Composant `GanttView` (agnostique données) : reçoit la liste de tâches (contrat §6),
    rend un diagramme sans dépendre du transport.
  - **Axe temps** (en-tête) : échelle jour / semaine / mois, **zoom** commutable ;
    marqueur « aujourd'hui » (ligne verticale).
  - **Barres de tâches** : position/longueur depuis `dtstart` → `due` ; jalon (milestone)
    = losange si `due` seul ; remplissage proportionnel à `percentComplete`.
  - **Hiérarchie** : lignes indentées par `parentId` ; barre de résumé (parent) couvrant
    l'étendue de ses enfants ; repli/dépli d'une branche.
  - **Dépendances** : flèches entre barres selon `dependsOn[]` ; style/ancrage selon
    le type (`FS`/`SS`/`FF`/`SF`) ; décalage visuel si `lagDays`.
  - **Couleurs** : par statut (`NEEDS-ACTION`/`IN-PROCESS`/`COMPLETED`/`CANCELLED`) ou
    priorité ; légende.
  - **Interactions (v1 lecture, v2 édition) :** survol = infobulle (dates, %, statut) ;
    clic = ouvrir la tâche (réutilise l'éditeur Phase 2). *Drag pour déplacer/redimensionner
    → v2, écrit via `modifierTache`.*
  - **Rendu :** SVG (barres + flèches) ou grille CSS + SVG pour les liens. Choix lib :
    soit rendu maison (léger, contrôle total), soit lib front sans dépendance lourde —
    **à trancher au démarrage de la phase** (voir Enjeux).
- **Enjeux :**
  - **Choix technique** : rendu maison SVG vs librairie Gantt. Maison = zéro dépendance,
    contrôle du style cmem-web, mais coût des flèches de dépendance. Lib = rapide mais
    poids + style à surcharger. **Décider avant de coder.**
  - **Performance** : projets à centaines de tâches → virtualisation (ne rendre que la
    fenêtre visible) ; recalcul du layout au zoom.
  - **Tâches sans date** : exclues de la timeline → les lister dans un volet « non
    planifiées » plutôt que les masquer silencieusement.
  - **Dépendances lisibles** : éviter le plat de spaghettis ; router les flèches
    proprement, atténuer celles hors écran.
  - **Cohérence** : la vue Gantt et la vue liste/calendrier (Phase 2) partagent le même
    `ProjetsClient` et le même état — pas de source de vérité divergente.
  - **Accessibilité** : le Gantt seul ne suffit pas (SVG) → garder la vue liste comme
    équivalent navigable au clavier.
- **Tests :**
  - Barres positionnées correctement pour : période (`dtstart`+`due`), échéance seule
    (jalon), tâche en cours (remplissage partiel).
  - Arbre à ≥3 niveaux : barres de résumé couvrent bien les enfants ; repli/dépli.
  - Flèche de dépendance tracée entre deux tâches liées ; type `SS` vs `FS` ancré au bon bord.
  - Bascule zoom jour↔semaine↔mois : layout recalculé sans casser les liens.
  - Tâche sans date → absente de la timeline, présente dans « non planifiées ».
  - Projet à ~300 tâches : rendu fluide (virtualisation active).
- **Terminé quand :** un projet réel s'affiche en Gantt (barres + hiérarchie + dépendances +
  progression) ; zoom fonctionnel ; marqueur « aujourd'hui » ; clic ouvre la tâche ;
  vue synchronisée avec la liste (Phase 2) ; tâches non planifiées listées à part.

### WEB-Phase 3 — Export/Import JSON (round-trip UI)

- **Actions :**
  - Bouton **Exporter JSON** → `exporterJSON` → `telechargerFichier` (`application/json`).
  - Bouton **Importer JSON** → lecture fichier → `importerJSON` → afficher le **diff**
    (à créer / à mettre à jour / orphelins) → **confirmation** avant application.
  - Écran de diff : listes claires, distinguer insert/update/orphelin.
- **Enjeux :** ne rien écrire avant confirmation (l'API renvoie un plan) ; gérer un
  fichier invalide (message d'erreur, pas de crash) ; UX du diff compréhensible.
- **Tests :** export produit un fichier ré-importable ; import affiche le bon diff ;
  annulation = aucune écriture ; fichier corrompu → message d'erreur propre.
- **Terminé quand :** cycle export → édition externe (éditeur JSON) → import → diff →
  confirmation → base à jour, sans doublon.

### WEB-Phase 4 — Bouton export `.ics`

- **Actions :** bouton **Exporter calendrier (.ics)** → `GET /export.ics` (ou lien
  authentifié) → téléchargement.
- **Enjeux :** transporter le JWT sur un GET de fichier (fetch + blob, pas `<a href>` nu) ;
  nommage du fichier.
- **Tests :** fichier téléchargé s'importe dans Google Agenda/Thunderbird.
- **Terminé quand :** `.ics` téléchargeable et importable depuis l'UI.

### WEB-Phase 5 — Export CSV (optionnel)

- **Actions :** bouton **Exporter CSV** → `ProjetsClient.exporterCSV(taches)` →
  `telechargerFichier`. Entièrement côté client (Annexe A).
- **Enjeux :** BOM UTF-8 pour Excel ; échappement `"`/`,`/CRLF déjà géré ; hiérarchie/
  dépendances « à plat » (par titre) — perte assumée.
- **Tests :** CSV s'ouvre correctement dans Excel/LibreOffice (accents, colonnes).
- **Terminé quand :** CSV lisible dans un tableur ; dépendances/parent affichés par titre.

### WEB-Phase 7 — 🔮 Import/Export MSPDI + `.gan` (round-trip UI) (rebaissé)

> **Rebaissé au rang 🔮 (§11) — dépend d'API-Phase 7, elle-même sans demande client confirmée.**

Même patron que la Phase 3 (JSON) : export téléchargement, import → diff → confirmation.
Ajoute `exporterMSPDI`/`importerMSPDI` et `exporterGan`/`importerGan` à `ProjetsClient`
(routes §6, `application/xml`).

- **Actions :** boutons **Exporter .xml** / **Exporter .gan** ; imports lisant un fichier
  → `POST import.xml` / `import.gan` → écran de **diff** (aCreer / aMettreAJour /
  orphelins) → confirmation avant application. Réutilise le composant diff de la Phase 3.
- **Enjeux :** dépend du spike API-7a (identité préservée par l'outil) ; masquer l'import
  d'un format dont le spike a échoué (export seul) ; même UX de diff que JSON.
- **Tests :** export → édition dans ProjectLibre/GanttProject → import → diff correct →
  base à jour sans doublon ; fichier d'un outil non validé → import bloqué proprement.
- **Terminé quand :** round-trip desktop complet depuis l'UI pour chaque outil validé.

### WEB-Phase 6 / 8 — 🔮 Futur

- **Phase 6 :** UI d'abonnement (URL `webcal://` via `urlFluxProjet`/`urlAbonnementWebcal`)
  quand le Cas B est activé côté API.
- **Phase 8 :** vue récurrence (`RRULE`), assignations — alignées sur les phases API.

---

## 14. Mappings d'interop (MSPDI ↔ cmem2 ↔ `.gan`)

Table de correspondance des champs pour les exporteurs/importeurs (Phase 7).

### 14.1 Champs de tâche

| cmem2 (contrat §6) | MSPDI (`<Task>`) | GanttProject (`<task>`) |
| - | - | - |
| `id` | `ExtendedAttribute` `Text1` (CmemId, FieldID `188743731`) | custom property `CmemId` (ou attr `id` si stable — voir 7a) |
| `title` | `Name` | attr `name` |
| `description` | `Notes` | `<notes>` |
| `dtstart` | `Start` (`Y-m-d\TH:i:s`, local sans fuseau) | attr `start` (`Y-m-d`) |
| `due` | `Finish` | `start` + `duration` (jours ouvrés) |
| `percentComplete` (0–100) | `PercentComplete` (0–100) | attr `complete` (0–100) |
| `priority` (0–9) | `Priority` (0–1000) — voir 14.2 | attr `priority` (0=low,1=normal,2=high — voir 14.2) |
| `parentId` | `OutlineLevel` + ordre (parents avant enfants) | **imbrication** `<task>` dans `<task>` |
| `status=CANCELLED` | (pas de champ direct) → `Notes` | (optionnel) couleur / `<notes>` |
| jalon (`due` seul) | `Milestone=1` | attr `meeting="true"` (durée 0) |

### 14.2 Priorité (échelles)

| cmem2 0–9 | Sens | MSPDI 0–1000 | GanttProject |
| - | - | - | - |
| 0 | non définie | 500 | 1 (normal) |
| 1–4 | haute | 900 / 800 / 700 / 600 | 2 (high) |
| 5 | normale | 500 | 1 (normal) |
| 6–9 | basse | 400 / 300 / 200 / 100 | 0 (low) |

Import (inverse) : MSPDI `≥600`→haute(1), `500`→normale(5), `<500`→basse(9) ;
`.gan` 2→1, 1→5, 0→9.

### 14.3 Types de dépendance

| cmem2 | MSPDI `Type` | GanttProject `<depend type>` |
| - | - | - |
| `FF` | 0 | 0 |
| `FS` | 1 | 2 (défaut) |
| `SF` | 2 | 1 |
| `SS` | 3 | 3 |

> ⚠️ Les codes `.gan` diffèrent de MSPDI. Vérifier à l'implémentation (GanttProject :
> `1`=SF, `2`=FS, `3`=SS, `0`=FF selon versions) — **valider par test réel** (7a/7b).

### 14.4 Décalage (`lagDays`)

- **MSPDI** : `LinkLag` en dixièmes de minute × calendrier projet ; 1 j ouvré = 8 h =
  4800 dixièmes ; `LagFormat=7` (jours). Import : `LinkLag / 4800` → `lagDays` (approx.).
- **GanttProject** : `<depend>` attr `difference` en jours. Mapping direct avec `lagDays`.

### 14.5 Détection du mode d'import & repli « nouveau projet »

L'importeur choisit le mode d'après la présence de `CmemId` dans le fichier :

| Fichier | Mode | Écriture | Identité pour la fusion |
| - | - | - | - |
| ≥1 `CmemId` reconnu | Mise à jour | projet cible (upsert) | `CmemId` → `id` cmem2 |
| aucun `CmemId` | Nouveau projet | projet **neuf** créé | UID MSPDI / id `.gan` (internes au fichier) |

**Mode « nouveau projet » (repli, §7a).** Déclenché quand l'outil externe a supprimé
tous les `CmemId` à la sauvegarde. Procédure :

1. Créer un projet neuf (nom depuis `<Project><Name>` / `<project name>`).
2. Insérer **toutes** les tâches (aucun `id` cmem2 réutilisé) → nouveaux `CmemId`.
3. Reconstruire hiérarchie + dépendances via les **identités internes du fichier**
   (UID MSPDI pour `PredecessorLink` ; id `.gan` pour `<depend>` et l'imbrication),
   puis les remapper vers les nouveaux `CmemId` en **2ᵉ passe** (même mécanique que les
   ids temporaires, §9.4).
4. Ne rien écrire dans le projet d'origine — pas de duplication silencieuse.

> Concrètement : les importeurs (Annexes G/H) construisent déjà une carte
> UID/idGan → tâche. En mode « nouveau projet », cette carte interne — et non `CmemId` —
> sert de clé de câblage, et chaque tâche reçoit un `id` temporaire résolu à l'insertion.

---

## Annexe A — `projets-client.js` (cmem-web)

```js
// projets-client.js — client de gestion de projet cmem-web. Agnostique à la vue.
// CRUD + import/export JSON (JWT). Export CSV côté client (optionnel).

export const StatutTache = Object.freeze({
  A_FAIRE: 'NEEDS-ACTION', EN_COURS: 'IN-PROCESS',
  TERMINEE: 'COMPLETED', ANNULEE: 'CANCELLED',
});
export const Priorite = Object.freeze({ AUCUNE: 0, HAUTE: 1, NORMALE: 5, BASSE: 9 });

export class ProjetsError extends Error {
  constructor(message, { status = 0, payload = null, cause = null } = {}) {
    super(message);
    this.name = 'ProjetsError'; this.status = status; this.payload = payload;
    if (cause) this.cause = cause;
  }
}

export class ProjetsClient {
  #baseUrl; #getToken; #fetch;

  constructor({ baseUrl, getToken, fetchImpl } = {}) {
    if (!baseUrl) throw new ProjetsError('baseUrl requis');
    if (typeof getToken !== 'function') throw new ProjetsError('getToken() requis');
    this.#baseUrl = baseUrl.replace(/\/+$/, '');
    this.#getToken = getToken;
    this.#fetch = fetchImpl ?? globalThis.fetch.bind(globalThis);
  }

  async #request(method, path, body) {
    const token = await this.#getToken();
    let res;
    try {
      res = await this.#fetch(`${this.#baseUrl}${path}`, {
        method,
        headers: {
          Accept: 'application/json',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
          ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
      });
    } catch (cause) { throw new ProjetsError('Échec réseau', { cause }); }
    if (res.status === 204) return null;
    let payload = null;
    if ((res.headers.get('content-type') || '').includes('application/json')) {
      payload = await res.json().catch(() => null);
    }
    if (!res.ok) {
      throw new ProjetsError(payload?.message || payload?.error || `HTTP ${res.status}`,
        { status: res.status, payload });
    }
    return payload;
  }

  listerProjets()        { return this.#request('GET', '/plugins/projets/projects'); }
  obtenirProjet(id)      { return this.#request('GET', `/plugins/projets/projects/${encodeURIComponent(id)}`); }
  creerProjet(data)      { return this.#request('POST', '/plugins/projets/projects', data); }
  modifierProjet(id, p)  { return this.#request('PATCH', `/plugins/projets/projects/${encodeURIComponent(id)}`, p); }
  supprimerProjet(id)    { return this.#request('DELETE', `/plugins/projets/projects/${encodeURIComponent(id)}`); }

  listerTaches(projetId)     { return this.#request('GET', `/plugins/projets/projects/${encodeURIComponent(projetId)}/tasks`); }
  creerTache(projetId, data) { return this.#request('POST', `/plugins/projets/projects/${encodeURIComponent(projetId)}/tasks`, data); }
  modifierTache(tacheId, p)  { return this.#request('PATCH', `/plugins/projets/tasks/${encodeURIComponent(tacheId)}`, p); }
  supprimerTache(tacheId)    { return this.#request('DELETE', `/plugins/projets/tasks/${encodeURIComponent(tacheId)}`); }

  changerStatut(tacheId, statut) {
    if (!Object.values(StatutTache).includes(statut)) throw new ProjetsError(`Statut invalide : ${statut}`);
    const patch = { status: statut };
    if (statut === StatutTache.TERMINEE) patch.percentComplete = 100;
    return this.modifierTache(tacheId, patch);
  }

  // --- JSON : export (contrat complet projet + tâches) ---
  exporterJSON(projetId) {
    return this.#request('GET', `/plugins/projets/projects/${encodeURIComponent(projetId)}/export.json`);
  }

  // --- JSON : import (round-trip). Renvoie le diff (à créer / MAJ / orphelins).
  importerJSON(projetId, payload) {
    return this.#request('POST',
      `/plugins/projets/projects/${encodeURIComponent(projetId)}/import.json`,
      payload); // { project, tasks, exportedAt, schemaVersion }
    // -> { aCreer, aMettreAJour, orphelins }
  }

  // --- Export CSV optionnel (côté client) ---
  static exporterCSV(taches, { projet = '' } = {}) {
    const entetes = ['Nom', 'Description', 'Début', 'Échéance', 'Statut', 'Priorité',
      '% complété', 'Responsable', 'Tâche parente', 'Dépendances', 'Étiquettes', 'Projet'];
    const titreParId = new Map(taches.map((t) => [t.id, t.title ?? String(t.id)]));
    const libStatut = { 'NEEDS-ACTION': 'À faire', 'IN-PROCESS': 'En cours',
      'COMPLETED': 'Terminée', 'CANCELLED': 'Annulée' };
    const libPrio = (p) => (p >= 1 && p <= 4) ? 'Haute' : (p === 5) ? 'Normale'
      : (p >= 6 && p <= 9) ? 'Basse' : '';
    const jour = (v) => v ? String(v).slice(0, 10) : '';
    const deps = (t) => (t.dependsOn ?? []).map((d) => titreParId.get(d.taskId) ?? d.taskId).join('; ');
    const parent = (t) => (t.parentId != null) ? (titreParId.get(t.parentId) ?? t.parentId) : '';

    const rangs = taches.map((t) => [
      t.title ?? '', t.description ?? '', jour(t.dtstart), jour(t.due),
      libStatut[t.status] ?? (t.status ?? ''), libPrio(Number(t.priority ?? 0)),
      t.percentComplete != null ? String(t.percentComplete) : '',
      t.assignee ?? '', parent(t), deps(t),
      Array.isArray(t.categories) ? t.categories.join('; ') : (t.categories ?? ''), projet,
    ]);
    const esc = (c) => { const s = String(c ?? ''); return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; };
    return '﻿' + [entetes, ...rangs].map((a) => a.map(esc).join(',')).join('\r\n');
  }

  static telechargerFichier(nomFichier, contenu, mime = 'text/csv;charset=utf-8') {
    const blob = new Blob([contenu], { type: mime });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = nomFichier;
    document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
  }

  // --- ⏸ STAND-BY : abonnement externe (Cas B) ---
  urlFluxProjet(projetId, feedToken) {
    const u = new URL(`${this.#baseUrl}/plugins/projets/projects/${encodeURIComponent(projetId)}/calendar.ics`);
    u.searchParams.set('feed_token', feedToken); return u.toString();
  }
  urlAbonnementWebcal(httpsUrl) { return httpsUrl.replace(/^https?:\/\//, 'webcal://'); }
}
```

---

## Annexe B — `VEventSerializer.php` (export `.ics`)

```php
<?php
declare(strict_types=1);

namespace Cmem\Plugins\Projets\Ical;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Sérialise chaque tâche en VEVENT (échéance = événement de calendrier). */
final class VEventSerializer
{
    private const CRLF   = "\r\n";
    private const PRODID = '-//journauxdebord//cmem2 projets//FR';
    private const DOMAIN = 'cmem.journauxdebord.com';

    /** @param array<int,array<string,mixed>> $taches */
    public function buildCalendar(array $taches): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:' . self::PRODID,
            'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
        foreach ($taches as $t) {
            foreach ($this->buildVEvent($t) as $l) { $lines[] = $l; }
        }
        $lines[] = 'END:VCALENDAR';
        return implode(self::CRLF, array_map([$this, 'fold'], $lines)) . self::CRLF;
    }

    /** @return string[] lignes NON pliées (déjà échappées) ; [] si tâche sans date */
    private function buildVEvent(array $t): array
    {
        // Résolution des dates (voir D2)
        $start = $t['dtstart'] ?? ($t['due'] ?? null);
        if ($start === null) { return []; }              // pas de date -> pas d'événement
        $allDay = (bool) ($t['allDay'] ?? false);
        $end = null;
        if (!empty($t['dtstart']) && !empty($t['due'])) {
            $end = $t['due'];                            // couvre la période
        } elseif (empty($t['dtstart']) && !empty($t['due'])) {
            $allDay = true; $start = $t['due'];          // échéance seule -> journée entière
        }

        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $status = (string) ($t['status'] ?? 'NEEDS-ACTION');
        $L = ['BEGIN:VEVENT'];
        $L[] = 'UID:' . $this->uid($t['id']);
        $L[] = 'DTSTAMP:' . $this->fmtUtc($nowUtc);
        $L[] = 'SEQUENCE:' . (int) ($t['sequence'] ?? 0);

        // Dates
        if ($allDay) {
            // Journée entière = date FLOTTANTE (§9.5). Ne jamais passer par un instant UTC :
            // on lit la valeur AAAA-MM-JJ telle quelle et on incrémente en arithmétique de date pure.
            $startDate = $this->dateFlottante($start);                 // AAAAMMJJ
            $L[] = 'DTSTART;VALUE=DATE:' . $startDate;
            $L[] = 'DTEND;VALUE=DATE:' . $this->jourSuivant($this->dateFlottante($end ?? $start));
        } else {
            $L[] = 'DTSTART:' . $this->fmtUtc($start);
            if ($end !== null) { $L[] = 'DTEND:' . $this->fmtUtc($end); }
        }

        // Titre + description (compense l'absence de % dans VEVENT)
        $prefixe = ($status === 'COMPLETED') ? '✓ ' : '';
        $L[] = 'SUMMARY:' . $this->esc($prefixe . (string) $t['title']);
        $desc = trim((string) ($t['description'] ?? ''));
        $meta = 'Statut : ' . $status;
        if (isset($t['percentComplete'])) { $meta .= ' — Progression : ' . (int) $t['percentComplete'] . '%'; }
        $L[] = 'DESCRIPTION:' . $this->esc($desc === '' ? $meta : ($desc . "\n" . $meta));

        // Statut VEVENT
        $L[] = 'STATUS:' . ($status === 'CANCELLED' ? 'CANCELLED' : 'CONFIRMED');
        if (isset($t['priority'])) { $L[] = 'PRIORITY:' . max(0, min(9, (int) $t['priority'])); }

        if (!empty($t['categories'])) {
            $L[] = 'CATEGORIES:' . implode(',', array_map([$this, 'esc'], (array) $t['categories']));
        }

        // Hiérarchie + dépendances (RELATED-TO multiple)
        if (!empty($t['parentId'])) { $L[] = 'RELATED-TO;RELTYPE=PARENT:' . $this->uid($t['parentId']); }
        foreach (($t['dependsOn'] ?? []) as $dep) {
            $prop = 'RELATED-TO;RELTYPE=' . $this->reltypeIcal((string) ($dep['type'] ?? 'FS'));
            if (!empty($dep['lagDays'])) { $prop .= ';GAP=P' . abs((int) $dep['lagDays']) . 'D'; }
            $L[] = $prop . ':' . $this->uid($dep['taskId']);
        }

        if (!empty($t['url'])) { $L[] = 'URL:' . (string) $t['url']; }

        if (!empty($t['rappelMinutesAvant'])) {
            $L[] = 'BEGIN:VALARM';
            $L[] = 'ACTION:DISPLAY';
            $L[] = 'DESCRIPTION:' . $this->esc((string) $t['title']);
            $L[] = 'TRIGGER;RELATED=START:-PT' . (int) $t['rappelMinutesAvant'] . 'M';
            $L[] = 'END:VALARM';
        }
        $L[] = 'END:VEVENT';
        return $L;
    }

    private function uid($id): string { return 'evt-' . $id . '@' . self::DOMAIN; }

    private function reltypeIcal(string $type): string
    {
        return ['FS' => 'FINISHTOSTART', 'SS' => 'STARTTOSTART',
                'FF' => 'FINISHTOFINISH', 'SF' => 'STARTTOFINISH'][strtoupper($type)] ?? 'FINISHTOSTART';
    }

    private function fmtUtc(DateTimeInterface $dt): string { return $this->toUtc($dt)->format('Ymd\THis\Z'); }

    /**
     * Date FLOTTANTE en AAAAMMJJ, SANS conversion de fuseau (§9.5).
     * Accepte une chaîne 'AAAA-MM-JJ'/'AAAA-MM-JJ...' (source de vérité pour allDay)
     * ou un DateTimeInterface (on lit ses composantes locales, jamais getTimestamp()).
     */
    private function dateFlottante(string|DateTimeInterface $d): string
    {
        if ($d instanceof DateTimeInterface) { return $d->format('Ymd'); } // composantes locales, pas d'instant UTC
        return str_replace('-', '', substr($d, 0, 10));                    // 'AAAA-MM-JJ' -> 'AAAAMMJJ'
    }

    /** Jour suivant en arithmétique de DATE pure (fin exclusive), sans timestamp/fuseau. */
    private function jourSuivant(string $ymd): string
    {
        $d = DateTimeImmutable::createFromFormat('!Ymd', $ymd, new DateTimeZone('UTC'));
        return $d->modify('+1 day')->format('Ymd'); // tz fixe + '!' => minuit stable, aucun décalage possible
    }

    private function toUtc(DateTimeInterface $dt): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $dt->getTimestamp()))->setTimezone(new DateTimeZone('UTC'));
    }

    private function esc(string $v): string
    {
        $v = str_replace('\\', '\\\\', $v);
        $v = str_replace(';', '\;', $v);
        $v = str_replace(',', '\,', $v);
        return str_replace(["\r\n", "\r", "\n"], '\n', $v);
    }

    private function fold(string $line): string
    {
        if (strlen($line) <= 75) { return $line; }
        $out = ''; $len = 0;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $b = strlen($ch);
            if ($len + $b > 75) { $out .= self::CRLF . ' '; $len = 1; }
            $out .= $ch; $len += $b;
        }
        return $out;
    }
}
```

---

## Annexe C — Service JSON round-trip (backend)

> Remplace l'ancien couple MSPDIExporter / MSPDIImporter. Le round-trip JSON n'a
> ni mapping de valeur, ni reconstruction de hiérarchie depuis un niveau : les
> champs du contrat §6 sont écrits/lus tels quels. Ne subsiste que la **fusion
> upsert** et la **résolution des ids temporaires**.

```php
<?php
declare(strict_types=1);

namespace Cmem\Plugins\Projets\Json;

/**
 * Round-trip JSON — export (contrat §6 tel quel) et plan de fusion à l'import.
 * Aucune écriture en base ici : planifier() produit le diff que l'appelant
 * valide, puis applique dans une transaction.
 */
final class JsonRoundTrip
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param array<string,mixed>              $projet
     * @param array<int,array<string,mixed>>   $taches  contrat §6
     */
    public function export(array $projet, array $taches): array
    {
        return [
            'project'       => $projet,
            'tasks'         => array_values($taches),
            'exportedAt'    => gmdate('Y-m-d\TH:i:s\Z'),
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * Produit un plan de fusion (upsert) SANS écrire en base.
     *
     * @param array<string,mixed>            $payload      JSON décodé (export d'un client)
     * @param array<int,array<string,mixed>> $tachesCmem2  état actuel (pour les orphelins)
     * @return array{aCreer: array, aMettreAJour: array, orphelins: array}
     */
    public function planifier(array $payload, array $tachesCmem2): array
    {
        if (($payload['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('schemaVersion incompatible');
        }
        $rows = $payload['tasks'] ?? [];
        if (!is_array($rows)) { throw new \RuntimeException('tasks absent ou invalide'); }

        $idsExistants = [];
        foreach ($tachesCmem2 as $t) { $idsExistants[$t['id']] = true; }

        $aCreer = []; $aMettreAJour = []; $vus = [];
        foreach ($rows as $r) {
            $this->valider($r);
            $id = $r['id'] ?? null;
            // id numérique connu -> UPDATE ; sinon (absent, "tmp-*", inconnu) -> INSERT
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $id = (int) $id;
                if (isset($idsExistants[$id])) { $vus[$id] = true; $aMettreAJour[] = $r; continue; }
            }
            $aCreer[] = $r; // id temporaire résolu à l'insertion (voir §9.4)
        }

        $orphelins = array_values(array_filter(
            $tachesCmem2,
            static fn ($t) => !isset($vus[$t['id']])   // en base, absent du fichier -> conservé, signalé
        ));

        return ['aCreer' => $aCreer, 'aMettreAJour' => $aMettreAJour, 'orphelins' => $orphelins];
    }

    /** Validation applicative minimale d'une tâche (statut, priorité, %). */
    private function valider(array $t): void
    {
        $statuts = ['NEEDS-ACTION', 'IN-PROCESS', 'COMPLETED', 'CANCELLED'];
        if (isset($t['status']) && !in_array($t['status'], $statuts, true)) {
            throw new \RuntimeException('Statut invalide : ' . $t['status']);
        }
        if (isset($t['priority']) && ($t['priority'] < 0 || $t['priority'] > 9)) {
            throw new \RuntimeException('Priorité hors 0..9');
        }
        if (isset($t['percentComplete']) && ($t['percentComplete'] < 0 || $t['percentComplete'] > 100)) {
            throw new \RuntimeException('percentComplete hors 0..100');
        }
        if (!isset($t['title']) || $t['title'] === '') {
            throw new \RuntimeException('title requis');
        }
    }
}
```

> **Résolution des ids temporaires (§9.4) — 2ᵉ passe.** Après INSERT des tâches de
> `aCreer`, construire une carte `tmp-id -> id réel`, puis récrire les `parentId` et
> `dependsOn[].taskId` qui pointent un id temporaire. Valider l'arbre (`parentId`) et
> le DAG (`dependsOn[]`) **après** résolution, avant commit de la transaction.

---

## Annexe D — Validation arbre / DAG (backend)

```php
<?php
declare(strict_types=1);

namespace Cmem\Plugins\Projets\Json;

/** Vérifie l'acyclicité : arbre pour parentId, DAG pour dependsOn[]. */
final class GraphValidator
{
    /**
     * @param array<int,array<string,mixed>> $taches  contrat §6, ids réels résolus
     * @throws \RuntimeException si cycle détecté
     */
    public function assertAcyclique(array $taches): void
    {
        $parent = []; $deps = [];
        foreach ($taches as $t) {
            $id = $t['id'];
            $parent[$id] = $t['parentId'] ?? null;
            $deps[$id]   = array_map(static fn ($d) => $d['taskId'], $t['dependsOn'] ?? []);
        }

        // Hiérarchie : remonter les parents, un cycle réapparaît sur un id déjà vu
        foreach (array_keys($parent) as $start) {
            $vus = []; $cur = $start;
            while ($cur !== null) {
                if (isset($vus[$cur])) { throw new \RuntimeException("Cycle hiérarchie sur #$cur"); }
                $vus[$cur] = true;
                $cur = $parent[$cur] ?? null;
            }
        }

        // Dépendances : DFS avec 3 couleurs (blanc/gris/noir)
        $couleur = [];
        $visiter = function ($id) use (&$visiter, &$couleur, &$deps) {
            $couleur[$id] = 'gris';
            foreach ($deps[$id] ?? [] as $suiv) {
                $c = $couleur[$suiv] ?? 'blanc';
                if ($c === 'gris') { throw new \RuntimeException("Cycle dépendances sur #$suiv"); }
                if ($c === 'blanc') { $visiter($suiv); }
            }
            $couleur[$id] = 'noir';
        };
        foreach (array_keys($deps) as $id) {
            if (($couleur[$id] ?? 'blanc') === 'blanc') { $visiter($id); }
        }
    }
}
```

---

## Annexe E — `MSPDIExporter.php` (export MS Project XML, avec `CmemId`)

```php
<?php
declare(strict_types=1);

namespace Cmem\Plugins\Projets\Export;

use DateTimeInterface;
use DOMDocument;
use DOMElement;

/**
 * Export MSPDI (.xml) — MS Project, ProjectLibre, GanttProject.
 * - Hiérarchie -> OutlineLevel + ordre (parents avant enfants, DFS).
 * - Dépendances (0..n) -> plusieurs <PredecessorLink>.
 * - Identité round-trip -> champ personnalisé Text1 aliasé « CmemId » (§14).
 */
final class MSPDIExporter
{
    private const NS       = 'http://schemas.microsoft.com/project';
    private const CMEM_FID = '188743731'; // FieldID de Text1

    public function build(array $projet, array $taches): string
    {
        $index = [];
        foreach ($taches as $t) { $index[$t['id']] = $t; }

        // Arbre + parcours profondeur (parents avant enfants) + niveau
        $enfants = []; $racines = [];
        foreach ($taches as $t) {
            $p = $t['parentId'] ?? null;
            if ($p !== null && isset($index[$p])) { $enfants[$p][] = $t['id']; }
            else { $racines[] = $t['id']; }
        }
        $ordonne = []; $vus = [];
        $dfs = function ($id, $niv) use (&$dfs, &$ordonne, &$enfants, &$vus) {
            if (isset($vus[$id])) { return; }
            $vus[$id] = true;
            $ordonne[] = ['id' => $id, 'level' => $niv];
            foreach ($enfants[$id] ?? [] as $c) { $dfs($c, $niv + 1); }
        };
        foreach ($racines as $r) { $dfs($r, 1); }

        $uidParId = []; $u = 1;
        foreach ($ordonne as $o) { $uidParId[$o['id']] = $u++; }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS(self::NS, 'Project');
        $dom->appendChild($root);
        $this->el($dom, $root, 'Name', (string) ($projet['name'] ?? 'Projet'));

        // Déclaration du champ personnalisé CmemId (niveau projet)
        $eas = $dom->createElement('ExtendedAttributes');
        $root->appendChild($eas);
        $ea = $dom->createElement('ExtendedAttribute');
        $eas->appendChild($ea);
        $this->el($dom, $ea, 'FieldID', self::CMEM_FID);
        $this->el($dom, $ea, 'FieldName', 'Text1');
        $this->el($dom, $ea, 'Alias', 'CmemId');

        $tasks = $dom->createElement('Tasks');
        $root->appendChild($tasks);

        $id = 1;
        foreach ($ordonne as $o) {
            $t = $index[$o['id']];
            $task = $dom->createElement('Task');
            $tasks->appendChild($task);

            $this->el($dom, $task, 'UID', (string) $uidParId[$t['id']]);
            $this->el($dom, $task, 'ID', (string) $id++);
            $this->el($dom, $task, 'Name', (string) ($t['title'] ?? ''));
            $this->el($dom, $task, 'OutlineLevel', (string) $o['level']);
            $this->el($dom, $task, 'PercentComplete', (string) max(0, min(100, (int) ($t['percentComplete'] ?? 0))));
            $this->el($dom, $task, 'Priority', (string) $this->mapPriority((int) ($t['priority'] ?? 0)));
            if (!empty($t['dtstart'])) { $this->el($dom, $task, 'Start', $this->dt($t['dtstart'])); }
            if (!empty($t['due']))     { $this->el($dom, $task, 'Finish', $this->dt($t['due'])); }
            if (!empty($t['description'])) { $this->el($dom, $task, 'Notes', (string) $t['description']); }
            $this->el($dom, $task, 'Milestone', (empty($t['dtstart']) && !empty($t['due'])) ? '1' : '0');

            // Identité round-trip : CmemId
            $tea = $dom->createElement('ExtendedAttribute');
            $task->appendChild($tea);
            $this->el($dom, $tea, 'FieldID', self::CMEM_FID);
            $this->el($dom, $tea, 'Value', (string) $t['id']);

            // Dépendances : 0..n prédécesseurs
            foreach (($t['dependsOn'] ?? []) as $dep) {
                if (!isset($uidParId[$dep['taskId']])) { continue; }
                $link = $dom->createElement('PredecessorLink');
                $task->appendChild($link);
                $this->el($dom, $link, 'PredecessorUID', (string) $uidParId[$dep['taskId']]);
                $this->el($dom, $link, 'Type', (string) $this->mapLinkType((string) ($dep['type'] ?? 'FS')));
                if (!empty($dep['lagDays'])) {
                    // 1 j ouvré = 8 h = 4800 dixièmes de minute (défaut ; §14.4)
                    $this->el($dom, $link, 'LinkLag', (string) ((int) $dep['lagDays'] * 4800));
                    $this->el($dom, $link, 'LagFormat', '7'); // 7 = jours
                }
            }
        }
        return $dom->saveXML();
    }

    private function el(DOMDocument $d, DOMElement $parent, string $name, string $val): void
    {
        $e = $d->createElement($name);
        $e->appendChild($d->createTextNode($val));
        $parent->appendChild($e);
    }
    private function mapPriority(int $p): int
    {
        if ($p === 0 || $p === 5) { return 500; }
        if ($p >= 1 && $p <= 4)   { return 1000 - $p * 100; }
        return (10 - $p) * 100;
    }
    private function mapLinkType(string $type): int
    {
        return ['FF' => 0, 'FS' => 1, 'SF' => 2, 'SS' => 3][strtoupper($type)] ?? 1;
    }
    private function dt(DateTimeInterface $dt): string { return $dt->format('Y-m-d\TH:i:s'); }
}
```

---

## Annexe F — `GanttProjectExporter.php` (export `.gan`)

```php
<?php
declare(strict_types=1);

namespace Cmem\Plugins\Projets\Export;

use DateTimeInterface;
use DOMDocument;
use DOMElement;

/**
 * Export GanttProject (.gan). Plus léger que MSPDI.
 * - Hiérarchie -> imbrication <task> dans <task>.
 * - Dépendances -> <depend id type difference> sur le prédécesseur.
 * - Identité round-trip -> custom property « CmemId » (§7a/§14).
 * NB : les codes de type de lien .gan diffèrent de MSPDI (§14.3) — à valider.
 */
final class GanttProjectExporter
{
    public function build(array $projet, array $taches): string
    {
        $index = [];
        foreach ($taches as $t) { $index[$t['id']] = $t; }
        $enfants = []; $racines = [];
        foreach ($taches as $t) {
            $p = $t['parentId'] ?? null;
            if ($p !== null && isset($index[$p])) { $enfants[$p][] = $t['id']; }
            else { $racines[] = $t['id']; }
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElement('project');
        $root->setAttribute('name', (string) ($projet['name'] ?? 'Projet'));
        $root->setAttribute('view-date', date('Y-m-d'));
        $dom->appendChild($root);

        // Déclaration de la custom property CmemId (tpc0)
        $props = $dom->createElement('taskproperties');
        $root->appendChild($props);
        $cp = $dom->createElement('taskproperty');
        $cp->setAttribute('id', 'tpc0');
        $cp->setAttribute('name', 'CmemId');
        $cp->setAttribute('type', 'text');
        $cp->setAttribute('valuetype', 'text');
        $props->appendChild($cp);

        $tasksEl = $dom->createElement('tasks');
        $root->appendChild($tasksEl);

        // Émission récursive (imbrication = hiérarchie)
        $ganId = 0; $ganParId = [];
        $emit = function ($id, DOMElement $parentEl) use (&$emit, &$index, &$enfants, &$dom, &$ganId, &$ganParId) {
            $t = $index[$id];
            $g = $ganId++;
            $ganParId[$id] = $g;
            $task = $dom->createElement('task');
            $task->setAttribute('id', (string) $g);
            $task->setAttribute('name', (string) ($t['title'] ?? ''));
            if (!empty($t['dtstart'])) { $task->setAttribute('start', $this->d($t['dtstart'])); }
            elseif (!empty($t['due']))  { $task->setAttribute('start', $this->d($t['due'])); $task->setAttribute('meeting', 'true'); }
            $task->setAttribute('duration', (string) $this->dureeJours($t));
            $task->setAttribute('complete', (string) max(0, min(100, (int) ($t['percentComplete'] ?? 0))));
            $task->setAttribute('priority', (string) $this->mapPriority((int) ($t['priority'] ?? 0)));
            if (!empty($t['description'])) {
                $notes = $dom->createElement('notes');
                $notes->appendChild($dom->createCDATASection((string) $t['description']));
                $task->appendChild($notes);
            }
            // CmemId (custom property tpc0)
            $cpv = $dom->createElement('customproperty');
            $cpv->setAttribute('taskproperty-id', 'tpc0');
            $cpv->setAttribute('value', (string) $t['id']);
            $task->appendChild($cpv);

            $parentEl->appendChild($task);
            foreach ($enfants[$id] ?? [] as $c) { $emit($c, $task); }
        };
        foreach ($racines as $r) { $emit($r, $tasksEl); }

        // Dépendances : <depend> placé sur le PRÉDÉCESSEUR, pointant le successeur
        $depsParPred = [];
        foreach ($taches as $t) {
            foreach (($t['dependsOn'] ?? []) as $dep) {
                $depsParPred[$dep['taskId']][] = ['succ' => $t['id'], 'type' => $dep['type'] ?? 'FS', 'lag' => (int) ($dep['lagDays'] ?? 0)];
            }
        }
        // Ré-attacher les <depend> aux bons <task> par id GanttProject
        $xp = new \DOMXPath($dom);
        foreach ($depsParPred as $predId => $liens) {
            if (!isset($ganParId[$predId])) { continue; }
            $noeud = $xp->query(sprintf('//task[@id="%d"]', $ganParId[$predId]))->item(0);
            if (!$noeud) { continue; }
            foreach ($liens as $l) {
                if (!isset($ganParId[$l['succ']])) { continue; }
                $d = $dom->createElement('depend');
                $d->setAttribute('id', (string) $ganParId[$l['succ']]);
                $d->setAttribute('type', (string) $this->mapLinkType($l['type']));
                $d->setAttribute('difference', (string) $l['lag']);
                $d->setAttribute('hardness', 'Strong');
                $noeud->appendChild($d);
            }
        }
        return $dom->saveXML();
    }

    private function d(DateTimeInterface $dt): string { return $dt->format('Y-m-d'); }
    private function dureeJours(array $t): int
    {
        if (empty($t['dtstart']) || empty($t['due'])) { return 1; }
        $diff = $t['dtstart']->diff($t['due'])->days;
        return max(1, (int) $diff);
    }
    private function mapPriority(int $p): int
    {
        if ($p >= 1 && $p <= 4) { return 2; } // high
        if ($p >= 6 && $p <= 9) { return 0; } // low
        return 1;                             // normal
    }
    /** cmem2 FS/SS/FF/SF -> code .gan (§14.3, à valider). */
    private function mapLinkType(string $type): int
    {
        return ['FF' => 0, 'SF' => 1, 'FS' => 2, 'SS' => 3][strtoupper($type)] ?? 2;
    }
}
```

---

## Annexe G — `MSPDIImporter.php` (ré-import round-trip)

```php
<?php
declare(strict_types=1);

namespace Cmem\Plugins\Projets\Import;

use DOMDocument;
use DOMXPath;

/**
 * Parse un MSPDI et produit une liste de tâches (contrat §6) prête pour la fusion
 * upsert de JsonRoundTrip::planifier (Annexe C). N'écrit rien en base.
 * Reconstruit hiérarchie (OutlineLevel) et dépendances (PredecessorLink),
 * en s'appuyant sur CmemId pour l'identité.
 */
final class MSPDIImporter
{
    private const NS       = 'http://schemas.microsoft.com/project';
    private const CMEM_FID = '188743731';

    /** @return array<int,array<string,mixed>> tâches au format contrat §6 (id = CmemId ou null) */
    public function versTaches(string $xml): array
    {
        $dom = new DOMDocument();
        if (!$dom->loadXML($xml)) { throw new \RuntimeException('MSPDI illisible'); }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('p', self::NS);

        $rows = []; $cmemParUid = [];
        foreach ($xp->query('//p:Task') as $task) {
            $uid    = $this->txt($xp, 'p:UID', $task);
            $cmemId = $this->extendedValue($xp, $task);
            $row = [
                'id'             => $cmemId !== null ? (int) $cmemId : null,
                'title'          => $this->txt($xp, 'p:Name', $task),
                'dtstart'        => $this->txt($xp, 'p:Start', $task) ?: null,
                'due'            => $this->txt($xp, 'p:Finish', $task) ?: null,
                'percentComplete'=> (int) ($this->txt($xp, 'p:PercentComplete', $task) ?: 0),
                'priority'       => $this->mapPriorityInverse((int) ($this->txt($xp, 'p:Priority', $task) ?: 500)),
                '_outlineLevel'  => (int) ($this->txt($xp, 'p:OutlineLevel', $task) ?: 1),
                '_uid'           => $uid,
                'parentId'       => null,
                'dependsOn'      => [],
                '_predUids'      => [],
            ];
            foreach ($xp->query('p:PredecessorLink', $task) as $pl) {
                $row['_predUids'][] = [
                    'uid'  => $this->txt($xp, 'p:PredecessorUID', $pl),
                    'type' => $this->linkTypeInverse((int) ($this->txt($xp, 'p:Type', $pl) ?: 1)),
                    'lag'  => (int) round(((int) ($this->txt($xp, 'p:LinkLag', $pl) ?: 0)) / 4800),
                ];
            }
            if ($cmemId !== null) { $cmemParUid[$uid] = (int) $cmemId; }
            $rows[] = $row;
        }

        // Hiérarchie : parent = dernière tâche de niveau inférieur (pile)
        $pile = [];
        foreach ($rows as $idx => &$r) {
            $niv = $r['_outlineLevel'];
            foreach (array_keys($pile) as $l) { if ($l >= $niv) { unset($pile[$l]); } }
            if ($niv > 1 && !empty($pile)) { $r['parentId'] = $rows[end($pile)]['id']; }
            $pile[$niv] = $idx;
        }
        unset($r);

        // Dépendances : PredecessorUID -> CmemId
        foreach ($rows as &$r) {
            foreach ($r['_predUids'] as $pred) {
                if (isset($cmemParUid[$pred['uid']])) {
                    $r['dependsOn'][] = ['taskId' => $cmemParUid[$pred['uid']], 'type' => $pred['type'], 'lagDays' => $pred['lag']];
                }
                // sinon : prédécesseur nouvellement créé -> relié en 2e passe (§9.4)
            }
            unset($r['_predUids'], $r['_outlineLevel'], $r['_uid']);
        }
        unset($r);

        return $rows; // -> JsonRoundTrip::planifier() puis GraphValidator
    }

    private function txt(DOMXPath $xp, string $q, \DOMNode $ctx): string
    {
        $n = $xp->query($q, $ctx)->item(0);
        return $n ? trim($n->textContent) : '';
    }
    private function extendedValue(DOMXPath $xp, \DOMNode $task): ?string
    {
        foreach ($xp->query('p:ExtendedAttribute', $task) as $ea) {
            if ($this->txt($xp, 'p:FieldID', $ea) === self::CMEM_FID) {
                return $this->txt($xp, 'p:Value', $ea);
            }
        }
        return null;
    }
    /** Type MSPDI (0=FF,1=FS,2=SF,3=SS) -> FS/SS/FF/SF. */
    private function linkTypeInverse(int $type): string
    {
        return [0 => 'FF', 1 => 'FS', 2 => 'SF', 3 => 'SS'][$type] ?? 'FS';
    }
    /** Priorité MSPDI 0-1000 -> 0-9 (§14.2). */
    private function mapPriorityInverse(int $p): int
    {
        if ($p >= 600) { return 1; } // haute
        if ($p >= 400) { return 5; } // normale
        return 9;                    // basse
    }
}
```

---

## Annexe H — `GanttProjectImporter.php` (ré-import round-trip)

```php
<?php
declare(strict_types=1);

namespace Cmem\Plugins\Projets\Import;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parse un .gan et produit une liste de tâches (contrat §6) pour la fusion upsert
 * de JsonRoundTrip::planifier (Annexe C). N'écrit rien en base.
 * Hiérarchie = imbrication <task> ; dépendances = <depend> ; identité = CmemId.
 */
final class GanttProjectImporter
{
    /** @return array<int,array<string,mixed>> tâches au format contrat §6 */
    public function versTaches(string $xml): array
    {
        $dom = new DOMDocument();
        if (!$dom->loadXML($xml)) { throw new \RuntimeException('.gan illisible'); }
        $xp = new DOMXPath($dom);

        $rows = [];          // ganId -> row
        $cmemParGan = [];    // ganId -> CmemId
        $liens = [];         // [predGanId, succGanId, typeGan, diff]

        // Parcours récursif : parent = <task> englobant
        $walk = function (DOMElement $el, ?string $parentGan) use (&$walk, &$rows, &$cmemParGan, &$liens, $xp) {
            foreach ($el->childNodes as $child) {
                if (!($child instanceof DOMElement) || $child->nodeName !== 'task') { continue; }
                $gan = $child->getAttribute('id');
                $cmem = null;
                foreach ($xp->query('customproperty', $child) as $cp) {
                    if ($cp->getAttribute('taskproperty-id') === 'tpc0') { $cmem = $cp->getAttribute('value'); }
                }
                $notesEl = $xp->query('notes', $child)->item(0);
                $rows[$gan] = [
                    'id'             => ($cmem !== null && $cmem !== '') ? (int) $cmem : null,
                    'title'          => $child->getAttribute('name'),
                    'dtstart'        => $child->getAttribute('start') ?: null,
                    'due'            => null, // recalculé depuis start + duration si besoin
                    'percentComplete'=> (int) ($child->getAttribute('complete') ?: 0),
                    'priority'       => $this->mapPriorityInverse((int) ($child->getAttribute('priority') ?: 1)),
                    'parentGan'      => $parentGan,
                    'dependsOn'      => [],
                    'description'    => $notesEl ? trim($notesEl->textContent) : null,
                ];
                if ($cmem !== null && $cmem !== '') { $cmemParGan[$gan] = (int) $cmem; }
                // <depend> : ce <task> est le prédécesseur, id = successeur
                foreach ($xp->query('depend', $child) as $dep) {
                    $liens[] = [
                        'pred' => $gan,
                        'succ' => $dep->getAttribute('id'),
                        'type' => (int) ($dep->getAttribute('type') ?: 2),
                        'diff' => (int) ($dep->getAttribute('difference') ?: 0),
                    ];
                }
                $walk($child, $gan); // enfants imbriqués
            }
        };
        $tasksRoot = $xp->query('//project/tasks')->item(0);
        if ($tasksRoot instanceof DOMElement) { $walk($tasksRoot, null); }

        // Hiérarchie : parentGan -> parentId (CmemId du parent)
        foreach ($rows as $gan => &$r) {
            $pg = $r['parentGan'];
            $r['parentId'] = ($pg !== null && isset($cmemParGan[$pg])) ? $cmemParGan[$pg] : null;
            unset($r['parentGan']);
        }
        unset($r);

        // Dépendances : successeur porte dependsOn[] vers le prédécesseur
        foreach ($liens as $l) {
            if (!isset($rows[$l['succ']]) || !isset($cmemParGan[$l['pred']])) { continue; }
            $rows[$l['succ']]['dependsOn'][] = [
                'taskId'  => $cmemParGan[$l['pred']],
                'type'    => $this->linkTypeInverse($l['type']),
                'lagDays' => $l['diff'],
            ];
        }

        return array_values($rows); // -> JsonRoundTrip::planifier() puis GraphValidator
    }

    /** Code .gan -> FS/SS/FF/SF (§14.3, à valider). */
    private function linkTypeInverse(int $type): string
    {
        return [0 => 'FF', 1 => 'SF', 2 => 'FS', 3 => 'SS'][$type] ?? 'FS';
    }
    /** Priorité .gan (0=low,1=normal,2=high) -> 0-9. */
    private function mapPriorityInverse(int $p): int
    {
        return [0 => 9, 1 => 5, 2 => 1][$p] ?? 5;
    }
}
```
