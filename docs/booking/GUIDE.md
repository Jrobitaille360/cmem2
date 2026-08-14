# Guide — Réservation publique (module `booking`)

Version 1.0.0 · Base URL : `/booking`

> Référence complète : [API_BOOKING_ENDPOINTS.json](API_BOOKING_ENDPOINTS.json)

## Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Gating par plan](#gating-par-plan)
- [Endpoints — hôte (authentifié)](#endpoints--hôte-authentifié)
- [Endpoints — public (sans authentification)](#endpoints--public-sans-authentification)
- [Génération des zones](#génération-des-zones)
- [Cron](#cron)
- [Erreurs](#erreurs)
- [Migrations](#migrations)

---

## Vue d'ensemble

Un usager (hôte, plan payant) publie une page de réservation sans authentification : un invité
externe choisit un créneau, l'événement apparaît directement dans le calendrier de l'hôte. Un seul
type de créneau par hôte (une durée fixe, une page par usager). Réservation **auto-confirmée**
(aucun état « en attente »). **Annulation par lien à jeton**, sans compte invité.

Décision structurante : pas de calcul freebusy à la volée sur l'endpoint public. Le serveur
**matérialise des zones** (`booking_slots`) à l'avance ; une réservation est une écriture atomique
`UPDATE ... WHERE reserved = 0` — la course entre deux invités sur le même créneau se règle par la
contrainte SQL, jamais par une revérification applicative.

---

## Gating par plan

Module `booking` (`tenant_modules.module_key`) :

| Plan | Disponible |
| --- | --- |
| `free` | Non |
| `monthly` / `yearly` / `team` | Oui |
| `ami` | Oui |

`PUT /booking/page` avec `active: true` sur un plan non éligible → `403 MODULE_NOT_AVAILABLE`.
Une page déjà active dont le plan de l'hôte est rétrogradé après coup devient invisible côté
public (`404 BOOKING_UNAVAILABLE`, voir plus bas) sans qu'aucune donnée soit perdue.

---

## Endpoints — hôte (authentifié)

**Auth** : `Authorization: Bearer <jwt_token>`

### GET /booking/page

Configuration courante de la page de l'usager. `404 BOOKING_PAGE_NOT_FOUND` si jamais créée.

### PUT /booking/page

Upsert. Régénère les zones à chaque appel (voir [Génération des zones](#génération-des-zones)).

```json
{
  "app_id": "cmemweb",
  "calendar_id": 42,
  "slug": "jean-dupont",
  "duration_minutes": 30,
  "buffer_before_minutes": 0,
  "buffer_after_minutes": 0,
  "timezone": "America/Montreal",
  "horizon_days": 30,
  "availability_windows": [
    { "weekday": 1, "start": "09:00", "end": "17:00" },
    { "weekday": 2, "start": "09:00", "end": "17:00" }
  ],
  "event_title_template": "Rendez-vous : {guest_name}",
  "active": true
}
```

| Champ | Type | Requis | Notes |
| --- | --- | --- | --- |
| `app_id` | string | Non | Défaut serveur `puzzle` |
| `calendar_id` | int | Oui | Doit appartenir à l'usager (`Calendar::isOwner`) |
| `slug` | string | Oui | `[a-z0-9-]+`, unique par `app_id` (même désactivé — jamais réutilisable) |
| `duration_minutes` | int | Oui | > 0 |
| `buffer_before_minutes` / `buffer_after_minutes` | int | Non | Défaut 0 — élargit la fenêtre de conflit vérifiée contre le calendrier, n'espace pas les créneaux générés entre eux |
| `timezone` | string | Oui | Identifiant IANA (incluant les alias historiques, ex. `America/Montreal`) |
| `horizon_days` | int | Non | Défaut 30, plafond 90 |
| `availability_windows` | array | Oui | `weekday` 0–6 (0 = dimanche, convention `Date.getDay()` JS), `start`/`end` `HH:MM`, `start < end` |
| `event_title_template` | string | Non | Défaut `"Rendez-vous : {guest_name}"` — seul placeholder supporté |
| `active` | bool | Non | `true` exige `booking.available = true` sur le plan de l'hôte |

**Erreurs** : `403 MODULE_NOT_AVAILABLE`, `403 CALENDAR_NOT_OWNED`, `409 SLUG_TAKEN`,
`422` (validation).

### DELETE /booking/page

Désactive la page et supprime les zones **non réservées**. Les zones déjà réservées et leurs
événements liés restent intacts — un invité avec un rendez-vous confirmé ne le perd pas parce que
l'hôte désactive la page après coup.

---

## Endpoints — public (sans authentification)

Tous scopés par `app_id` (query, défaut `puzzle`) + `slug`.

### GET /booking/public/{slug}

Nom d'affichage de l'hôte, `duration_minutes`, `timezone`.

`404 BOOKING_UNAVAILABLE` uniforme si la page n'existe pas, si `active = false`, ou si le plan de
l'hôte n'inclut plus `booking` — **volontairement indiscernable** entre ces trois causes, pour ne
pas révéler l'état interne d'un hôte par énumération de slug.

### GET /booking/public/{slug}/slots?from=&to=

Zones `reserved = false` dans la plage demandée, en UTC (format `Y-m-d\TH:i:sZ`). Plage max
**60 jours** par appel (`422 RANGE_TOO_WIDE` au-delà). Rate-limit : **20 req/min par IP**.

```json
{
  "success": true,
  "data": {
    "duration_minutes": 30,
    "timezone": "America/Montreal",
    "slots": [
      { "id": 4821, "start_datetime": "2026-08-20T13:00:00Z", "end_datetime": "2026-08-20T13:30:00Z" }
    ]
  }
}
```

### POST /booking/public/{slug}/book

```json
{ "slot_id": 4821, "guest_name": "Alice", "guest_email": "alice@ex.com", "guest_timezone": "Europe/Paris" }
```

Écriture atomique sur la zone ciblée. `0` ligne affectée → `409 SLOT_TAKEN`. `slot_id` inexistant
ou n'appartenant pas à cette page → `422 SLOT_INVALID` (pas de fuite d'ID d'autres pages). Sur
succès : événement créé (`title` = gabarit substitué, `description` contient le courriel invité,
`status: confirmed`), courriel de confirmation envoyé avec le lien d'annulation. Rate-limit :
**5 req/min par IP**.

```json
{
  "success": true,
  "data": {
    "cancel_token": "b7e1...",
    "event_id": 91234,
    "start_datetime": "2026-08-20T13:00:00Z",
    "end_datetime": "2026-08-20T13:30:00Z"
  }
}
```

### POST /booking/public/cancel/{token}

Libère la zone (`reserved = false`, champs invité + `cancel_token` vidés), passe l'événement à
`status: cancelled`. Jeton inconnu ou déjà utilisé → `404 CANCEL_TOKEN_INVALID` — idempotent :
rejouer l'appel ne casse rien. Rate-limit : **5 req/min par IP**.

---

## Génération des zones

`Booking\Services\BookingSlotService::regenerate()` — appelée par `PUT /booking/page` et par le
cron quotidien :

1. Supprime les zones non réservées futures de la page.
2. Calcule les créneaux candidats depuis `availability_windows` × `duration_minutes` sur
   `horizon_days`, en heure locale de la page (pas de gap entre créneaux consécutifs).
3. Exclut tout candidat dont la fenêtre `[start - buffer_before, end + buffer_after]` chevauche un
   événement `OPAQUE` du calendrier de l'hôte (récurrence expansée via
   `ICS\Models\EventOccurrence::getExpandedOpaqueByCalendarId`).
4. Convertit les survivants en UTC et les insère dans `booking_slots`.

Les zones réservées ne sont **jamais** supprimées ni régénérées par ce processus.

---

## Cron

`src/cron/booking_regenerate.php` — quotidien, régénère chaque page active (roule l'horizon d'un
jour, resynchronise contre le calendrier courant de l'hôte). Un événement ajouté manuellement par
l'hôte après une génération précédente finit par bloquer la zone correspondante au prochain
passage (délai acceptable, pas temps réel). Voir [docs/cron.md](../cron.md).

---

## Erreurs

| Code | Signification |
| --- | --- |
| `BOOKING_PAGE_NOT_FOUND` | `GET /booking/page` — aucune page configurée pour cet usager |
| `MODULE_NOT_AVAILABLE` | `active: true` demandé, module `booking` non inclus dans le plan |
| `CALENDAR_NOT_OWNED` | `calendar_id` n'appartient pas à l'usager authentifié |
| `SLUG_TAKEN` | `slug` déjà utilisé par une autre page du même `app_id` |
| `BOOKING_UNAVAILABLE` | Page inexistante, inactive, ou plan de l'hôte rétrogradé (404 uniforme) |
| `RANGE_TOO_WIDE` | Plage `from`/`to` de `/slots` > 60 jours |
| `SLOT_TAKEN` | Zone déjà réservée par un autre invité (409) |
| `SLOT_INVALID` | `slot_id` inexistant ou n'appartenant pas à ce `slug` |
| `CANCEL_TOKEN_INVALID` | Jeton d'annulation inconnu ou déjà utilisé |
| `RATE_LIMITED` | Seuil IP dépassé sur une route publique (429) |

---

## Migrations

| Fichier | Description |
| --- | --- |
| `docs/v-2-16-0/20260813_booking_public.sql` | Création `booking_pages` / `booking_slots` |
