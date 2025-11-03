# Correction de la Gestion des Fuseaux Horaires - Plugin ICS

## 📅 Date de correction

3 novembre 2025

## 🎯 Objectif

Corriger les incohérences dans la gestion des fuseaux horaires (timezone) du plugin ICS pour assurer une compatibilité complète avec la norme RFC 5545 (iCalendar) et les clients CalDAV.

## 🔴 Problèmes identifiés

### 1. Absence de bloc VTIMEZONE

- Les fichiers `.ics` générés n'incluaient aucun bloc VTIMEZONE
- Seule la propriété non-standard `X-WR-TIMEZONE` était utilisée
- **Impact** : Les clients CalDAV pouvaient mal interpréter les heures

### 2. Conversion UTC incorrecte

- Utilisation de `strtotime()` sans contexte de timezone explicite
- `gmdate()` convertissait en UTC mais la source n'était pas dans un timezone connu
- **Risque** : Décalage horaire selon la configuration du serveur PHP

### 3. Parsing de dates sans gestion du timezone

- Le suffixe `Z` (UTC) était supprimé mais jamais utilisé pour la conversion
- Pas de support des paramètres TZID dans les dates iCalendar

### 4. Pas de validation du timezone

- Aucune validation lors de la création/mise à jour de calendriers
- Aucune validation lors de la création/mise à jour d'événements

### 5. Configuration PHP du timezone non définie

- Le timezone utilisé dépendait de `php.ini`
- Risque d'incohérence entre serveurs

## ✅ Corrections apportées

### 1. Nouvelle classe `TimezoneHelper`

**Fichier** : `src/ics/Utils/TimezoneHelper.php`

Fonctionnalités :

- ✅ `isValidTimezone($timezone)` : Validation de timezone
- ✅ `generateVTimezone($timezone)` : Génération de bloc VTIMEZONE complet
- ✅ `toICalDateTimeUTC($datetime, $sourceTimezone)` : Conversion correcte vers UTC
- ✅ `toICalDateTimeWithTZ($datetime, $timezone)` : Format iCalendar avec timezone local
- ✅ `fromICalDateTime($icalDate, $targetTimezone)` : Parsing de dates iCalendar
- ✅ `escapeIcsText($text)` : Échappement de texte pour format ICS
- ✅ `unescapeIcsText($text)` : Désérialisation de texte ICS

### 2. Configuration du timezone PHP au démarrage

**Fichier** : `src/ics/CalendarPlugin.php`

```php
public function initialize(): void
{
    // Charger la configuration d'abord
    $this->loadConfig();
    
    // Définir le timezone par défaut pour PHP
    $defaultTimezone = $this->config['config']['default_timezone'] ?? 'America/Montreal';
    date_default_timezone_set($defaultTimezone);
    // ...
}
```

### 3. Génération ICS avec VTIMEZONE complet

**Fichier** : `src/ics/Models/Calendar.php`

Modifications :

- ✅ Import de `TimezoneHelper`
- ✅ Ajout du bloc VTIMEZONE dans `generateIcsContent()`
- ✅ Utilisation de `TimezoneHelper::toICalDateTimeUTC()` pour conversion correcte
- ✅ Utilisation de `TimezoneHelper::escapeIcsText()` pour échappement
- ✅ Passage du timezone du calendrier aux événements

**Avant** :

```php
$eventIcs .= "DTSTART:" . gmdate('Ymd\THis\Z', strtotime($event['start_datetime'])) . "\r\n";
```

**Après** :

```php
$eventIcs .= "DTSTART:" . TimezoneHelper::toICalDateTimeUTC($event['start_datetime'], $calendarTimezone) . "\r\n";
```

### 4. Correction CalDAVServer

**Fichier** : `src/ics/Services/CalDAVServer.php`

Modifications :

- ✅ Import de `TimezoneHelper`
- ✅ Récupération du timezone du calendrier pour chaque événement
- ✅ Ajout du bloc VTIMEZONE dans `generateSingleEventIcs()`
- ✅ Utilisation de `TimezoneHelper` pour conversion et parsing
- ✅ Délégation des méthodes `parseICalDate()`, `escapeIcsString()`, `unescapeIcsString()` vers `TimezoneHelper`

### 5. Validation du timezone

**Fichier** : `src/ics/Controllers/CalendarController.php`

Ajouts :

- ✅ Import de `TimezoneHelper`
- ✅ Validation dans `createCalendar()` :

  ```php
  if (isset($input['timezone']) && !TimezoneHelper::isValidTimezone($input['timezone'])) {
      Response::error('Le timezone fourni est invalide', ...);
  }
  ```

- ✅ Validation dans `updateCalendar()`
- ✅ Validation dans `createEvent()` (pour compatibilité future)
- ✅ Validation dans `updateEvent()` (pour compatibilité future)

## 📊 Impact des changements

### Compatibilité

- ✅ **Amélioration** : Les fichiers `.ics` générés sont maintenant conformes RFC 5545
- ✅ **Support CalDAV** : Meilleur support des clients CalDAV (Google Calendar, Apple Calendar, Outlook, Thunderbird)
- ✅ **Pas de breaking change** : Les API existantes continuent de fonctionner

### Performance

- ⚡ Légère amélioration : Utilisation de `DateTime` au lieu de `strtotime()`
- ⚡ Cache possible : Le bloc VTIMEZONE pourrait être mis en cache (amélioration future)

### Sécurité

- 🔒 **Validation** : Les timezones invalides sont rejetés
- 🔒 **Cohérence** : Timezone PHP défini explicitement au démarrage

## 🧪 Tests recommandés

### 1. Test de création de calendrier avec timezone

```php
POST /calendars
{
    "title": "Mon Calendrier",
    "timezone": "Europe/Paris"
}
```

### 2. Test de création de calendrier avec timezone invalide

```php
POST /calendars
{
    "title": "Mon Calendrier",
    "timezone": "Invalid/Timezone"
}
// Devrait retourner une erreur 400
```

### 3. Test de génération ICS

```php
GET /calendar/{token}.ics
// Vérifier la présence du bloc VTIMEZONE dans le fichier généré
```

### 4. Test CalDAV

- Synchroniser avec Apple Calendar
- Synchroniser avec Google Calendar
- Vérifier que les heures sont correctement affichées

## 📝 Notes techniques

### Timezones supportés

Tous les timezones PHP valides sont supportés. Liste des plus courants dans `TimezoneHelper::getCommonTimezones()`.

### Format de dates

- **All-day events** : `DTSTART;VALUE=DATE:20231225`
- **Events avec heure** : `DTSTART:20231225T120000Z` (UTC)

### Stockage en base de données

- Les dates sont stockées au format MySQL : `Y-m-d H:i:s`
- Le timezone du calendrier est stocké dans la colonne `calendars.timezone`
- Les événements héritent du timezone de leur calendrier parent

### Bloc VTIMEZONE généré

Le bloc VTIMEZONE est généré de manière simplifiée basé sur les transitions du timezone PHP. Pour une solution plus complète avec support DST (Daylight Saving Time), considérer l'utilisation d'une bibliothèque comme `sabre/vobject`.

## 🔄 Améliorations futures possibles

1. **Cache VTIMEZONE** : Mettre en cache les blocs VTIMEZONE pour éviter de les régénérer
2. **Support DST complet** : Intégrer `sabre/vobject` pour un support DST complet
3. **Timezone par événement** : Ajouter une colonne `timezone` dans `calendar_events` pour permettre des événements avec timezones différents
4. **Migration automatique** : Script de migration pour recalculer les dates existantes avec le bon timezone

## 📚 Références

- [RFC 5545 - iCalendar](https://datatracker.ietf.org/doc/html/rfc5545)
- [RFC 4791 - CalDAV](https://datatracker.ietf.org/doc/html/rfc4791)
- [PHP DateTimeZone](https://www.php.net/manual/en/class.datetimezone.php)
- [IANA Time Zone Database](https://www.iana.org/time-zones)

## ✍️ Auteur

Corrections effectuées le 3 novembre 2025
