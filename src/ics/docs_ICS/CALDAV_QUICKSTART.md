# Guide de démarrage rapide - CalDAV

## 🚀 Installation en 5 minutes

### 1. Exécuter la migration SQL (1 min)

```sql
-- Dans MySQL / PhpMyAdmin
SOURCE src/ics/docs_ICS/Proc_add_caldav_support.sql;

-- Ou
CALL AddCalDAVSupport();
```

✅ Cela ajoute :

- Colonnes `ctag`, `sync_token`, `etag`, `uid`, `sequence`
- Tables `caldav_sync_log` et `caldav_locks`
- Triggers automatiques

### 2. Vérifier que ça fonctionne (30 sec)

```bash
# Test basique
curl -X OPTIONS http://localhost/cmem2_API/caldav/

# Devrait répondre avec:
# DAV: 1, 2, calendar-access
```

### 3. Configurer votre client (2 min)

#### Option A : Apple Calendar (iPhone/Mac)

**Automatique:**

```text
1. GET /caldav/mobile-config avec votre token
2. Ouvrir le fichier .mobileconfig
3. Installer le profil
```

**Manuel:**

```text
Réglages → Calendrier → Ajouter compte → CalDAV
Serveur: votre-domaine.com/cmem2_API/caldav/
Utilisateur: votre@email.com
Mot de passe: VOTRE_JWT_TOKEN
```

#### Option B : Thunderbird

```text
1. Calendrier → Nouveau → Sur le réseau → CalDAV
2. URL: http://localhost/cmem2_API/caldav/
3. Utilisateur: votre@email.com
4. Mot de passe: VOTRE_JWT_TOKEN
```

#### Option C : Android (DAVx⁵)

```text
1. Installer DAVx⁵
2. Ajouter compte
3. URL: http://votre-domaine/cmem2_API/caldav/
4. Email et JWT token
```

### 4. Obtenir votre token JWT (1 min)

```bash
curl -X POST http://localhost/cmem2_API/users/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"motdepasse"}'

# Copier le token de la réponse
```

### 5. Tester la synchronisation (30 sec)

```text
1. Créer un événement dans votre client CalDAV
2. Vérifier dans la base de données :
   SELECT * FROM calendar_events ORDER BY created_at DESC LIMIT 1;
3. Modifier l'événement dans le client
4. Vérifier que l'ETag a changé
```

## ✅ C'est fait

Votre serveur CalDAV est opérationnel. Les événements se synchronisent automatiquement bidirectionnellement entre :

- Votre API CMEM2
- Tous vos clients CalDAV configurés

## 📚 Pour aller plus loin

- Voir `CALDAV_GUIDE.md` pour la documentation complète
- Tester d'autres clients CalDAV
- Configurer le partage de calendriers
- Activer HTTPS pour la production

## 🐛 Problème ?

**Client ne connecte pas:**

- Vérifier l'URL (doit finir par `/caldav/`)
- Vérifier que le token est valide
- Essayer avec `/caldav/service-info` pour tester

**Erreur 401:**

- Le token JWT a expiré, reconnectez-vous
- Vérifier que le header `Authorization: Bearer TOKEN` est envoyé

**Événements ne se synchronisent pas:**

- Vérifier que les triggers SQL sont actifs :

  ```sql
  SHOW TRIGGERS WHERE `Table` = 'calendar_events';
  ```

- Consulter les logs :

  ```sql
  SELECT * FROM logs WHERE context LIKE '%CalDAV%' ORDER BY created_at DESC;
  ```

## 🎯 URL importantes

- Service info: `/caldav/service-info`
- Config iOS/macOS: `/caldav/mobile-config`
- Racine CalDAV: `/caldav/`
- Calendrier spécifique: `/caldav/{share_token}/`

---

**Besoin d'aide ?** Consultez `CALDAV_GUIDE.md` ou les logs système.
