# 📅 ICS Calendar API - Points d'entrée

## Vue d'ensemble

**API Version**: 1.0.0  
**Base URL**: `/calendars`  
**Généré le**: 2025-11-23  

**Description**: Module de gestion des calendriers ICS/iCalendar avec support CalDAV RFC 5545 et synchronisation bidirectionnelle.

## 🔐 Authentification

- **Types supportés**: JWT, API_KEY
- **Header JWT**: `Authorization: Bearer {token}`
- **Header API Key**: `X-API-Key: {key}`
- **Note**: Authentification requise pour la plupart des endpoints, sauf téléchargement ICS public

---

## 🌐 Endpoints Publics

Endpoints accessibles sans authentification.

### GET /calendar/{token}.ics

**Description**: Télécharger un fichier ICS public via token de partage

**Paramètres**:

- `token` (string, required): Token de partage du calendrier

**Réponse**: Fichier ICS au format iCalendar

### OPTIONS /caldav/

**Description**: Discovery CalDAV (OPTIONS request)

**Réponse**: Headers CalDAV supportés

---

## 🔒 Endpoints Authentifiés

Endpoints nécessitant une authentification.

### POST /calendars

**Description**: Créer un nouveau calendrier

**Corps de la requête**:

```json
{
  "title": "string (required) - Titre du calendrier",
  "description": "string (optional) - Description",
  "visibility": "string (optional, default: private) - 'public' ou 'private'",
  "max_members": "integer (optional, default: 1000) - Nombre maximum de membres",
  "color": "string (optional) - Couleur en nom ou hex (#RRGGBB)",
  "timezone": "string (optional, default: America/Montreal) - Timezone valide"
}
```

**Réponse**: Objet calendrier créé avec ID

### GET /calendars

**Description**: Récupérer tous les calendriers de l'utilisateur

**Réponse**: Liste des calendriers avec métadonnées

### PUT /calendars/{id}

**Description**: Mettre à jour un calendrier

**Paramètres**:

- `id` (integer, required): ID du calendrier

**Corps de la requête**:

```json
{
  "title": "string (optional) - Nouveau titre",
  "description": "string (optional) - Nouvelle description",
  "visibility": "string (optional) - Nouvelle visibilité",
  "max_members": "integer (optional) - Nouveau max membres",
  "color": "string (optional) - Nouvelle couleur",
  "timezone": "string (optional) - Nouveau timezone"
}
```

**Réponse**: Calendrier mis à jour

### DELETE /calendars/{id}

**Description**: Supprimer un calendrier (soft delete)

**Paramètres**:

- `id` (integer, required): ID du calendrier

**Réponse**: Confirmation de suppression

### DELETE /calendars/{id}/hard

**Description**: Supprimer définitivement un calendrier

**Paramètres**:

- `id` (integer, required): ID du calendrier

**Réponse**: Confirmation de suppression définitive

### GET /calendars/{id}/ics

**Description**: Télécharger le fichier ICS d'un calendrier (authentifié)

**Paramètres**:

- `id` (integer, required): ID du calendrier

**Réponse**: Fichier ICS au format iCalendar

### POST /calendars/{id}/events

**Description**: Créer un événement dans un calendrier

**Paramètres**:

- `id` (integer, required): ID du calendrier

**Corps de la requête**:

```json
{
  "title": "string (required) - Titre de l'événement",
  "description": "string (optional) - Description",
  "start_date": "string (required) - Date/heure de début (ISO 8601)",
  "end_date": "string (required) - Date/heure de fin (ISO 8601)",
  "location": "string (optional) - Lieu",
  "recurrence": "object (optional) - Règles de récurrence",
  "reminders": "array (optional) - Rappels",
  "attendees": "array (optional) - Participants"
}
```

**Réponse**: Événement créé avec ID

### GET /calendars/{id}/events

**Description**: Lister les événements d'un calendrier

**Paramètres**:

- `id` (integer, required): ID du calendrier

**Réponse**: Liste des événements avec détails

### PUT /calendars/{id}/events/{eventId}

**Description**: Mettre à jour un événement

**Paramètres**:

- `id` (integer, required): ID du calendrier
- `eventId` (integer, required): ID de l'événement

**Corps de la requête**: Objet événement avec champs à mettre à jour

**Réponse**: Événement mis à jour

### DELETE /calendars/{id}/events/{eventId}

**Description**: Supprimer un événement (soft delete)

**Paramètres**:

- `id` (integer, required): ID du calendrier
- `eventId` (integer, required): ID de l'événement

**Réponse**: Confirmation de suppression

### DELETE /calendars/{id}/events/{eventId}/hard

**Description**: Supprimer définitivement un événement

**Paramètres**:

- `id` (integer, required): ID du calendrier
- `eventId` (integer, required): ID de l'événement

**Réponse**: Confirmation de suppression définitive

### GET /calendars/{id}/events/{eventId}/occurrences

**Description**: Obtenir les occurrences d'un événement récurrent

**Paramètres**:

- `id` (integer, required): ID du calendrier
- `eventId` (integer, required): ID de l'événement récurrent

**Réponse**: Liste des occurrences avec dates

### POST /calendars/{id}/share

**Description**: Partager un calendrier avec un token public

**Paramètres**:

- `id` (integer, required): ID du calendrier

**Corps de la requête**:

```json
{
  "email": "string (optional) - Email du destinataire",
  "message": "string (optional) - Message personnalisé"
}
```

**Réponse**: Token de partage généré

### DELETE /calendars/{id}/share

**Description**: Supprimer le partage d'un calendrier

**Paramètres**:

- `id` (integer, required): ID du calendrier

**Réponse**: Confirmation de suppression du partage

### POST /calendars/import

**Description**: Importer un fichier ICS

**Corps de la requête**:

```json
{
  "ics_file": "file (required) - Fichier ICS à importer",
  "calendar_title": "string (optional) - Titre du nouveau calendrier"
}
```

**Réponse**: Calendrier créé avec événements importés

---

## 🔄 Endpoints CalDAV

Endpoints CalDAV pour synchronisation calendrier.

### PROPFIND /caldav/

**Description**: Discovery des ressources CalDAV

**Réponse**: XML CalDAV avec ressources disponibles

### GET /caldav/service-info

**Description**: Informations sur le service CalDAV

**Réponse**: Informations JSON sur le service

### GET /caldav/mobile-config

**Description**: Configuration mobile pour CalDAV

**Réponse**: Fichier de configuration mobile

### GET/PUT/DELETE /caldav/calendars/{user_id}/{calendar_id}/

**Description**: Opérations CalDAV sur les calendriers

**Paramètres**:

- `user_id` (integer, required): ID utilisateur
- `calendar_id` (integer, required): ID calendrier

**Réponse**: Réponse CalDAV selon la méthode

---

*Documentation générée automatiquement à partir de `API_ICS_ENDPOINTS_v1_0_0.json`*
