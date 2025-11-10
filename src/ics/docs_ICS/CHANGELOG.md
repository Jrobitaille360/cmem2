# 📝 Changelog - Module ICS Calendar

## [1.1.0] - 2025-11-09

### ✨ Nouveautés

#### Support complet des événements récurrents (RRULE)

- **RecurrenceService** : Nouveau service pour gérer les récurrences iCalendar
  - `expandRecurrence()` : Expanse un événement en ses occurrences
  - `expandMultipleEvents()` : Expanse plusieurs événements
  - `getOccurrences()` : Récupère les occurrences d'un événement
  - `countOccurrences()` : Compte le nombre d'occurrences (ou détecte infini)
  - `hasOccurrencesInPeriod()` : Vérifie si un événement a des occurrences dans une période

- **Expansion automatique** : Les événements récurrents sont automatiquement expansés côté serveur
  - Modification de `CalendarEvent::getByCalendarId()` pour supporter l'expansion
  - Paramètre `$expandRecurrence` (true par défaut) pour contrôler l'expansion
  - Performance optimisée avec limitation des occurrences

- **Nouvel endpoint API** : `GET /calendars/{id}/events/{eventId}/occurrences`
  - Liste les occurrences d'un événement récurrent
  - Paramètres : `start_date`, `end_date`, `limit`
  - Retourne les métadonnées : `occurrence_id`, `recurrence_index`, `parent_event_id`

- **Support des règles RRULE** :
  - Fréquences : DAILY, WEEKLY, MONTHLY, YEARLY, HOURLY, MINUTELY, SECONDLY
  - Options : COUNT, UNTIL, INTERVAL, BYDAY, BYMONTHDAY, BYMONTH, etc.
  - Validation stricte selon RFC 5545
  - Support des récurrences infinies (sans COUNT ni UNTIL)

### 📚 Documentation

- **RECURRENCE.md** : Guide complet (300+ lignes)
  - Architecture du service
  - Exemples de règles RRULE
  - API Reference complète
  - Exemples d'utilisation frontend/backend
  - Limitations et améliorations futures

- **README.md** : Mise à jour avec section récurrences
  - Ajout de la documentation RECURRENCE.md dans l'index
  - Exemples d'utilisation API
  - Structure des fichiers mise à jour

- **🚀_COMMENCER_ICI.md** : Mise à jour des statistiques
  - Ajout de la référence au guide des récurrences
  - Mise à jour du nombre de fichiers et lignes de code

### 🧪 Tests

- **test_recurrence_events.php** : Script de test complet
  - Test des récurrences quotidiennes (DAILY)
  - Test des récurrences hebdomadaires (WEEKLY + BYDAY)
  - Test des récurrences mensuelles (MONTHLY + BYMONTHDAY)
  - Test des récurrences infinies
  - Test avec UNTIL
  - Test de l'expansion automatique
  - Validation avec affichage couleur

### 🔧 Dépendances

- **Ajout** : `simshaun/recurr` v5.0.3
  - Bibliothèque PHP pour parser et calculer les règles de récurrence RRULE
  - Compatibilité RFC 5545
  - Support complet des patterns complexes

### 🐛 Correctifs

- **Composer** : Désactivation de `optimize-autoloader` pour éviter les blocages
  - Modification de `composer.json` : `"optimize-autoloader": false`
  - Résolution du problème de blocage sur "Generating optimized autoload files"

### 🔄 Modifications

- **CalendarEvent::getByCalendarId()** : Ajout du paramètre `$expandRecurrence`
  - Expansion automatique des événements récurrents dans une période
  - Tri des occurrences par date de début
  - Conservation de l'API existante (rétrocompatible)

- **CalendarRouteHandler** : Ajout de la route pour les occurrences
  - Pattern : `/calendars/{id}/events/{eventId}/occurrences`
  - Méthode : GET
  - Authentification requise

### 📊 Statistiques

- **Fichiers créés** : 2 nouveaux fichiers
  - `src/ics/Services/RecurrenceService.php` (203 lignes)
  - `tests_new/test_recurrence_events.php` (227 lignes)
  - `src/ics/docs_ICS/RECURRENCE.md` (300+ lignes)

- **Fichiers modifiés** : 5 fichiers
  - `composer.json`
  - `src/ics/Models/CalendarEvent.php`
  - `src/ics/Controllers/CalendarController.php`
  - `src/ics/Routing/RouteHandlers/CalendarRouteHandler.php`
  - Documentation (README.md, 🚀_COMMENCER_ICI.md)

- **Total** : ~730 lignes de code + 300+ lignes de documentation

---

## [1.0.0] - 2025-10-22

### ✨ Version initiale

- Support complet du format iCalendar (RFC 5545)
- Protocole CalDAV (RFC 4791)
- Partage de calendriers (public et entre utilisateurs)
- Multi-timezone
- Gestion des participants
- Synchronisation bidirectionnelle
- Documentation complète (CALDAV_GUIDE.md, CALDAV_QUICKSTART.md)
- Scripts de migration SQL
- Tests de validation

---

**Format du changelog basé sur [Keep a Changelog](https://keepachangelog.com/)**  
**Versioning selon [Semantic Versioning](https://semver.org/)**
