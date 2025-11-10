# Documentation : Gestion des Récurrences d'Événements

## Vue d'ensemble

Le système de gestion des récurrences permet de créer des événements répétitifs selon les règles iCalendar (RFC 5545). Les récurrences sont calculées **côté serveur** pour garantir la cohérence entre tous les clients.

## Architecture

### Composants

1. **RecurrenceService** (`src/ics/Services/RecurrenceService.php`)
   - Service principal pour calculer les occurrences
   - Utilise la bibliothèque `simshaun/recurr` v5.0

2. **CalendarEvent Model**
   - Stocke la règle RRULE dans le champ `recurrence_rule`
   - Méthode `getByCalendarId()` expanse automatiquement les récurrences

3. **CalendarController**
   - Endpoint `getEventOccurrences()` pour lister les occurrences

## Utilisation

### 1. Créer un événement récurrent

```http
POST /calendars/{calendarId}/events
Authorization: Bearer {token}
Content-Type: application/json

{
  "title": "Réunion quotidienne",
  "start_datetime": "2025-11-09 09:00:00",
  "end_datetime": "2025-11-09 09:30:00",
  "recurrence_rule": "FREQ=DAILY;COUNT=10",
  "description": "Stand-up meeting",
  "status": "confirmed"
}
```

### 2. Récupérer les événements avec expansion automatique

```http
GET /calendars/{calendarId}/events?start_date=2025-11-01&end_date=2025-11-30
Authorization: Bearer {token}
```

**Réponse** : Les événements récurrents sont automatiquement expansés en occurrences individuelles.

### 3. Obtenir les occurrences d'un événement spécifique

```http
GET /calendars/{calendarId}/events/{eventId}/occurrences?start_date=2025-11-01&end_date=2025-12-31&limit=100
Authorization: Bearer {token}
```

**Paramètres optionnels** :

- `start_date` : Date de début (défaut: aujourd'hui)
- `end_date` : Date de fin (défaut: +2 ans)
- `limit` : Nombre max d'occurrences (défaut: 100)

## Règles de Récurrence (RRULE)

### Format

```text
FREQ=<fréquence>;[options]
```

### Exemples de règles

#### Quotidien

```text
FREQ=DAILY;COUNT=10                    // 10 jours
FREQ=DAILY;UNTIL=20251231T235959Z      // Jusqu'au 31 déc 2025
FREQ=DAILY;INTERVAL=2                  // Tous les 2 jours
```

#### Hebdomadaire

```text
FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=12    // Lun, Mer, Ven (12 fois)
FREQ=WEEKLY;BYDAY=MO                    // Tous les lundis (infini)
FREQ=WEEKLY;INTERVAL=2;BYDAY=TU        // Tous les 2 mardis
```

#### Mensuel

```text
FREQ=MONTHLY;BYMONTHDAY=1               // Le 1er de chaque mois
FREQ=MONTHLY;BYDAY=1MO                  // Le 1er lundi de chaque mois
FREQ=MONTHLY;BYDAY=-1FR                 // Le dernier vendredi de chaque mois
FREQ=MONTHLY;BYMONTHDAY=15;COUNT=6      // Le 15 de chaque mois (6 fois)
```

#### Annuel

```text
FREQ=YEARLY;BYMONTH=12;BYMONTHDAY=25    // Chaque 25 décembre
FREQ=YEARLY;BYMONTH=1;BYDAY=1MO         // Le 1er lundi de janvier
```

### Options communes

| Option | Description | Exemple |
|--------|-------------|---------|
| `FREQ` | Fréquence (obligatoire) | `DAILY`, `WEEKLY`, `MONTHLY`, `YEARLY` |
| `COUNT` | Nombre d'occurrences | `COUNT=10` |
| `UNTIL` | Date de fin | `UNTIL=20251231T235959Z` |
| `INTERVAL` | Intervalle entre occurrences | `INTERVAL=2` (tous les 2) |
| `BYDAY` | Jours de la semaine | `MO`, `TU`, `WE`, `TH`, `FR`, `SA`, `SU` |
| `BYMONTHDAY` | Jour du mois | `BYMONTHDAY=1,15` |
| `BYMONTH` | Mois | `BYMONTH=1,6,12` |
| `WKST` | Jour de début de semaine | `WKST=MO` |

**Note** : `COUNT` et `UNTIL` sont mutuellement exclusifs.

## API du RecurrenceService

### expandRecurrence()

Expanse un événement en ses occurrences.

```php
use ICS\Services\RecurrenceService;

$occurrences = RecurrenceService::expandRecurrence(
    $event,           // array: L'événement avec recurrence_rule
    $startDate,       // string: Date début (Y-m-d H:i:s)
    $endDate,         // string: Date fin (Y-m-d H:i:s)
    $maxOccurrences   // int: Limite (défaut: 100)
);
```

### expandMultipleEvents()

Expanse plusieurs événements et les trie par date.

```php
$allOccurrences = RecurrenceService::expandMultipleEvents(
    $events,          // array: Tableau d'événements
    $startDate,       // string: Date début
    $endDate,         // string: Date fin
    $maxPerEvent      // int: Limite par événement (défaut: 100)
);
```

### getOccurrences()

Obtient les occurrences d'un événement récurrent.

```php
$occurrences = RecurrenceService::getOccurrences(
    $event,           // array: L'événement récurrent
    $startDate,       // string|null: Date début (optionnel)
    $endDate,         // string|null: Date fin (optionnel)
    $limit            // int: Nombre max (défaut: 100)
);
```

### countOccurrences()

Compte le nombre total d'occurrences (ou retourne 'infinite').

```php
$count = RecurrenceService::countOccurrences(
    $event,           // array: L'événement
    $maxToCheck       // int: Limite de vérification (défaut: 1000)
);
// Retourne: int|'infinite'
```

## Structure des Occurrences

Chaque occurrence retournée contient les champs suivants :

```php
[
    'id' => 123,                           // ID de l'événement parent
    'parent_event_id' => 123,              // ID de l'événement parent
    'occurrence_id' => '123_20251109T090000', // ID unique de l'occurrence
    'is_recurring' => true,                 // Indique une occurrence
    'recurrence_index' => 0,                // Index de l'occurrence (0, 1, 2...)
    'title' => 'Réunion quotidienne',
    'start_datetime' => '2025-11-09 09:00:00',
    'end_datetime' => '2025-11-09 09:30:00',
    'recurrence_rule' => 'FREQ=DAILY;COUNT=10',
    // ... autres champs de l'événement
]
```

## Comportements Importants

### 1. Expansion automatique

- `getByCalendarId($calendarId, $startDate, $endDate, true)` expanse automatiquement
- Paramètre `$expandRecurrence` = `true` par défaut

### 2. Occurrences infinies

- Les règles sans `COUNT` ni `UNTIL` génèrent des occurrences infinies
- Le service limite automatiquement à la période demandée
- Utilisez toujours une période raisonnable pour éviter la surcharge

### 3. Performance

- Les occurrences sont calculées à la volée (non stockées en base)
- Pour de meilleures performances, limitez la période de recherche
- Le service met en cache les calculs pendant la requête

### 4. Modification d'événements récurrents

- Actuellement, modifier l'événement parent modifie toutes les occurrences
- Pour modifier une occurrence spécifique, il faut créer une exception (à implémenter)

## Tests

Exécuter le script de test :

```bash
php tests_new/test_recurrence_events.php
```

Ce script teste :

- Création d'événements quotidiens, hebdomadaires, mensuels
- Expansion des occurrences
- Événements infinis
- Événements avec UNTIL

## Exemples d'Utilisation

### Frontend : Afficher un calendrier mensuel

```javascript
// Récupérer tous les événements du mois avec occurrences
const response = await fetch(
  `/calendars/${calendarId}/events?start_date=2025-11-01&end_date=2025-11-30`,
  {
    headers: { 'Authorization': `Bearer ${token}` }
  }
);

const { data } = await response.json();

// data contient toutes les occurrences déjà calculées
data.forEach(event => {
  if (event.is_recurring) {
    console.log(`Occurrence ${event.recurrence_index} de ${event.title}`);
  }
});
```

### Afficher les prochaines occurrences d'un événement

```javascript
const response = await fetch(
  `/calendars/${calendarId}/events/${eventId}/occurrences?limit=5`,
  {
    headers: { 'Authorization': `Bearer ${token}` }
  }
);

const { data } = await response.json();

console.log(`Prochaines occurrences: ${data.count}`);
data.occurrences.forEach((occ, i) => {
  console.log(`${i + 1}. ${occ.start_datetime}`);
});
```

## Limitations Actuelles

1. **Pas de support des exceptions** : Impossible de modifier/supprimer une occurrence spécifique
2. **Pas de EXDATE/RDATE** : Exclusions et dates additionnelles non supportées
3. **Pas de gestion de timezone avancée** : Les calculs utilisent le timezone du calendrier

## Améliorations Futures

- [ ] Support des exceptions d'occurrences (EXDATE)
- [ ] Modification d'une occurrence spécifique
- [ ] Suppression d'une occurrence spécifique
- [ ] Support de RDATE (dates additionnelles)
- [ ] Cache des occurrences calculées
- [ ] Interface UI pour créer des règles RRULE
- [ ] Gestion avancée des timezones

## Références

- [RFC 5545 - iCalendar](https://icalendar.org/iCalendar-RFC-5545/)
- [Bibliothèque Recurr](https://github.com/simshaun/recurr)
- [RRULE Generator](https://jakubroztocil.github.io/rrule/)
