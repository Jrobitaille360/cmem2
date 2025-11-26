# 📅 CalDAV Server - README

## 🎯 Qu'est-ce que CalDAV ?

**CalDAV** (Calendar Distributed Authoring and Versioning) est un protocole standard basé sur **WebDAV** qui permet la synchronisation bidirectionnelle des calendriers entre un serveur et des clients multiples.

## ✨ Pourquoi utiliser CalDAV avec CMEM2 ?

- **📱 Synchronisation native** - Vos calendriers CMEM2 apparaissent automatiquement dans Apple Calendar, Thunderbird, etc.
- **🔄 Bidirectionnel** - Créez, modifiez, supprimez des événements depuis n'importe quel client
- **🌍 Multi-plateformes** - Compatible iOS, Android, Windows, macOS, Linux
- **⚡ Temps réel** - Changements instantanés sur tous vos appareils
- **🔒 Sécurisé** - Authentification JWT intégrée à CMEM2

## 🚀 Démarrage rapide

### 1. Installation (5 minutes)

```sql
-- Exécuter dans MySQL/PhpMyAdmin
SOURCE src/ics/docs_ICS/Proc_add_caldav_support.sql;
```

### 2. Test

```bash
curl -X OPTIONS http://your-domain/cmem2_API/caldav/
# Devrait répondre: DAV: 1, 2, calendar-access
```

### 3. Configuration client

- **URL CalDAV**: `https://your-domain.com/cmem2_API/caldav/`
- **Nom d'utilisateur**: Votre email CMEM2
- **Mot de passe**: Votre token JWT

## 📚 Documentation complète

| Fichier | Description |
|---------|-------------|
| [`CALDAV_QUICKSTART.md`](./CALDAV_QUICKSTART.md) | Installation en 5 minutes |
| [`CALDAV_GUIDE.md`](./CALDAV_GUIDE.md) | Guide technique complet |
| [`CALDAV_API_KEYS.md`](./CALDAV_API_KEYS.md) | Support des API Keys |
| [`README.md`](./README.md) | Documentation générale du module ICS |

## 🔧 Architecture

### Composants principaux

```text
CalDAVServer.php        # Serveur CalDAV principal (RFC 4791/4918)
CalDAVController.php    # Contrôleur API REST
CalDAVRouteHandler.php  # Gestionnaire de routes
```

### Tables de base de données

- **`calendars`** - Calendriers avec colonnes CalDAV (`ctag`, `sync_token`)
- **`calendar_events`** - Événements avec ETags (`etag`, `uid`, `sequence`)
- **`caldav_sync_log`** - Journal des modifications pour sync incrémentale
- **`caldav_locks`** - Verrous WebDAV pour prévenir les conflits

## 🎯 Fonctionnalités supportées

### ✅ Protocoles

- **CalDAV RFC 4791** - Protocole de synchronisation des calendriers
- **WebDAV RFC 4918** - Protocole de base pour l'accès distant aux fichiers
- **iCalendar RFC 5545** - Format standard des événements

### ✅ Méthodes HTTP

| Méthode | Usage |
|---------|-------|
| `OPTIONS` | Découverte des capacités du serveur |
| `PROPFIND` | Découverte des calendriers et événements |
| `REPORT` | Requêtes avec filtres (calendar-query, sync-collection) |
| `GET` | Récupération d'événements individuels |
| `PUT` | Création/modification d'événements |
| `DELETE` | Suppression d'événements |
| `MKCALENDAR` | Création de nouveaux calendriers |
| `LOCK`/`UNLOCK` | Verrouillage WebDAV |

### ✅ Synchronisation

- **ETags** - Détection des modifications sur les événements
- **CTags** - Détection des modifications sur les calendriers
- **Sync-tokens** - Synchronisation incrémentale optimisée
- **Conflict detection** - HTTP 412 Precondition Failed

## 📱 Clients compatibles

### Desktop

- ✅ **Mozilla Thunderbird** (toutes plateformes)
- ✅ **Apple Calendar** (macOS)
- ✅ **Microsoft Outlook** (import ICS)
- ✅ **Evolution** (Linux)

### Mobile

- ✅ **Apple Calendar** (iOS)
- ✅ **DAVx⁵** (Android)
- ✅ **Calendar** (Android natif avec DAVx⁵)

### Web

- ✅ Tous les clients supportant les URLs iCalendar (.ics)

## 🔌 Endpoints principaux

```text
# Découverte
OPTIONS  /caldav/

# Calendriers
PROPFIND /caldav/calendars/{user_id}/
MKCALENDAR /caldav/calendars/{user_id}/{new_calendar}/

# Événements
GET      /caldav/calendars/{user_id}/{cal_id}/{event_uid}.ics
PUT      /caldav/calendars/{user_id}/{cal_id}/{event_uid}.ics
DELETE   /caldav/calendars/{user_id}/{cal_id}/{event_uid}.ics

# Synchronisation
REPORT   /caldav/calendars/{user_id}/{cal_id}/  # calendar-query, sync-collection
```

## 🛠️ Configuration avancée

### Headers requis

```http
Authorization: Bearer <JWT_TOKEN>
Content-Type: application/xml
Depth: 1
```

### Format des réponses

- **XML WebDAV** - Pour PROPFIND, REPORT
- **iCalendar** - Pour GET/PUT des événements
- **HTTP status codes** - 200, 201, 204, 404, 412, etc.

## 🆘 Dépannage

### Client ne se connecte pas

1. **Vérifier l'URL** - Doit finir par `/caldav/`
2. **Tester OPTIONS** - `curl -X OPTIONS http://domain/cmem2_API/caldav/`
3. **Vérifier le token JWT** - Doit être valide et non expiré
4. **Consulter les logs** - Table `logs` avec context `CalDAV`

### Synchronisation ne fonctionne pas

1. **Vérifier les triggers** - `SHOW TRIGGERS WHERE Table = 'calendar_events'`
2. **Tester la migration** - Exécuter `php test_caldav.php`
3. **Forcer resync** - Supprimer et recréer le compte client

### Erreurs communes

| Erreur | Cause | Solution |
|--------|-------|----------|
| 401 Unauthorized | Token JWT invalide | Renouveler le token |
| 404 Not Found | URL incorrecte | Vérifier `/caldav/` à la fin |
| 412 Precondition Failed | Conflit ETag | Le client doit récupérer la version actuelle |
| 501 Not Implemented | Méthode non supportée | Vérifier la compatibilité client |

## 🔒 Sécurité

- **🔐 Authentification JWT** - Obligatoire pour tous les accès
- **🛡️ Validation des permissions** - owner/read/write selon les partages
- **🔍 Logging complet** - Toutes les opérations sont tracées
- **⚠️ Input validation** - Validation stricte des données iCalendar

## 📝 Logs et monitoring

### Table logs

```sql
SELECT * FROM logs 
WHERE context LIKE '%CalDAV%' 
ORDER BY created_at DESC 
LIMIT 20;
```

### Table caldav_sync_log

```sql
SELECT * FROM caldav_sync_log 
ORDER BY timestamp DESC 
LIMIT 20;
```

## 🤝 Contribution

### Structure du code

- **Services/** - Logique métier CalDAV
- **Controllers/** - API endpoints
- **Models/** - Accès aux données avec support CalDAV
- **Routing/** - Gestionnaires de routes

### Tests

```bash
php src/ics/docs_ICS/test_caldav.php
```

## 📞 Support

1. **Documentation** - Lire [`CALDAV_GUIDE.md`](./CALDAV_GUIDE.md)
2. **Tests** - Exécuter `test_caldav.php`
3. **Logs** - Consulter la table `logs`
4. **RFC** - Référencer CalDAV RFC 4791

---

**Version**: 1.0.0  
**Compatibilité**: CMEM2 API v2+  
**Standards**: RFC 4791 (CalDAV), RFC 4918 (WebDAV), RFC 5545 (iCalendar)  
**Auteur**: CMEM2 Development Team  
**Date**: Octobre 2025
