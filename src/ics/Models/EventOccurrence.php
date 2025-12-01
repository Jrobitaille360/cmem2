<?php

namespace ICS\Models;

use AuthGroups\Models\BaseModel;
use AuthGroups\Services\LogService;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use PDO;

// Charger la configuration ICS
require_once __DIR__ . '/../config/ics_config.php';

/**
 * Modèle pour gérer les occurrences d'événements
 * Stocke toutes les occurrences jusqu'à 2099-12-31
 * Génération à la volée pour les dates post-2099
 */
class EventOccurrence extends BaseModel
{
    protected $table = 'event_occurrences';
    
    public $id;
    public $eventId;
    public $calendarId;
    public $occurrenceDate;
    public $startDatetime;
    public $endDatetime;
    public $recurrenceIndex;
    public $isModified;
    public $isCancelled;
    public $modifiedTitle;
    public $modifiedDescription;
    public $modifiedLocation;
    public $modifiedStartDatetime;
    public $modifiedEndDatetime;

    public function __construct() {
        parent::__construct();
    }

    /**
     * Obtient l'instance de base de données (méthode publique pour accès externe)
     */
    public static function getDbConnection(): PDO
    {
        $instance = new static();
        return $instance->getDb();
    }

    /**
     * Insère une occurrence (ou l'ignore si elle existe déjà)
     */
    public function create(): bool
    {
        return $this->createOrIgnore();
    }

    /**
     * Insère une occurrence (ou l'ignore si elle existe déjà)
     */
    public function createOrIgnore(): bool
    {
        try {
            $query = "INSERT INTO event_occurrences (
                    event_id, calendar_id, occurrence_date, start_datetime, end_datetime, recurrence_index
                ) VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE id = id";

            $stmt = $this->getDb()->prepare($query);
            $stmt->execute([
                $this->eventId,
                $this->calendarId,
                $this->occurrenceDate,
                $this->startDatetime,
                $this->endDatetime,
                $this->recurrenceIndex
            ]);

            return true;
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la création d'occurrence", [
                'event_id' => $this->eventId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Insère plusieurs occurrences en batch (ou les met à jour si elles existent)
     */
    public static function createUpdateBatch(array $occurrences): bool
    {
        if (empty($occurrences)) {
            return true;
        }

        try {
            $db = (new static())->getDb();
            
            $values = [];
            $params = [];
            
            foreach ($occurrences as $occ) {
                $values[] = "(?, ?, ?, ?, ?, ?)";
                $params[] = $occ['event_id'];
                $params[] = $occ['calendar_id'];
                $params[] = $occ['occurrence_date'];
                $params[] = $occ['start_datetime'];
                $params[] = $occ['end_datetime'];
                $params[] = $occ['recurrence_index'];
            }
            
            $query = "INSERT INTO event_occurrences (
                    event_id, calendar_id, occurrence_date, start_datetime, end_datetime, recurrence_index
                ) VALUES " . implode(', ', $values) . "
                ON DUPLICATE KEY UPDATE 
                    start_datetime = VALUES(start_datetime),
                    end_datetime = VALUES(end_datetime),
                    recurrence_index = VALUES(recurrence_index),
                    updated_at = CURRENT_TIMESTAMP";

            $stmt = $db->prepare($query);
            $stmt->execute($params);

            LogService::info("Occurrences créées/mises à jour en batch", [
                'count' => count($occurrences)
            ]);

            return true;
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la création/mise à jour d'occurrences en batch", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Récupère les occurrences d'un événement dans une période
     * Génère à la volée si date demandée > 2099-12-31
     */
    public static function getByEventId(int $eventId, ?string $startDate = null, ?string $endDate = null): array
    {
        try {
            $db = (new static())->getDb();
            
            // Vérifier si on demande des dates au-delà de 2099-12-31
            if ($endDate && $endDate > ICS_OCCURRENCES_MAX_DATE . ' 23:59:59') {
                // Générer à la volée pour cette plage
                return self::generateOccurrencesOnDemand($eventId, $startDate, $endDate);
            }
            
            $query = "SELECT eo.*, 
                      ce.title, ce.description, ce.location, ce.all_day, ce.color, 
                      ce.status, ce.timezone, ce.organizer_email, ce.attendees, 
                      ce.meeting_link, ce.notifications, ce.recurrence_rule
                      FROM event_occurrences eo
                      LEFT JOIN calendar_events ce ON eo.event_id = ce.id
                      WHERE eo.event_id = ? AND eo.is_cancelled = 0";
            $params = [$eventId];

            if ($startDate) {
                $query .= " AND eo.end_datetime >= ?";
                $params[] = $startDate;
            }

            if ($endDate) {
                $query .= " AND eo.start_datetime <= ?";
                $params[] = $endDate;
            }

            $query .= " ORDER BY eo.start_datetime ASC";

            $stmt = $db->prepare($query);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la récupération des occurrences", [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Récupère les occurrences pour plusieurs événements dans une période
     */
    public static function getByEventIds(array $eventIds, ?string $startDate = null, ?string $endDate = null): array
    {
        if (empty($eventIds)) {
            return [];
        }

        try {
            $db = (new static())->getDb();
            
            $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
            $query = "SELECT eo.*, 
                      ce.title, ce.description, ce.location, ce.all_day, ce.color, 
                      ce.status, ce.timezone, ce.organizer_email, ce.attendees, 
                      ce.meeting_link, ce.notifications, ce.recurrence_rule
                      FROM event_occurrences eo
                      LEFT JOIN calendar_events ce ON eo.event_id = ce.id
                      WHERE eo.event_id IN ($placeholders) AND eo.is_cancelled = 0";
            $params = $eventIds;

            if ($startDate) {
                $query .= " AND eo.end_datetime >= ?";
                $params[] = $startDate;
            }

            if ($endDate) {
                $query .= " AND eo.start_datetime <= ?";
                $params[] = $endDate;
            }

            $query .= " ORDER BY eo.start_datetime ASC";

            $stmt = $db->prepare($query);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la récupération des occurrences multiples", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Récupère les occurrences d'un calendrier dans une période
     * Inclut aussi les événements non récurrents transformés en occurrences
     * Génère à la volée si date demandée > 2099-12-31
     */
    public static function getByCalendarId(int $calendarId, ?string $startDate = null, ?string $endDate = null): array
    {
        try {
            $db = (new static())->getDb();

            // Vérifier si on demande des dates au-delà de 2099-12-31
            if ($endDate && $endDate > ICS_OCCURRENCES_MAX_DATE . ' 23:59:59') {
                // Générer à la volée pour cette plage
                return self::generateOccurrencesOnDemandForCalendar($calendarId, $startDate, $endDate);
            }

            $query = "SELECT eo.*, 
                      ce.title, ce.description, ce.location, ce.all_day, ce.color, 
                      ce.status, ce.timezone, ce.organizer_email, ce.attendees, 
                      ce.meeting_link, ce.notifications, ce.recurrence_rule
                      FROM event_occurrences eo
                      LEFT JOIN calendar_events ce ON eo.event_id = ce.id
                      WHERE eo.calendar_id = ? AND eo.is_cancelled = 0";
            $params = [$calendarId];

            if ($startDate) {
                $query .= " AND eo.end_datetime >= ?";
                $params[] = $startDate;
            }

            if ($endDate) {
                $query .= " AND eo.start_datetime <= ?";
                $params[] = $endDate;
            }

            $query .= " ORDER BY eo.start_datetime ASC";

            $stmt = $db->prepare($query);
            $stmt->execute($params);

            $occurrences = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Ajouter les événements non récurrents comme "occurrences"
            $nonRecurringQuery = "SELECT * FROM calendar_events 
                                 WHERE calendar_id = ? AND (recurrence_rule IS NULL OR recurrence_rule = '') 
                                 AND deleted_at IS NULL";
            $nonRecurringParams = [$calendarId];

            if ($startDate) {
                $nonRecurringQuery .= " AND end_datetime >= ?";
                $nonRecurringParams[] = $startDate;
            }

            if ($endDate) {
                $nonRecurringQuery .= " AND start_datetime <= ?";
                $nonRecurringParams[] = $endDate;
            }

            $nonRecurringQuery .= " ORDER BY start_datetime ASC";

            $stmt2 = $db->prepare($nonRecurringQuery);
            $stmt2->execute($nonRecurringParams);

            $nonRecurringEvents = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            // Transformer les événements non récurrents en format occurrence
            // Pour les événements multi-jours, les développer en occurrences journalières
            foreach ($nonRecurringEvents as $event) {
                if ($event['start_datetime'] !== $event['end_datetime']) {
                    // Événement multi-jours : développer en occurrences journalières
                    $dayOccurrences = \ICS\Services\RecurrenceService::expandOneDay($event, $startDate, $endDate);
                    foreach ($dayOccurrences as $dayOcc) {
                        $occurrences[] = [
                            'id' => null, // Pas d'ID d'occurrence pour les événements non récurrents
                            'event_id' => $event['id'],
                            'calendar_id' => $event['calendar_id'],
                            'occurrence_date' => substr($dayOcc['start_datetime'], 0, 10),
                            'start_datetime' => $dayOcc['start_datetime'],
                            'end_datetime' => $dayOcc['end_datetime'],
                            'recurrence_index' => null,
                            'is_modified' => false,
                            'is_cancelled' => false,
                            'modified_title' => null,
                            'modified_description' => null,
                            'modified_location' => null,
                            'modified_start_datetime' => null,
                            'modified_end_datetime' => null,
                            'title' => $event['title'],
                            'description' => $event['description'],
                            'location' => $event['location'],
                            'all_day' => $event['all_day'],
                            'color' => $event['color'],
                            'status' => $event['status'],
                            'timezone' => $event['timezone'],
                            'organizer_email' => $event['organizer_email'],
                            'attendees' => $event['attendees'],
                            'meeting_link' => $event['meeting_link'],
                            'notifications' => $event['notifications'],
                            'recurrence_rule' => $event['recurrence_rule'],
                            'is_recurring' => false,
                            'parent_event_id' => $event['id']
                        ];
                    }
                } else {
                    // Événement d'une seule journée
                    $occurrences[] = [
                        'id' => null, // Pas d'ID d'occurrence pour les événements non récurrents
                        'event_id' => $event['id'],
                        'calendar_id' => $event['calendar_id'],
                        'occurrence_date' => substr($event['start_datetime'], 0, 10),
                        'start_datetime' => $event['start_datetime'],
                        'end_datetime' => $event['end_datetime'],
                        'recurrence_index' => null,
                        'is_modified' => false,
                        'is_cancelled' => false,
                        'modified_title' => null,
                        'modified_description' => null,
                        'modified_location' => null,
                        'modified_start_datetime' => null,
                        'modified_end_datetime' => null,
                        'title' => $event['title'],
                        'description' => $event['description'],
                        'location' => $event['location'],
                        'all_day' => $event['all_day'],
                        'color' => $event['color'],
                        'status' => $event['status'],
                        'timezone' => $event['timezone'],
                        'organizer_email' => $event['organizer_email'],
                        'attendees' => $event['attendees'],
                        'meeting_link' => $event['meeting_link'],
                        'notifications' => $event['notifications'],
                        'recurrence_rule' => $event['recurrence_rule'],
                        'is_recurring' => false,
                        'parent_event_id' => $event['id']
                    ];
                }
            }

            // Retrier par date de début
            usort($occurrences, function($a, $b) {
                return strcmp($a['start_datetime'], $b['start_datetime']);
            });

            return $occurrences;
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la récupération des occurrences par calendrier", [
                'calendar_id' => $calendarId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Supprime toutes les occurrences d'un événement
     */
    public static function deleteByEventId(int $eventId): bool
    {
        try {
            $db = (new static())->getDb();
            $stmt = $db->prepare("DELETE FROM event_occurrences WHERE event_id = ?");
            $stmt->execute([$eventId]);

            LogService::info("Occurrences supprimées", [
                'event_id' => $eventId
            ]);

            return true;
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la suppression des occurrences", [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Génère les occurrences à la volée pour un événement (dates post-2099)
     * Ne stocke PAS en base, retourne directement les occurrences calculées
     */
    private static function generateOccurrencesOnDemand(int $eventId, ?string $startDate, ?string $endDate): array
    {
        try {
            $db = (new static())->getDb();
            $stmt = $db->prepare("SELECT * FROM calendar_events WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$eventId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$event || empty($event['recurrence_rule'])) {
                return [];
            }

            // Utiliser RecurrenceService pour calculer
            require_once __DIR__ . '/../Services/RecurrenceService.php';
            $occurrences = \ICS\Services\RecurrenceService::expandRecurrence(
                $event,
                $startDate,
                $endDate
            );

            // Transformer en format compatible avec les occurrences stockées
            $result = [];
            foreach ($occurrences as $occ) {
                $result[] = array_merge($occ, [
                    'event_id' => $eventId,
                    'calendar_id' => $event['calendar_id'],
                    'occurrence_date' => substr($occ['start_datetime'], 0, 10),
                    'is_on_demand' => true // Marqueur pour indiquer que c'est généré à la volée
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la génération à la volée", [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Génère les occurrences à la volée pour un calendrier (dates post-2099)
     * Ne stocke PAS en base, retourne directement les occurrences calculées
     */
    private static function generateOccurrencesOnDemandForCalendar(int $calendarId, ?string $startDate, ?string $endDate): array
    {
        try {
            $db = (new static())->getDb();
            $stmt = $db->prepare(
                "SELECT * FROM calendar_events 
                 WHERE calendar_id = ? AND recurrence_rule IS NOT NULL 
                 AND recurrence_rule != '' AND deleted_at IS NULL"
            );
            $stmt->execute([$calendarId]);
            $recurringEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $allOccurrences = [];
            foreach ($recurringEvents as $event) {
                $eventOccurrences = self::generateOccurrencesOnDemand(
                    $event['id'],
                    $startDate,
                    $endDate
                );
                $allOccurrences = array_merge($allOccurrences, $eventOccurrences);
            }

            // Ajouter les événements non récurrents filtrés par période
            $nonRecurringQuery = "SELECT * FROM calendar_events 
                                 WHERE calendar_id = ? AND (recurrence_rule IS NULL OR recurrence_rule = '') 
                                 AND deleted_at IS NULL";
            $nonRecurringParams = [$calendarId];

            if ($startDate) {
                $nonRecurringQuery .= " AND end_datetime >= ?";
                $nonRecurringParams[] = $startDate;
            }

            if ($endDate) {
                $nonRecurringQuery .= " AND start_datetime <= ?";
                $nonRecurringParams[] = $endDate;
            }

            $stmt2 = $db->prepare($nonRecurringQuery);
            $stmt2->execute($nonRecurringParams);

            $nonRecurringEvents = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            // Transformer les événements non récurrents en format occurrence
            // Pour les événements multi-jours, les développer en occurrences journalières
            foreach ($nonRecurringEvents as $event) {
                if ($event['start_datetime'] !== $event['end_datetime']) {
                    // Événement multi-jours : développer en occurrences journalières
                    $dayOccurrences = \ICS\Services\RecurrenceService::expandOneDay($event, $startDate, $endDate);
                    foreach ($dayOccurrences as $dayOcc) {
                        $allOccurrences[] = [
                            'id' => null,
                            'event_id' => $event['id'],
                            'calendar_id' => $event['calendar_id'],
                            'occurrence_date' => substr($dayOcc['start_datetime'], 0, 10),
                            'start_datetime' => $dayOcc['start_datetime'],
                            'end_datetime' => $dayOcc['end_datetime'],
                            'recurrence_index' => null,
                            'is_modified' => false,
                            'is_cancelled' => false,
                            'modified_title' => null,
                            'modified_description' => null,
                            'modified_location' => null,
                            'modified_start_datetime' => null,
                            'modified_end_datetime' => null,
                            'title' => $event['title'],
                            'description' => $event['description'],
                            'location' => $event['location'],
                            'all_day' => $event['all_day'],
                            'color' => $event['color'],
                            'status' => $event['status'],
                            'timezone' => $event['timezone'],
                            'organizer_email' => $event['organizer_email'],
                            'attendees' => $event['attendees'],
                            'meeting_link' => $event['meeting_link'],
                            'notifications' => $event['notifications'],
                            'recurrence_rule' => $event['recurrence_rule'],
                            'is_recurring' => false,
                            'parent_event_id' => $event['id'],
                            'is_on_demand' => true
                        ];
                    }
                } else {
                    // Événement d'une seule journée
                    $allOccurrences[] = [
                        'id' => null,
                        'event_id' => $event['id'],
                        'calendar_id' => $event['calendar_id'],
                        'occurrence_date' => substr($event['start_datetime'], 0, 10),
                        'start_datetime' => $event['start_datetime'],
                        'end_datetime' => $event['end_datetime'],
                        'recurrence_index' => null,
                        'is_modified' => false,
                        'is_cancelled' => false,
                        'modified_title' => null,
                        'modified_description' => null,
                        'modified_location' => null,
                        'modified_start_datetime' => null,
                        'modified_end_datetime' => null,
                        'title' => $event['title'],
                        'description' => $event['description'],
                        'location' => $event['location'],
                        'all_day' => $event['all_day'],
                        'color' => $event['color'],
                        'status' => $event['status'],
                        'timezone' => $event['timezone'],
                        'organizer_email' => $event['organizer_email'],
                        'attendees' => $event['attendees'],
                        'meeting_link' => $event['meeting_link'],
                        'notifications' => $event['notifications'],
                        'recurrence_rule' => $event['recurrence_rule'],
                        'is_recurring' => false,
                        'parent_event_id' => $event['id'],
                        'is_on_demand' => true
                    ];
                }
            }

            // Trier par date de début
            usort($allOccurrences, function($a, $b) {
                return strcmp($a['start_datetime'], $b['start_datetime']);
            });

            return $allOccurrences;
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la génération à la volée pour calendrier", [
                'calendar_id' => $calendarId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Annule une occurrence spécifique
     */
    public function cancel(): bool
    {
        try {
            $stmt = $this->getDb()->prepare(
                "UPDATE event_occurrences SET is_cancelled = 1, updated_at = CURRENT_TIMESTAMP 
                 WHERE id = ?"
            );
            $stmt->execute([$this->id]);

            LogService::info("Occurrence annulée", [
                'occurrence_id' => $this->id,
                'event_id' => $this->eventId
            ]);

            return true;
        } catch (\Exception $e) {
            LogService::error("Erreur lors de l'annulation d'occurrence", [
                'occurrence_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Modifie une occurrence spécifique
     */
    public function update(): bool
    {
        try {
            $fields = [];
            $params = [];

            if (isset($this->isModified)) {
                $fields[] = 'is_modified = ?';
                $params[] = $this->isModified ? 1 : 0;
            }
            if (isset($this->isCancelled)) {
                $fields[] = 'is_cancelled = ?';
                $params[] = $this->isCancelled ? 1 : 0;
            }
            if (isset($this->modifiedTitle)) {
                $fields[] = 'modified_title = ?';
                $params[] = $this->modifiedTitle;
            }
            if (isset($this->modifiedDescription)) {
                $fields[] = 'modified_description = ?';
                $params[] = $this->modifiedDescription;
            }
            if (isset($this->modifiedLocation)) {
                $fields[] = 'modified_location = ?';
                $params[] = $this->modifiedLocation;
            }
            if (isset($this->modifiedStartDatetime)) {
                $fields[] = 'modified_start_datetime = ?';
                $params[] = $this->modifiedStartDatetime;
            }
            if (isset($this->modifiedEndDatetime)) {
                $fields[] = 'modified_end_datetime = ?';
                $params[] = $this->modifiedEndDatetime;
            }

            if (empty($fields)) {
                return false;
            }

            $fields[] = 'updated_at = CURRENT_TIMESTAMP';
            $params[] = $this->id;

            $query = "UPDATE event_occurrences SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $this->getDb()->prepare($query);
            $result = $stmt->execute($params);

            LogService::info("Occurrence mise à jour", [
                'occurrence_id' => $this->id
            ]);

            return $result;
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la mise à jour d'occurrence", [
                'occurrence_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Modifie une occurrence spécifique (méthode alternative)
     */
    public function modify(array $modifications): bool
    {
        try {
            $fields = ['is_modified = 1'];
            $params = [];

            if (isset($modifications['title'])) {
                $fields[] = 'modified_title = ?';
                $params[] = $modifications['title'];
            }
            if (isset($modifications['description'])) {
                $fields[] = 'modified_description = ?';
                $params[] = $modifications['description'];
            }
            if (isset($modifications['location'])) {
                $fields[] = 'modified_location = ?';
                $params[] = $modifications['location'];
            }
            if (isset($modifications['start_datetime'])) {
                $fields[] = 'modified_start_datetime = ?';
                $params[] = $modifications['start_datetime'];
            }
            if (isset($modifications['end_datetime'])) {
                $fields[] = 'modified_end_datetime = ?';
                $params[] = $modifications['end_datetime'];
            }

            $fields[] = 'updated_at = CURRENT_TIMESTAMP';
            $params[] = $this->id;

            $query = "UPDATE event_occurrences SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $this->getDb()->prepare($query);
            $stmt->execute($params);

            LogService::info("Occurrence modifiée", [
                'occurrence_id' => $this->id,
                'event_id' => $this->eventId
            ]);

            return true;
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la modification de l'occurrence", [
                'occurrence_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
