# Index des docs JSON d'endpoints

Chaque module expose sa documentation d'API au format JSON. Ces fichiers décrivent les routes réellement implémentées dans `src/` (audit docs ↔ code : 2026-07-06).

| Fichier | Routes | Description |
| - | - | - |
| [core/API_ENDPOINTS.json](docs-api/core/API_ENDPOINTS.json) | 96 | API cœur : auth JWT/OTP, users, groups (dont `/groups/{id}/modules` — plan équipe), files, tags, stats, plans, métadonnées de clé e2e (`/users/me/e2e-key`), suppression de compte et restauration. Document de référence transmis aux clients. |
| [ics/API_ICS_ENDPOINTS.json](docs-api/ics/API_ICS_ENDPOINTS.json) | 49 | Calendriers ICS/CalDAV : événements, occurrences, todos, journaux, partage, notifications email, sessions de temps par tâche. |
| [contacts/API_CONTACTS_ENDPOINTS.json](docs-api/contacts/API_CONTACTS_ENDPOINTS.json) | 19 | Pilier Contacts : fiches personnes/organisations, vCard 4.0 import/export, cap `max_contacts`, chiffrement de bout en bout, CRM (interactions, `/opportunites`), corbeille (`/contacts/deleted` + restore). |
| [projets/API_PROJETS_ENDPOINTS.json](docs-api/projets/API_PROJETS_ENDPOINTS.json) | 18 | Gestion de projet : tâches, hiérarchie/dépendances (DAG), round-trip JSON, export `.ics` VEVENT, corbeille projets et tâches (`/deleted` + restore). |
| [links/API_LINKS_ENDPOINTS.json](docs-api/links/API_LINKS_ENDPOINTS.json) | 3 | Liens croisés bidirectionnels entre entités (event, task, journal, project, project_task, file, contact, interaction, opportunite) : création idempotente, liste enrichie du titre cible, suppression, cascade de purge. |
| [booking/API_BOOKING_ENDPOINTS.json](docs-api/booking/API_BOOKING_ENDPOINTS.json) | 7 | Réservation publique : page hôte (`/booking/page`, JWT), créneaux libres et réservation atomique par slug (public, rate-limité), annulation par token. |
| [items/API_ITEMS_ENDPOINTS.json](docs-api/items/API_ITEMS_ENDPOINTS.json) | 13 | Gestionnaire d'items génériques — privé / public / partagé, catégories, permissions par utilisateur. |
| [quiz/API_QUIZ_ENDPOINTS_v1_0_0.json](docs-api/quiz/API_QUIZ_ENDPOINTS_v1_0_0.json) | 17 | Quiz temps réel : CRUD quiz/questions, sessions animateur (JWT) et participants (participant_token). |
| [puzzle/API_PUZZLE_ENDPOINTS.json](docs-api/puzzle/API_PUZZLE_ENDPOINTS.json) | 20 | Plugin puzzle : carrousel, thèmes, livraison d'images, backup en ligne, casse-têtes partagés (pick/drop). Auth device_token. |
| [puzzle/API_PUZZLE_ADMIN_MANAGER.json](docs-api/puzzle/API_PUZZLE_ADMIN_MANAGER.json) | 16 | Administration puzzle pour le SPA React : CRUD images et thèmes, tri, associations, livraison images admin. JWT ADMINISTRATEUR. |
| [playstore/API_PLAYSTORE_ENDPOINTS.json](docs-api/playstore/API_PLAYSTORE_ENDPOINTS.json) | 8 | Devices Android (register, pseudonyme) et abonnements Google Play (verify/status/cancel via X-Device-Token). |
| [webdevice/API_WEBDEVICE_ENDPOINTS.json](docs-api/webdevice/API_WEBDEVICE_ENDPOINTS.json) | 5 (+5 alias) | Devices web et Windows (table `web_devices`) : register + pseudonyme. Routes `/v2/devices/windows/*` identiques en alias. |
| [stripe/API_STRIPE_ENDPOINTS.json](docs-api/stripe/API_STRIPE_ENDPOINTS.json) | 5 (+6 legacy) | Abonnements Stripe web/Windows : checkout, portal, webhook, statut, annulation. `group_id` optionnel (plan équipe) sur checkout/portal/statut/annulation. Sections deprecated/removed pour le legacy `/subscription/*`. |
| [access/API_ACCESS_ENDPOINTS.json](docs-api/access/API_ACCESS_ENDPOINTS.json) | 12 | `GET /v2/access/status` — statut d'accès consolidé (croisement Stripe + Play Store) par app_id. Accès sur approbation : `PUT /users/{id}/access-status`, `POST/GET/DELETE /invitations`, `GET/PUT /users/{id}/modules` (modules cochés), codes 403 `ACCOUNT_PENDING` / `ACCOUNT_SUSPENDED`. Caps de ressources à trois niveaux : `GET /caps/defaults`, `GET/PUT /users/{id}/caps`, `GET/PUT /groups/{id}/caps`. |
| [traque/API_TRAQUE_ENDPOINTS.json](docs-api/traque/API_TRAQUE_ENDPOINTS.json) | 17 | Jeu Traque : monstres géolocalisés (OSM), combat, personnage (création, repos, level-up), bestiaire, leaderboard. |
| [pomo/API_POMO_ENDPOINTS_v1_0_0.json](docs-api/pomo/API_POMO_ENDPOINTS_v1_0_0.json) | 1 | Plugin Pomodoro — phase 1A seulement : `POST /pomo/engagement` (waitlist/sondage public). Phases 1B/2/3 planifiées. |
| [push/API_PUSH_ENDPOINTS.json](docs-api/push/API_PUSH_ENDPOINTS.json) | 5 | Notifications push web (VAPID) : clé publique, subscriptions par appareil, préférences par compte (4 kinds dont `contact_followup`). |
| [modules/API_MODULES_ENDPOINTS.json](docs-api/modules/API_MODULES_ENDPOINTS.json) | 4 | Registre de modules activables : gating par plan (`available`), interrupteur usager (`enabled`), quota serveur. Plan équipe : `GET/PATCH /groups/{id}/modules[/{key}]`, fusionnés dans `GET /modules`. |
| [ai/API_AI_ENDPOINTS.json](docs-api/ai/API_AI_ENDPOINTS.json) | 2 | Proxy IA — `POST /ai/summarize` (résumé d'agenda), `GET /ai/usage`. Budget mensuel en dollars réservé avant l'appel modèle, clé Anthropic serveur seule, consigne fixe (pas de prompt libre). |

## Guides narratifs par module

Chaque guide complète le JSON avec les flux d'intégration, exemples et codes d'erreur
(audit guides ↔ JSON ↔ code : 2026-07-06).

| Guide | Contenu |
| - | - |
| [core/GUIDE.md](docs-api/core/GUIDE.md) | Auth JWT/OTP, device tokens, users, groupes, fichiers (accessibilité, SVG→PNG), tags, stats, abonnements. |
| [ics/GUIDE.md](docs-api/ics/GUIDE.md) | Calendriers, événements récurrents, occurrences, VTODO/VJOURNAL/VFREEBUSY, partage public, CalDAV. |
| [contacts/GUIDE.md](docs-api/contacts/GUIDE.md) | Fiches contacts, champs répétables, vCard 4.0 et import, cap de plan, chiffrement de bout en bout, envoi de courriel, CRM (interactions, opportunités), GED, relance. |
| [links/GUIDE.md](docs-api/links/GUIDE.md) | Modèle polymorphe des liens, règles (idempotence, sens inverse, scoping propriétaire), cascade de purge. |
| [booking/GUIDE.md](docs-api/booking/GUIDE.md) | Gating par plan, endpoints hôte et publics, génération des zones, cron, erreurs. |
| [items/GUIDE.md](docs-api/items/GUIDE.md) | Items privés/publics/partagés, catégories, permissions par utilisateur. |
| [quiz/GUIDE.md](docs-api/quiz/GUIDE.md) | Flux animateur (JWT) et participant (participant_token), sessions, leaderboard. |
| [puzzle/GUIDE.md](docs-api/puzzle/GUIDE.md) | Client mobile Flutter : carrousel, thèmes, backup/claim, casse-têtes partagés (pick/drop). |
| [puzzle/guide_image_manager.md](docs-api/puzzle/guide_image_manager.md) | SPA React d'administration des images et thèmes. |
| [playstore/GUIDE.md](docs-api/playstore/GUIDE.md) | Devices Android, pseudonyme, abonnements Google Play (verify/status/cancel). |
| [webdevice/GUIDE.md](docs-api/webdevice/GUIDE.md) | Devices web/Windows (alias `/v2/devices/windows/*`), pseudonyme multi-plateforme. |
| [stripe/GUIDE.md](docs-api/stripe/GUIDE.md) | Checkout, portail, webhook `/v2/billing/*`, routes legacy dépréciées, plan effectif cmem, plan équipe (abonnement de groupe). |
| [access/GUIDE.md](docs-api/access/GUIDE.md) | `GET /v2/access/status` — matrice d'accès premium par plateforme. Accès sur approbation (pending/approved/suspended), invitations admin. |
| [traque/GUIDE.md](docs-api/traque/GUIDE.md) | Personnage, monstres géolocalisés, combat, repos, bestiaire, leaderboard. |
| [pomo/GUIDE.md](docs-api/pomo/GUIDE.md) | Engagement public (Ph1A actif) ; support/sync/Stripe = contrat prévisionnel (404). |
| [push/GUIDE.md](docs-api/push/GUIDE.md) | Flux d'abonnement Web Push, préférences par compte, sélection des échéances, idempotence, purge sur 410, cron. |
| [modules/GUIDE.md](docs-api/modules/GUIDE.md) | Trois états (disponible / activé / quota), mapping plan→modules, rétro-fit des pans livrés, codes d'erreur du gating, plan équipe (fusion perso ∪ groupe). |
| [ai/GUIDE.md](docs-api/ai/GUIDE.md) | Contrat de confidentialité (métadonnées seulement, jamais de corps de journal), quota avant appel, consigne fixe côté serveur. |

## Exploitation (cron)

| Document | Contenu |
| - | - |
| [cron/CRON_maintenance.md](docs-api/cron/CRON_maintenance.md) | Script `src/cron/maintenance.php` et commandes crontab de production (cPanel). |
| [cron/PLAN_backup_system.md](docs-api/cron/PLAN_backup_system.md) | Plan du système de sauvegarde par module (cron). |

## Conventions

- Format commun des entrées : `method`, `path`, `description`, `body`/`query_params`, `responses`.
- Routes legacy actives : section `deprecated_routes` avec `replaced_by`.
- Routes supprimées (404/410) : section `removed_routes`.
- Les routes `secret-admin` ne sont volontairement pas documentées.

## Enveloppe de réponse commune

Toutes les routes cmem2 (hors téléchargements de fichiers/ICS) répondent en JSON avec
la même enveloppe, produite par `AuthGroups\Utils\Response` (`success()`, `error()`,
`paginated()`) :

```json
{
  "success": true,
  "message": "...",
  "timestamp": "2026-08-17 12:34:56",
  "data": {}
}
```

- `success` (bool) — toujours présent.
- `message` (string) — toujours présent.
- `timestamp` (string `Y-m-d H:i:s`, heure serveur) — toujours présent.
- `data` — présent sur succès quand une charge utile existe (absent sinon, jamais `null` à la racine).
- `errors` — présent sur échec quand des détails de validation existent (`success: false`).
- Pagination (`paginated()`) : mêmes clés + `pagination` (`current_page`, `per_page`, `total`, `total_pages`, `has_next`, `has_prev`).

Codes HTTP standards : 200, 201, 401, 403, 404, 409, 422, 429.

## Effacement d'un champ optionnel

Règle générale sur les `PUT` : **champ absent = inchangé, `null` = effacé**.

`PUT /calendars/{calendar_id}/events/{event_id}` applique ce contrat à `location`,
`description`, `color` et `recurrence_rule` : la chaîne vide est acceptée et normalisée
en `NULL` (compatibilité avec les clients existants), et aucun de ces champs ne produit
de `422` sur `null`.

`recurrence_rule: null` fait cesser la récurrence de l'événement. Les lignes d'exception
déjà posées dans `event_occurrences` sont **conservées** (jamais purgées) : elles
deviennent inertes tant que l'événement n'est pas récurrent, et redeviennent actives si
une `RRULE` est reposée plus tard.

Les **occurrences** suivent une sémantique distincte, car `NULL` y signifie déjà « pas de
surcharge » : sur `PUT /calendars/{cid}/events/{eid}/occurrences/{date}`, c'est la chaîne
vide qui efface.

| Valeur envoyée | Événement | Occurrence |
| - | - | - |
| champ absent | colonne inchangée | surcharge inchangée |
| `null` | colonne mise à `NULL` | normalisé en `''` → effacement |
| `""` | colonne mise à `NULL` | effacement (l'occurrence n'a pas de valeur) |

## Contenus chiffrés de bout en bout

Certains contenus sont chiffrés côté client et stockés par l'API comme des octets opaques.
Le serveur ne détient aucune clé et n'applique **aucune transformation** sur ces champs :
ni `strip_tags`, ni `htmlspecialchars`, ni purificateur HTML, ni normalisation d'espaces.
Un seul octet modifié rend le contenu définitivement indéchiffrable (AES-GCM échoue à
l'authentification, aucune récupération partielle). Aucun endpoint ne doit déchiffrer,
indexer ou résumer un contenu marqué comme chiffré.

| Entité | Champs marqueurs | Champs opaques | Référence |
| - | - | - | - |
| Journaux (VJOURNAL) | `enc_alg`, `enc_iv` | `summary`, `description` | [ics/GUIDE.md](docs-api/ics/GUIDE.md#chiffrement-de-bout-en-bout) |
| Tâches (VTODO) | `enc_alg`, `enc_iv` | `title`, `description` | [ics/GUIDE.md](docs-api/ics/GUIDE.md#chiffrement-de-bout-en-bout-des-tâches) |
| Contacts | `enc_alg`, `enc_iv` | `enc_payload` | [contacts/GUIDE.md](docs-api/contacts/GUIDE.md#chiffrement-de-bout-en-bout) |

`enc_alg` et `enc_iv` valent `null` pour un contenu en clair, et sont acceptés en écriture
(`POST`, `PUT`, `PATCH`) comme restitués en lecture (`GET`) au même titre que les autres champs.

Les contacts font exception au modèle « champs chiffrés en place » : leurs données sensibles
sont structurées et validées (courriels, téléphones, adresses), donc un blob base64 n'y entre
pas. Le client place le JSON chiffré de ces champs dans `enc_payload` (`MEDIUMTEXT`, borne
16 000 000 caractères, dépassement → `400`) et laisse `prenom` / `nom` / `categories` en clair
pour préserver le tri, la pagination et la recherche serveur.

## Tâches de projet — contrat et import JSON

Référence : [projets/API_PROJETS_ENDPOINTS.json](docs-api/projets/API_PROJETS_ENDPOINTS.json).
Constat du 2026-11-08 (directives #234 et #235).

### Champs de tâche

`POST /projets/projects/{id}/tasks` (création) et `PATCH /projets/tasks/{id}` (champs partiels).
Le projet est porté par l'URL : aucun champ `project` dans le corps.

| Champ | Type / borne | Validation |
| - | - | - |
| `title` | string, `VARCHAR(2000)` | requis à la création, non vide en `PATCH` (`422`) |
| `description` | string markdown, `MEDIUMTEXT` (~16 Mo) | aucune borne applicative, stocké tel quel |
| `status` | `NEEDS-ACTION`, `IN-PROCESS`, `COMPLETED`, `CANCELLED` | enum SQL |
| `priority` | entier 0-9 (RFC 5545, 0 = indéfini, 1 = haute) | `0..9` à l'import JSON |
| `percentComplete` | entier 0-100 | `0..100` à l'import JSON |
| `categories` | tableau de chaînes | stocké en JSON |
| `parentId` | id d'une tâche du **même projet** | `422` sinon, cycle → `422` |
| `dependsOn` | `[{taskId, type, lagDays}]`, `type` ∈ `FS`, `SS`, `FF`, `SF` | voir ci-dessous |

### Dépendances `dependsOn`

- Une tâche peut dépendre d'une tâche créée juste avant (l'id réel est connu) : passer
  `dependsOn` à la création, ou l'ajouter ensuite par `PATCH` (remplace la liste entière).
- `taskId` inexistant, tâche d'un autre utilisateur ou tâche d'un **autre projet** du même
  utilisateur → `422 dependsOn[].taskId inexistant ou hors-projet`.
- Cycle (dépendances ou hiérarchie) → `422`, transaction annulée, rien écrit.

### Import en lot `POST /projets/projects/{id}/import.json[/confirm]`

- Identité : un `id` existant dans le projet cible = **mise à jour** (aller-retour export →
  import). Tout autre `id` — texte (`tmp-1`, `API-01`) ou numérique absent du projet
  (`9101`) — est un id temporaire : la tâche est **créée**, jamais d'upsert par l'id du fichier.
- `parentId` et `dependsOn[].taskId` sont remappés vers les nouveaux id réels, que l'id
  temporaire soit numérique ou texte (`9101` et `"9101"` sont la même clé).
- `422` au dry-run comme au confirm : id en double dans le fichier, `dependsOn` non objet
  (`[9101]`), `type` hors `FS|SS|FF|SF`, `lagDays` non entier, référence ni dans le fichier
  ni dans le projet cible.
- Le dry-run expose `liens` (`[{de, vers, type}]`, `type: parent` pour la hiérarchie) et
  `doublonsTitre` (titres à créer déjà présents dans le projet — un réimport recrée les tâches).
- Le confirm est transactionnel (tout ou rien) ; les orphelines sont comptées, jamais supprimées.
