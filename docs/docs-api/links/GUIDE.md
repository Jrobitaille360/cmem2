# Links — liens croisés inter-entités

<!-- markdownlint-disable MD013 -->

Liens bidirectionnels polymorphes entre les entités CMEM : **event, task, journal, project,
project_task** et, depuis la Phase G-E (GED), **file, contact, interaction, opportunite**.
Directives `cmem_web` B2 (`20260722_141845`) et GED (`20260724_154619`).

## Modèle

| Type | Table | Titre | Sous-type |
| - | - | - | - |
| `event` | `calendar_events` | `title` | — |
| `task` | `calendar_todos` | `title` | `project_id IS NULL` |
| `journal` | `calendar_journals` | `summary` | — |
| `project` | `projects` | `name` | — |
| `project_task` | `calendar_todos` | `title` | `project_id IS NOT NULL` |
| `file` | `files` | `original_name` | propriétaire = `uploaded_by` |
| `contact` | `contacts` | « prénom nom », à défaut `organisation` | suppression = `supprime_le` |
| `interaction` | `interaction` | `resume`, à défaut `sujet` | suppression = `supprime_le` |
| `opportunite` | `opportunite` | `titre` | suppression = `supprime_le` |

Table `links` : `id, app_id, owner_id, src_type, src_id, dst_type, dst_id, created_at`.
Unicité logique : `(app_id, owner_id, src_type, src_id, dst_type, dst_id)`.

## Règles

- **Portée owner-strict** : une extrémité n'est valide que si son `user_id == owner_id` (le user
  courant). Toute entité non visible → `404` (jamais de divulgation d'existence).
- **Bidirectionnel** : un seul enregistrement `src → dst`. Le sens inverse (`dst → src`) est le
  **même lien logique**. La dédup se fait **à la création** : un doublon exact ou inverse renvoie le
  lien existant (`200`, idempotent) au lieu d'en créer un second.
- **`GET`** renvoie les liens **entrants + sortants** d'une entité. `direction` vaut `outgoing`
  quand l'entité interrogée est `src`, `incoming` quand elle est `dst`. Chaque item porte
  `other_title` (titre de la cible) pour éviter N requêtes de résolution côté client.
- **Multi-tenant** : `app_id` (défaut serveur `puzzle`, `cmemweb` pour cmem_web). Les liens sont
  filtrés par `app_id` en lecture.

## Cascade de purge

À la suppression (soft ou hard) d'une entité, tous les liens la référençant (en `src` **ou** `dst`)
sont purgés — zéro orphelin. Points d'ancrage :

- `CalendarEvent::softDelete()` → purge `event`
- `CalendarTodo::softDeleteById()` et `Projets\Task::softDeleteTask()` → purge `task` + `project_task`
- `CalendarJournal::softDeleteById()` → purge `journal`
- `Projets\Project::deleteProject()` → purge `project` + les liens de ses `project_task` (avant le
  DELETE, car la suppression FK-cascade les tâches hors PHP)
- `File::deleteById()` / `File::delete()` → purge `file`
- `Contacts\Contact::softDeleteContact()` → purge `contact` + les liens de ses `interaction` et
  `opportunite` (ids collectés avant le masquage)
- `Contacts\Interaction::softDeleteInteraction()` → purge `interaction`
- `Contacts\Opportunite::softDeleteOpportunite()` / `softDeleteByContact()` → purge `opportunite`

La purge est appelée via `AuthGroups\Models\Link::purge()` / `purgeTodo()` (statiques, try/catch : la
suppression de l'entité prime, jamais interrompue même si la table `links` est absente).

> Limite connue : la **restauration** d'une entité soft-deleted ne restaure pas ses liens (purgés à
> la suppression). Acceptable pour la v1 (non requis par la directive).

## Endpoints

Voir `API_LINKS_ENDPOINTS.json`. Résumé :

- `POST /links` — `{ src_type, src_id, dst_type, dst_id, app_id? }` → `201` (créé) / `200` (existant)
- `GET /links?type=&id=&app_id=` → `[{ id, direction, other_type, other_id, other_title, created_at }]`
- `DELETE /links/{id}` — owner-scoped (`403` si tiers, `404` si absent)

## Tests

`php private/tests/test_links.php` — 55 assertions (sécurité, validation, dédup bidirectionnelle,
GET entrants/sortants, DELETE scopé, cascade de purge event/journal/project/project_task).

`php private/tests/test_links_ged.php` — types GED (sécurité owner-strict sur `file`/`contact`,
liens vers `interaction`/`opportunite`, `other_title`, purge à la suppression d'un fichier ou
d'un contact).
