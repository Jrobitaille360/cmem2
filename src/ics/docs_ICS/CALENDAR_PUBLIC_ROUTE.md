# CalendarPublicRoute - Documentation

## Vue d'ensemble

`CalendarPublicRoute` est un gestionnaire de routes modulaire spécifiquement conçu pour le plugin ICS Calendar. Il gère les endpoints publics qui ne nécessitent pas d'authentification.

## Architecture Modulaire

- **Namespace**: `AuthGroups\Routing`
- **Extends**: `BaseRouteHandler`
- **Authentication**: Désactivée (`requiresAuth = false`)
- **Contrôleurs supportés**: `['calendar']`

## Endpoints Publics Supportés

### GET /calendar/{token}.ics

- **Description**: Télécharge un fichier ICS public via un token de partage
- **Méthode**: GET
- **Authentification**: Non requise
- **Paramètres**:
  - `token`: Token de partage public du calendrier
- **Réponse**: Fichier ICS en téléchargement direct
- **Headers**:
  - `Content-Type: text/calendar; charset=utf-8`
  - `Content-Disposition: attachment; filename="calendar.ics"`

## Utilisation

### Intégration dans le système de routage

Le `CalendarPublicRoute` implémente l'interface `BaseRouteHandler` et peut être intégré dans le système de routage existant :

```php
// Exemple d'intégration
$calendarPublicRoute = new CalendarPublicRoute();

// Vérifier si le gestionnaire peut traiter une route
if ($calendarPublicRoute->canHandle('calendar')) {
    $result = $calendarPublicRoute->handle($request);
}
```

### Format de requête

```php
$request = [
    'method' => 'GET',
    'path' => '/calendar/abc123xyz.ics',
    'controller' => 'calendar',
    'action' => 'abc123xyz.ics'
];
```

## Gestion des erreurs

- **Token invalide**: Géré par `CalendarController->getPublicCalendarIcs()`
- **Erreurs de serveur**: HTTP 500 avec message JSON
- **Méthodes non supportées**: HTTP 405

## Avantages de la modularité

1. **Séparation des responsabilités**: Les routes publiques sont isolées
2. **Maintenance facilitée**: Code spécifique au plugin calendrier
3. **Réutilisabilité**: Peut être activé/désactivé indépendamment
4. **Sécurité**: Pas d'impact sur les routes authentifiées

## Compatibilité

- Compatible avec le système `BaseRouteHandler` existant
- Utilise `ICS\Controllers\CalendarController` pour la logique métier
- N'interfère pas avec les autres gestionnaires de routes

## Configuration recommandée

Le `CalendarPublicRoute` devrait être enregistré avec une priorité élevée pour les routes commençant par `/calendar/` afin d'optimiser les performances de routage.
