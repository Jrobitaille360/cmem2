# Guide de Synchronisation avec Google Calendar et Proton Calendar

## Vue d'ensemble

Votre API supporte le protocole CalDAV, ce qui permet la synchronisation bidirectionnelle avec la plupart des clients de calendrier modernes. Cependant, **Google Calendar et Proton Calendar ont des limitations** car ils ne supportent pas nativement CalDAV pour les calendriers externes.

## 🔑 Étape préliminaire : Obtenir une API Key

Avant toute synchronisation, vous devez générer une API Key :

```php
use AuthGroups\Models\ApiKey;

$apiKeyResult = ApiKey::generate(
    $userId,
    'Mon Calendrier Personnel',
    [
        'scopes' => ['read', 'write'],
        'environment' => ApiKey::ENV_PRODUCTION,
        'rate_limit_per_minute' => 60
    ]
);

$apiKey = $apiKeyResult['key']; // ag_live_xxxxxxxxxxxxx
```

**Important** : Conservez cette clé en sécurité, elle ne sera affichée qu'une seule fois !

---

## 📅 Google Calendar

### ❌ Limitation : CalDAV non supporté directement

Google Calendar **ne supporte pas** la synchronisation CalDAV bidirectionnelle pour les calendriers externes. Google utilise son propre protocole propriétaire.

### ✅ Solutions alternatives

#### Solution 1 : Export/Import manuel (ICS)

**Export depuis votre API vers Google Calendar** :

1. Générez un lien ICS public pour votre calendrier :

   ```text
   https://votre-domaine.com/cmem2_API/calendars/public/{share_token}
   ```

2. Dans Google Calendar :
   - Cliquez sur le `+` à côté de "Autres agendas"
   - Sélectionnez "À partir d'une URL"
   - Collez l'URL ICS
   - Google rafraîchira automatiquement toutes les quelques heures

**⚠️ Limitation** : Synchronisation unidirectionnelle (lecture seule) et différée.

#### Solution 2 : Utiliser Google Calendar API (bidirectionnel)

Créez un script de synchronisation bidirectionnelle :

```php
<?php
// sync_google_calendar.php

require_once 'vendor/autoload.php';

use Google\Client;
use Google\Service\Calendar;
use ICS\Models\Calendar as LocalCalendar;
use ICS\Models\CalendarEvent;

// Configuration Google
$client = new Client();
$client->setAuthConfig('credentials.json'); // Téléchargé depuis Google Cloud Console
$client->addScope(Calendar::CALENDAR);

// Si pas de token, rediriger vers l'authentification
if (!file_exists('token.json')) {
    $authUrl = $client->createAuthUrl();
    header('Location: ' . filter_var($authUrl, FILTER_SANITIZE_URL));
    exit;
}

$accessToken = json_decode(file_get_contents('token.json'), true);
$client->setAccessToken($accessToken);

// Si token expiré, rafraîchir
if ($client->isAccessTokenExpired()) {
    $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
    file_put_contents('token.json', json_encode($client->getAccessToken()));
}

$service = new Calendar($client);

// Synchroniser depuis votre API vers Google
$localCalendar = new LocalCalendar();
$localEvents = $localCalendar->getEventsByCalendarId($calendarId);

foreach ($localEvents as $localEvent) {
    $googleEvent = new Google\Service\Calendar\Event([
        'summary' => $localEvent['title'],
        'description' => $localEvent['description'],
        'start' => [
            'dateTime' => $localEvent['start_datetime'],
            'timeZone' => 'America/Toronto',
        ],
        'end' => [
            'dateTime' => $localEvent['end_datetime'],
            'timeZone' => 'America/Toronto',
        ],
    ]);
    
    // Vérifier si l'événement existe déjà (via uid)
    $existingEvent = findGoogleEventByUid($service, $googleCalendarId, $localEvent['uid']);
    
    if ($existingEvent) {
        // Mettre à jour
        $service->events->update($googleCalendarId, $existingEvent->getId(), $googleEvent);
    } else {
        // Créer
        $service->events->insert($googleCalendarId, $googleEvent);
    }
}

// Synchroniser depuis Google vers votre API
$googleEvents = $service->events->listEvents($googleCalendarId);

foreach ($googleEvents->getItems() as $googleEvent) {
    $localEvent = CalendarEvent::findByUid($googleEvent->getICalUID());
    
    if (!$localEvent) {
        // Créer dans votre API
        CalendarEvent::create([
            'calendar_id' => $calendarId,
            'title' => $googleEvent->getSummary(),
            'description' => $googleEvent->getDescription(),
            'start_datetime' => $googleEvent->getStart()->getDateTime(),
            'end_datetime' => $googleEvent->getEnd()->getDateTime(),
            'uid' => $googleEvent->getICalUID(),
        ]);
    }
}

echo "Synchronisation terminée !";
```

**Configuration** :

1. Créer un projet dans [Google Cloud Console](https://console.cloud.google.com/)
2. Activer l'API Google Calendar
3. Créer des identifiants OAuth 2.0
4. Télécharger `credentials.json`
5. Exécuter le script : `php sync_google_calendar.php`

#### Solution 3 : Zapier / Make (no-code)

1. Créer un compte sur [Zapier](https://zapier.com/) ou [Make](https://www.make.com/)
2. Créer un "Zap" ou "Scenario" :
   - **Trigger** : Nouvelle modification dans votre API (via webhook ou polling)
   - **Action** : Créer/Modifier événement dans Google Calendar
3. Créer le flux inverse pour la synchronisation bidirectionnelle

---

## 🔐 Proton Calendar

### ✅ Support CalDAV natif

**Bonne nouvelle** : Proton Calendar supporte CalDAV ! Mais uniquement via l'application Proton Calendar Bridge (pour les abonnés Proton Mail Plus/Visionary).

### Configuration avec Proton Calendar Bridge

#### Prérequis

1. Abonnement **Proton Mail Plus** ou supérieur
2. **Proton Calendar Bridge** installé
   - Télécharger depuis : <https://proton.me/mail/bridge>
   - Disponible pour Windows, macOS, Linux

#### Étape 1 : Configurer Proton Calendar Bridge

1. Installer et démarrer Proton Calendar Bridge
2. Se connecter avec votre compte Proton
3. Noter les informations fournies :
   - **Serveur CalDAV** : généralement `127.0.0.1:1043`
   - **Nom d'utilisateur** : votre email Proton
   - **Mot de passe** : généré par Bridge (différent de votre mot de passe Proton)

#### Étape 2 : Synchroniser votre API avec Proton via un client CalDAV

Puisque Proton Calendar Bridge expose un serveur CalDAV local, vous pouvez utiliser un client CalDAV compatible (comme Thunderbird ou DAVx5) pour créer une synchronisation entre les deux calendriers.

##### **Solution recommandée : Thunderbird**

1. **Installer Thunderbird** : <https://www.thunderbird.net/>

2. **Ajouter le calendrier Proton** :
   - Aller dans Calendrier
   - Nouveau calendrier > Sur le réseau > CalDAV
   - URL : `http://127.0.0.1:1043/`
   - Identifiants : ceux fournis par Proton Calendar Bridge

3. **Ajouter votre API CMEM2** :
   - Nouveau calendrier > Sur le réseau > CalDAV
   - URL : `https://votre-domaine.com/cmem2_API/caldav/`
   - Configuration des headers (voir ci-dessous)

#### Configuration des headers pour CMEM2 API dans Thunderbird

Thunderbird ne supporte pas nativement les headers personnalisés comme `X-API-Key`. Il faut utiliser une extension ou un proxy.

##### **Option A : Extension Header Editor**

1. Installer l'extension "Header Editor" pour Thunderbird
2. Ajouter une règle :
   - Type : Request Header
   - Action : Add
   - Header : `X-API-Key`
   - Value : `ag_live_xxxxxxxxxxxxx`
   - URL Pattern : `*cmem2_API/caldav/*`

##### **Option B : Proxy CalDAV local**

Créez un proxy local qui ajoute automatiquement l'API Key :

```php
<?php
// caldav_proxy.php
// À exécuter avec : php -S localhost:8888 caldav_proxy.php

$targetUrl = 'https://votre-domaine.com/cmem2_API/caldav';
$apiKey = 'ag_live_xxxxxxxxxxxxx';

// Récupérer la requête
$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['REQUEST_URI'];
$headers = getallheaders();
$body = file_get_contents('php://input');

// Préparer les headers avec l'API Key
$curlHeaders = [];
foreach ($headers as $key => $value) {
    if (strtolower($key) !== 'host') {
        $curlHeaders[] = "$key: $value";
    }
}
$curlHeaders[] = "X-API-Key: $apiKey";

// Faire la requête vers votre API
$ch = curl_init($targetUrl . $path);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);

if ($body) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

$response = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$responseHeaders = substr($response, 0, $headerSize);
$responseBody = substr($response, $headerSize);

curl_close($ch);

// Retourner la réponse
$headerLines = explode("\r\n", $responseHeaders);
foreach ($headerLines as $header) {
    if (!empty($header) && strpos($header, 'HTTP/') !== 0) {
        header($header);
    }
}

http_response_code(curl_getinfo($ch, CURLINFO_HTTP_CODE));
echo $responseBody;
```

**Utilisation du proxy** :

```bash
# Démarrer le proxy
php -S localhost:8888 caldav_proxy.php

# Dans Thunderbird, utiliser :
# URL : http://localhost:8888/
```

---

## 📱 Applications mobiles compatibles

### Android : DAVx5

**Installation** :

1. Installer [DAVx5](https://play.google.com/store/apps/details?id=at.bitfire.davdroid) depuis Google Play
2. Ouvrir DAVx5 > Ajouter un compte
3. Login avec URL et identifiants
4. URL : `https://votre-domaine.com/cmem2_API/caldav/`
5. Aller dans Paramètres du compte > Headers HTTP personnalisés
6. Ajouter : `X-API-Key: ag_live_xxxxxxxxxxxxx`

**Synchronisation** :

- DAVx5 crée un compte Android qui synchronise avec l'application Calendrier native
- Synchronisation bidirectionnelle automatique
- **Fonctionne aussi avec Proton Calendar Android** si vous utilisez Proton Calendar Bridge

### iOS/macOS : Configuration limitée

Apple Calendar ne supporte pas les headers personnalisés pour CalDAV.

**Solutions** :

1. **Utiliser un proxy local** (comme celui décrit ci-dessus)
2. **Générer un fichier .mobileconfig** :

    ```bash
    curl https://votre-domaine.com/cmem2_API/caldav/mobile-config \
    -H "X-API-Key: ag_live_xxxxxxxxxxxxx" \
    -o calendar.mobileconfig
    ```

   Puis installer le profil sur iOS/macOS (bien que cela ne résout pas le problème des headers).

3. **Alternative** : Utiliser l'app Calendars 5 ou Fantastical (payantes) qui supportent mieux CalDAV

---

## 🔄 Script de synchronisation automatique

Pour maintenir une synchronisation continue, créez un cron job :

```bash
# Synchroniser toutes les 15 minutes
*/15 * * * * php /chemin/vers/sync_calendars.php >> /var/log/calendar_sync.log 2>&1
```

```php
<?php
// sync_calendars.php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/auth_groups/loader.php';

use ICS\Models\Calendar;
use ICS\Models\CalendarEvent;

// Configuration
$googleCalendarId = 'primary'; // ou ID spécifique
$localCalendarId = 1; // ID de votre calendrier dans cmem2

// Logique de synchronisation
try {
    // 1. Récupérer événements modifiés depuis dernière sync
    $lastSync = file_get_contents('/tmp/last_calendar_sync.txt') ?: '2000-01-01 00:00:00';
    
    // 2. Synchroniser local → Google
    $localEvents = CalendarEvent::getModifiedSince($localCalendarId, $lastSync);
    foreach ($localEvents as $event) {
        syncEventToGoogle($event, $googleCalendarId);
    }
    
    // 3. Synchroniser Google → local
    $googleEvents = getGoogleEventsModifiedSince($googleCalendarId, $lastSync);
    foreach ($googleEvents as $event) {
        syncEventToLocal($event, $localCalendarId);
    }
    
    // 4. Enregistrer timestamp de la sync
    file_put_contents('/tmp/last_calendar_sync.txt', date('Y-m-d H:i:s'));
    
    echo "[" . date('Y-m-d H:i:s') . "] Synchronisation réussie\n";
    
} catch (Exception $e) {
    error_log("Erreur de synchronisation : " . $e->getMessage());
    echo "[" . date('Y-m-d H:i:s') . "] ERREUR : " . $e->getMessage() . "\n";
}
```

---

## 📊 Tableau récapitulatif des solutions

| Client | CalDAV natif | Solution | Difficulté | Bidirectionnel |
|--------|-------------|----------|------------|----------------|
| **Google Calendar** | ❌ Non | API Google Calendar | 🟡 Moyen | ✅ Oui |
| **Google Calendar** | ❌ Non | Export ICS | 🟢 Facile | ❌ Non (lecture seule) |
| **Proton Calendar** | ✅ Oui (avec Bridge) | Thunderbird + Proxy | 🟡 Moyen | ✅ Oui |
| **Thunderbird** | ✅ Oui | Direct avec proxy | 🟢 Facile | ✅ Oui |
| **DAVx5 (Android)** | ✅ Oui | Direct avec headers | 🟢 Très facile | ✅ Oui |
| **Apple Calendar** | ✅ Oui | Proxy local | 🔴 Difficile | ✅ Oui |
| **Evolution (Linux)** | ✅ Oui | Direct avec headers | 🟢 Facile | ✅ Oui |

---

## 🎯 Recommandation

Pour une **synchronisation bidirectionnelle optimale** :

### Setup recommandé pour Proton Calendar

```text
Proton Calendar 
    ↕ (CalDAV via Bridge - local)
Thunderbird 
    ↕ (CalDAV via proxy)
Votre API CMEM2
```

### Setup recommandé pour Google Calendar

```text
Google Calendar
    ↕ (API Google Calendar)
Script de sync (cron)
    ↕ (API REST)
Votre API CMEM2
```

---

## 🛠️ Dépannage

### Problème : Headers personnalisés non acceptés

**Solution** : Vérifiez que votre serveur web transmet bien les headers :

```apache
# Apache .htaccess
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
SetEnvIf X-API-Key "(.*)" HTTP_X_API_KEY=$1
```

### Problème : Certificat SSL non reconnu

```php
// Pour dev uniquement - À NE PAS utiliser en production
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
```

### Problème : Rate limiting dépassé

Augmentez la limite pour votre API Key :

```sql
UPDATE api_keys 
SET rate_limit_per_minute = 120 
WHERE id = ?;
```

---

## 📚 Ressources supplémentaires

- [Documentation CalDAV (RFC 4791)](https://datatracker.ietf.org/doc/html/rfc4791)
- [Google Calendar API](https://developers.google.com/calendar)
- [Proton Calendar Bridge](https://proton.me/mail/bridge)
- [DAVx5 Documentation](https://www.davx5.com/manual/)

---

## ✅ Conclusion

Bien que Google Calendar et Proton Calendar ne supportent pas CalDAV de manière standard pour les calendriers externes, il existe plusieurs solutions de contournement efficaces. Pour la meilleure expérience, utilisez :

- **Proton Calendar** : Via Proton Calendar Bridge + client CalDAV compatible
- **Google Calendar** : Script de synchronisation avec Google Calendar API

Les deux solutions permettent une synchronisation bidirectionnelle complète et automatique.
