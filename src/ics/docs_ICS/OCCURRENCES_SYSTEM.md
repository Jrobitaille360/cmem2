# Système d'Occurrences Pré-calculées pour Événements Récurrents

## Vue d'ensemble

Le système de gestion des récurrences a été amélioré avec une table d'occurrences pré-calculées (`event_occurrences`) pour optimiser considérablement les performances lors de la récupération des événements récurrents.

### Principe

Au lieu de calculer les occurrences à la volée à chaque requête, elles sont pré-calculées et stockées dans une table dédiée avec une **fenêtre glissante** :

- **-6 mois** avant la date actuelle
- **+1 an** après la date actuelle

Cette fenêtre est maintenue automatiquement via un script de maintenance.

## Architecture

### Tables de la base de données

#### `calendar_events`

Table principale des événements, incluant les événements récurrents avec leur règle RRULE.

#### `event_occurrences` (NOUVELLE)

Table des occurrences pré-calculées :

```sql
CREATE TABLE event_occurrences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    calendar_id INT NOT NULL,
    occurrence_date DATE NOT NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    recurrence_index INT NOT NULL DEFAULT 0,
    is_modified BOOLEAN DEFAULT FALSE,
    is_cancelled BOOLEAN DEFAULT FALSE,
    modified_title VARCHAR(255),
    modified_description TEXT,
    modified_location VARCHAR(255),
    modified_start_datetime DATETIME,
    modified_end_datetime DATETIME,
    ...
    INDEX idx_calendar_dates (calendar_id, start_datetime, end_datetime)
);
```

### Composants

#### 1. **EventOccurrence Model** (`src/ics/Models/EventOccurrence.php`)

Gère les opérations CRUD sur les occurrences :

- `createBatch()` : Insertion en batch pour performance
- `getByCalendarId()` : Récupération par calendrier (optimisé)
- `getByEventId()` : Récupération par événement
- `cleanupOutdated()` : Nettoyage des occurrences périmées
- `cancel()` : Annulation d'une occurrence spécifique
- `modify()` : Modification d'une occurrence spécifique

#### 2. **RecurrenceService** (`src/ics/Services/RecurrenceService.php`)

Service amélioré pour gérer les récurrences :

- `generateOccurrences()` : Génère et sauvegarde les occurrences pour un événement
- `regenerateAllOccurrences()` : Régénère toutes les occurrences
- `calculateOccurrences()` : Calcule les occurrences sans les sauvegarder
- `expandRecurrence()` : Méthode publique pour expansion à la volée (rétrocompatibilité)

#### 3. **OccurrenceMaintenanceService** (`src/ics/Services/OccurrenceMaintenanceService.php`)

Service de maintenance de la fenêtre glissante :

- `performMaintenance()` : Maintenance complète (nettoyage + régénération)
- `cleanupOnly()` : Nettoyage uniquement
- `needsMaintenance()` : Vérifie si maintenance nécessaire
- `getStatistics()` : Statistiques sur les occurrences

#### 4. **Script CLI de maintenance** (`src/ics/maintenance_occurrences.php`)

Script à exécuter via cron pour maintenir la fenêtre :

```bash
# Maintenance complète (quotidien recommandé)
php src/ics/maintenance_occurrences.php

# Options disponibles
php src/ics/maintenance_occurrences.php --stats          # Afficher statistiques
php src/ics/maintenance_occurrences.php --check          # Vérifier si maintenance nécessaire
php src/ics/maintenance_occurrences.php --cleanup-only   # Nettoyage seulement
```

## Fonctionnement

### Création/Modification d'un événement récurrent

Lorsqu'un événement récurrent est créé ou modifié :

```php
// Dans CalendarEvent::create()
$event = $eventModel->create();

// Les occurrences sont automatiquement générées
if (!empty($event['recurrence_rule'])) {
    RecurrenceService::generateOccurrences($event);
}
```

### Récupération des événements

Lors de la récupération des événements d'un calendrier :

```php
// Dans CalendarEvent::getByCalendarId()
$events = $eventModel->getByCalendarId($calendarId, $startDate, $endDate);

// Les occurrences sont récupérées directement depuis event_occurrences
// (pas de calcul RRULE coûteux)
$occurrences = EventOccurrence::getByCalendarId($calendarId, $startDate, $endDate);
```

### Modifications d'occurrences individuelles

Il est possible de modifier ou annuler une occurrence spécifique sans affecter l'événement parent :

```php
// Annuler une occurrence
$occurrence = new EventOccurrence();
$occurrence->id = $occurrenceId;
$occurrence->cancel();

// Modifier une occurrence
$occurrence->modify([
    'title' => 'Titre modifié pour cette occurrence',
    'start_datetime' => '2025-12-01 10:00:00',
    'location' => 'Nouveau lieu'
]);
```

## Avantages

### Performance

- ✅ **Récupération ultra-rapide** : Simple SELECT au lieu de calculs RRULE complexes
- ✅ **Index optimisés** : `idx_calendar_dates` pour filtrage rapide par calendrier et dates
- ✅ **Pas de calcul en temps réel** : Charge CPU réduite

### Fonctionnalités

- ✅ **Modification d'occurrences individuelles** : Possible sans affecter l'événement parent
- ✅ **Annulation d'occurrences** : Marquer des occurrences comme annulées
- ✅ **Événements multi-jours** : Gestion native des événements s'étalant sur plusieurs jours
- ✅ **Recherche par calendrier** : Récupération efficace de toutes les occurrences d'un calendrier

### Maintenabilité

- ✅ **Fenêtre glissante** : Évite l'explosion du volume de données
- ✅ **Maintenance automatisée** : Script cron pour nettoyage et régénération
- ✅ **Statistiques** : Monitoring du système

## Inconvénients et Considérations

### Espace de stockage

- ❌ **Plus d'espace disque** : Occurrences stockées individuellement
- 💡 **Mitigation** : Fenêtre limitée à 18 mois (-6 mois à +1 an)

### Synchronisation

- ❌ **Maintenance requise** : Script cron doit s'exécuter régulièrement
- 💡 **Mitigation** : Script automatisé et surveillance disponible

### Complexité initiale

- ❌ **Migration** : Les événements existants doivent être traités
- 💡 **Mitigation** : `regenerateAllOccurrences()` pour migration

## Configuration et Déploiement

### 1. Création de la table

Exécuter la procédure stockée mise à jour :

```sql
CALL ResetICSTables();
```

### 2. Migration des données existantes

Générer les occurrences pour tous les événements récurrents existants :

```php
use ICS\Services\RecurrenceService;

$stats = RecurrenceService::regenerateAllOccurrences();
// Retourne: ['total' => X, 'success' => Y, 'failed' => Z]
```

### 3. Configuration du cron

Ajouter au crontab pour exécution quotidienne (3h du matin recommandé) :

```cron
0 3 * * * cd /path/to/cmem2_API && php src/ics/maintenance_occurrences.php >> logs/occurrences_maintenance.log 2>&1
```

### 4. Monitoring

Vérifier régulièrement les statistiques :

```bash
php src/ics/maintenance_occurrences.php --stats
```

## API

### Endpoints existants (inchangés)

Les endpoints API existants fonctionnent de la même manière, mais sont maintenant plus performants :

```http
GET /calendars/{id}/events?start_date=2025-11-01&end_date=2025-12-31
```

Les occurrences sont automatiquement incluses dans la réponse, avec métadonnées :

```json
{
    "id": 123,
    "calendar_id": 1,
    "title": "Réunion hebdomadaire",
    "start_datetime": "2025-11-25 10:00:00",
    "end_datetime": "2025-11-25 11:00:00",
    "recurrence_rule": "FREQ=WEEKLY;BYDAY=MO",
    "is_recurring": true,
    "occurrence_id": 456,
    "occurrence_date": "2025-11-25",
    "recurrence_index": 3,
    "parent_event_id": 123,
    "is_occurrence_modified": false,
    "is_occurrence_cancelled": false
}
```

### Nouveaux endpoints possibles

Pour gérer les occurrences individuelles (à implémenter si nécessaire) :

```http
# Annuler une occurrence
POST /calendars/{calendarId}/events/{eventId}/occurrences/{occurrenceId}/cancel

# Modifier une occurrence
PATCH /calendars/{calendarId}/events/{eventId}/occurrences/{occurrenceId}
{
    "title": "Titre modifié",
    "start_datetime": "2025-11-25 11:00:00"
}
```

## Tests et Validation

### Test de performance

Comparer les performances avant/après :

```php
// Avant: calcul à la volée
$start = microtime(true);
$events = $eventModel->getByCalendarId(1, '2025-11-01', '2025-12-31', true);
$timeOld = microtime(true) - $start;

// Après: occurrences pré-calculées
$start = microtime(true);
$events = $eventModel->getByCalendarId(1, '2025-11-01', '2025-12-31', true);
$timeNew = microtime(true) - $start;

echo "Gain de performance: " . round(($timeOld - $timeNew) / $timeOld * 100, 2) . "%\n";
```

### Test de la fenêtre glissante

```php
// Vérifier les limites
$stats = OccurrenceMaintenanceService::getStatistics();
assert($stats['out_of_window'] === 0, "Des occurrences sont hors fenêtre");
```

## Dépannage

### Problème: Occurrences manquantes

```bash
# Régénérer toutes les occurrences
php src/ics/maintenance_occurrences.php
```

### Problème: Trop d'occurrences stockées

```bash
# Vérifier les statistiques
php src/ics/maintenance_occurrences.php --stats

# Nettoyer si nécessaire
php src/ics/maintenance_occurrences.php --cleanup-only
```

### Problème: Performance dégradée

1 Vérifier les index :

```sql
SHOW INDEX FROM event_occurrences;
```

2 Vérifier le volume :

```sql
SELECT COUNT(*) FROM event_occurrences;
SELECT COUNT(*) FROM event_occurrences 
WHERE occurrence_date < DATE_SUB(NOW(), INTERVAL 6 MONTH)
   OR occurrence_date > DATE_ADD(NOW(), INTERVAL 1 YEAR);
```

## Migration depuis l'ancien système

1. Sauvegarder les données existantes
2. Exécuter `CALL ResetICSTables();`
3. Restaurer les calendriers et événements
4. Exécuter `RecurrenceService::regenerateAllOccurrences()`
5. Configurer le cron
6. Tester

## Références

- [RFC 5545 - iCalendar](https://icalendar.org/iCalendar-RFC-5545/)
- [Documentation RRULE](./RECURRENCE.md)
- [Changelog](./CHANGELOG.md)
