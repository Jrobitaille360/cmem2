<?php

namespace ICS\Services;

use ICS\Models\EventOccurrence;
use AuthGroups\Services\LogService;

// Charger la configuration ICS
require_once __DIR__ . '/../config/ics_config.php';

/**
 * Service pour la maintenance des occurrences d'événements
 * Régénère les occurrences pour tous les événements récurrents jusqu'en 2099-12-31
 */
class OccurrenceMaintenanceService
{
    /**
     * Régénère les occurrences pour les événements récurrents modifiés depuis la dernière maintenance
     * Ou tous les événements si c'est la première exécution ou si forceAll = true
     * 
     * @param bool $forceAll Force la régénération de tous les événements
     * @return array Statistiques de la maintenance
     */
    public static function performMaintenance(bool $forceAll = false): array
    {
        $stats = [
            'regenerated_events' => 0,
            'skipped_events' => 0,
            'errors' => [],
            'mode' => $forceAll ? 'full' : 'incremental'
        ];

        try {
            // Récupérer la date de la dernière maintenance
            $lastMaintenance = self::getLastMaintenanceDate();
            
            if ($forceAll || !$lastMaintenance) {
                // Régénération complète
                $stats['last_maintenance'] = $lastMaintenance ?? 'never';
                $regenerateStats = RecurrenceService::regenerateAllOccurrences();
            } else {
                // Régénération incrémentale (seulement les événements modifiés)
                $stats['last_maintenance'] = $lastMaintenance;
                $regenerateStats = RecurrenceService::regenerateModifiedOccurrences($lastMaintenance);
            }
            
            if (isset($regenerateStats['success'])) {
                $stats['regenerated_events'] = $regenerateStats['success'];
            }
            
            if (isset($regenerateStats['skipped'])) {
                $stats['skipped_events'] = $regenerateStats['skipped'];
            }
            
            if (isset($regenerateStats['error'])) {
                $stats['errors'][] = $regenerateStats['error'];
            }
            
            // Enregistrer la date de cette maintenance
            self::setLastMaintenanceDate();
            
            LogService::info("Maintenance des occurrences terminée", $stats);
            
            return $stats;
            
        } catch (\Exception $e) {
            $stats['errors'][] = $e->getMessage();
            LogService::error("Erreur lors de la maintenance des occurrences", [
                'error' => $e->getMessage()
            ]);
            return $stats;
        }
    }

    /**
     * Obtient des statistiques sur les occurrences stockées
     * 
     * @return array Statistiques
     */
    public static function getStatistics(): array
    {
        try {
            $db = EventOccurrence::getDbConnection();
            
            // Nombre total d'occurrences
            $stmt = $db->query("SELECT COUNT(*) as total FROM event_occurrences");
            $total = $stmt->fetch(\PDO::FETCH_ASSOC)['total'] ?? 0;
            
            // Occurrences modifiées
            $stmt = $db->query("SELECT COUNT(*) as modified FROM event_occurrences WHERE is_modified = 1");
            $modified = $stmt->fetch(\PDO::FETCH_ASSOC)['modified'] ?? 0;
            
            // Occurrences annulées
            $stmt = $db->query("SELECT COUNT(*) as cancelled FROM event_occurrences WHERE is_cancelled = 1");
            $cancelled = $stmt->fetch(\PDO::FETCH_ASSOC)['cancelled'] ?? 0;
            
            // Nombre d'événements récurrents
            $stmt = $db->query("SELECT COUNT(DISTINCT event_id) as recurring_events FROM event_occurrences");
            $recurringEvents = $stmt->fetch(\PDO::FETCH_ASSOC)['recurring_events'] ?? 0;
            
            // Date min et max
            $stmt = $db->query("SELECT MIN(occurrence_date) as min_date, MAX(occurrence_date) as max_date FROM event_occurrences");
            $dates = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return [
                'total_occurrences' => (int)$total,
                'modified_occurrences' => (int)$modified,
                'cancelled_occurrences' => (int)$cancelled,
                'recurring_events' => (int)$recurringEvents,
                'date_range_start' => $dates['min_date'] ?? 'N/A',
                'date_range_end' => $dates['max_date'] ?? 'N/A'
            ];
            
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la récupération des statistiques", [
                'error' => $e->getMessage()
            ]);
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Récupère la date de la dernière maintenance
     * 
     * @return string|null Date au format Y-m-d H:i:s ou null si jamais exécuté
     */
    private static function getLastMaintenanceDate(): ?string
    {
        $cacheFile = ICS_OCCURRENCES_LAST_MAINTENANCE_FILE;
        
        if (!file_exists($cacheFile)) {
            return null;
        }
        
        $content = @file_get_contents($cacheFile);
        if ($content === false) {
            return null;
        }
        
        $date = trim($content);
        // Valider le format de date
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $date);
        if ($dt && $dt->format('Y-m-d H:i:s') === $date) {
            return $date;
        }
        
        return null;
    }

    /**
     * Enregistre la date actuelle comme dernière maintenance
     * 
     * @return bool Succès de l'enregistrement
     */
    private static function setLastMaintenanceDate(): bool
    {
        $cacheFile = ICS_OCCURRENCES_LAST_MAINTENANCE_FILE;
        $cacheDir = dirname($cacheFile);
        
        // Créer le répertoire si nécessaire
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        $now = date('Y-m-d H:i:s');
        $result = @file_put_contents($cacheFile, $now);
        
        if ($result === false) {
            LogService::warning("Impossible d'enregistrer la date de maintenance", [
                'file' => $cacheFile
            ]);
            return false;
        }
        
        return true;
    }
}
