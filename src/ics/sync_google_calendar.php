<?php
/**
 * Script de synchronisation bidirectionnelle avec Google Calendar
 * 
 * Ce script synchronise automatiquement les événements entre votre API CMEM2
 * et Google Calendar dans les deux sens.
 * 
 * Prérequis :
 *   1. Compte Google Cloud avec API Calendar activée
 *   2. Credentials OAuth 2.0 téléchargés (credentials.json)
 *   3. Installer : composer require google/apiclient:^2.0
 * 
 * Installation :
 *   cd /chemin/vers/cmem2_API
 *   composer require google/apiclient:^2.0
 * 
 * Usage :
 *   php sync_google_calendar.php
 * 
 * Pour automatiser (cron Windows Task Scheduler) :
 *   Créer une tâche planifiée qui exécute toutes les 15 minutes :
 *   php C:\chemin\vers\cmem2_API\src\ics\sync_google_calendar.php
 * 
 * Pour automatiser (cron Linux) :
 *   Ajouter dans crontab -e :
 *   Toutes les 15 minutes : cd /path/to/cmem2_API && php src/ics/sync_google_calendar.php >> logs/calendar_sync.log 2>&1
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../auth_groups/loader.php';

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\EventReminders;
use ICS\Models\Calendar as LocalCalendar;
use ICS\Models\CalendarEvent;
use AuthGroups\Services\LogService;

// ============================================
// CONFIGURATION
// ============================================

// Chemin vers le fichier credentials.json (téléchargé depuis Google Cloud Console)
const CREDENTIALS_PATH = __DIR__ . '/credentials.json';

// Chemin pour stocker le token d'accès
const TOKEN_PATH = __DIR__ . '/token.json';

// Chemin pour stocker le timestamp de la dernière sync
const LAST_SYNC_PATH = __DIR__ . '/last_sync.txt';

// ID du calendrier Google (utilisez 'primary' pour le calendrier principal)
const GOOGLE_CALENDAR_ID = 'primary';

// ID du calendrier local dans votre API CMEM2
const LOCAL_CALENDAR_ID = 1; // À adapter selon votre configuration

// Fuseau horaire
const TIMEZONE = 'America/Toronto';

// ============================================
// FONCTIONS
// ============================================

/**
 * Initialiser le client Google
 */
function getGoogleClient(): Client {
    $client = new Client();
    $client->setApplicationName('CMEM2 Calendar Sync');
    $client->setScopes(Google\Service\Calendar::CALENDAR);
    $client->setAuthConfig(CREDENTIALS_PATH);
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    // Charger le token si disponible
    if (file_exists(TOKEN_PATH)) {
        $accessToken = json_decode(file_get_contents(TOKEN_PATH), true);
        $client->setAccessToken($accessToken);
    }

    // Si le token est expiré, le rafraîchir
    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents(TOKEN_PATH, json_encode($client->getAccessToken()));
        } else {
            // Besoin d'autorisation
            $authUrl = $client->createAuthUrl();
            printf("Ouvrez cette URL dans votre navigateur:\n%s\n", $authUrl);
            print 'Entrez le code de vérification: ';
            $authCode = trim(fgets(STDIN));

            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            $client->setAccessToken($accessToken);

            if (array_key_exists('error', $accessToken)) {
                throw new Exception(join(', ', $accessToken));
            }

            file_put_contents(TOKEN_PATH, json_encode($client->getAccessToken()));
        }
    }

    return $client;
}

/**
 * Obtenir le timestamp de la dernière synchronisation
 */
function getLastSyncTime(): string {
    if (file_exists(LAST_SYNC_PATH)) {
        return trim(file_get_contents(LAST_SYNC_PATH));
    }
    // Par défaut, synchroniser depuis 30 jours
    return date('Y-m-d H:i:s', strtotime('-30 days'));
}

/**
 * Enregistrer le timestamp de la synchronisation
 */
function saveLastSyncTime(): void {
    file_put_contents(LAST_SYNC_PATH, date('Y-m-d H:i:s'));
}

/**
 * Convertir un événement local en événement Google
 */
function localEventToGoogle(array $localEvent): Event {
    $googleEvent = new Event([
        'summary' => $localEvent['title'],
        'description' => $localEvent['description'] ?? '',
        'location' => $localEvent['location'] ?? '',
    ]);

    // Dates
    if ($localEvent['all_day']) {
        $googleEvent->setStart(new EventDateTime([
            'date' => date('Y-m-d', strtotime($localEvent['start_datetime'])),
            'timeZone' => TIMEZONE,
        ]));
        $googleEvent->setEnd(new EventDateTime([
            'date' => date('Y-m-d', strtotime($localEvent['end_datetime'])),
            'timeZone' => TIMEZONE,
        ]));
    } else {
        $googleEvent->setStart(new EventDateTime([
            'dateTime' => date('c', strtotime($localEvent['start_datetime'])),
            'timeZone' => TIMEZONE,
        ]));
        $googleEvent->setEnd(new EventDateTime([
            'dateTime' => date('c', strtotime($localEvent['end_datetime'])),
            'timeZone' => TIMEZONE,
        ]));
    }

    // Rappels
    if (!empty($localEvent['reminder_minutes'])) {
        $googleEvent->setReminders(new EventReminders([
            'useDefault' => false,
            'overrides' => [
                ['method' => 'popup', 'minutes' => $localEvent['reminder_minutes']],
            ],
        ]));
    }

    // Couleur
    if (!empty($localEvent['color'])) {
        $googleEvent->setColorId($localEvent['color']);
    }

    return $googleEvent;
}

/**
 * Convertir un événement Google en événement local
 */
function googleEventToLocal(Event $googleEvent, int $calendarId): array {
    $allDay = false;
    $start = $googleEvent->getStart();
    $end = $googleEvent->getEnd();

    if ($start->date) {
        // Événement toute la journée
        $allDay = true;
        $startDatetime = $start->date . ' 00:00:00';
        $endDatetime = $end->date . ' 23:59:59';
    } else {
        $startDatetime = date('Y-m-d H:i:s', strtotime($start->dateTime));
        $endDatetime = date('Y-m-d H:i:s', strtotime($end->dateTime));
    }

    return [
        'calendar_id' => $calendarId,
        'title' => $googleEvent->getSummary() ?? 'Sans titre',
        'description' => $googleEvent->getDescription() ?? '',
        'location' => $googleEvent->getLocation() ?? '',
        'start_datetime' => $startDatetime,
        'end_datetime' => $endDatetime,
        'all_day' => $allDay,
        'uid' => $googleEvent->getICalUID(),
        'color' => $googleEvent->getColorId(),
    ];
}

/**
 * Trouver un événement Google par UID
 */
function findGoogleEventByUid(Calendar $service, string $calendarId, string $uid): ?Event {
    try {
        $events = $service->events->listEvents($calendarId, [
            'q' => $uid,
            'singleEvents' => true,
        ]);

        foreach ($events->getItems() as $event) {
            if ($event->getICalUID() === $uid) {
                return $event;
            }
        }
    } catch (Exception $e) {
        // Événement non trouvé
    }

    return null;
}

/**
 * Synchroniser depuis l'API locale vers Google Calendar
 */
function syncLocalToGoogle(Calendar $service, string $lastSync): int {
    echo "\n=== Synchronisation Local → Google ===\n";
    
    $db = \Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT * FROM calendar_events 
        WHERE calendar_id = ? 
        AND (updated_at > ? OR created_at > ?)
        ORDER BY updated_at DESC
    ");
    $stmt->execute([LOCAL_CALENDAR_ID, $lastSync, $lastSync]);
    $localEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;
    foreach ($localEvents as $localEvent) {
        try {
            $googleEvent = localEventToGoogle($localEvent);
            $existingEvent = findGoogleEventByUid($service, GOOGLE_CALENDAR_ID, $localEvent['uid']);

            if ($existingEvent) {
                // Mettre à jour
                $service->events->update(GOOGLE_CALENDAR_ID, $existingEvent->getId(), $googleEvent);
                echo "  ✓ Mis à jour: {$localEvent['title']}\n";
            } else {
                // Créer
                $googleEvent->setICalUID($localEvent['uid']);
                $service->events->insert(GOOGLE_CALENDAR_ID, $googleEvent);
                echo "  + Créé: {$localEvent['title']}\n";
            }
            
            $count++;
            LogService::info("Événement synchronisé vers Google", [
                'event_id' => $localEvent['id'],
                'title' => $localEvent['title']
            ]);
            
        } catch (Exception $e) {
            echo "  ✗ Erreur: {$localEvent['title']} - {$e->getMessage()}\n";
            LogService::error("Erreur sync vers Google", [
                'event_id' => $localEvent['id'],
                'error' => $e->getMessage()
            ]);
        }
    }

    echo "Total synchronisé: $count événements\n";
    return $count;
}

/**
 * Synchroniser depuis Google Calendar vers l'API locale
 */
function syncGoogleToLocal(Calendar $service, string $lastSync): int {
    echo "\n=== Synchronisation Google → Local ===\n";
    
    $timeMin = date('c', strtotime($lastSync));
    $events = $service->events->listEvents(GOOGLE_CALENDAR_ID, [
        'updatedMin' => $timeMin,
        'singleEvents' => true,
        'orderBy' => 'updated',
    ]);

    $db = \Database::getInstance()->getConnection();
    $count = 0;

    foreach ($events->getItems() as $googleEvent) {
        try {
            $uid = $googleEvent->getICalUID();
            
            // Chercher l'événement local
            $stmt = $db->prepare("SELECT * FROM calendar_events WHERE uid = ?");
            $stmt->execute([$uid]);
            $localEvent = $stmt->fetch(PDO::FETCH_ASSOC);

            $eventData = googleEventToLocal($googleEvent, LOCAL_CALENDAR_ID);

            if ($localEvent) {
                // Mettre à jour
                $stmt = $db->prepare("
                    UPDATE calendar_events 
                    SET title = ?, description = ?, location = ?,
                        start_datetime = ?, end_datetime = ?, all_day = ?,
                        color = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $eventData['title'],
                    $eventData['description'],
                    $eventData['location'],
                    $eventData['start_datetime'],
                    $eventData['end_datetime'],
                    $eventData['all_day'],
                    $eventData['color'],
                    $localEvent['id']
                ]);
                echo "  ✓ Mis à jour: {$eventData['title']}\n";
            } else {
                // Créer
                $stmt = $db->prepare("
                    INSERT INTO calendar_events 
                    (calendar_id, title, description, location, start_datetime, end_datetime, all_day, uid, color, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $eventData['calendar_id'],
                    $eventData['title'],
                    $eventData['description'],
                    $eventData['location'],
                    $eventData['start_datetime'],
                    $eventData['end_datetime'],
                    $eventData['all_day'],
                    $eventData['uid'],
                    $eventData['color']
                ]);
                echo "  + Créé: {$eventData['title']}\n";
            }
            
            $count++;
            LogService::info("Événement synchronisé depuis Google", [
                'uid' => $uid,
                'title' => $eventData['title']
            ]);
            
        } catch (Exception $e) {
            echo "  ✗ Erreur: {$googleEvent->getSummary()} - {$e->getMessage()}\n";
            LogService::error("Erreur sync depuis Google", [
                'google_event_id' => $googleEvent->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    echo "Total synchronisé: $count événements\n";
    return $count;
}

// ============================================
// SCRIPT PRINCIPAL
// ============================================

try {
    echo "\n╔════════════════════════════════════════════════╗\n";
    echo "║   Synchronisation Google Calendar ↔ CMEM2     ║\n";
    echo "╚════════════════════════════════════════════════╝\n";
    echo "\nDate: " . date('Y-m-d H:i:s') . "\n";

    // Vérifier les prérequis
    if (!file_exists(CREDENTIALS_PATH)) {
        throw new Exception("Fichier credentials.json introuvable. Téléchargez-le depuis Google Cloud Console.");
    }

    // Initialiser le client Google
    echo "\n→ Initialisation du client Google...\n";
    $client = getGoogleClient();
    $service = new Calendar($client);
    echo "✓ Client Google initialisé\n";

    // Obtenir la dernière synchronisation
    $lastSync = getLastSyncTime();
    echo "→ Dernière synchronisation: $lastSync\n";

    // Synchroniser dans les deux sens
    $localToGoogleCount = syncLocalToGoogle($service, $lastSync);
    $googleToLocalCount = syncGoogleToLocal($service, $lastSync);

    // Enregistrer le timestamp
    saveLastSyncTime();

    // Résumé
    echo "\n╔════════════════════════════════════════════════╗\n";
    echo "║              SYNCHRONISATION TERMINÉE          ║\n";
    echo "╚════════════════════════════════════════════════╝\n";
    echo "→ Local → Google: $localToGoogleCount événements\n";
    echo "→ Google → Local: $googleToLocalCount événements\n";
    echo "→ Prochaine sync recommandée dans 15 minutes\n\n";

    LogService::info("Synchronisation Google Calendar terminée", [
        'local_to_google' => $localToGoogleCount,
        'google_to_local' => $googleToLocalCount
    ]);

    exit(0);

} catch (Exception $e) {
    echo "\n❌ ERREUR: " . $e->getMessage() . "\n\n";
    LogService::error("Erreur synchronisation Google Calendar", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    exit(1);
}
