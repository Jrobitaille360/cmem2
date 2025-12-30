<?php

namespace ICS\Models;

use AuthGroups\Models\BaseModel;
use AuthGroups\Services\LogService;
use PDO;
use SebastianBergmann\CodeCoverage\Filter;

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
                    timezone, meeting_link, notifications, color
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stmt = $this->getDb()->prepare($query);

            // Préparer les données
            $notificationsJson = null;
            if (isset($this->notifications)) {
                $notificationsJson = is_string($this->notifications) 
                    ? $this->notifications 
                    : json_encode($this->notifications);
            }

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
                $this->color ?? null
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
                'recurrence_rule' => $this->recurrenceRule ?? null
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
        return $result ?: null;
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
     *
     * @param int $calendarId L'ID du calendrier où importer les événements.
     * @param string $icsContent Le contenu du fichier ICS.
     * @return int Le nombre d'événements importés.
     */
    public function importEventsFromIcsContent(int $calendarId, string $icsContent, string $userId): int
    {
        // J'utilise un simple parseur manuel car je ne peux pas ajouter de dépendances.
        // Pour une solution de production, une bibliothèque comme `johngrogg/ics-parser` serait préférable.
        $lines = explode("\n", str_replace("\r", "", $icsContent));
        $eventsData = [];
        $inEvent = false;
        $currentEvent = [];

        foreach ($lines as $line) {
            if (strpos($line, 'BEGIN:VEVENT') !== false) {
                $inEvent = true;
                $currentEvent = [];
                continue;
            }

            if (strpos($line, 'END:VEVENT') !== false) {
                if ($inEvent) {
                    $eventsData[] = $currentEvent;
                    $inEvent = false;
                }
                continue;
            }

            if ($inEvent) {
                if (preg_match('/^([^;:]+)(;[^:]*)?:(.*)$/', $line, $matches)) {
                    $key = $matches[1];
                    $params = $matches[2] ?? '';
                    $value = $matches[3];
                    
                    // Stocker la valeur avec la clé de base
                    $currentEvent[$key] = $value;
                    
                    // Stocker aussi avec la clé complète si des paramètres existent
                    if ($params) {
                        $currentEvent[$key . $params] = $value;
                    }
                }
            }
        }

        $importedCount = 0;
        foreach ($eventsData as $eventData) {
            try {
                $event = new self();
                $event->calendarId = $calendarId;
                $event->userId = $userId;
                $event->title = $eventData['SUMMARY'] ?? 'Sans titre';
                $event->description = $eventData['DESCRIPTION'] ?? null;
                $event->location = $eventData['LOCATION'] ?? null;
                
                // Détecter si c'est un événement "all-day"
                $isAllDay = isset($eventData['DTSTART;VALUE=DATE']);
                $event->allDay = $isAllDay;
                
                if ($isAllDay) {
                    // Événement toute la journée
                    $startDate = $eventData['DTSTART'];
                    $event->startDatetime = date('Y-m-d 00:00:00', strtotime($startDate));
                    
                    // Si DTEND existe, l'utiliser (attention : exclusif dans iCal)
                    if (isset($eventData['DTEND'])) {
                        $endDate = $eventData['DTEND'];
                        // La date de fin dans iCal est exclusive pour les événements all-day
                        $event->endDatetime = date('Y-m-d 23:59:59', strtotime($endDate . ' -1 day'));
                    } else {
                        // Pas de DTEND : événement d'une seule journée
                        $event->endDatetime = date('Y-m-d 23:59:59', strtotime($startDate));
                    }
                } else {
                    // Événement avec heures précises
                    $event->startDatetime = date('Y-m-d H:i:s', strtotime($eventData['DTSTART']));
                    
                    // Si DTEND n'existe pas, utiliser DTSTART comme fin
                    if (isset($eventData['DTEND'])) {
                        $event->endDatetime = date('Y-m-d H:i:s', strtotime($eventData['DTEND']));
                    } else {
                        $event->endDatetime = $event->startDatetime;
                    }
                }

                $event->recurrenceRule = $eventData['RRULE'] ?? null;
                $event->status = isset($eventData['STATUS']) ? strtolower($eventData['STATUS']) : 'confirmed';

                $event->create();
                $importedCount++;
            } catch (\Exception $e) {
                LogService::error("Erreur lors de l'importation d'un événement depuis ICS", [
                    'calendar_id' => $calendarId,
                    'event_data' => $eventData,
                    'error' => $e->getMessage()
                ]);
                // On continue avec les autres événements
            }
        }

        return $importedCount;
    }

}