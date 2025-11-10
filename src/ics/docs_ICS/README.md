# 📅 Module ICS Calendar - Documentation

## Vue d'ensemble

Le module **ICS Calendar** est un système complet de gestion de calendriers pour CMEM2 API. Il supporte :

- ✅ **Format iCalendar (RFC 5545)** - Compatible avec tous les clients calendrier
- ✅ **Protocole CalDAV (RFC 4791)** - Synchronisation bidirectionnelle automatique
- ✅ **Partage de calendriers** - Public (via token) et entre utilisateurs
- ✅ **Événements récurrents (RRULE)** - Support complet des récurrences quotidiennes, hebdomadaires, mensuelles, annuelles
- ✅ **Multi-timezone** - Gestion des fuseaux horaires
- ✅ **Participants** - Gestion des participants et invitations
- ✅ **Expansion automatique** - Les occurrences sont calculées côté serveur

## 🚀 Démarrage rapide

### Installation (5 minutes)

1. **Exécuter la migration SQL** :

   ```sql
   SOURCE src/ics/docs_ICS/Proc_add_caldav_support.sql;
   ```

2. **Vérifier l'installation** :

   ```bash
   php src/ics/docs_ICS/test_caldav.php
   ```

3. **Configurer un client CalDAV** (optionnel) :
   - Voir [`CALDAV_QUICKSTART.md`](./CALDAV_QUICKSTART.md) pour les instructions détaillées

## 📖 Documentation

### Pour démarrer

- **[`CALDAV_QUICKSTART.md`](./CALDAV_QUICKSTART.md)** - Installation et configuration rapide (5 min)
  - Migration SQL
  - Test de validation
  - Configuration clients (Apple Calendar, Thunderbird, Android)
  - Obtenir un token JWT

### Documentation technique complète

- **[`CALDAV_GUIDE.md`](./CALDAV_GUIDE.md)** - Guide technique détaillé (400+ lignes)
  - Architecture du serveur CalDAV
  - Méthodes HTTP supportées (PROPFIND, REPORT, GET, PUT, DELETE, etc.)
  - Structure de la base de données
  - Synchronisation et gestion des conflits (ETags, CTags, sync-token)
  - Exemples d'utilisation API avec XML
  - Dépannage et optimisations
  - Références RFC

- **[`RECURRENCE.md`](./RECURRENCE.md)** - Guide complet des récurrences (300+ lignes)
  - Architecture du service de récurrence
  - Règles RRULE (DAILY, WEEKLY, MONTHLY, YEARLY)
  - API RecurrenceService (expandRecurrence, getOccurrences, etc.)
  - Exemples de règles de récurrence (COUNT, UNTIL, INTERVAL, BYDAY, etc.)
  - Endpoints pour gérer les occurrences
  - Tests et validation

### Fichiers de migration

- **[`Proc_add_caldav_support.sql`](./Proc_add_caldav_support.sql)** - Procédure SQL d'installation
  - Ajoute les colonnes CalDAV aux tables existantes
  - Crée les tables `caldav_sync_log` et `caldav_locks`
  - Installe les triggers automatiques pour ETags/CTags
  - Initialise les données pour les calendriers existants

### Fichiers de test

- **[`test_caldav.php`](./test_caldav.php)** - Script de validation
  - Vérifie la présence des colonnes CalDAV
  - Teste les tables de synchronisation
  - Valide les triggers automatiques
  - Confirme que le serveur répond correctement

## 🏗️ Architecture

### Structure des fichiers

```text
src/ics/
├── CalendarPlugin.php              # Point d'entrée du plugin
├── plugin.json                     # Configuration du module
├── Controllers/
│   ├── CalendarController.php      # API REST calendriers
│   ├── CalDAVController.php        # API CalDAV
├── Models/
│   ├── Calendar.php                # Modèle calendrier
│   └── CalendarEvent.php           # Modèle événement
├── Services/
│   ├── CalDAVServer.php            # Serveur CalDAV complet
│   └── RecurrenceService.php       # Service de gestion des récurrences (RRULE)
├── Routing/
│   └── RouteHandlers/
│       ├── CalendarRouteHandler.php    # Routes REST
│       ├── CalendarPublicRoute.php     # Routes publiques ICS
│       └── CalDAVRouteHandler.php      # Routes CalDAV
└── docs_ICS/                       # Documentation et migrations
    ├── README.md                   # Ce fichier
    ├── CALDAV_GUIDE.md             # Guide technique
    ├── CALDAV_QUICKSTART.md        # Démarrage rapide
    ├── RECURRENCE.md               # Guide des récurrences
    ├── Proc_add_caldav_support.sql # Migration SQL
    ├── Proc_create_tables_ICS.sql  # Création tables initiales
    └── test_caldav.php             # Script de test
```

### Base de données

- **[`Proc_create_tables_ICS.sql`](./Proc_create_tables_ICS.sql)** - Script de création des tables initiales.
- **[`Proc_add_caldav_support.sql`](./Proc_add_caldav_support.sql)** - Script de migration pour ajouter le support CalDAV.

## 📚 API REST

Voici un aperçu des principaux endpoints de l'API REST pour la gestion des calendriers.

### Importer un calendrier depuis un fichier ICS

Crée un nouveau calendrier et importe tous ses événements depuis un fichier `.ics`.

- **Endpoint**: `POST /calendars/import`
- **Authentification**: Requise (API Key ou JWT)
- **Type de contenu**: `multipart/form-data`

#### Paramètres (form-data)

| Paramètre | Type   | Obligatoire | Description                   |
|-----------|--------|-------------|-------------------------------|
| `icsfile` | `file` | **Oui**     | Le fichier `.ics` à importer. |

#### Exemple de requête avec `curl`

```bash
curl -X POST "https://your-api-url/calendars/import" \
     -H "Authorization: Bearer VOTRE_TOKEN_JWT" \
     -F "icsfile=@/chemin/vers/mon_calendrier.ics"
```

#### Réponse en cas de succès (201 Created)

```json
{
    "status": "success",
    "message": "Calendrier importé avec succès.",
    "data": {
        "id": 124,
        "share_token": "a1b2c3d4...",
        "ics_url": "https://your-api-url/calendar/a1b2c3d4....ics",
        "ctag": "e5f6g7h8...",
        "sync_token": "i9j0k1l2...",
        "imported_events_count": 15
    }
}
```

### Calendriers

- **`GET /calendars`**: Lister tous les calendriers de l'utilisateur.
- **`POST /calendars`**: Créer un nouveau calendrier
- **`GET /calendars/{id}`**: Obtenir les détails d'un calendrier
- **`PUT /calendars/{id}`**: Mettre à jour un calendrier
- **`DELETE /calendars/{id}`**: Supprimer un calendrier

### Événements

- **`GET /calendars/{id}/events`**: Lister les événements d'un calendrier
- **`POST /calendars/{id}/events`**: Créer un nouvel événement
- **`GET /calendars/{id}/events/{event_id}`**: Obtenir les détails d'un événement
- **`PUT /calendars/{id}/events/{event_id}`**: Mettre à jour un événement
- **`DELETE /calendars/{id}/events/{event_id}`**: Supprimer un événement

### Partage de calendriers

- **`POST /calendars/{id}/share`**: Partager un calendrier

### Exporter un calendrier au format ICS

- **`GET /calendar/{token}.ics`**: Télécharger le calendrier au format ICS

### Synchronisation CalDAV

- **`OPTIONS /caldav/`**: Vérifier les capacités du serveur
- **`PROPFIND /caldav/calendars/{user_id}/`**: Découverte des calendriers
- **`PROPFIND /caldav/calendars/{user_id}/{cal_id}/`**: Liste des événements d'un calendrier
- **`REPORT /caldav/calendars/{user_id}/{cal_id}/`**: Requêtes avancées sur les événements
- **`GET /caldav/calendars/{user_id}/{cal_id}/{event_uid}.ics`**: Récupérer un événement au format ICS
- **`PUT /caldav/calendars/{user_id}/{cal_id}/{event_uid}.ics`**: Créer ou modifier un événement
- **`DELETE /caldav/calendars/{user_id}/{cal_id}/{event_uid}.ics`**: Supprimer un événement

## 🔐 Authentification

### API REST et CalDAV

```bash
Authorization: Bearer <JWT_TOKEN>
```

Obtenir un token JWT :

```bash
curl -X POST http://localhost/cmem2_API/users/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"motdepasse"}'
```

### Export public ICS

Aucune authentification requise. Le token de partage est dans l'URL :

```text
http://votre-domaine.com/cmem2_API/calendar/abc123def456.ics
```

## 📱 Clients compatibles

Le serveur CalDAV est compatible avec tous les clients standards :

- ✅ **Apple Calendar** (iOS, macOS)
- ✅ **Google Calendar** (import ICS)
- ✅ **Microsoft Outlook** (import ICS)
- ✅ **Mozilla Thunderbird** (CalDAV)
- ✅ **DAVx⁵** (Android - CalDAV)
- ✅ **Evolution** (Linux - CalDAV)
- ✅ Tous les clients supportant CalDAV ou iCalendar

## 🧪 Tests et validation

### Tester l'installation

```bash
# Script de validation complet
php src/ics/docs_ICS/test_caldav.php

# Test basique CalDAV
curl -X OPTIONS http://localhost/cmem2_API/caldav/

# Devrait retourner:
# DAV: 1, 2, calendar-access
# Allow: OPTIONS, GET, PUT, DELETE, PROPFIND, REPORT, ...
```

### Vérifier la base de données

```sql
-- Vérifier les colonnes CalDAV
SHOW COLUMNS FROM calendars WHERE Field IN ('ctag', 'sync_token');
SHOW COLUMNS FROM calendar_events WHERE Field IN ('etag', 'uid', 'sequence');

-- Vérifier les tables de sync
SELECT COUNT(*) FROM caldav_sync_log;
SELECT COUNT(*) FROM caldav_locks;
```

## 🔧 Configuration

La configuration du module se trouve dans [`plugin.json`](../plugin.json) :

```json
{
  "config": {
    "max_calendars_per_user": 100,
    "max_events_per_calendar": 10000,
    "default_timezone": "America/Montreal",
    "enable_public_sharing": true,
    "enable_user_sharing": true,
    "enable_caldav": true,
    "caldav_url_base": "/caldav/"
  }
}
```

## 📝 Fonctionnalités avancées

### Synchronisation bidirectionnelle

Les modifications sont automatiquement synchronisées dans les deux sens :

1. **Client → Serveur** : Créer/modifier/supprimer dans Apple Calendar → mise à jour immédiate dans la base de données
2. **Serveur → Client** : Créer/modifier/supprimer via l'API REST → synchronisation automatique vers tous les clients CalDAV

### Gestion des conflits

- **ETags** - Détection des modifications concurrentes
- **CTags** - Détection des changements dans un calendrier
- **Sync-token** - Synchronisation incrémentale (seules les modifications)
- **Verrous WebDAV** - Prévention des écritures simultanées

### Événements récurrents

Support complet des règles de récurrence iCalendar :

- Récurrence quotidienne, hebdomadaire, mensuelle, annuelle
- Exceptions (dates exclues)
- Modifications d'instances spécifiques
- Fin de récurrence (date ou nombre d'occurrences)

## 🆘 Dépannage

### Problème : 401 Unauthorized

**Cause** : Token JWT manquant ou invalide

**Solution** :

```bash
# Générer un nouveau token
curl -X POST http://localhost/cmem2_API/users/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'
```

### Problème : Client CalDAV ne se connecte pas

**Vérifications** :

1. Le serveur CalDAV répond-il ? `curl -X OPTIONS http://localhost/cmem2_API/caldav/`
2. La migration SQL a-t-elle été exécutée ? `php test_caldav.php`
3. L'URL CalDAV est-elle correcte ? Doit inclure `/caldav/`
4. Le token JWT est-il valide et non expiré ?

### Problème : Les événements ne se synchronisent pas

**Vérifications** :

1. Les triggers sont-ils installés ?

   ```sql
   SHOW TRIGGERS LIKE 'calendar_events';  
   ```

2. Les ETags sont-ils générés ?

   ```sql
   SELECT etag, uid FROM calendar_events LIMIT 5;
   ```

3. Le journal de sync est-il fonctionnel ?

   ```sql
   SELECT * FROM caldav_sync_log ORDER BY change_time DESC LIMIT 10;
   ```

Pour plus de dépannage, voir [`CALDAV_GUIDE.md`](./CALDAV_GUIDE.md#dépannage).

## � Événements récurrents

Le module supporte les règles de récurrence iCalendar (RRULE) :

```bash
# Créer un événement récurrent quotidien
curl -X POST http://localhost/cmem2_API/calendars/1/events \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Réunion quotidienne",
    "start_datetime": "2025-11-09 09:00:00",
    "end_datetime": "2025-11-09 09:30:00",
    "recurrence_rule": "FREQ=DAILY;COUNT=10"
  }'

# Obtenir les occurrences d'un événement récurrent
curl -X GET "http://localhost/cmem2_API/calendars/1/events/123/occurrences?limit=30" \
  -H "Authorization: Bearer {token}"

# Les événements avec récurrence sont automatiquement expansés lors de la récupération
curl -X GET "http://localhost/cmem2_API/calendars/1/events?start_date=2025-11-01&end_date=2025-11-30" \
  -H "Authorization: Bearer {token}"
```

Exemples de règles RRULE :

- `FREQ=DAILY;COUNT=10` - Quotidien pendant 10 jours
- `FREQ=WEEKLY;BYDAY=MO,WE,FR` - Lun, Mer, Ven (infini)
- `FREQ=MONTHLY;BYMONTHDAY=1` - Le 1er de chaque mois
- `FREQ=YEARLY;BYMONTH=12;BYMONTHDAY=25` - Chaque 25 décembre

Voir [`RECURRENCE.md`](./RECURRENCE.md) pour la documentation complète.

## �📚 Références

- **RFC 5545** - iCalendar format
- **RFC 4791** - CalDAV protocol
- **RFC 4918** - WebDAV protocol
- **RFC 6638** - CalDAV scheduling extensions

## 🤝 Support

Pour toute question ou problème :

1. Consulter [`CALDAV_GUIDE.md`](./CALDAV_GUIDE.md) pour la documentation complète
2. Exécuter `test_caldav.php` pour diagnostiquer les problèmes
3. Vérifier les logs du serveur web et de la base de données

---

**Version** : 1.1.0  
**Dernière mise à jour** : Novembre 2025  
**Auteur** : CMEM Team
