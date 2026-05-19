<?php

namespace ICS\Models;

use AuthGroups\Models\BaseModel;
use AuthGroups\Services\LogService;
use PDO;

class CalendarEvent extends BaseModel
{
    protected $table = 'calendar_events';
    
    public $id;
    public $calendarId;
    public $userId;
    public $title;
    public $description;
    public $startDatetime;
    public $endDatetime;
    public $allDay;
    public $location;
    public $attendees;
    public $recurrenceRule;
    public $status;
    public $timezone;
    public $meetingLink;
    public $notifications;
    public $color;
    public $createdAt;
    public $updatedAt;
    // Phase 2 — propriétés VEVENT
    public $priority;    // 2.2 — TINYINT 0–9
    public $class;       // 2.3 — PUBLIC|PRIVATE|CONFIDENTIAL
    public $transp;      // 2.4 — OPAQUE|TRANSPARENT
    public $categories;  // 2.1 — JSON array de chaînes
    public $geoLat;      // 2.5 — DECIMAL(10,7)
    public $geoLng;      // 2.5 — DECIMAL(10,7)
    public $attachments; // 2.6 — JSON array [{url, mime_type}]
    // Phase 3 — ATTENDEE & ORGANIZER
    public $organizerEmail; // 3.2 — override email de l'organisateur (déduit de user_id si absent)
    public $organizerName;  // 3.2 — CN de l'organisateur
    // Phase 4 — Récurrence avancée & VALARM
    public $rdate;      // 4.2 — TEXT, ISO datetimes locales CSV (ex: 2026-04-15 14:00:00,2026-04-22 14:00:00)
    public $relatedTo;  // 4.3 — VARCHAR(255), UID de l'événement parent
    public $duration;   // 4.5 — VARCHAR(20), format ISO 8601 (ex: PT1H30M) — exclusif avec end_datetime
    public $uid;        // UID ICS optionnel (RFC 5545 §3.8.4.7) — préservé lors de l'import

    public function __construct() {
        parent::__construct();
    }

    /**
     * Crée un nouvel événement dans un calendrier
     */
    public function create(): array
    {

        try {
            $query = "INSERT INTO calendar_events (
                    calendar_id, user_id, title, description, start_datetime, end_datetime,
                    all_day, location, attendees, recurrence_rule, status,
                    timezone, meeting_link, notifications, color, uid,
                    priority, class, transp, categories, geo_lat, geo_lng, attachments,
                    organizer_email, organizer_name,
                    rdate, related_to, duration
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stmt = $this->getDb()->prepare($query);

            // Préparer les données
            $notificationsJson = null;
            if (isset($this->notifications)) {
                $notificationsJson = is_string($this->notifications)
                    ? $this->notifications
                    : json_encode($this->notifications);
            }

            // UID stable RFC-conforme (RFC 5545 §3.8.4.7) — item 1.3
            // Si un uid ICS valide a été fourni (import), on le préserve ; sinon on génère un UUID v4
            $uid = (!empty($this->uid) && self::isValidUid($this->uid))
                ? $this->uid
                : self::generateUuidV4();

            $stmt->execute([
                $this->calendarId,
                $this->userId,
                $this->title,
                $this->description ?? null,
                $this->startDatetime,
                $this->endDatetime,
                $this->allDay ? 1 : 0,
                $this->location ?? null,
                $this->attendees ? json_encode($this->attendees) : null,
                $this->recurrenceRule ?? null,
                $this->status ?? 'confirmed',
                $this->timezone ?? 'America/Montreal',
                $this->meetingLink ?? null,
                $notificationsJson,
                $this->color ?? null,
                $uid,
                // Phase 2
                $this->priority ?? 0,
                $this->class ?? 'PUBLIC',
                $this->transp ?? 'OPAQUE',
                isset($this->categories) ? json_encode($this->categories) : null,
                $this->geoLat ?? null,
                $this->geoLng ?? null,
                isset($this->attachments) ? json_encode($this->attachments) : null,
                // Phase 3
                $this->organizerEmail ?? null,
                $this->organizerName  ?? null,
                // Phase 4
                $this->rdate     ?? null,
                $this->relatedTo ?? null,
                $this->duration  ?? null,
            ]);

            $eventId = $this->getDb()->lastInsertId();

            LogService::info("Événement créé", [
                'event_id' => $eventId,
                'calendar_id' => $this->calendarId,
                'title' => $this->title
            ]); 
            
            $result = [
                'id' => $eventId,
                'calendar_id' => $this->calendarId,
                'title' => $this->title,
                'start_datetime' => $this->startDatetime,
                'end_datetime' => $this->endDatetime,
                'all_day' => $this->allDay ? 1 : 0,
                'status' => $this->status ?? 'confirmed',
                'timezone' => $this->timezone ?? 'America/Montreal',
                'meeting_link' => $this->meetingLink ?? null,
                'notifications' => $this->notifications ?? null,
                'color' => $this->color ?? null,
                'recurrence_rule' => $this->recurrenceRule ?? null,
                'uid' => $uid,
                'attendees' => $this->attendees ?? null,
                'organizer_email' => $this->organizerEmail ?? null,
                'organizer_name'  => $this->organizerName  ?? null,
            ];
            
            // Générer les occurrences si c'est un événement récurrent
            if (!empty($this->recurrenceRule)) {
                \ICS\Services\RecurrenceService::generateAllOccurrences($result);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la création de l'événement", [
                'calendar_id' => $this->calendarId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Récupère un événement par son ID
     */
    public function getEventById($eventId): ?array
    {
        $query = "SELECT * FROM calendar_events WHERE id = ? AND deleted_at IS NULL";
        $stmt = $this->getDb()->prepare($query);
        $stmt->execute([$eventId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $this->castEvent($result) : null;
    }

    private function castEvent(array $row): array
    {
        if (isset($row['priority']))    $row['priority']    = (int) $row['priority'];
        if (isset($row['all_day']))     $row['all_day']     = (bool) $row['all_day'];
        if (isset($row['categories']) && is_string($row['categories']))
            $row['categories'] = json_decode($row['categories'], true) ?? [];
        if (isset($row['attachments']) && is_string($row['attachments']))
            $row['attachments'] = json_decode($row['attachments'], true) ?? [];
        if (isset($row['attendees']) && is_string($row['attendees']))
            $row['attendees'] = json_decode($row['attendees'], true) ?? [];
        if (isset($row['notifications']) && is_string($row['notifications']))
            $row['notifications'] = json_decode($row['notifications'], true) ?? [];
        return $row;
    }  

    /**
     * Récupère tous les événements d'un calendrier
     * si $expandRecurrence est true, les événements récurrents sont développés en occurrences.
     * si $expandMultiJour est true, les événements multi-jours sont développés en occurrences journalières.
     * Utilise la table event_occurrences pour les événements récurrents (performant)
     */
    public function getByCalendarId($calendarId, $startDatePeriod = null, $endDatePeriode = null, $expandRecurrence = true, $lastUpdateAfter = null, $expandMultiJour = true, $limit = 100): array
    {
        $query = "SELECT * FROM calendar_events WHERE calendar_id = ? AND deleted_at IS NULL";
        $params = [$calendarId];
        
        if ($lastUpdateAfter) {
            $query .= " AND updated_at >= ?";
            $params[] = $lastUpdateAfter;
        }
        
        $query .= " ORDER BY start_datetime ASC";
        
        $stmt = $this->getDb()->prepare($query);
        $stmt->execute($params);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($events)) {
            return [];
        }
        $expandedEvents = [];
        // traiter les évenements multi-jours
        if( $expandMultiJour=="true" || $expandMultiJour===true )   {
            foreach ($events as $event) {
                if( date('Y-m-d', strtotime($event['start_datetime'])) !== date('Y-m-d', strtotime($event['end_datetime']))) {
                    $dayOccurrences = \ICS\Services\RecurrenceService::expandOneDay($event, $startDatePeriod, $endDatePeriode, $limit);
                    $expandedEvents = array_merge($expandedEvents, $dayOccurrences);
                }
                else {
                    $expandedEvents[] = $event;
                }
            }
        } else {
            $expandedEvents = $events;
        }

        if( !$expandRecurrence || $expandRecurrence !== "true" ) {
            return $expandedEvents;
        } 

        // Séparer les événements récurrents et non-récurrents
        $recurringEvents = array_filter($expandedEvents, function($event) {
            return !empty($event['recurrence_rule']);
        });
        
   
        $nonRecurringEvents = array_filter($expandedEvents,  function($event) {
            return empty($event['recurrence_rule']);
        });

        $allEvents = $nonRecurringEvents;
        // ICI 
        // Utiliser les occurrences pré-calculées pour les événements récurrents
        if ($expandRecurrence && !empty($recurringEvents)) {
            $occurrences = \ICS\Models\EventOccurrence::getByCalendarId($calendarId, $startDatePeriod, $endDatePeriode);
            
            // Fusionner les occurrences avec les données des événements parents
            $eventById = [];
            foreach ($recurringEvents as $event) {
                $eventById[$event['id']] = $event;
            }
            
            foreach ($occurrences as $occurrence) {
                if (isset($eventById[$occurrence['event_id']])) {
                    $parentEvent = $eventById[$occurrence['event_id']];
                    
                    // Créer l'événement expandé avec les données de l'occurrence
                    $expandedEvent = $parentEvent;
                    
                    // Utiliser les dates modifiées si disponibles, sinon les dates de l'occurrence
                    $expandedEvent['start_datetime'] = $occurrence['modified_start_datetime'] ?? $occurrence['start_datetime'];
                    $expandedEvent['end_datetime'] = $occurrence['modified_end_datetime'] ?? $occurrence['end_datetime'];
                    
                    // Appliquer les modifications si présentes
                    if ($occurrence['is_modified']) {
                        if ($occurrence['modified_title']) {
                            $expandedEvent['title'] = $occurrence['modified_title'];
                        }
                        if ($occurrence['modified_description']) {
                            $expandedEvent['description'] = $occurrence['modified_description'];
                        }
                        if ($occurrence['modified_location']) {
                            $expandedEvent['location'] = $occurrence['modified_location'];
                        }
                    }
                    
                    // Ajouter les métadonnées d'occurrence
                    $expandedEvent['occurrence_id'] = $occurrence['id'];
                    $expandedEvent['occurrence_date'] = $occurrence['occurrence_date'];
                    $expandedEvent['recurrence_index'] = $occurrence['recurrence_index'];
                    $expandedEvent['is_recurring'] = true;
                    $expandedEvent['parent_event_id'] = $occurrence['event_id'];
                    $expandedEvent['is_occurrence_modified'] = (bool)$occurrence['is_modified'];
                    $expandedEvent['is_occurrence_cancelled'] = (bool)$occurrence['is_cancelled'];
                    
                    $allEvents[] = $expandedEvent;
                }
            }
        }

        // Filtrer par période si spécifié
        if ($startDatePeriod || $endDatePeriode) {
            $startDate = $startDatePeriod ? new \DateTime($startDatePeriod) : null;
            $endDate = $endDatePeriode ? new \DateTime($endDatePeriode) : null;
            
            foreach ($allEvents as $key => $event) {
                $eventStart = new \DateTime($event['start_datetime']);
                $eventEnd = new \DateTime($event['end_datetime']);
                
                if ($startDate && $eventEnd < $startDate) {
                    unset($allEvents[$key]);
                    continue;
                }
                if ($endDate && $eventStart > $endDate) {
                    unset($allEvents[$key]);
                    continue;
                }
            }
        }

        // Trier par date de début
        usort($allEvents, function($a, $b) {
            return strcmp($a['start_datetime'], $b['start_datetime']);
        });

        // Limiter le nombre de résultats
        if (count($allEvents) > $limit) {
            $allEvents = array_slice($allEvents, 0, $limit);
        }

        return $allEvents;
    }

    /**
     * Met à jour un événement
     */
    public function update(): bool
    {
        try {
            $fields = [];
            $values = [];
            
            if(isset($this->title)) {
                $fields[] = "title = ?";
                $values[] = $this->title;
            }

            if(isset($this->description)) {
                $fields[] = "description = ?";
                $values[] = $this->description;
            }

            if(isset($this->startDatetime)) {
                $fields[] = "start_datetime = ?";
                $values[] = $this->startDatetime;
            }

            if(isset($this->endDatetime)) {
                $fields[] = "end_datetime = ?";
                $values[] = $this->endDatetime;
            }

            if(isset($this->allDay)) {
                $fields[] = "all_day = ?";
                $values[] = (int)$this->allDay;
            }

            if(isset($this->location)) {
                $fields[] = "location = ?";
                $values[] = $this->location;
            }

            if(isset($this->userId)) {
                $fields[] = "user_id = ?";
                $values[] = $this->userId;
            }

            if(isset($this->attendees)) {
                $fields[] = "attendees = ?";
                $values[] = json_encode($this->attendees);
            }
            if(isset($this->recurrenceRule)) {
                $fields[] = "recurrence_rule = ?";
                $values[] = $this->recurrenceRule;
            }
            if(isset($this->status)) {
                $fields[] = "status = ?";
                $values[] = $this->status;
            }
            
            if(isset($this->timezone)) {
                $fields[] = "timezone = ?";
                $values[] = $this->timezone;
            }
            
            if(isset($this->meetingLink)) {
                $fields[] = "meeting_link = ?";
                $values[] = $this->meetingLink;
            }
            
            if(isset($this->notifications)) {
                $fields[] = "notifications = ?";
                $notificationsJson = is_string($this->notifications) 
                    ? $this->notifications 
                    : json_encode($this->notifications);
                $values[] = $notificationsJson;
            }
            
            if(isset($this->color)) {
                $fields[] = "color = ?";
                $values[] = $this->color;
            }

            // Phase 2
            if (isset($this->priority)) {
                $fields[] = "priority = ?";
                $values[] = (int)$this->priority;
            }
            if (isset($this->class)) {
                $fields[] = "class = ?";
                $values[] = $this->class;
            }
            if (isset($this->transp)) {
                $fields[] = "transp = ?";
                $values[] = $this->transp;
            }
            if (isset($this->categories)) {
                $fields[] = "categories = ?";
                $values[] = is_array($this->categories)
                    ? json_encode($this->categories)
                    : $this->categories;
            }
            if (isset($this->geoLat)) {
                $fields[] = "geo_lat = ?";
                $values[] = $this->geoLat;
            }
            if (isset($this->geoLng)) {
                $fields[] = "geo_lng = ?";
                $values[] = $this->geoLng;
            }
            if (isset($this->attachments)) {
                $fields[] = "attachments = ?";
                $values[] = is_array($this->attachments)
                    ? json_encode($this->attachments)
                    : $this->attachments;
            }
            // Phase 3.2 — ORGANIZER
            if (isset($this->organizerEmail)) {
                $fields[] = "organizer_email = ?";
                $values[] = $this->organizerEmail;
            }
            if (isset($this->organizerName)) {
                $fields[] = "organizer_name = ?";
                $values[] = $this->organizerName;
            }
            // Phase 4 — Récurrence avancée
            if (isset($this->rdate)) {
                $fields[] = "rdate = ?";
                $values[] = $this->rdate;
            }
            if (isset($this->relatedTo)) {
                $fields[] = "related_to = ?";
                $values[] = $this->relatedTo;
            }
            if (isset($this->duration)) {
                $fields[] = "duration = ?";
                $values[] = $this->duration;
            }

            if (empty($fields)) {
                return false;
            }
            
            $fields[] = "updated_at = CURRENT_TIMESTAMP";
            $values[] = $this->id;
            
            $query = "UPDATE calendar_events SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $this->getDb()->prepare($query);
            
            $result = $stmt->execute($values);
            
            LogService::info("Événement mis à jour", [
                'event_id' => $this->id
            ]);
            
            // Régénérer les occurrences si l'événement est récurrent et que des champs affectant les occurrences ont été modifiés
            $event = $this->getEventById($this->id);
            if ($event && (!empty($event['recurrence_rule']) || isset($this->recurrenceRule) || isset($this->startDatetime) || isset($this->endDatetime))) {
                \ICS\Services\RecurrenceService::generateAllOccurrences($event);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la mise à jour de l'événement", [
                'event_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Génère un UUID v4 RFC 4122 pour le champ uid (item 1.3).
     */
    private static function generateUuidV4(): string
    {
        $data     = random_bytes(16);
        $data[6]  = chr(ord($data[6])  & 0x0f | 0x40); // version 4
        $data[8]  = chr(ord($data[8])  & 0x3f | 0x80); // variante RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function isValidUid(string $uid): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uid
        );
    }

    public static function isValidRecurrenceRule($rule): bool
    {
        // ref : https://icalendar.org/iCalendar-RFC-5545/3-3-10-recurrence-rule.html

        if (empty($rule)) {
            return true; // Pas de règle = valide
        }
        
        // Séparer les composants de la règle
        $components = explode(';', $rule);
        $parsedRule = [];
        
        foreach ($components as $component) {
            if (strpos($component, '=') === false) {
                return false; // Format invalide
            }
            
            list($key, $value) = explode('=', $component, 2);
            $parsedRule[strtoupper($key)] = $value;
        }
        
        // FREQ est obligatoire selon RFC-5545
        if (!isset($parsedRule['FREQ'])) {
            return false;
        }
        
        // Valider FREQ
        $validFreqs = ['SECONDLY', 'MINUTELY', 'HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];
        if (!in_array(strtoupper($parsedRule['FREQ']), $validFreqs)) {
            return false;
        }
        
        // Valider les autres composants optionnels
        foreach ($parsedRule as $key => $value) {
            switch ($key) {
                case 'FREQ':
                    // Déjà validé
                    break;
                    
                case 'UNTIL':
                    // Format date: YYYYMMDD ou YYYYMMDDTHHMMSS ou YYYYMMDDTHHMMSSZ
                    if (!preg_match('/^\d{8}(T\d{6}Z?)?$/', $value)) {
                        return false;
                    }
                    break;
                    
                case 'COUNT':
                    if (!is_numeric($value) || intval($value) <= 0) {
                        return false;
                    }
                    break;
                    
                case 'INTERVAL':
                    if (!is_numeric($value) || intval($value) <= 0) {
                        return false;
                    }
                    break;
                    
                case 'BYSECOND':
                case 'BYMINUTE':
                    // 0-59
                    $values = explode(',', $value);
                    foreach ($values as $v) {
                        if (!is_numeric($v) || intval($v) < 0 || intval($v) > 59) {
                            return false;
                        }
                    }
                    break;
                    
                case 'BYHOUR':
                    // 0-23
                    $values = explode(',', $value);
                    foreach ($values as $v) {
                        if (!is_numeric($v) || intval($v) < 0 || intval($v) > 23) {
                            return false;
                        }
                    }
                    break;
                    
                case 'BYDAY':
                    // Format: MO,TU,WE... ou +1MO,-2FR...
                    $validDays = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
                    $values = explode(',', $value);
                    foreach ($values as $v) {
                        if (!preg_match('/^([+-]?\d+)?(SU|MO|TU|WE|TH|FR|SA)$/', $v, $matches)) {
                            return false;
                        }
                        if (isset($matches[1]) && $matches[1] !== '') {
                            $num = intval($matches[1]);
                            if ($num < -53 || $num > 53 || $num === 0) {
                                return false;
                            }
                        }
                    }
                    break;
                    
                case 'BYMONTHDAY':
                    // 1-31 ou -31 à -1
                    $values = explode(',', $value);
                    foreach ($values as $v) {
                        if (!is_numeric($v)) {
                            return false;
                        }
                        $num = intval($v);
                        if ($num === 0 || $num < -31 || $num > 31) {
                            return false;
                        }
                    }
                    break;
                    
                case 'BYYEARDAY':
                    // 1-366 ou -366 à -1
                    $values = explode(',', $value);
                    foreach ($values as $v) {
                        if (!is_numeric($v)) {
                            return false;
                        }
                        $num = intval($v);
                        if ($num === 0 || $num < -366 || $num > 366) {
                            return false;
                        }
                    }
                    break;
                    
                case 'BYWEEKNO':
                    // 1-53 ou -53 à -1
                    $values = explode(',', $value);
                    foreach ($values as $v) {
                        if (!is_numeric($v)) {
                            return false;
                        }
                        $num = intval($v);
                        if ($num === 0 || $num < -53 || $num > 53) {
                            return false;
                        }
                    }
                    break;
                    
                case 'BYMONTH':
                    // 1-12
                    $values = explode(',', $value);
                    foreach ($values as $v) {
                        if (!is_numeric($v) || intval($v) < 1 || intval($v) > 12) {
                            return false;
                        }
                    }
                    break;
                    
                case 'WKST':
                    // Jour de début de semaine
                    $validDays = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
                    if (!in_array(strtoupper($value), $validDays)) {
                        return false;
                    }
                    break;
                    
                default:
                    // Propriété non reconnue - selon RFC peut être ignorée
                    // mais pour la validation stricte, on peut retourner false
                    break;
            }
        }
        
        // Vérification de compatibilité COUNT et UNTIL (mutuellement exclusifs)
        if (isset($parsedRule['COUNT']) && isset($parsedRule['UNTIL'])) {
            return false;
        }
        
        return true;
    }

    /**
     * Importe des événements depuis un contenu de fichier ICS.
     * Utilise IcsParser (sabre/vobject) pour un parsing RFC 5545 complet.
     *
     * @param int    $calendarId  ID du calendrier cible
     * @param string $icsContent  Contenu du fichier ICS
     * @param string $userId      ID de l'utilisateur propriétaire
     * @return int Nombre d'événements importés avec succès
     */
    public function importEventsFromIcsContent(int $calendarId, string $icsContent, string $userId): int
    {
        $eventsData = \ICS\Utils\IcsParser::parseEvents($icsContent);

        $importedCount = 0;
        foreach ($eventsData as $eventData) {
            try {
                $event = new self();
                $event->calendarId      = $calendarId;
                $event->userId          = $userId;
                $event->title           = $eventData['title'];
                $event->description     = $eventData['description'];
                $event->location        = $eventData['location'];
                $event->allDay          = $eventData['all_day'];
                $event->startDatetime   = $eventData['start_datetime'];
                $event->endDatetime     = $eventData['end_datetime'];
                $event->recurrenceRule  = $eventData['rrule'];
                $event->status          = $eventData['status'];
                // Phase 2
                if (isset($eventData['categories']))  $event->categories  = $eventData['categories'];
                if (isset($eventData['priority']))     $event->priority    = $eventData['priority'];
                if (isset($eventData['class']))        $event->class       = $eventData['class'];
                if (isset($eventData['transp']))       $event->transp      = $eventData['transp'];
                if (isset($eventData['url']))          $event->meetingLink = $eventData['url'];
                if (isset($eventData['geo_lat']))      $event->geoLat      = $eventData['geo_lat'];
                if (isset($eventData['geo_lng']))      $event->geoLng      = $eventData['geo_lng'];
                if (isset($eventData['attachments']))  $event->attachments = $eventData['attachments'];
                // Phase 3
                if (isset($eventData['attendees']))       $event->attendees      = $eventData['attendees'];
                if (isset($eventData['organizer_email'])) $event->organizerEmail = $eventData['organizer_email'];
                if (isset($eventData['organizer_name']))  $event->organizerName  = $eventData['organizer_name'];
                // Phase 4
                if (isset($eventData['rdate']))       $event->rdate     = $eventData['rdate'];
                if (isset($eventData['related_to']))  $event->relatedTo = $eventData['related_to'];
                if (isset($eventData['duration']))    $event->duration  = $eventData['duration'];
                if (isset($eventData['notifications'])) $event->notifications = $eventData['notifications'];

                $result = $event->create();

                // Phase 4.1 — EXDATE : créer les occurrences annulées correspondantes
                if (!empty($eventData['exdates']) && !empty($result['id'])) {
                    \ICS\Services\RecurrenceService::cancelOccurrencesByDatetimes(
                        $result,
                        $eventData['exdates']
                    );
                }

                // Phase 4.2 — RDATE : générer les occurrences additionnelles
                if (!empty($eventData['rdate']) && !empty($result['id'])) {
                    \ICS\Services\RecurrenceService::generateRdateOccurrences($result);
                }

                $importedCount++;
            } catch (\Exception $e) {
                LogService::error("Erreur lors de l'importation d'un événement depuis ICS", [
                    'calendar_id' => $calendarId,
                    'event_data'  => $eventData,
                    'error'       => $e->getMessage()
                ]);
                // Continue avec les autres événements
            }
        }

        return $importedCount;
    }

    /**
     * Recherche un événement dans un calendrier par son UID ICS.
     */
    public function getByUidAndCalendarId(string $uid, int $calendarId): ?array
    {
        $stmt = $this->getDb()->prepare(
            "SELECT * FROM calendar_events WHERE uid = ? AND calendar_id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$uid, $calendarId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Importe (upsert) les événements d'un fichier ICS dans un calendrier existant.
     * Si l'UID existe déjà dans ce calendrier → mise à jour.
     * Si l'UID est absent ou nouveau → création (en préservant l'UID ICS).
     *
     * @return array ['created' => int, 'updated' => int]
     */
    public function upsertEventsFromIcsContent(int $calendarId, string $icsContent, string $userId): array
    {
        $eventsData = \ICS\Utils\IcsParser::parseEvents($icsContent);
        $created = 0;
        $updated = 0;

        foreach ($eventsData as $eventData) {
            try {
                $existing = null;
                if (!empty($eventData['uid'])) {
                    $existing = $this->getByUidAndCalendarId($eventData['uid'], $calendarId);
                }

                if ($existing) {
                    // Mise à jour de l'événement existant
                    $event = new self();
                    $event->id             = $existing['id'];
                    $event->title          = $eventData['title'];
                    $event->description    = $eventData['description'];
                    $event->location       = $eventData['location'];
                    $event->allDay         = $eventData['all_day'];
                    $event->startDatetime  = $eventData['start_datetime'];
                    $event->endDatetime    = $eventData['end_datetime'];
                    $event->recurrenceRule = $eventData['rrule'];
                    $event->status         = $eventData['status'];
                    if (isset($eventData['categories']))     $event->categories     = $eventData['categories'];
                    if (isset($eventData['priority']))       $event->priority       = $eventData['priority'];
                    if (isset($eventData['class']))          $event->class          = $eventData['class'];
                    if (isset($eventData['transp']))         $event->transp         = $eventData['transp'];
                    if (isset($eventData['url']))            $event->meetingLink    = $eventData['url'];
                    if (isset($eventData['geo_lat']))        $event->geoLat         = $eventData['geo_lat'];
                    if (isset($eventData['geo_lng']))        $event->geoLng         = $eventData['geo_lng'];
                    if (isset($eventData['attachments']))    $event->attachments    = $eventData['attachments'];
                    if (isset($eventData['attendees']))      $event->attendees      = $eventData['attendees'];
                    if (isset($eventData['organizer_email'])) $event->organizerEmail = $eventData['organizer_email'];
                    if (isset($eventData['organizer_name']))  $event->organizerName  = $eventData['organizer_name'];
                    if (isset($eventData['rdate']))          $event->rdate          = $eventData['rdate'];
                    if (isset($eventData['related_to']))     $event->relatedTo      = $eventData['related_to'];
                    if (isset($eventData['duration']))       $event->duration       = $eventData['duration'];
                    if (isset($eventData['notifications']))  $event->notifications  = $eventData['notifications'];
                    $event->update();
                    $updated++;
                } else {
                    // Création — on préserve l'UID du fichier ICS si disponible
                    $event = new self();
                    $event->calendarId     = $calendarId;
                    $event->userId         = $userId;
                    $event->uid            = $eventData['uid'] ?? null;
                    $event->title          = $eventData['title'];
                    $event->description    = $eventData['description'];
                    $event->location       = $eventData['location'];
                    $event->allDay         = $eventData['all_day'];
                    $event->startDatetime  = $eventData['start_datetime'];
                    $event->endDatetime    = $eventData['end_datetime'];
                    $event->recurrenceRule = $eventData['rrule'];
                    $event->status         = $eventData['status'];
                    if (isset($eventData['categories']))     $event->categories     = $eventData['categories'];
                    if (isset($eventData['priority']))       $event->priority       = $eventData['priority'];
                    if (isset($eventData['class']))          $event->class          = $eventData['class'];
                    if (isset($eventData['transp']))         $event->transp         = $eventData['transp'];
                    if (isset($eventData['url']))            $event->meetingLink    = $eventData['url'];
                    if (isset($eventData['geo_lat']))        $event->geoLat         = $eventData['geo_lat'];
                    if (isset($eventData['geo_lng']))        $event->geoLng         = $eventData['geo_lng'];
                    if (isset($eventData['attachments']))    $event->attachments    = $eventData['attachments'];
                    if (isset($eventData['attendees']))      $event->attendees      = $eventData['attendees'];
                    if (isset($eventData['organizer_email'])) $event->organizerEmail = $eventData['organizer_email'];
                    if (isset($eventData['organizer_name']))  $event->organizerName  = $eventData['organizer_name'];
                    if (isset($eventData['rdate']))          $event->rdate          = $eventData['rdate'];
                    if (isset($eventData['related_to']))     $event->relatedTo      = $eventData['related_to'];
                    if (isset($eventData['duration']))       $event->duration       = $eventData['duration'];
                    if (isset($eventData['notifications']))  $event->notifications  = $eventData['notifications'];
                    $result = $event->create();

                    if (!empty($eventData['exdates']) && !empty($result['id'])) {
                        \ICS\Services\RecurrenceService::cancelOccurrencesByDatetimes($result, $eventData['exdates']);
                    }
                    if (!empty($eventData['rdate']) && !empty($result['id'])) {
                        \ICS\Services\RecurrenceService::generateRdateOccurrences($result);
                    }
                    $created++;
                }
            } catch (\Exception $e) {
                LogService::error("Erreur lors de l'upsert d'un événement depuis ICS", [
                    'calendar_id' => $calendarId,
                    'uid'         => $eventData['uid'] ?? null,
                    'error'       => $e->getMessage()
                ]);
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Phase 5.3 — Récupère les événements OPAQUE (bloquants) d'un calendrier pour VFREEBUSY.
     * Les événements sans TRANSP défini sont traités comme OPAQUE (RFC 5545 §3.8.2.7).
     *
     * @param int    $calendarId
     * @param string $startDatetime Format 'Y-m-d H:i:s'
     * @param string $endDatetime   Format 'Y-m-d H:i:s'
     */
    public function getOpaqueEventsForFreeBusy(int $calendarId, string $startDatetime, string $endDatetime): array
    {
        $stmt = $this->getDb()->prepare("
            SELECT id, title, start_datetime, end_datetime, timezone, transp
            FROM calendar_events
            WHERE calendar_id = ?
              AND deleted_at IS NULL
              AND status != 'cancelled'
              AND (transp IS NULL OR transp = 'OPAQUE')
              AND start_datetime < ?
              AND end_datetime > ?
            ORDER BY start_datetime ASC
        ");
        $stmt->execute([$calendarId, $endDatetime, $startDatetime]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

}