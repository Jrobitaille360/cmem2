<?php

namespace ICS\Models;

use AuthGroups\Models\BaseModel;
use AuthGroups\Services\LogService;
use PDO;

/**
 * Modèle pour gérer les occurrences d'événements
 * Maintient une fenêtre glissante de -6 mois à +1 an
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
     * Insère plusieurs occurrences en batch
     */
    public static function createBatch(array $occurrences): bool
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
                ON DUPLICATE KEY UPDATE id = id";

            $stmt = $db->prepare($query);
            $stmt->execute($params);

            LogService::info("Occurrences créées en batch", [
                'count' => count($occurrences)
            ]);

            return true;
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la création d'occurrences en batch", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Récupère les occurrences d'un événement dans une période
     */
    public static function getByEventId(int $eventId, ?string $startDate = null, ?string $endDate = null): array
    {
        try {
            $db = (new static())->getDb();
            
            $query = "SELECT * FROM event_occurrences 
                      WHERE event_id = ? AND is_cancelled = 0";
            $params = [$eventId];

            if ($startDate) {
                $query .= " AND end_datetime >= ?";
                $params[] = $startDate;
            }

            if ($endDate) {
                $query .= " AND start_datetime <= ?";
                $params[] = $endDate;
            }

            $query .= " ORDER BY start_datetime ASC";

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
            $query = "SELECT * FROM event_occurrences 
                      WHERE event_id IN ($placeholders) AND is_cancelled = 0";
            $params = $eventIds;

            if ($startDate) {
                $query .= " AND end_datetime >= ?";
                $params[] = $startDate;
            }

            if ($endDate) {
                $query .= " AND start_datetime <= ?";
                $params[] = $endDate;
            }

            $query .= " ORDER BY start_datetime ASC";

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
     */
    public static function getByCalendarId(int $calendarId, ?string $startDate = null, ?string $endDate = null): array
    {
        try {
            $db = (new static())->getDb();
            
            $query = "SELECT * FROM event_occurrences 
                      WHERE calendar_id = ? AND is_cancelled = 0";
            $params = [$calendarId];

            if ($startDate) {
                $query .= " AND end_datetime >= ?";
                $params[] = $startDate;
            }

            if ($endDate) {
                $query .= " AND start_datetime <= ?";
                $params[] = $endDate;
            }

            $query .= " ORDER BY start_datetime ASC";

            $stmt = $db->prepare($query);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
     * Nettoie les occurrences en dehors de la fenêtre (-6 mois à +1 an)
     */
    public static function cleanupOutdated(): int
    {
        try {
            $db = (new static())->getDb();
            
            $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
            $oneYearAhead = date('Y-m-d', strtotime('+1 year'));
            
            $stmt = $db->prepare("DELETE FROM event_occurrences 
                                 WHERE occurrence_date < ? OR occurrence_date > ?");
            $stmt->execute([$sixMonthsAgo, $oneYearAhead]);
            
            $deletedCount = $stmt->rowCount();

            if ($deletedCount > 0) {
                LogService::info("Occurrences périmées nettoyées", [
                    'count' => $deletedCount
                ]);
            }

            return $deletedCount;
        } catch (\Exception $e) {
            LogService::error("Erreur lors du nettoyage des occurrences", [
                'error' => $e->getMessage()
            ]);
            return 0;
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
            LogService::error("Erreur lors de la modification d'occurrence", [
                'occurrence_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
