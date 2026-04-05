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
POST /calendars/12/share               → { token: "abc123..." }
# Lien public :
GET  /calendar/abc123....ics           → fichier .ics (sans auth)
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
| POST | `/calendars/{id}/share` | Générer token public |
| GET | `/calendars/{id}/share` | Lister les partages |
| DELETE | `/calendars/{id}/share` | Supprimer le partage |
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
| DELETE | `/calendars/{id}/events/{eventId}` | Supprimer |

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
| GET | `/calendars/{id}/events/{eventId}/occurrences` | Lister les occurrences |
| PUT | `/calendars/{id}/events/{eventId}/occurrences` | Modifier une occurrence |
| DELETE | `/calendars/{id}/events/{eventId}/occurrences` | Supprimer/annuler une occurrence |

### Exemples de RRULE

| Cas | RRULE |
| --- | --- |
| Chaque semaine le mardi | `FREQ=WEEKLY;BYDAY=TU` |
| Tous les jours ouvrables pendant 2 semaines | `FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR;COUNT=10` |
| Le 1er lundi du mois | `FREQ=MONTHLY;BYDAY=1MO` |
| Chaque année le 25 décembre | `FREQ=YEARLY;BYMONTH=12;BYMONTHDAY=25` |

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
| PUT | `/calendars/{id}/journals/{journalId}` | Modifier |
| DELETE | `/calendars/{id}/journals/{journalId}` | Supprimer (soft) |

Statuts : `DRAFT` | `FINAL` | `CANCELLED`.

---

## Endpoints — Disponibilités (VFREEBUSY)

> RFC 5545 §3.6.4 — Phase 5.3

```http
GET /calendars/{id}/freebusy?start=2026-04-01&end=2026-04-30
```

Retourne les plages occupées (`TRANSP=OPAQUE`) sur la période demandée :

```json
{
  "freebusy": [
    { "dtstart": "2026-04-07T09:00:00", "dtend": "2026-04-07T10:00:00" }
  ],
  "calendar_id": 12,
  "period": { "start": "2026-04-01", "end": "2026-04-30" }
}
```

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
