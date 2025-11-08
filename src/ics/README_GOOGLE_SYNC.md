# Configuration de la synchronisation avec Google Calendar

## 📋 Prérequis

1. ✅ Bibliothèque Google API Client installée (déjà fait via composer)
2. Compte Google Cloud avec projet créé
3. API Google Calendar activée
4. Credentials OAuth 2.0 téléchargés

---

## 🚀 Configuration étape par étape

### Étape 1 : Créer un projet Google Cloud

1. Allez sur [Google Cloud Console](https://console.cloud.google.com/)
2. Cliquez sur "Sélectionner un projet" → "Nouveau projet"
3. Nommez votre projet : **"CMEM2 Calendar Sync"**
4. Cliquez sur **"Créer"**

### Étape 2 : Activer l'API Google Calendar

1. Dans le menu de gauche, allez dans **"API et services"** → **"Bibliothèque"**
2. Recherchez **"Google Calendar API"**
3. Cliquez dessus puis sur **"Activer"**

### Étape 3 : Créer des identifiants OAuth 2.0

1. Allez dans **"API et services"** → **"Identifiants"**
2. Cliquez sur **"Créer des identifiants"** → **"ID client OAuth"**

3. **Configurer l'écran de consentement OAuth** (si demandé) :
   - Type d'utilisateur : **Externe** (ou Interne si G Suite)
   - Nom de l'application : **CMEM2 Calendar Sync**
   - E-mail d'assistance : votre email
   - Domaine autorisé (optionnel) : votre domaine
   - Cliquez sur **"Enregistrer et continuer"**
   
4. **Portées (Scopes)** :
   - Cliquez sur **"Ajouter ou supprimer des portées"**
   - Recherchez et sélectionnez : `https://www.googleapis.com/auth/calendar`
   - Cliquez sur **"Enregistrer et continuer"**

5. **Utilisateurs de test** :
   - Ajoutez votre adresse email Google
   - Cliquez sur **"Enregistrer et continuer"**

6. **Créer l'ID client** :
   - Type d'application : **Application de bureau**
   - Nom : **CMEM2 Sync Client**
   - Cliquez sur **"Créer"**

7. **Télécharger les credentials** :
   - Cliquez sur l'icône **télécharger** (⬇) à côté de votre client OAuth créé
   - Enregistrez le fichier JSON

### Étape 4 : Placer le fichier credentials.json

```bash
# Renommez le fichier téléchargé en credentials.json
# Placez-le dans le répertoire src/ics/
cp ~/Téléchargements/client_secret_*.json "c:/Users/escif/Proton Drive/jrobitaille04/My files/My_htdocs/cmem2_API/src/ics/credentials.json"
```

**Structure attendue :**
```
cmem2_API/
└── src/
    └── ics/
        ├── credentials.json      ← Fichier à créer (credentials Google)
        ├── token.json           ← Sera créé automatiquement lors de la 1ère auth
        ├── last_sync.txt        ← Sera créé automatiquement
        └── sync_google_calendar.php
```

---

## 🔐 Première authentification

### Étape 1 : Exécuter le script pour la première fois

```bash
cd "c:/Users/escif/Proton Drive/jrobitaille04/My files/My_htdocs/cmem2_API"
php src/ics/sync_google_calendar.php
```

### Étape 2 : Autoriser l'accès

Le script affichera une URL comme celle-ci :

```
Ouvrez cette URL dans votre navigateur:
https://accounts.google.com/o/oauth2/auth?client_id=...
Entrez le code de vérification:
```

1. **Copiez l'URL** et ouvrez-la dans votre navigateur
2. Connectez-vous avec votre compte Google
3. Autorisez l'accès au calendrier
4. Google affichera un **code de vérification**
5. **Copiez ce code** et collez-le dans le terminal
6. Appuyez sur **Entrée**

✅ Le fichier `token.json` sera automatiquement créé et stockera votre authentification.

---

## ⚙️ Configuration du script

Éditez `sync_google_calendar.php` et modifiez les constantes selon vos besoins :

```php
// ID du calendrier Google (utilisez 'primary' pour le calendrier principal)
const GOOGLE_CALENDAR_ID = 'primary';

// ID du calendrier local dans votre API CMEM2
const LOCAL_CALENDAR_ID = 1; // ← Modifiez selon votre configuration

// Fuseau horaire
const TIMEZONE = 'America/Toronto';
```

### Trouver l'ID de votre calendrier local

```bash
# Option 1 : Via MySQL
mysql -u root -p cmem2_db
SELECT id, name FROM calendars WHERE user_id = 1;

# Option 2 : Via API
curl https://votre-domaine.com/cmem2_API/calendars \
  -H "X-API-Key: ag_live_xxxxxxxxxxxxx"
```

### Utiliser un calendrier Google spécifique (pas le principal)

1. Allez sur [Google Calendar](https://calendar.google.com/)
2. Cliquez sur les **3 points** à côté du calendrier souhaité
3. **Paramètres et partage** → copiez l'**"ID du calendrier"**
4. Remplacez dans le script :

```php
const GOOGLE_CALENDAR_ID = 'abc123xyz@group.calendar.google.com';
```

---

## 🔄 Automatisation de la synchronisation

### Windows (Task Scheduler)

1. Ouvrez **Planificateur de tâches** (Task Scheduler)
2. **Créer une tâche** :
   - **Général** :
     - Nom : `CMEM2 Google Calendar Sync`
     - Exécuter avec les autorisations maximales
   
   - **Déclencheurs** :
     - Nouveau → Répéter la tâche toutes les : **15 minutes**
     - Pendant : **Indéfiniment**
   
   - **Actions** :
     - Programme : `C:\xampp\php\php.exe` (ou chemin vers votre PHP)
     - Arguments : `"C:\Users\escif\Proton Drive\jrobitaille04\My files\My_htdocs\cmem2_API\src\ics\sync_google_calendar.php"`
     - Dossier de démarrage : `C:\Users\escif\Proton Drive\jrobitaille04\My files\My_htdocs\cmem2_API`

3. **Enregistrer**

### Linux (crontab)

```bash
# Ouvrir l'éditeur cron
crontab -e

# Ajouter cette ligne pour synchroniser toutes les 15 minutes
*/15 * * * * cd /var/www/cmem2_API && php src/ics/sync_google_calendar.php >> logs/calendar_sync.log 2>&1
```

### macOS (launchd)

Créez le fichier : `~/Library/LaunchAgents/com.cmem2.calendar.sync.plist`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.cmem2.calendar.sync</string>
    <key>ProgramArguments</key>
    <array>
        <string>/usr/bin/php</string>
        <string>/path/to/cmem2_API/src/ics/sync_google_calendar.php</string>
    </array>
    <key>StartInterval</key>
    <integer>900</integer> <!-- 15 minutes = 900 secondes -->
    <key>RunAtLoad</key>
    <true/>
</dict>
</plist>
```

Puis activez :
```bash
launchctl load ~/Library/LaunchAgents/com.cmem2.calendar.sync.plist
```

---

## 📊 Logs et monitoring

### Vérifier les logs

Le script enregistre dans les logs de l'API :

```bash
# Voir les dernières synchronisations
tail -f logs/app.log | grep "Google Calendar"

# Rechercher les erreurs
grep "Erreur sync" logs/app.log
```

### Résultat attendu

```
╔════════════════════════════════════════════════╗
║   Synchronisation Google Calendar ↔ CMEM2     ║
╚════════════════════════════════════════════════╝

Date: 2025-11-07 14:30:00

→ Initialisation du client Google...
✓ Client Google initialisé
→ Dernière synchronisation: 2025-11-07 14:15:00

=== Synchronisation Local → Google ===
  ✓ Mis à jour: Réunion d'équipe
  + Créé: Anniversaire Marie
Total synchronisé: 2 événements

=== Synchronisation Google → Local ===
  ✓ Mis à jour: Dentiste
  + Créé: Concert ce soir
Total synchronisé: 2 événements

╔════════════════════════════════════════════════╗
║              SYNCHRONISATION TERMINÉE          ║
╚════════════════════════════════════════════════╝
→ Local → Google: 2 événements
→ Google → Local: 2 événements
→ Prochaine sync recommandée dans 15 minutes
```

---

## 🐛 Dépannage

### Erreur : "credentials.json introuvable"

```bash
# Vérifiez que le fichier existe
ls -la "c:/Users/escif/Proton Drive/jrobitaille04/My files/My_htdocs/cmem2_API/src/ics/credentials.json"

# Sinon, retéléchargez-le depuis Google Cloud Console
```

### Erreur : "Token expiré"

```bash
# Supprimez le token et réauthentifiez
rm "c:/Users/escif/Proton Drive/jrobitaille04/My files/My_htdocs/cmem2_API/src/ics/token.json"
php src/ics/sync_google_calendar.php
```

### Erreur : "Access denied"

Vérifiez que :
1. L'API Google Calendar est bien **activée** dans Google Cloud Console
2. Les **scopes** incluent `https://www.googleapis.com/auth/calendar`
3. Votre email est dans la liste des **utilisateurs de test**

### Événements non synchronisés

```bash
# Vérifiez la dernière synchronisation
cat src/ics/last_sync.txt

# Réinitialisez pour resynchroniser tout
rm src/ics/last_sync.txt
php src/ics/sync_google_calendar.php
```

---

## 🔒 Sécurité

### Protégez vos credentials

```bash
# Permissions restrictives (Linux/macOS)
chmod 600 src/ics/credentials.json
chmod 600 src/ics/token.json

# Ajoutez au .gitignore
echo "src/ics/credentials.json" >> .gitignore
echo "src/ics/token.json" >> .gitignore
echo "src/ics/last_sync.txt" >> .gitignore
```

### Révoquer l'accès

Si vous devez révoquer l'accès :

1. Allez sur [Google Account Permissions](https://myaccount.google.com/permissions)
2. Trouvez **"CMEM2 Calendar Sync"**
3. Cliquez sur **"Supprimer l'accès"**

---

## 📚 Ressources

- [Google Calendar API Documentation](https://developers.google.com/calendar/api/guides/overview)
- [OAuth 2.0 pour applications de bureau](https://developers.google.com/identity/protocols/oauth2/native-app)
- [PHP Google API Client](https://github.com/googleapis/google-api-php-client)

---

## ✅ Checklist de configuration

- [ ] Projet Google Cloud créé
- [ ] API Google Calendar activée
- [ ] Credentials OAuth 2.0 créés et téléchargés
- [ ] Fichier `credentials.json` placé dans `src/ics/`
- [ ] Première authentification effectuée (token.json créé)
- [ ] LOCAL_CALENDAR_ID configuré correctement
- [ ] Script testé manuellement avec succès
- [ ] Synchronisation automatique configurée (Task Scheduler/cron)
- [ ] Logs vérifiés et fonctionnels

🎉 **Vous êtes prêt ! Votre calendrier se synchronise maintenant automatiquement avec Google Calendar.**
