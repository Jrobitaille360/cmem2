# Serveur CalDAV - Guide Complet

## 📋 Vue d'ensemble

Le serveur CalDAV CMEM2 implémente le protocole **CalDAV (RFC 4791)** et **WebDAV (RFC 4918)** pour offrir une synchronisation bidirectionnelle complète des calendriers avec les applications natives.

## ✨ Fonctionnalités

### ✅ Implémenté

- **Synchronisation bidirectionnelle** - Créer, modifier, supprimer des événements depuis n'importe quel client
- **Support multi-clients** - Compatible avec Thunderbird, Apple Calendar, DAVx⁵ (Android), etc.
- **ETags et CTags** - Gestion des conflits et synchronisation optimisée
- **Sync-collection** - Synchronisation incrémentale pour économiser la bande passante
- **Verrouillage WebDAV** - Prévention des conflits d'écriture simultanée
- **Calendriers partagés** - Partage avec permissions read/write
- **Authentification JWT** - Sécurité par token Bearer
- **Format iCalendar** - Standard RFC 5545
- **Multi-timezone** - Support complet des fuseaux horaires

### 🚀 Méthodes HTTP supportées

- `OPTIONS` - Annonce des capacités CalDAV
- `PROPFIND` - Découverte de ressources (calendriers et événements)
- `REPORT` - Requêtes avancées (calendar-query, sync-collection, multiget)
- `GET` - Récupération d'événements au format iCalendar
- `PUT` - Création/mise à jour d'événements
- `DELETE` - Suppression d'événements
- `MKCALENDAR` - Création de calendriers
- `LOCK/UNLOCK` - Verrouillage de ressources
- `PROPPATCH` - Modification de propriétés

## 🏗️ Architecture

### Structure des fichiers

```text
src/ics/
├── Services/
│   └── CalDAVServer.php           # Serveur CalDAV principal
├── Controllers/
│   └── CalDAVController.php       # Contrôleur API
├── Routing/
│   └── RouteHandlers/
│       └── CalDAVRouteHandler.php # Gestionnaire de routes
├── Models/
│   ├── Calendar.php               # Modèle calendrier (mis à jour)
│   └── CalendarEvent.php          # Modèle événement (mis à jour)
└── docs_ICS/
    └── Proc_add_caldav_support.sql # Migration SQL
```

### Base de données

Nouvelles colonnes ajoutées :

**Table `calendars`:**

- `ctag` - Collection Tag pour détecter les changements du calendrier
- `sync_token` - Token pour la synchronisation incrémentale

**Table `calendar_events`:**

- `etag` - Entity Tag pour détecter les changements d'événement
- `uid` - UID unique iCalendar (ex: event-uuid@cmem2)
- `sequence` - Numéro de séquence pour les mises à jour
- `last_modified` - Timestamp de dernière modification

**Nouvelles tables:**

- `caldav_sync_log` - Journal des changements pour sync-collection
- `caldav_locks` - Verrous WebDAV pour prévenir les conflits

## 🔧 Installation

### 1. Exécuter la migration SQL

```sql
-- Depuis MySQL ou PhpMyAdmin
SOURCE src/ics/docs_ICS/Proc_add_caldav_support.sql;

-- Ou directement
CALL AddCalDAVSupport();
```

Cette procédure :

- ✅ Ajoute les colonnes CalDAV aux tables existantes
- ✅ Crée les nouvelles tables de synchronisation
- ✅ Génère les triggers automatiques pour ETags/CTags
- ✅ Initialise les données pour les calendriers existants

### 2. Vérifier que le plugin est activé

Le plugin ICS Calendar est automatiquement chargé. Les routes CalDAV sont disponibles à :

```text
BASE_URL/caldav/
```

### 3. Tester l'installation

```bash
# Vérifier que CalDAV répond
curl -X OPTIONS http://your-domain/cmem2_API/caldav/

# Réponse attendue:
# DAV: 1, 2, calendar-access, calendar-schedule
# Allow: OPTIONS, GET, PUT, DELETE, PROPFIND, REPORT, MKCALENDAR, LOCK, UNLOCK
```

## 📱 Configuration des clients

### Apple Calendar (iOS/macOS)

#### Méthode automatique (Recommandé)

1. **Générer le fichier de configuration:**

   ```text
   GET /caldav/mobile-config
   Authorization: Bearer YOUR_JWT_TOKEN
   ```

2. **Ouvrir le fichier `.mobileconfig`** sur votre appareil
3. **Suivre les instructions d'installation**
4. **Entrer votre mot de passe JWT** quand demandé

#### Méthode manuelle

1. **Réglages** → **Calendrier** → **Comptes** → **Ajouter un compte**
2. Sélectionner **CalDAV**
3. Remplir:
   - Serveur: `your-domain.com/cmem2_API/caldav/`
   - Nom d'utilisateur: Votre email
   - Mot de passe: Votre token JWT
   - Port: 443 (HTTPS) ou 80 (HTTP)

### Thunderbird (Desktop)

1. **Installer Lightning** (intégré dans Thunderbird 78+)
2. **Calendrier** → **Nouveau calendrier** → **Sur le réseau**
3. Sélectionner **CalDAV**
4. URL: `https://your-domain.com/cmem2_API/caldav/`
5. Nom d'utilisateur: Votre email
6. Mot de passe: Votre token JWT

### Android (DAVx⁵)

1. **Installer DAVx⁵** depuis Google Play ou F-Droid
2. **Ajouter un compte** → **Connexion avec URL et nom d'utilisateur**
3. Remplir:
   - URL de base: `https://your-domain.com/cmem2_API/caldav/`
   - Nom d'utilisateur: Votre email
   - Mot de passe: Votre token JWT
4. **Valider** et sélectionner les calendriers à synchroniser

### Outlook

⚠️ **Outlook ne supporte pas CalDAV nativement**

**Solutions alternatives:**

1. Utiliser l'export ICS existant (URL abonnement)
2. Installer un plugin tiers comme Outlook CalDav Synchronizer
3. Utiliser Thunderbird à la place

## 🔐 Authentification

### Token JWT

Toutes les requêtes CalDAV nécessitent un token JWT valide :

```http
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

### Obtenir un token

```bash
# Login
curl -X POST http://your-domain/cmem2_API/users/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user@example.com",
    "password": "your_password"
  }'

# Réponse contient: { "token": "eyJhbGc..." }
```

### Informations de service

```bash
# Obtenir les infos CalDAV
curl http://your-domain/cmem2_API/caldav/service-info \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## 🛠️ Utilisation de l'API

### Découverte de calendriers (PROPFIND)

```xml
PROPFIND /cmem2_API/caldav/ HTTP/1.1
Authorization: Bearer YOUR_TOKEN
Depth: 1
Content-Type: application/xml

<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:displayname/>
    <d:resourcetype/>
    <c:calendar-description/>
    <cs:getctag xmlns:cs="http://calendarserver.org/ns/"/>
  </d:prop>
</d:propfind>
```

### Requête de calendrier (REPORT)

```xml
REPORT /cmem2_API/caldav/{share_token}/ HTTP/1.1
Authorization: Bearer YOUR_TOKEN
Content-Type: application/xml

<?xml version="1.0" encoding="UTF-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag/>
    <c:calendar-data/>
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:time-range start="20231201T000000Z" end="20231231T235959Z"/>
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>
```

### Créer un événement (PUT)

```http
PUT /cmem2_API/caldav/{share_token}/my-event-uid.ics HTTP/1.1
Authorization: Bearer YOUR_TOKEN
Content-Type: text/calendar

BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//My Calendar//EN
BEGIN:VEVENT
UID:my-event-uid@cmem2
DTSTAMP:20231220T120000Z
DTSTART:20231225T100000Z
DTEND:20231225T120000Z
SUMMARY:Réunion importante
DESCRIPTION:Description de l'événement
LOCATION:Salle de conférence
STATUS:CONFIRMED
END:VEVENT
END:VCALENDAR
```

### Synchronisation incrémentale (sync-collection)

```xml
REPORT /cmem2_API/caldav/{share_token}/ HTTP/1.1
Authorization: Bearer YOUR_TOKEN
Content-Type: application/xml

<?xml version="1.0" encoding="UTF-8"?>
<d:sync-collection xmlns:d="DAV:">
  <d:sync-token>previous-sync-token</d:sync-token>
  <d:sync-level>1</d:sync-level>
  <d:prop>
    <d:getetag/>
  </d:prop>
</d:sync-collection>
```

## 🔄 Synchronisation

### Comment ça fonctionne

1. **CTags (Collection Tags)** - Détectent si le calendrier a changé
   - Le client stocke le CTag localement
   - À chaque sync, compare avec le CTag du serveur
   - Si différent → télécharger les changements

2. **ETags (Entity Tags)** - Détectent quel événement a changé
   - Chaque événement a un ETag unique
   - Le client compare ses ETags avec ceux du serveur
   - Télécharge seulement les événements modifiés

3. **Sync-Token** - Synchronisation incrémentale
   - Le serveur garde un journal des changements
   - Le client envoie son dernier sync-token
   - Le serveur retourne seulement les changements depuis ce token

### Gestion des conflits

- **ETags sur PUT** - Empêche l'écrasement accidentel
- **Verrous WebDAV** - Prévient l'édition simultanée
- **Sequence numbers** - Détecte l'ordre des modifications
- **Last-Modified** - Timestamp précis des changements

## 📊 Structure des URLs

```text
/cmem2_API/caldav/                              # Racine CalDAV
├── service-info                                # Infos du service (JSON)
├── mobile-config                               # Config iOS/macOS (.mobileconfig)
├── {share_token}/                              # Calendrier spécifique
│   ├── event-uuid-1.ics                       # Événement 1
│   ├── event-uuid-2.ics                       # Événement 2
│   └── ...
```

## 🐛 Dépannage

### Le client ne trouve pas le serveur

- Vérifier que l'URL se termine par `/caldav/`
- S'assurer que le serveur répond à `OPTIONS`
- Vérifier les certificats SSL si HTTPS

### Erreur 401 Unauthorized

- Vérifier que le token JWT est valide
- S'assurer que le header `Authorization: Bearer TOKEN` est envoyé
- Le token doit être présent dans la table `valid_tokens`

### Les changements ne se synchronisent pas

- Vérifier que les triggers SQL sont actifs
- Contrôler que les CTags/ETags se mettent à jour
- Consulter la table `caldav_sync_log`

### Conflits d'édition

- Utiliser les verrous WebDAV (`LOCK`/`UNLOCK`)
- Vérifier les ETags avant `PUT`
- Implémenter une stratégie de résolution côté client

## 📈 Performance

### Optimisations automatiques

- **Sync incrémentale** - Télécharge seulement les changements
- **ETags** - Évite le téléchargement d'événements inchangés
- **Index SQL** - Recherche rapide par `share_token`, `uid`, `etag`
- **Triggers** - Mise à jour automatique des tags

### Recommandations

- Utiliser `sync-collection` plutôt que `PROPFIND` complet
- Configurer un intervalle de sync de 15-30 minutes
- Limiter la profondeur des requêtes PROPFIND à 1
- Activer le cache HTTP côté client

## 🔒 Sécurité

### Implémenté

- ✅ Authentification JWT obligatoire
- ✅ Validation des permissions (owner/read/write)
- ✅ Protection contre les injections SQL (requêtes préparées)
- ✅ Validation des données iCalendar
- ✅ Logging de toutes les opérations

### Recommandations2

- Utiliser HTTPS en production
- Implémenter un rate limiting
- Configurer les CORS selon vos besoins
- Auditer régulièrement les logs CalDAV

## 📝 Exemples complets

Voir les fichiers dans `src/ics/docs_ICS/examples/` :

- `example-propfind.xml` - Découverte de calendriers
- `example-report.xml` - Requête avec filtres
- `example-event.ics` - Format d'événement
- `example-sync.xml` - Synchronisation incrémentale

## 🆘 Support

### Logs

Les logs CalDAV sont dans :

- Table `logs` avec le tag `CalDAV`
- Filtrer par `context->method` pour voir les requêtes spécifiques

### Debug

Activer le mode verbose dans le client CalDAV pour voir :

- Les requêtes XML envoyées
- Les réponses du serveur
- Les erreurs de parsing

### Ressources

- RFC 4791 (CalDAV): <https://tools.ietf.org/html/rfc4791>
- RFC 5545 (iCalendar): <https://tools.ietf.org/html/rfc5545>
- RFC 4918 (WebDAV): <https://tools.ietf.org/html/rfc4918>

## ✅ Checklist de déploiement

- [ ] Migration SQL exécutée
- [ ] Triggers SQL actifs
- [ ] Plugin ICS activé
- [ ] Routes CalDAV accessibles
- [ ] Authentification JWT fonctionnelle
- [ ] Test avec un client CalDAV
- [ ] Logs activés
- [ ] HTTPS configuré (production)
- [ ] Backups de la base de données
- [ ] Documentation partagée aux utilisateurs

## 🎉 Prochaines étapes

1. Tester avec différents clients
2. Monitorer les performances
3. Collecter les retours utilisateurs
4. Optimiser selon l'usage réel
5. Considérer l'ajout de CardDAV pour les contacts

---

**Version:** 1.0.0  
**Date:** Octobre 2025  
**Auteur:** CMEM2 Development Team
