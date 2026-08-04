# Guide — Module ICS / CalDAV

Version 2.0.0 · Base URL : `/calendars` (REST) · `/caldav` (CalDAV)

> Référence complète : [API_ICS_ENDPOINTS.json](API_ICS_ENDPOINTS.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Phases d'implémentation](#phases-dimplémentation)
- [Authentification](#authentification)
- [Flux typiques](#flux-typiques)
- [Endpoints — Calendriers](#endpoints--calendriers)
- [Endpoints — Événements](#endpoints--événements)
- [Endpoints — Récurrence](#endpoints--récurrence)
- [Endpoints — Tâches (VTODO)](#endpoints--tâches-vtodo)
- [Endpoints — Journaux (VJOURNAL)](#endpoints--journaux-vjournal)
- [Endpoints — Étiquettes (Tags)](#endpoints--étiquettes-tags)
- [Endpoints — Disponibilités (VFREEBUSY)](#endpoints--disponibilités-vfreebusy)
- [Endpoints — Notifications et RSVP](#endpoints--notifications-et-rsvp)
- [CalDAV](#caldav)
- [Import / Export ICS](#import--export-ics)
- [Erreurs](#erreurs)
- [Migrations](#migrations)

---

## Vue d'ensemble

Le module ICS/CalDAV gère des calendriers iCalendar conformes **RFC 5545** avec :

- CRUD calendriers et événements
- Export `.ics` (public via token, authentifié via JWT)
- Import `.ics` avec upsert par UID
- Récurrence RRULE (moteur simshaun/recurr)
- Support CalDAV complet (PROPFIND, REPORT, PUT, DELETE)
- Participants/ATTENDEE/ORGANIZER et iTIP METHOD:REPLY (Ph3)
- VTODO, VJOURNAL, VFREEBUSY (Ph5)
- Notifications email VALARM

---

## Phases d'implémentation

| Phase | Contenu | Statut |
| --- | --- | --- |
| Ph1 | CRUD calendriers/événements, export `.ics`, import `.ics`, CalDAV basique | Actif |
| Ph2 | PRIORITY, CLASS, TRANSP, champs enrichis | Actif |
| Ph3 | ATTENDEE, ORGANIZER, iTIP (RSVP), notifications email | Actif |
| Ph4 | EXDATE, RDATE, VALARM, DURATION | Actif |
| Ph5 | VTODO (5.1), VJOURNAL (5.2), VFREEBUSY (5.3) | Actif |

---

## Authentification

```http
Authorization: Bearer <jwt_token>
```

Seul `GET /calendar/{token}.ics` est public (pas de JWT).

---

## Flux typiques

### Créer un calendrier et des événements

```txt
POST /calendars                        → { id: 12 }
POST /calendars/12/events              → { id: 45 }  (événement simple)
POST /calendars/12/events              → { id: 46 }  (récurrent RRULE)
GET  /calendars/12/ics                 → fichier .ics complet
```

### Partager un calendrier publiquement

```txt
GET  /calendar/{share_token}.ics       → fichier .ics (sans auth, token généré à la création du calendrier)
```

### Partager un calendrier avec un utilisateur ou un groupe

```txt
POST /calendars/12/share               { user_id: 42, permission: "write" }
POST /calendars/12/share               { email: "a@b.com", permission: "read" }
POST /calendars/12/share               { group_id: 7, permission: "write" }  # tous les membres du groupe héritent de l'accès
GET  /calendars/12/share               → { shares: [{ shared_with_group_id: 7, group_name: "Famille", permission: "write" }, ...] }
DELETE /calendars/12/share             { group_id: 7 }                       # réservé au propriétaire du calendrier
```

### Import ICS — mise à jour

```txt
POST /calendars/12/ics/import          → { events_created: 3, events_updated: 7 }
# Upsert par UID RFC 5545 §3.8.4.7
```

### Synchronisation CalDAV (clients tiers)

Configurer le client CalDAV avec :

```txt
URL    : https://api.example.com/caldav/calendars/{user_id}/{calendar_id}/
Auth   : Basic  (email + mot de passe) ou Bearer JWT
```

---

## Endpoints — Calendriers

| Méthode | Endpoint | Description |
| --- | --- | --- |
| POST | `/calendars` | Créer un calendrier |
| GET | `/calendars` | Lister ses calendriers |
| GET | `/calendars/{id}` | Détails |
| PUT | `/calendars/{id}` | Modifier |
| DELETE | `/calendars/{id}` | Soft delete |
| DELETE | `/calendars/{id}/hard` | Suppression définitive |
| GET | `/calendars/{id}/ics` | Export ICS (authentifié) |
| POST | `/calendars/{id}/ics/import` | Import ICS → upsert |
| POST | `/calendars/import` | Créer depuis ICS (import initial) |
| POST | `/calendars/{id}/share` | Partager avec un utilisateur (`user_id`/`email`) ou un groupe (`group_id`) |
| GET | `/calendars/{id}/share` | Lister les partages (utilisateur ou groupe) |
| DELETE | `/calendars/{id}/share` | Supprimer un partage (`user_id`/`email`/`group_id`) |
| GET | `/calendar/{token}.ics` | Téléchargement public |

### POST /calendars

```json
{
  "title": "Réunions d'équipe",
  "description": "Calendrier partagé projets",
  "visibility": "private",
  "color": "#3498db",
  "timezone": "America/Montreal",
  "max_members": 100
}
```

### POST /calendars/{id}/ics/import

- Corps : `multipart/form-data`, champ `icsfile`
- Nécessite accès en écriture (propriétaire ou partage write)
- UID existant → mise à jour, UID absent → création
- Retourne : `{ id, title, timezone, events_created, events_updated }`

---

## Endpoints — Événements

| Méthode | Endpoint | Description |
| --- | --- | --- |
| POST | `/calendars/{id}/events` | Créer un événement |
| GET | `/calendars/{id}/events` | Lister les événements |
| GET | `/calendars/{id}/events/{eventId}` | Détails |
| PUT | `/calendars/{id}/events/{eventId}` | Modifier |
| DELETE | `/calendars/{id}/events/{eventId}` | Supprimer (soft) |
| DELETE | `/calendars/{id}/events/{eventId}/hard` | Suppression définitive |

### POST /calendars/{id}/events — exemple complet

```json
{
  "title": "Réunion hebdo",
  "start_datetime": "2026-04-07T09:00:00",
  "end_datetime": "2026-04-07T10:00:00",
  "description": "Point d'équipe",
  "location": "Salle A",
  "all_day": false,
  "timezone": "America/Montreal",
  "status": "confirmed",
  "recurrence_rule": "FREQ=WEEKLY;BYDAY=TU;COUNT=10",
  "priority": 5,
  "class": "PRIVATE",
  "transp": "OPAQUE"
}
```

### Événement avec participants (Ph3)

```json
{
  "title": "Présentation client",
  "start_datetime": "2026-04-10T14:00:00",
  "end_datetime": "2026-04-10T15:00:00",
  "organizer_email": "alice@example.com",
  "organizer_name": "Alice",
  "attendees": [
    {
      "email": "bob@example.com",
      "name": "Bob",
      "role": "REQ-PARTICIPANT",
      "partstat": "NEEDS-ACTION",
      "rsvp": true,
      "cutype": "INDIVIDUAL"
    }
  ]
}
```

### Champs de l'événement

| Champ | Type | Description |
| --- | --- | --- |
| `title` | string (requis) | SUMMARY dans l'ICS |
| `start_datetime` | ISO 8601 | DTSTART |
| `end_datetime` | ISO 8601 | DTEND (exclusif avec `duration`) |
| `duration` | string | ex. `PT1H30M` — exclusif avec `end_datetime` |
| `all_day` | boolean | Événement toute la journée |
| `recurrence_rule` | string | RRULE valide RFC 5545 |
| `timezone` | IANA string | ex. `America/Montreal` |
| `status` | string | `confirmed` \| `tentative` \| `cancelled` |
| `priority` | integer 0–9 | 0=non défini, 1=haute, 5=normale, 9=basse |
| `class` | string | `PUBLIC` \| `PRIVATE` \| `CONFIDENTIAL` |
| `transp` | string | `OPAQUE` \| `TRANSPARENT` |
| `alarms` | array (Ph4) | `[{ action, trigger, description }]` |
| `exdates` | array (Ph4) | Dates d'exclusion de récurrence |
| `rdates` | array (Ph4) | Dates d'ajout à la récurrence |

---

## Endpoints — Récurrence

| Méthode | Endpoint | Description |
| --- | --- | --- |
| GET | `/calendars/{id}/events/{eventId}/occurrences` | Lister les occurrences d'un événement |
| GET | `/calendars/{id}/events/occurrences` | Occurrences de tous les événements du calendrier (`?start=&end=`) |
| PUT | `/calendars/{id}/events/{eventId}/occurrences` | Modifier une occurrence |
| DELETE | `/calendars/{id}/events/{eventId}/occurrences` | Supprimer/annuler une occurrence |
| GET | `/calendars/{id}/events/occurrences/expand` | Expansion RRULE à la demande, timezone-aware (`?start=&end=`) |
| GET | `/calendars/{id}/events/{eventId}/occurrences/expand` | Variante par événement de l'expansion à la demande |

### Expansion à la demande (`/occurrences/expand`)

Route additive (phase 3 rewrite cmem_web) : expanse la RRULE **à la volée sur la seule plage
demandée**, dans le `TZID` de l'événement (DST-safe), sans dépendre de la table
pré-calculée `event_occurrences` ni du CRON. Coexiste avec l'ancien chemin
(`/events/occurrences` sans `/expand`) qui reste inchangé pour le client Flutter — les deux
chemins lisent les mêmes exceptions (`is_cancelled`/`is_modified`).

```txt
GET /calendars/12/events/occurrences/expand?start=2026-03-01&end=2026-03-31
```

- `start`/`end` **requis** (contrairement à l'ancien chemin qui tolère leur absence grâce à
  la table pré-calculée) ; date-seule acceptée (`start` = minuit inclusif, `end` = fin de
  journée 23:59:59 inclusive).
- Occurrence annulée (EXDATE) → absente de la réponse ; occurrence modifiée (RECURRENCE-ID)
  → retournée avec ses `modified_*` déjà appliqués.
- Réponse : mêmes champs que l'ancien endpoint, sauf pas de champ `id` (occurrence non
  stockée) ni `is_on_demand`.
- RRULE hors du sous-ensemble supporté par `simshaun/recurr` → `422` explicite (jamais `500`).

### Exemples de RRULE

| Cas | RRULE |
| --- | --- |
| Chaque semaine le mardi | `FREQ=WEEKLY;BYDAY=TU` |
| Tous les jours ouvrables pendant 2 semaines | `FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR;COUNT=10` |
| Le 1er lundi du mois | `FREQ=MONTHLY;BYDAY=1MO` |
| Chaque année le 25 décembre | `FREQ=YEARLY;BYMONTH=12;BYMONTHDAY=25` |

### Exceptions d'occurrence — double clé (`occurrence_id` XOR `occurrence_date`)

`PUT` et `DELETE /calendars/{id}/events/{eventId}/occurrences` acceptent **exactement une**
des deux clés (les deux présentes ou aucune → `400`) :

| Clé | Type | Usage |
| --- | --- | --- |
| `occurrence_id` | integer | Id de ligne `event_occurrences` — chemin historique (Flutter) |
| `occurrence_date` | string | Clé naturelle RECURRENCE-ID (RFC 5545 §3.8.4.4) — chemin `/expand` (client React) |

Comportement de `occurrence_date` :

- Formats acceptés : `YYYY-MM-DD` (cas courant) ou `YYYY-MM-DD HH:MM:SS` (désambiguïsation
  si plusieurs occurrences le même jour). Interprétée dans le `TZID` de l'événement.
- Ligne matérialisée existante pour `event_id` + date → réutilisée (pas de doublon).
- Aucune ligne : la date est validée contre la grille RRULE (même moteur d'expansion que
  `/expand`, exceptions appliquées) puis la ligne d'exception est **matérialisée à la demande**.
- Date hors grille RRULE → `404` (jamais de `500`, aucune ligne créée).
- `scope=all_future` opère sur `occurrence_date >= date` (et non `id >=`) : les occurrences
  antérieures et leurs exceptions restent intactes ; les occurrences futures non matérialisées
  sont matérialisées sur un horizon de 2 ans pour que l'annulation soit visible via `/expand`.
- Les réponses des deux endpoints incluent `occurrence_date`.

```txt
DELETE /calendars/12/events/45/occurrences?occurrence_date=2026-08-11&scope=only_this
PUT    /calendars/12/events/45/occurrences?occurrence_date=2026-08-18
       body { "title": "Réunion déplacée", "scope": "only_this" }
```

### Portée de modification / suppression

```json
{
  "scope": "only_this"
}
```

| Valeur | Effet |
| --- | --- |
| `only_this` | Seulement cette occurrence |
| `all_future` | Cette occurrence et toutes les suivantes |
| `all` | Toutes les occurrences (supprime l'événement entier) |

---

## Endpoints — Tâches (VTODO)

> RFC 5545 §3.6.2 — Phase 5.1

| Méthode | Endpoint | Description |
| --- | --- | --- |
| POST | `/calendars/{id}/todos` | Créer une tâche |
| GET | `/calendars/{id}/todos` | Lister |
| GET | `/calendars/{id}/todos/{todoId}` | Détails |
| PUT | `/calendars/{id}/todos/{todoId}` | Modifier |
| DELETE | `/calendars/{id}/todos/{todoId}` | Supprimer (soft) |

### Champs principaux

| Champ | Type | Description |
| --- | --- | --- |
| `title` | string (requis) | SUMMARY |
| `due` | date \| datetime | Échéance DUE |
| `status` | string | `NEEDS-ACTION` \| `IN-PROCESS` \| `COMPLETED` \| `CANCELLED` |
| `percent_complete` | integer 0–100 | Avancement |
| `priority` | integer 0–9 | Priorité |
| `recurrence_rule` | string | RRULE valide |

---

## Endpoints — Journaux (VJOURNAL)

> RFC 5545 §3.6.3 — Phase 5.2

| Méthode | Endpoint | Description |
| --- | --- | --- |
| POST | `/calendars/{id}/journals` | Créer une entrée |
| GET | `/calendars/{id}/journals` | Lister |
| GET | `/calendars/{id}/journals/{journalId}` | Détails |
| PUT \| PATCH | `/calendars/{id}/journals/{journalId}` | Modifier (les deux verbes sont équivalents : seuls les champs envoyés sont modifiés) |
| DELETE | `/calendars/{id}/journals/{journalId}` | Supprimer (soft) |

Statuts : `DRAFT` | `FINAL` | `CANCELLED`.

`related_to` (optionnel, string max 255) — UID du journal parent (RFC 5545 §3.8.4.5), accepté
sur `POST`/`PUT` ; envoyer `null` sur `PUT` retire le lien (directive
`20260713_161125_cmem_web_vers_cmem2_API`).

### Chiffrement de bout en bout

> Directive `20260803_165946_cmem_web_vers_cmem2_API__e2e-journaux-champs-chiffres`

Le chiffrement est fait **entièrement côté client** (WebCrypto : PBKDF2-SHA256 pour la
dérivation, AES-GCM 256 pour le contenu). Le serveur stocke et restitue des octets opaques :
il n'a besoin d'aucune primitive cryptographique. **Aucune clé, aucune passphrase, aucun code
de secours ne doit transiter vers l'API** — si l'API en reçoit un, c'est un bug client.

| Champ | Type | Défaut | Rôle |
| - | - | - | - |
| `enc_alg` | string, max 32 | `null` | Algorithme, ex. `AES-GCM-256`. `null` = journal en clair |
| `enc_iv` | string, max 32 | `null` | Vecteur d'initialisation base64 (12 octets → 16 caractères) |

Les deux champs sont acceptés sur `POST`, `PUT` et `PATCH`, et restitués par tous les `GET`
(détail, liste, corbeille). Sur `PUT`/`PATCH`, envoyer `null` explicitement remet le journal
en clair.

**Règle de non-transformation.** Quand `enc_alg` est renseigné, `summary` et `description`
contiennent du base64 opaque. Le serveur n'applique **aucune** transformation : ni
`strip_tags`, ni `htmlspecialchars`, ni purificateur HTML, ni normalisation d'espaces. Un seul
octet modifié rend le journal définitivement indéchiffrable — AES-GCM échoue à
l'authentification et il n'existe aucune récupération partielle.

**Longueurs.** Le base64 gonfle le contenu d'environ 4/3 : `summary` accepte jusqu'à 2 000
caractères (`VARCHAR(2000)`) et `description` jusqu'à 16 000 000 (`MEDIUMTEXT`). Encodage
`utf8mb4` — l'alphabet base64 (`A-Za-z0-9+/=`) traverse intact.

**Métadonnées en clair.** `dtstart`, `calendar_id`, `uid`, `status`, `categories`,
`related_to`, `url`, `created_at` et `updated_at` restent en clair et pleinement exploitables
par l'API : le client s'appuie dessus pour la note du jour, la revue hebdomadaire et la heatmap.

**Interdits côté serveur.** Aucun endpoint ne déchiffre, n'indexe ni ne résume un journal dont
`enc_alg` est renseigné (recherche, export, IA, notifications, courriels). Un contenu chiffré
qui devrait apparaître dans un gabarit de courriel ou une notification est remplacé par un
libellé générique — jamais le blob.

L'export `.ics` reste possible : l'échappement RFC 5545 et le pliage de lignes à 75 octets
appliqués par sabre/vobject sont réversibles et l'alphabet base64 ne contient aucun caractère
échappé, donc le blob se reconstitue à l'octet près après dépliage.

---

## Endpoints — Étiquettes (Tags)

> Directive `20260715_090000_cmem_web_vers_cmem2_API` — entité distincte de `/tags` (scopée
> utilisateur) : réservoir de noms d'étiquettes scopé par calendrier, partagé entre tous les
> membres, appliqué via `categories[]` sur events/todos/journals.

| Méthode | Endpoint | Description |
| --- | --- | --- |
| GET | `/calendars/{id}/tags` | Lister les étiquettes, triées par `name` |
| POST | `/calendars/{id}/tags` | Créer une étiquette |
| PUT | `/calendars/{id}/tags/{tagId}` | Renommer/recolorer — cascade sur `categories[]` |
| DELETE | `/calendars/{id}/tags/{tagId}` | Supprimer — cascade sur `categories[]` |

Autorisation identique aux autres sous-ressources du calendrier : lecture = tout membre avec
accès (`getUserPermissionForCalendar`), écriture (create/rename/delete) = tout membre en
écriture (`canUserWrite`), pas seulement le propriétaire.

Renommer ou supprimer un tag propage le changement (transaction serveur) dans le tableau
`categories[]` de tous les events/todos/journals du calendrier qui contiennent l'ancienne
valeur — le client n'a plus à boucler un `PUT` par enregistrement.

```http
POST /calendars/42/tags
{ "name": "urgent" }
→ 201 { "tag": { "id": 9, "calendar_id": 42, "name": "urgent", "color": null } }

POST /calendars/42/tags   { "name": "URGENT" }   (existe déjà, case-insensitive)
→ 409 TAG_ALREADY_EXISTS

PUT /calendars/42/tags/9   { "name": "priorité" }
→ 200 (tout event/todo/journal du calendrier 42 avec "urgent" dans categories → "priorité")
```

---

## Endpoints — Disponibilités (VFREEBUSY)

> RFC 5545 §3.6.4 — Phase 5.3

```http
GET /calendars/{id}/freebusy?start=2026-04-01&end=2026-04-30
```

Retourne les plages occupées (`TRANSP=OPAQUE`, ou sans `TRANSP`) sur la période demandée.
Récurrence expansée à la volée (RRULE `TZID`-aware, même moteur que
`/events/occurrences/expand`) : un événement récurrent OPAQUE produit **un créneau busy par
occurrence réelle** dans la plage — occurrence annulée absente, occurrence modifiée reflète
ses `modified_start_datetime`/`modified_end_datetime`. `end` date-seule normalisée en fin de
journée avant la requête (même correctif que `/occurrences`, directive `20260707_082006`,
étendu à ce chemin par la directive `20260708_200813`). `TRANSPARENT` toujours exclu.

```json
{
  "calendar_id": 12,
  "start": "2026-04-01 00:00:00",
  "end": "2026-04-30 23:59:59",
  "timezone": "America/Toronto",
  "busy": [
    { "start": "2026-04-07 09:00:00", "end": "2026-04-07 10:00:00", "summary": "Réunion" }
  ]
}
```

`summary` est masqué (`null`) si le calendrier est privé et l'appelant n'en est pas
propriétaire. Avec l'en-tête `Accept: text/calendar`, la réponse est un `VCALENDAR`/`VFREEBUSY`
(une propriété `FREEBUSY;FBTYPE=BUSY:` par occurrence).

---

## Endpoints — Notifications et RSVP

| Méthode | Endpoint | Description |
| --- | --- | --- |
| GET | `/notifications/email` | Notifications planifiées |
| POST | `/notifications/email/test` | Envoyer email de test |
| DELETE | `/notifications/email/{id}` | Annuler une notification |
| POST | `/notifications/send-email` | Envoyer rappel immédiat |
| POST | `/notifications/attendee-reply` | Réponse RSVP (iTIP) |

### POST /notifications/attendee-reply

```json
{
  "event_id": 45,
  "attendee_email": "bob@example.com",
  "partstat": "ACCEPTED"
}
```

Valeurs `partstat` : `ACCEPTED` | `DECLINED` | `TENTATIVE`.

---

## CalDAV

Configuration client (ex. macOS Calendrier, Thunderbird/Lightning) :

| Paramètre | Valeur |
| --- | --- |
| URL serveur | `https://api.example.com/caldav/` |
| URL calendrier | `https://api.example.com/caldav/calendars/{user_id}/{calendar_id}/` |
| Authentification | Basic (email + mot de passe) |
| Discovery | `OPTIONS /caldav/` |

| Méthode CalDAV | Endpoint | Description |
| --- | --- | --- |
| PROPFIND | `/caldav/` | Discovery ressources |
| GET | `/caldav/service-info` | Infos service |
| GET | `/caldav/mobile-config` | Config mobile |
| GET/PUT/DELETE | `/caldav/calendars/{uid}/{cid}/` | Opérations CalDAV |

---

## Import / Export ICS

### Export (authenticated)

```http
GET /calendars/{id}/ics
Authorization: Bearer <jwt>
```

### Export (public)

```http
GET /calendar/{share_token}.ics
```

### Import initial (nouveau calendrier)

```http
POST /calendars/import
Authorization: Bearer <jwt>
Content-Type: multipart/form-data

icsfile=@mon_calendrier.ics
```

### Import mise à jour (upsert)

```http
POST /calendars/{id}/ics/import
Authorization: Bearer <jwt>
Content-Type: multipart/form-data

icsfile=@mise_a_jour.ics
```

Règles de l'upsert :

- UID conforme UUID v4 → préservé
- UID absent ou non conforme → remplacé par nouveau UUID v4
- UID existant en base → mise à jour de l'événement
- UID absent en base → création

---

## Erreurs

| Code | Signification |
| --- | --- |
| 400 | Données manquantes ou format invalide (date, timezone, RRULE) |
| 401 | JWT absent ou invalide |
| 403 | Accès refusé (lecture seule, non membre) |
| 404 | Calendrier, événement ou ressource introuvable |
| 500 | Erreur serveur (parsage ICS, SMTP) |

---

## Migrations

| Fichier | Description |
| --- | --- |
| [migrations/](migrations/) | Migrations SQL du module ICS |
| [Proc_create_tables_ICS.sql](Proc_create_tables_ICS.sql) | Procédure de création des tables |
