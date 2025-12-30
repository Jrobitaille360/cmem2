<?php

namespace ICS\Services;

use ICS\Models\Calendar;
use ICS\Models\CalendarEvent;
use ICS\Utils\TimezoneHelper;
use AuthGroups\Services\LogService;
use PDO;

/**
 * Serveur CalDAV complet avec synchronisation bidirectionnelle
 * Implémente RFC 4791 (CalDAV) et RFC 4918 (WebDAV)
 */
class CalDAVServer
{
    private $db;
    private $userId;
    private $baseUrl;
    private $principalUrl;

    public function __construct($db, $userId = null)
    {
        $this->db = $db;
        $this->userId = $userId;
        $this->baseUrl = BASE_URL . '/caldav';
        $this->principalUrl = $this->baseUrl . '/principals/' . ($userId ?? 'anonymous');
    }

    /**
     * Gère une requête CalDAV/WebDAV
     */
    public function handleRequest(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        // Retirer le préfixe de base
        $path = str_replace('/cmem2_API/caldav', '', $path);
        
        LogService::info("CalDAV Request", [
            'method' => $method,
            'path' => $path,
            'user_id' => $this->userId
        ]);

        // Router vers la bonne méthode selon le verbe HTTP
        switch ($method) {
            case 'OPTIONS':
                $this->handleOptions();
                break;
            case 'PROPFIND':
                $this->handlePropfind($path);
                break;
            case 'REPORT':
                $this->handleReport($path);
                break;
            case 'GET':
                $this->handleGet($path);
                break;
            case 'PUT':
                $this->handlePut($path);
                break;
            case 'DELETE':
                $this->handleDelete($path);
                break;
            case 'MKCALENDAR':
                $this->handleMkCalendar($path);
                break;
            case 'LOCK':
                $this->handleLock($path);
                break;
            case 'UNLOCK':
                $this->handleUnlock($path);
                break;
            case 'PROPPATCH':
                $this->handleProppatch($path);
                break;
            default:
                $this->sendResponse(501, 'Not Implemented');
        }
    }

    /**
     * Gère OPTIONS - Annonce les capacités CalDAV
     */
    private function handleOptions(): void
    {
        header('Allow: OPTIONS, GET, PUT, DELETE, PROPFIND, REPORT, MKCALENDAR, LOCK, UNLOCK, PROPPATCH');
        header('DAV: 1, 2, calendar-access, calendar-schedule');
        header('Content-Length: 0');
        http_response_code(200);
        exit;
    }

    /**
     * Gère PROPFIND - Découverte de ressources
     */
    private function handlePropfind($path): void
    {
        $depth = $_SERVER['HTTP_DEPTH'] ?? '0';
        $body = file_get_contents('php://input');
        
        // Parser la requête XML
        $xml = simplexml_load_string($body);
        if (!$xml) {
            $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><propfind xmlns="DAV:"><allprop/></propfind>');
        }

        // Analyser le chemin
        $pathParts = array_filter(explode('/', $path));
        
        if (empty($pathParts)) {
            // Root - Liste des calendriers
            $this->propfindRoot($depth);
        } elseif (count($pathParts) == 1) {
            // Calendar collection
            $calendarId = $this->getCalendarIdFromPath($pathParts[0]);
            $this->propfindCalendar($calendarId, $depth);
        } else {
            // Event resource
            $calendarId = $this->getCalendarIdFromPath($pathParts[0]);
            $eventUid = $pathParts[1];
            $this->propfindEvent($calendarId, $eventUid);
        }
    }

    /**
     * PROPFIND sur la racine - Liste les calendriers
     */
    private function propfindRoot($depth): void
    {
        $cal = new Calendar();
        $calendars = $cal->getUserCalendars($this->userId);

        $response = '<?xml version="1.0" encoding="UTF-8"?>';
        $response .= '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/">';
        
        // Réponse pour le principal
        $response .= '<d:response>';
        $response .= '<d:href>' . $this->baseUrl . '/</d:href>';
        $response .= '<d:propstat>';
        $response .= '<d:prop>';
        $response .= '<d:resourcetype><d:collection/></d:resourcetype>';
        $response .= '<d:displayname>Calendars</d:displayname>';
        $response .= '<d:current-user-principal><d:href>' . $this->principalUrl . '</d:href></d:current-user-principal>';
        $response .= '</d:prop>';
        $response .= '<d:status>HTTP/1.1 200 OK</d:status>';
        $response .= '</d:propstat>';
        $response .= '</d:response>';

        // Ajouter chaque calendrier
        if ($depth != '0') {
            foreach ($calendars as $calendar) {
                $response .= $this->buildCalendarResponse($calendar);
            }
        }

        $response .= '</d:multistatus>';

        header('Content-Type: application/xml; charset=utf-8');
        http_response_code(207); // Multi-Status
        echo $response;
        exit;
    }

    /**
     * PROPFIND sur un calendrier - Liste les événements
     */
    private function propfindCalendar($calendarId, $depth): void
    {
        $cal = new Calendar();
        $calendar = $cal->getById($calendarId);

        if (!$calendar) {
            $this->sendResponse(404, 'Not Found');
            return;
        }

        $response = '<?xml version="1.0" encoding="UTF-8"?>';
        $response .= '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/">';
        
        // Réponse pour le calendrier lui-même
        $response .= $this->buildCalendarResponse($calendar);

        // Si depth > 0, inclure les événements
        if ($depth != '0') {
            $events = $cal->getEventsForCalendar($calendarId);
            foreach ($events as $event) {
                $response .= $this->buildEventResponse($calendar, $event);
            }
        }

        $response .= '</d:multistatus>';

        header('Content-Type: application/xml; charset=utf-8');
        http_response_code(207);
        echo $response;
        exit;
    }

    /**
     * PROPFIND sur un événement
     */
    private function propfindEvent($calendarId, $eventUid): void
    {
        $stmt = $this->db->prepare("
            SELECT ce.*, c.share_token, c.title as calendar_title
            FROM calendar_events ce
            JOIN calendars c ON c.id = ce.calendar_id
            WHERE ce.uid = ? AND ce.calendar_id = ? AND ce.deleted_at IS NULL
        ");
        $stmt->execute([$eventUid, $calendarId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            $this->sendResponse(404, 'Not Found');
            return;
        }

        $response = '<?xml version="1.0" encoding="UTF-8"?>';
        $response .= '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">';
        $response .= $this->buildEventResponse(['share_token' => $event['share_token'], 'id' => $calendarId], $event);
        $response .= '</d:multistatus>';

        header('Content-Type: application/xml; charset=utf-8');
        http_response_code(207);
        echo $response;
        exit;
    }

    /**
     * Gère REPORT - Requêtes de calendrier avancées
     */
    private function handleReport($path): void
    {
        $body = file_get_contents('php://input');
        $xml = simplexml_load_string($body);

        if (!$xml) {
            $this->sendResponse(400, 'Bad Request');
            return;
        }

        $reportType = $xml->getName();

        switch ($reportType) {
            case 'calendar-query':
                $this->handleCalendarQuery($path, $xml);
                break;
            case 'calendar-multiget':
                $this->handleCalendarMultiget($path, $xml);
                break;
            case 'sync-collection':
                $this->handleSyncCollection($path, $xml);
                break;
            default:
                $this->sendResponse(501, 'Report Not Implemented');
        }
    }

    /**
     * Gère calendar-query - Recherche d'événements avec filtres
     */
    private function handleCalendarQuery($path, $xml): void
    {
        $calendarId = $this->getCalendarIdFromPath($path);
        $cal = new Calendar();
        
        // Extraire les filtres de temps si présents
        $namespaces = $xml->getNamespaces(true);
        $xml->registerXPathNamespace('c', $namespaces['c'] ?? 'urn:ietf:params:xml:ns:caldav');
        
        $timeRange = $xml->xpath('//c:time-range');
        $startDate = null;
        $endDate = null;
        
        if (!empty($timeRange)) {
            $attrs = $timeRange[0]->attributes();
            $startDate = isset($attrs['start']) ? $this->parseICalDate((string)$attrs['start']) : null;
            $endDate = isset($attrs['end']) ? $this->parseICalDate((string)$attrs['end']) : null;
        }

        $events = $cal->getEventsForCalendar($calendarId, $startDate, $endDate);
        $calendar = $cal->getById($calendarId);

        $response = '<?xml version="1.0" encoding="UTF-8"?>';
        $response .= '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">';
        
        foreach ($events as $event) {
            $response .= $this->buildEventResponse($calendar, $event, true);
        }

        $response .= '</d:multistatus>';

        header('Content-Type: application/xml; charset=utf-8');
        http_response_code(207);
        echo $response;
        exit;
    }

    /**
     * Gère calendar-multiget - Récupérer plusieurs événements
     */
    private function handleCalendarMultiget($path, $xml): void
    {
        $calendarId = $this->getCalendarIdFromPath($path);
        
        $namespaces = $xml->getNamespaces(true);
        $xml->registerXPathNamespace('d', 'DAV:');
        
        $hrefs = $xml->xpath('//d:href');
        
        $response = '<?xml version="1.0" encoding="UTF-8"?>';
        $response .= '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">';
        
        foreach ($hrefs as $href) {
            $eventUid = basename((string)$href, '.ics');
            
            $stmt = $this->db->prepare("
                SELECT ce.*, c.share_token
                FROM calendar_events ce
                JOIN calendars c ON c.id = ce.calendar_id
                WHERE ce.uid = ? AND ce.calendar_id = ? AND ce.deleted_at IS NULL
            ");
            $stmt->execute([$eventUid, $calendarId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($event) {
                $response .= $this->buildEventResponse(['share_token' => $event['share_token'], 'id' => $calendarId], $event, true);
            }
        }

        $response .= '</d:multistatus>';

        header('Content-Type: application/xml; charset=utf-8');
        http_response_code(207);
        echo $response;
        exit;
    }

    /**
     * Gère sync-collection - Synchronisation incrémentale
     */
    private function handleSyncCollection($path, $xml): void
    {
        $calendarId = $this->getCalendarIdFromPath($path);
        
        $namespaces = $xml->getNamespaces(true);
        $xml->registerXPathNamespace('d', 'DAV:');
        
        $syncToken = $xml->xpath('//d:sync-token');
        $oldToken = !empty($syncToken) ? (string)$syncToken[0] : null;
        
        $cal = new Calendar();
        $calendar = $cal->getById($calendarId);
        
        if (!$calendar) {
            $this->sendResponse(404, 'Not Found');
            return;
        }

        // Récupérer les changements depuis le dernier sync
        $changes = $this->getChangesSinceToken($calendarId, $oldToken);
        
        $response = '<?xml version="1.0" encoding="UTF-8"?>';
        $response .= '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">';
        
        foreach ($changes as $change) {
            if ($change['change_type'] == 'deleted') {
                $response .= '<d:response>';
                $response .= '<d:href>' . $this->baseUrl . '/' . $calendar['share_token'] . '/' . $change['uid'] . '.ics</d:href>';
                $response .= '<d:status>HTTP/1.1 404 Not Found</d:status>';
                $response .= '</d:response>';
            } else {
                $response .= $this->buildEventResponse($calendar, $change, true);
            }
        }
        
        $response .= '<d:sync-token>' . htmlspecialchars($calendar['sync_token']) . '</d:sync-token>';
        $response .= '</d:multistatus>';

        header('Content-Type: application/xml; charset=utf-8');
        http_response_code(207);
        echo $response;
        exit;
    }

    /**
     * Gère GET - Récupérer un événement en format iCalendar
     */
    private function handleGet($path): void
    {
        $pathParts = array_filter(explode('/', $path));
        
        if (count($pathParts) < 2) {
            $this->sendResponse(400, 'Bad Request');
            return;
        }

        $calendarId = $this->getCalendarIdFromPath($pathParts[0]);
        $eventUid = basename($pathParts[1], '.ics');

        $stmt = $this->db->prepare("
            SELECT ce.*, c.title as calendar_title, c.timezone
            FROM calendar_events ce
            JOIN calendars c ON c.id = ce.calendar_id
            WHERE ce.uid = ? AND ce.calendar_id = ? AND ce.deleted_at IS NULL
        ");
        $stmt->execute([$eventUid, $calendarId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            $this->sendResponse(404, 'Not Found');
            return;
        }

        $icsContent = $this->generateSingleEventIcs($event);

        header('Content-Type: text/calendar; charset=utf-8');
        header('ETag: "' . $event['etag'] . '"');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($event['last_modified'])) . ' GMT');
        echo $icsContent;
        exit;
    }

    /**
     * Gère PUT - Créer ou mettre à jour un événement
     */
    private function handlePut($path): void
    {
        $pathParts = array_filter(explode('/', $path));
        
        if (count($pathParts) < 2) {
            $this->sendResponse(400, 'Bad Request');
            return;
        }

        $calendarId = $this->getCalendarIdFromPath($pathParts[0]);
        $eventUid = basename($pathParts[1], '.ics');
        
        // Vérifier les permissions
        if (!$this->canModifyCalendar($calendarId)) {
            $this->sendResponse(403, 'Forbidden');
            return;
        }

        $icsContent = file_get_contents('php://input');
        $eventData = $this->parseIcsContent($icsContent);

        if (!$eventData) {
            $this->sendResponse(400, 'Bad Request - Invalid iCalendar data');
            return;
        }

        // Vérifier si l'événement existe
        $stmt = $this->db->prepare("
            SELECT * FROM calendar_events 
            WHERE uid = ? AND calendar_id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$eventUid, $calendarId]);
        $existingEvent = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingEvent) {
            // Vérifier l'ETag pour éviter les conflits
            $ifMatch = $_SERVER['HTTP_IF_MATCH'] ?? null;
            if ($ifMatch && $ifMatch != '"' . $existingEvent['etag'] . '"') {
                $this->sendResponse(412, 'Precondition Failed');
                return;
            }

            // Mise à jour
            $this->updateEventFromIcs($existingEvent['id'], $eventData);
            
            // Récupérer l'etag mis à jour
            $stmt->execute([$eventUid, $calendarId]);
            $updatedEvent = $stmt->fetch(PDO::FETCH_ASSOC);
            
            header('ETag: "' . $updatedEvent['etag'] . '"');
            http_response_code(204); // No Content
        } else {
            // Création
            $eventData['uid'] = $eventUid;
            $eventData['calendar_id'] = $calendarId;
            $eventId = $this->createEventFromIcs($eventData);
            
            $stmt->execute([$eventUid, $calendarId]);
            $newEvent = $stmt->fetch(PDO::FETCH_ASSOC);
            
            header('ETag: "' . $newEvent['etag'] . '"');
            http_response_code(201); // Created
        }
        
        exit;
    }

    /**
     * Gère DELETE - Supprimer un événement
     */
    private function handleDelete($path): void
    {
        $pathParts = array_filter(explode('/', $path));
        
        if (count($pathParts) < 2) {
            $this->sendResponse(400, 'Bad Request');
            return;
        }

        $calendarId = $this->getCalendarIdFromPath($pathParts[0]);
        $eventUid = basename($pathParts[1], '.ics');
        
        if (!$this->canModifyCalendar($calendarId)) {
            $this->sendResponse(403, 'Forbidden');
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE calendar_events 
            SET deleted_at = NOW()
            WHERE uid = ? AND calendar_id = ? AND deleted_at IS NULL
        ");
        $result = $stmt->execute([$eventUid, $calendarId]);

        if ($stmt->rowCount() > 0) {
            // Enregistrer le changement
            $this->logChange($calendarId, null, 'deleted', $eventUid);
            http_response_code(204); // No Content
        } else {
            $this->sendResponse(404, 'Not Found');
        }
        exit;
    }

    /**
     * Gère MKCALENDAR - Créer un nouveau calendrier
     */
    private function handleMkCalendar($path): void
    {
        $body = file_get_contents('php://input');
        $xml = simplexml_load_string($body);

        $displayname = 'New Calendar';
        $description = '';
        $color = '#3174ad';

        if ($xml) {
            $namespaces = $xml->getNamespaces(true);
            $xml->registerXPathNamespace('d', 'DAV:');
            $xml->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
            
            $dn = $xml->xpath('//d:displayname');
            if (!empty($dn)) $displayname = (string)$dn[0];
            
            $desc = $xml->xpath('//c:calendar-description');
            if (!empty($desc)) $description = (string)$desc[0];
        }

        $cal = new Calendar();
        $cal->userId = $this->userId;
        $cal->title = $displayname;
        $cal->description = $description;
        $cal->color = $color;
        $cal->visibility = 'private';

        $result = $cal->create();

        http_response_code(201); // Created
        header('Location: ' . $this->baseUrl . '/' . $result['share_token'] . '/');
        exit;
    }

    /**
     * Gère LOCK - Verrouiller une ressource
     */
    private function handleLock($path): void
    {
        $body = file_get_contents('php://input');
        $timeout = $_SERVER['HTTP_TIMEOUT'] ?? 'Second-3600';
        
        // Extraire le timeout en secondes
        preg_match('/Second-(\d+)/', $timeout, $matches);
        $timeoutSeconds = isset($matches[1]) ? (int)$matches[1] : 3600;
        
        $lockToken = 'opaquelocktoken:' . bin2hex(random_bytes(16));
        
        // Créer le verrou dans la base de données
        $stmt = $this->db->prepare("
            INSERT INTO caldav_locks (resource_path, lock_token, lock_scope, lock_type, timeout, expires_at)
            VALUES (?, ?, 'exclusive', 'write', ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
        ");
        $stmt->execute([$path, $lockToken, $timeoutSeconds, $timeoutSeconds]);

        $response = '<?xml version="1.0" encoding="UTF-8"?>';
        $response .= '<d:prop xmlns:d="DAV:">';
        $response .= '<d:lockdiscovery><d:activelock>';
        $response .= '<d:locktype><d:write/></d:locktype>';
        $response .= '<d:lockscope><d:exclusive/></d:lockscope>';
        $response .= '<d:depth>0</d:depth>';
        $response .= '<d:timeout>Second-' . $timeoutSeconds . '</d:timeout>';
        $response .= '<d:locktoken><d:href>' . $lockToken . '</d:href></d:locktoken>';
        $response .= '</d:activelock></d:lockdiscovery>';
        $response .= '</d:prop>';

        header('Content-Type: application/xml; charset=utf-8');
        header('Lock-Token: <' . $lockToken . '>');
        http_response_code(200);
        echo $response;
        exit;
    }

    /**
     * Gère UNLOCK - Déverrouiller une ressource
     */
    private function handleUnlock($path): void
    {
        $lockToken = $_SERVER['HTTP_LOCK_TOKEN'] ?? '';
        $lockToken = trim($lockToken, '<>');

        $stmt = $this->db->prepare("
            DELETE FROM caldav_locks 
            WHERE resource_path = ? AND lock_token = ?
        ");
        $stmt->execute([$path, $lockToken]);

        if ($stmt->rowCount() > 0) {
            http_response_code(204); // No Content
        } else {
            $this->sendResponse(409, 'Conflict - Lock not found');
        }
        exit;
    }

    /**
     * Gère PROPPATCH - Modifier les propriétés
     */
    private function handleProppatch($path): void
    {
        // Pour l'instant, on accepte mais on ne fait rien
        $response = '<?xml version="1.0" encoding="UTF-8"?>';
        $response .= '<d:multistatus xmlns:d="DAV:">';
        $response .= '<d:response>';
        $response .= '<d:href>' . htmlspecialchars($path) . '</d:href>';
        $response .= '<d:propstat>';
        $response .= '<d:status>HTTP/1.1 200 OK</d:status>';
        $response .= '</d:propstat>';
        $response .= '</d:response>';
        $response .= '</d:multistatus>';

        header('Content-Type: application/xml; charset=utf-8');
        http_response_code(207);
        echo $response;
        exit;
    }

    // ============ Méthodes utilitaires ============

    private function buildCalendarResponse($calendar): string
    {
        $href = $this->baseUrl . '/' . $calendar['share_token'] . '/';
        
        $response = '<d:response>';
        $response .= '<d:href>' . htmlspecialchars($href) . '</d:href>';
        $response .= '<d:propstat>';
        $response .= '<d:prop>';
        $response .= '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype>';
        $response .= '<d:displayname>' . htmlspecialchars($calendar['title']) . '</d:displayname>';
        $response .= '<c:calendar-description>' . htmlspecialchars($calendar['description'] ?? '') . '</c:calendar-description>';
        $response .= '<c:calendar-timezone>' . htmlspecialchars($calendar['timezone']) . '</c:calendar-timezone>';
        $response .= '<c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>';
        $response .= '<cs:getctag>' . htmlspecialchars($calendar['ctag'] ?? '') . '</cs:getctag>';
        $response .= '<d:sync-token>' . htmlspecialchars($calendar['sync_token'] ?? '') . '</d:sync-token>';
        $response .= '<d:owner><d:href>' . $this->principalUrl . '</d:href></d:owner>';
        $response .= '</d:prop>';
        $response .= '<d:status>HTTP/1.1 200 OK</d:status>';
        $response .= '</d:propstat>';
        $response .= '</d:response>';
        
        return $response;
    }

    private function buildEventResponse($calendar, $event, $includeCalendarData = false): string
    {
        $href = $this->baseUrl . '/' . $calendar['share_token'] . '/' . $event['uid'] . '.ics';
        
        $response = '<d:response>';
        $response .= '<d:href>' . htmlspecialchars($href) . '</d:href>';
        $response .= '<d:propstat>';
        $response .= '<d:prop>';
        $response .= '<d:getetag>"' . htmlspecialchars($event['etag']) . '"</d:getetag>';
        $response .= '<d:getcontenttype>text/calendar; component=vevent</d:getcontenttype>';
        
        if ($includeCalendarData) {
            $icsData = $this->generateSingleEventIcs($event);
            $response .= '<c:calendar-data>' . htmlspecialchars($icsData) . '</c:calendar-data>';
        }
        
        $response .= '</d:prop>';
        $response .= '<d:status>HTTP/1.1 200 OK</d:status>';
        $response .= '</d:propstat>';
        $response .= '</d:response>';
        
        return $response;
    }

    private function generateSingleEventIcs($event): string
    {
        // Récupérer le timezone du calendrier
        $calendarTimezone = 'America/Montreal';
        if (isset($event['calendar_id'])) {
            $stmt = $this->db->prepare("SELECT timezone FROM calendars WHERE id = ?");
            $stmt->execute([$event['calendar_id']]);
            $cal = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cal && !empty($cal['timezone'])) {
                $calendarTimezone = $cal['timezone'];
            }
        }
        
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//CMEM2//CalDAV Server//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        
        // Ajouter VTIMEZONE
        $ics .= TimezoneHelper::generateVTimezone($calendarTimezone);
        
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:" . $event['uid'] . "\r\n";
        $ics .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        
        if ($event['all_day']) {
            $ics .= "DTSTART;VALUE=DATE:" . date('Ymd', strtotime($event['start_datetime'])) . "\r\n";
            $ics .= "DTEND;VALUE=DATE:" . date('Ymd', strtotime($event['end_datetime'])) . "\r\n";
        } else {
            $ics .= "DTSTART:" . TimezoneHelper::toICalDateTimeUTC($event['start_datetime'], $calendarTimezone) . "\r\n";
            $ics .= "DTEND:" . TimezoneHelper::toICalDateTimeUTC($event['end_datetime'], $calendarTimezone) . "\r\n";
        }
        
        $ics .= "SUMMARY:" . TimezoneHelper::escapeIcsText($event['title']) . "\r\n";
        
        if (!empty($event['description'])) {
            $ics .= "DESCRIPTION:" . TimezoneHelper::escapeIcsText($event['description']) . "\r\n";
        }
        
        if (!empty($event['location'])) {
            $ics .= "LOCATION:" . TimezoneHelper::escapeIcsText($event['location']) . "\r\n";
        }
        
        $ics .= "STATUS:" . strtoupper($event['status']) . "\r\n";
        $ics .= "SEQUENCE:" . ($event['sequence'] ?? 0) . "\r\n";
        
        if (!empty($event['last_modified'])) {
            $ics .= "LAST-MODIFIED:" . TimezoneHelper::toICalDateTimeUTC($event['last_modified'], $calendarTimezone) . "\r\n";
        }
        
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";
        
        return $ics;
    }

    private function parseIcsContent($icsContent): ?array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $icsContent));
        $eventData = [];
        $inEvent = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if ($line == 'BEGIN:VEVENT') {
                $inEvent = true;
                continue;
            }
            
            if ($line == 'END:VEVENT') {
                break;
            }
            
            if (!$inEvent) continue;
            
            if (strpos($line, ':') === false) continue;
            
            list($prop, $value) = explode(':', $line, 2);
            
            // Gérer les paramètres (ex: DTSTART;VALUE=DATE)
            if (strpos($prop, ';') !== false) {
                $prop = explode(';', $prop)[0];
            }
            
            switch ($prop) {
                case 'UID':
                    $eventData['uid'] = $value;
                    break;
                case 'SUMMARY':
                    $eventData['title'] = $this->unescapeIcsString($value);
                    break;
                case 'DESCRIPTION':
                    $eventData['description'] = $this->unescapeIcsString($value);
                    break;
                case 'DTSTART':
                    $eventData['start_datetime'] = $this->parseICalDate($value);
                    $eventData['all_day'] = (strlen($value) == 8);
                    break;
                case 'DTEND':
                    $eventData['end_datetime'] = $this->parseICalDate($value);
                    break;
                case 'LOCATION':
                    $eventData['location'] = $this->unescapeIcsString($value);
                    break;
                case 'STATUS':
                    $eventData['status'] = strtolower($value);
                    break;
                case 'SEQUENCE':
                    $eventData['sequence'] = (int)$value;
                    break;
            }
        }
        
        return !empty($eventData) ? $eventData : null;
    }

    private function parseICalDate($dateString): string
    {
        // Utiliser TimezoneHelper pour le parsing
        return TimezoneHelper::fromICalDateTime($dateString, 'America/Montreal');
    }

    private function escapeIcsString($string): string
    {
        return TimezoneHelper::escapeIcsText($string);
    }

    private function unescapeIcsString($string): string
    {
        return TimezoneHelper::unescapeIcsText($string);
    }

    private function getCalendarIdFromPath($pathSegment): ?int
    {
        // Le pathSegment est le share_token
        $stmt = $this->db->prepare("
            SELECT id FROM calendars WHERE share_token = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$pathSegment]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['id'] : null;
    }

    private function canModifyCalendar($calendarId): bool
    {
        if (!$this->userId) return false;
        
        $stmt = $this->db->prepare("
            SELECT c.user_id, cs.permission
            FROM calendars c
            LEFT JOIN calendar_shares cs ON c.id = cs.calendar_id 
                AND cs.shared_with_user_id = ? AND cs.deleted_at IS NULL
            WHERE c.id = ? AND c.deleted_at IS NULL
        ");
        $stmt->execute([$this->userId, $calendarId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) return false;
        
        // Propriétaire ou permission write
        return $result['user_id'] == $this->userId || $result['permission'] == 'write';
    }

    private function createEventFromIcs($eventData): int
    {
        $event = new CalendarEvent();
        $event->calendarId = $eventData['calendar_id'];
        $event->title = $eventData['title'] ?? 'Untitled Event';
        $event->description = $eventData['description'] ?? null;
        $event->startDatetime = $eventData['start_datetime'];
        $event->endDatetime = $eventData['end_datetime'];
        $event->allDay = $eventData['all_day'] ?? false;
        $event->location = $eventData['location'] ?? null;
        $event->status = $eventData['status'] ?? 'confirmed';
        
        // Forcer l'UID si fourni
        if (!empty($eventData['uid'])) {
            $stmt = $this->db->prepare("
                INSERT INTO calendar_events (
                    calendar_id, title, description, start_datetime, end_datetime,
                    all_day, location, status, uid, sequence
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $event->calendarId,
                $event->title,
                $event->description,
                $event->startDatetime,
                $event->endDatetime,
                $event->allDay ? 1 : 0,
                $event->location,
                $event->status,
                $eventData['uid'],
                $eventData['sequence'] ?? 0
            ]);
            
            $eventId = $this->db->lastInsertId();
        } else {
            $result = $event->create();
            $eventId = $result['id'];
        }
        
        $this->logChange($eventData['calendar_id'], $eventId, 'created');
        
        return $eventId;
    }

    private function updateEventFromIcs($eventId, $eventData): void
    {
        $stmt = $this->db->prepare("
            UPDATE calendar_events 
            SET title = ?, description = ?, start_datetime = ?, end_datetime = ?,
                all_day = ?, location = ?, status = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $eventData['title'] ?? 'Untitled Event',
            $eventData['description'] ?? null,
            $eventData['start_datetime'],
            $eventData['end_datetime'],
            ($eventData['all_day'] ?? false) ? 1 : 0,
            $eventData['location'] ?? null,
            $eventData['status'] ?? 'confirmed',
            $eventId
        ]);
        
        // Récupérer le calendar_id
        $stmt = $this->db->prepare("SELECT calendar_id FROM calendar_events WHERE id = ?");
        $stmt->execute([$eventId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $this->logChange($result['calendar_id'], $eventId, 'updated');
        }
    }

    private function logChange($calendarId, $eventId, $changeType, $eventUid = null): void
    {
        $cal = new Calendar();
        $calendar = $cal->getById($calendarId);
        
        if (!$calendar) return;
        
        $stmt = $this->db->prepare("
            INSERT INTO caldav_sync_log (calendar_id, event_id, change_type, sync_token, user_id, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $calendarId,
            $eventId,
            $changeType,
            $calendar['sync_token'],
            $this->userId,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }

    private function getChangesSinceToken($calendarId, $oldToken): array
    {
        if (!$oldToken) {
            // Premier sync, retourner tous les événements
            $stmt = $this->db->prepare("
                SELECT *, 'updated' as change_type
                FROM calendar_events 
                WHERE calendar_id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$calendarId]);
        } else {
            // Sync incrémental
            $stmt = $this->db->prepare("
                SELECT sl.change_type, ce.*, sl.sync_token
                FROM caldav_sync_log sl
                LEFT JOIN calendar_events ce ON sl.event_id = ce.id
                WHERE sl.calendar_id = ? 
                  AND sl.sync_token > ?
                ORDER BY sl.changed_at ASC
            ");
            $stmt->execute([$calendarId, $oldToken]);
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function sendResponse($code, $message): void
    {
        http_response_code($code);
        echo $message;
        exit;
    }
}
