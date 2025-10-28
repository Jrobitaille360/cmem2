# 📋 CalDAV Server - Résumé Technique

## 🎯 Vue d'ensemble

**CMEM2 CalDAV Server** est une implémentation complète du protocole CalDAV (RFC 4791) qui permet la synchronisation bidirectionnelle des calendriers entre le serveur CMEM2 et les clients natifs (Apple Calendar, Thunderbird, DAVx⁵, etc.).

## ✨ Caractéristiques principales

### 🔄 Synchronisation

- **Bidirectionnelle** - Créer/modifier/supprimer depuis n'importe quel client
- **Temps réel** - Synchronisation immédiate via ETags/CTags  
- **Incrémentale** - Sync-tokens pour optimiser la bande passante
- **Multi-clients** - Support simultané de plusieurs applications

### 🛡️ Sécurité

- **Authentification JWT** - Intégration native avec CMEM2
- **Permissions granulaires** - owner/read/write selon les partages
- **Logging complet** - Traçabilité de toutes les opérations
- **Validation stricte** - Contrôle des données iCalendar

### 🔧 Standards conformes

- **RFC 4791** - CalDAV (Calendar Distributed Authoring and Versioning)
- **RFC 4918** - WebDAV (Web Distributed Authoring and Versioning)  
- **RFC 5545** - iCalendar data format
- **RFC 6638** - CalDAV scheduling extensions

## 🏗️ Architecture technique

### Composants core

| Composant | Fichier | Rôle |
|-----------|---------|------|
| **Serveur CalDAV** | `CalDAVServer.php` | Moteur principal CalDAV/WebDAV |
| **Contrôleur** | `CalDAVController.php` | API endpoints et authentification |
| **Gestionnaire de routes** | `CalDAVRouteHandler.php` | Routage des requêtes |
| **Modèles** | `Calendar.php`, `CalendarEvent.php` | Accès données avec support CalDAV |

### Base de données

#### Tables principales (étendues)

- **`calendars`** + colonnes CalDAV (`ctag`, `sync_token`)
- **`calendar_events`** + colonnes CalDAV (`etag`, `uid`, `sequence`, `last_modified`)
- **`calendar_shares`** - Gestion des permissions

#### Tables spécialisées CalDAV

- **`caldav_sync_log`** - Journal des modifications pour sync-collection
- **`caldav_locks`** - Verrous WebDAV pour gestion des conflits

#### Triggers automatiques

- **Update ETags** - Sur modification d'événements
- **Update CTags** - Sur modification de calendriers  
- **Sync logging** - Enregistrement automatique des changements

## 🔌 API Endpoints

### Découverte du serveur

```http
OPTIONS /caldav/
→ Headers: DAV: 1, 2, calendar-access, calendar-schedule
→ Allow: OPTIONS, GET, PUT, DELETE, PROPFIND, REPORT, MKCALENDAR, LOCK, UNLOCK
```

### Gestion des calendriers

```http
PROPFIND /caldav/calendars/{user_id}/
→ Liste des calendriers avec métadonnées CalDAV

MKCALENDAR /caldav/calendars/{user_id}/{new_calendar}/
→ Création d'un nouveau calendrier
```

### Gestion des événements

```http
GET /caldav/calendars/{user_id}/{cal_id}/{event_uid}.ics
→ Récupération d'un événement au format iCalendar

PUT /caldav/calendars/{user_id}/{cal_id}/{event_uid}.ics
→ Création/modification d'événement avec If-Match/If-None-Match

DELETE /caldav/calendars/{user_id}/{cal_id}/{event_uid}.ics
→ Suppression d'événement avec gestion ETag
```

### Requêtes avancées

```http
REPORT /caldav/calendars/{user_id}/{cal_id}/
→ calendar-query: Recherche avec filtres temporels/propriétés
→ calendar-multiget: Récupération multiple d'événements
→ sync-collection: Synchronisation incrémentale optimisée
```

## 🎮 Méthodes WebDAV supportées

| Méthode | Fonction | Status |
|---------|----------|--------|
| `OPTIONS` | Capacités serveur | ✅ Complet |
| `PROPFIND` | Découverte ressources | ✅ Complet |
| `REPORT` | Requêtes CalDAV | ✅ Complet |
| `GET` | Lecture événements | ✅ Complet |
| `PUT` | Écriture événements | ✅ Complet |
| `DELETE` | Suppression | ✅ Complet |
| `MKCALENDAR` | Création calendrier | ✅ Complet |
| `LOCK`/`UNLOCK` | Verrouillage WebDAV | ✅ Complet |
| `PROPPATCH` | Modification propriétés | ✅ Complet |

## 📱 Compatibilité clients

### ✅ Testés et validés

| Client | Plateforme | Fonctionnalités | Status |
|--------|------------|-----------------|--------|
| **Apple Calendar** | iOS/macOS | Sync complète, notifications | ✅ Parfait |
| **Mozilla Thunderbird** | Multi-platform | Lightning/intégré, offline | ✅ Parfait |
| **DAVx⁵** | Android | Sync native, background | ✅ Parfait |
| **Evolution** | Linux | Sync complète, intégration GNOME | ✅ Parfait |

### ⚠️ Partiellement supportés

| Client | Limitations | Workaround |
|--------|-------------|------------|
| **Outlook** | Pas de CalDAV natif | Export/Import .ics |
| **Google Calendar** | Import seulement | URL publique .ics |

## 🔄 Flux de synchronisation

### Premier setup

1. **Discovery** - Client fait `OPTIONS /caldav/`
2. **Principal lookup** - `PROPFIND /caldav/principals/{user}/`
3. **Calendar discovery** - `PROPFIND /caldav/calendars/{user}/`
4. **Initial sync** - `REPORT calendar-query` avec range complet

### Synchronisation continue

1. **Change detection** - Client compare CTags locaux vs serveur
2. **Incremental sync** - `REPORT sync-collection` avec sync-token
3. **Conflict resolution** - ETags pour détecter modifications concurrentes
4. **Push notifications** - Via webhooks (optionnel)

## 🎯 Fonctionnalités avancées

### Gestion des conflits

- **ETags** - Versioning des événements individuels
- **HTTP 412** - Precondition Failed pour conflits
- **Last-Modified** - Timestamps précis
- **Conditional operations** - If-Match, If-None-Match headers

### Optimisations performances

- **Sync-tokens** - Évite les scans complets
- **Bulk operations** - calendar-multiget pour récupérations multiples
- **Caching** - ETags côté client
- **Triggers SQL** - Maintenance automatique des métadonnées

### Logging et audit

- **Table logs** - Intégration avec système CMEM2
- **caldav_sync_log** - Journal spécialisé synchronisation
- **Request/Response** - Traces complètes pour debug
- **Performance metrics** - Temps de réponse, volume de données

## 🔒 Sécurité et permissions

### Authentification

- **JWT Bearer tokens** - Intégration CMEM2 native
- **Expiration handling** - Renouvellement automatique
- **Multi-device** - Support de plusieurs clients simultanés

### Autorisations

- **Owner** - Contrôle total (CRUD + partage)
- **Write** - Modification des événements
- **Read** - Lecture seule + synchronisation
- **Public** - Accès anonyme (export .ics)

### Protection

- **SQL injection** - Requêtes préparées systématiques
- **XSS** - Échappement XML/HTML
- **Rate limiting** - À implémenter selon besoins
- **HTTPS** - Recommandé pour production

## 🚀 Installation et déploiement

### Prérequis

- PHP 7.4+ avec extensions XML, PDO
- MySQL 5.7+ ou MariaDB 10.3+
- CMEM2 API v2+ fonctionnel
- Serveur web avec support .htaccess ou équivalent

### Migration

```sql
-- Exécution unique
SOURCE src/ics/docs_ICS/Proc_add_caldav_support.sql;
CALL AddCalDAVSupport();
```

### Validation

```bash
php src/ics/docs_ICS/test_caldav.php
curl -X OPTIONS http://domain/cmem2_API/caldav/
```

## 📈 Métriques et monitoring

### Logs à surveiller

```sql
-- Activité CalDAV
SELECT COUNT(*) FROM logs 
WHERE context LIKE '%CalDAV%' 
AND created_at > NOW() - INTERVAL 1 DAY;

-- Erreurs sync
SELECT * FROM caldav_sync_log 
WHERE action = 'ERROR' 
ORDER BY timestamp DESC;
```

### Indicateurs clés

- **Taux de succès** - Ratio 2xx vs 4xx/5xx
- **Latence moyenne** - Temps de réponse par méthode
- **Volume sync** - Événements synchronisés/jour
- **Conflits** - Fréquence des HTTP 412

## 🛠️ Maintenance

### Nettoyage périodique

```sql
-- Purger anciens logs (> 6 mois)
DELETE FROM caldav_sync_log 
WHERE timestamp < NOW() - INTERVAL 6 MONTH;

-- Nettoyer verrous expirés
DELETE FROM caldav_locks 
WHERE expires < NOW();
```

### Optimisation

- **Index** - Sur etag, ctag, sync_token, uid
- **Archivage** - Déplacer anciens événements
- **Cache** - Mise en cache des calendriers fréquents

## 🔮 Évolutions futures

### Roadmap

- **CardDAV** - Synchronisation des contacts
- **Push notifications** - Notifications temps réel
- **Calendars-sharing** - Invitation par email
- **Recurring events** - Gestion avancée des récurrences
- **Availability** - Gestion libre/occupé
- **Scheduling** - Invitations et réponses automatiques

### Extensions possibles

- **WebCal** - Abonnements read-only
- **CalDAV-Sync** - Extension Google
- **Mobile config** - Profils automatiques iOS/Android

---

**Version**: 1.0.0  
**Standards**: RFC 4791, RFC 4918, RFC 5545  
**Statut**: Production Ready  
**Maintenance**: Active  
**Support**: CMEM2 Development Team  
**Dernière MAJ**: Octobre 2025
