# PLAN — Suivi du temps (sessions par tâche, D3)

Directive source : `c:\code\directives_inter_projet\20260814_143000_cmem_web_vers_cmem2_API__time-tracking-sessions.md`
Directives liées : `20260727_144926_..._modules-gating.md` (complétée), `20260804_090000_..._e2e-taches-contacts.md` (complétée)

## Décisions actées avec l'utilisateur (2026-08-14)

| Question ouverte | Décision |
| - | - |
| Gating `tenant_modules` | **Aucun.** `calendar_todos` lui-même n'est gaté par aucune `module_key` (fonctionnalité socle, tous plans). Aucune clé existante ne correspond (`projet` = module Gantt distinct, table différente). Les sessions suivent le même régime que les tâches. |
| Chiffrement du champ `note` | **Chiffré**, même convention que `title`/`description` des VTODO (`enc_alg`/`enc_iv`, opaque, aucune transformation serveur). |

## Décisions techniques (à valider avant migration — voir §Confirmation requise)

- **Table réelle des tâches** : `calendar_todos` (module `ics`), pas `projets.tasks`. La directive parle
  de VTODO au sens RFC 5545 ; c'est la même table déjà étendue par `e2e-taches-contacts`.
- **Portée d'une session** : personnelle à l'usager du JWT, indépendante du propriétaire de la tâche.
  Contrainte « un seul minuteur actif par usager » est **globale**, pas par tâche — un usager qui a
  accès à plusieurs tâches (calendrier partagé) ne peut avoir qu'un minuteur en cours, tous todos confondus.
- **Autorisation** :
  - `start` / liste : la même permission que `GET .../todos/{id}` (accès en lecture au calendrier,
    pas nécessairement propriétaire — cohérent avec un calendrier partagé).
  - `stop` / `update` / `delete` : uniquement le propriétaire de la **session** (`session.user_id`),
    pas le propriétaire de la tâche.
- **Suppression** : hard delete (pas de corbeille). La directive dit « suppression d'une session
  erronée », rien n'indique un besoin de restauration comme pour les todos/events. À corriger si
  le client en a besoin.
- **Pas de quota/cap** en v1 — aucune demande dans la directive.

## Ce qui existe déjà (réutilisé tel quel)

- `CalendarTodo` (Modèle) / `TodoController` / routes `/calendars/{id}/todos/*` — pattern à copier
  pour le contrôleur/modèle des sessions.
- `Calendar::getUserPermissionForCalendar()` — vérif d'accès en lecture à un calendrier.
- Convention e2e : `enc_alg`/`enc_iv` nullable, `null` explicite efface, champ omis = inchangé
  (déjà implémenté dans `TodoController::updateTodo`, à copier).
- `ConditionalRequest::enforce()` — pas utilisé ici (pas de `updated_at` client-fourni en entrée
  pour ce type de ressource, sessions courtes).
- Pattern de routage `match(true)` par `$request['controller']` dans `CalendarRouteHandler` —
  extension du même handler plutôt qu'une nouvelle classe (comme `freebusy` réutilise déjà
  `CalendarRouteHandler`).

## Améliorations à faire (portée de cette directive)

### 1. Migration `docs/20260814_time_sessions.sql`

```sql
CREATE TABLE time_sessions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    todo_id        INT NOT NULL,
    user_id        INT NOT NULL,
    started_at     DATETIME NOT NULL,
    ended_at       DATETIME DEFAULT NULL,
    note           VARCHAR(2000) DEFAULT NULL
                   COMMENT 'Clair, ou base64 opaque si enc_alg renseigné',
    enc_alg        VARCHAR(32) DEFAULT NULL
                   COMMENT 'Algorithme de chiffrement client, ex. AES-GCM-256. NULL = note en clair',
    enc_iv         VARCHAR(32) DEFAULT NULL
                   COMMENT 'Vecteur d''initialisation base64. NULL = note en clair',
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    active_user_id INT AS (IF(ended_at IS NULL, user_id, NULL)) STORED,
    CONSTRAINT fk_time_sessions_todo FOREIGN KEY (todo_id) REFERENCES calendar_todos(id) ON DELETE CASCADE,
    CONSTRAINT fk_time_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_time_sessions_active_user (active_user_id),
    INDEX idx_time_sessions_todo (todo_id),
    INDEX idx_time_sessions_user (user_id)
);
```

`active_user_id` est le truc MySQL classique pour une contrainte d'unicité partielle (colonne
générée `NULL` sauf quand `ended_at IS NULL`) : MySQL autorise plusieurs `NULL` dans un index
`UNIQUE` mais un seul `user_id` non-`NULL`. C'est la garde-fou **serveur** (pas seulement applicatif)
contre deux sessions actives simultanées, y compris en cas de requêtes concurrentes.

### 2. Modèle `src/ics/Models/TimeSession.php`

`getById`, `getByTodoId`, `getActiveForUser`, `create`, `stop`, `update` (avec `clearEncAlg`/
`clearEncIv` comme `CalendarTodo`), `delete`.

### 3. Contrôleur `src/ics/Controllers/TimeSessionController.php`

| Méthode | Route |
| - | - |
| `startSession` | `POST /calendars/{id}/todos/{todoId}/time-sessions/start` |
| `getSessionsForTodo` | `GET /calendars/{id}/todos/{todoId}/time-sessions` |
| `getActiveSession` | `GET /time-sessions/active` |
| `stopSession` | `PATCH /time-sessions/{id}/stop` |
| `updateSession` | `PUT\|PATCH /time-sessions/{id}` |
| `deleteSession` | `DELETE /time-sessions/{id}` |

Comportements spécifiques :

- `startSession` : `409 ACTIVE_SESSION_EXISTS` (`errors.active_session_id`) si une session active
  existe déjà pour l'usager — vérifié applicativement **avant** l'insert (message clair), avec la
  contrainte `UNIQUE` en filet de sécurité si une course survient (capturée en `409` générique).
- `stopSession` / `updateSession` (remise à `ended_at: null`) : capture de la violation de
  contrainte unique → `409 ACTIVE_SESSION_EXISTS`.
- `updateSession` : si `started_at` et `ended_at` sont tous deux connus (existant ou fournis) et
  `ended_at < started_at` → `400`.
- Enveloppe de réponse standard `{ success, message, data, errors }`, cohérent avec le reste de l'API
  (pas le format `{ "error": ... }` de la directive — même écart déjà documenté dans `modules-gating`).

### 4. Routage

Extension de `CalendarRouteHandler` (comme `freebusy`) :

- `getSupportedControllers()` += `'time-sessions'`.
- Cas nichés sous `todos/{todoId}/time-sessions[...]` dans le bloc existant (segments `[2]='todos'`).
- Cas `$request['controller'] === 'time-sessions'` pour `active` / `{id}/stop` / `{id}` / delete.
- `CalendarPlugin::getRouteHandlers()` += clé `'time-sessions'` → même instance `CalendarRouteHandler`.

### 5. Documentation

- `docs/ics/GUIDE.md` — section suivi du temps.
- `docs/ics/API_ICS_ENDPOINTS.json` — 6 nouveaux endpoints.
- `docs/entrypoints.md` — mis à jour.

### 6. Tests

Nouveau fichier `private/tests/test_time_sessions.php` (pattern `test_ics_todos_e2e.php`), ajouté à
`run_all_tests.php`. Couverture minimale (critères d'acceptation testables, un test ≈ un critère) :

1. `POST start` sans session active → `201`, `ended_at: null`.
2. `POST start` avec session active (même usager, tâche différente ou même) → `409
   ACTIVE_SESSION_EXISTS` + `active_session_id` correct, aucune ligne créée.
3. `PATCH stop` sur sa propre session active → `200`, `ended_at` posé.
4. `PATCH stop` sur session déjà arrêtée → `409` (pas de double-stop silencieux).
5. `PATCH stop` sur session d'un autre usager → `403`.
6. `GET .../todos/{id}/time-sessions` → liste triée, `count` correct.
7. `GET /time-sessions/active` → session en cours si existante, `data.session: null` sinon.
8. `PATCH /time-sessions/{id}` — correction `started_at`/`ended_at`/`note`, `ended_at < started_at`
   → `400`.
9. `PATCH /time-sessions/{id}` avec `enc_alg`/`enc_iv` — round-trip octet pour octet du `note` opaque
   (comme `title`/`description` des todos), `null` explicite efface, champ omis inchangé.
10. `DELETE /time-sessions/{id}` — uniquement par le propriétaire, `403` sinon.
11. Accès à une tâche sans permission sur le calendrier → `404`/`403` cohérent avec `getTodo`.
12. Suite complète non régressée (`run_all_tests.php`).

## Maintenances à prévoir (hors portée v1, à noter pour plus tard)

- Pas de quota/cap sur le nombre de sessions — si abusé, ajouter un cap `CmemPlans` plus tard.
- Pas de corbeille pour les sessions supprimées — si le client en a besoin, ajouter `deleted_at` +
  restore comme pour todos/events.
- Agrégat serveur du temps total par tâche — reporté explicitement par la directive (calcul client).

## Phase unique (P2, item isolé — pas de découpage nécessaire)

**Actions** : migration → modèle → contrôleur → routage → tests (rouges puis verts) → doc → CHANGELOG.
**Enjeux** : contrainte d'unicité "un seul minuteur actif" doit tenir sous concurrence (index
`UNIQUE` généré, pas seulement une vérif applicative) ; ne jamais transformer/lire `note` chiffrée.
**Tests** : `test_time_sessions.php` (12 cas ci-dessus) + suite complète verte.
**Condition de complétion** : les 5 cases à cocher de la directive + tests verts + doc à jour +
réponse écrite dans le fichier de directive (gating + chiffrement) + `statut: complété`.

## Confirmation requise avant de continuer

Conformément au protocole Spec-First et aux règles du projet (migration DB + directive
inter-projet = STOP obligatoire) :

- [ ] Le nom de table `time_sessions`, la structure ci-dessus et le mécanisme de contrainte
      (`active_user_id` généré) conviennent.
- [ ] La portée d'autorisation (n'importe quel usager avec accès au calendrier peut démarrer un
      minuteur sur une tâche ; seul le propriétaire de la session peut l'arrêter/modifier/supprimer)
      convient.
- [ ] Hard delete (pas de corbeille) convient pour `DELETE /time-sessions/{id}`.
- [ ] Feu vert pour écrire les tests (rouges) puis la migration + le code.
