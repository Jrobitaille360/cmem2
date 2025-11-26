<?php

namespace ICS\Services;

use ICS\Models\EventOccurrence;
use AuthGroups\Services\LogService;

/**
 * Service pour la maintenance des occurrences d'événements
 * Gère le nettoyage de la fenêtre glissante (-6 mois à +1 an)
 */
class OccurrenceMaintenanceService
{
    /**
     * Nettoie les occurrences en dehors de la fenêtre glissante
     * et régénère celles qui manquent pour tous les événements récurrents
     * 
     * @return array Statistiques de la maintenance
     */
    public static function performMaintenance(): array
    {
        $stats = [
            'cleaned_count' => 0,
            'regenerated_events' => 0,
            'errors' => []
        ];

        try {
            // Étape 1: Nettoyer les occurrences périmées
            $stats['cleaned_count'] = EventOccurrence::cleanupOutdated();
            
            // Étape 2: Régénérer les occurrences pour tous les événements récurrents
            $regenerateStats = RecurrenceService::regenerateAllOccurrences();
            
            if (isset($regenerateStats['success'])) {
                $stats['regenerated_events'] = $regenerateStats['success'];
            }
            
            if (isset($regenerateStats['error'])) {
                $stats['errors'][] = $regenerateStats['error'];
            }
            
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
     * Nettoie uniquement les occurrences périmées sans régénération
     * 
     * @return int Nombre d'occurrences supprimées
     */
    public static function cleanupOnly(): int
    {
        try {
            $count = EventOccurrence::cleanupOutdated();
            
            LogService::info("Nettoyage des occurrences périmées", [
                'deleted_count' => $count
            ]);
            
            return $count;
            
        } catch (\Exception $e) {
            LogService::error("Erreur lors du nettoyage des occurrences", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Vérifie si une maintenance est nécessaire
     * (si des occurrences existent en dehors de la fenêtre)
     * 
     * @return bool True si maintenance nécessaire
     */
    public static function needsMaintenance(): bool
    {
        try {
            $db = EventOccurrence::getDbConnection();
            
            $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
            $oneYearAhead = date('Y-m-d', strtotime('+1 year'));
            
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM event_occurrences 
                                 WHERE occurrence_date < ? OR occurrence_date > ?");
            $stmt->execute([$sixMonthsAgo, $oneYearAhead]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return ($result['count'] ?? 0) > 0;
            
        } catch (\Exception $e) {
            LogService::error("Erreur lors de la vérification de maintenance", [
                'error' => $e->getMessage()
            ]);
            return false;
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
            
            // Occurrences dans la fenêtre
            $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
            $oneYearAhead = date('Y-m-d', strtotime('+1 year'));
            
            $stmt = $db->prepare("SELECT COUNT(*) as in_window FROM event_occurrences 
                                 WHERE occurrence_date >= ? AND occurrence_date <= ?");
            $stmt->execute([$sixMonthsAgo, $oneYearAhead]);
            $inWindow = $stmt->fetch(\PDO::FETCH_ASSOC)['in_window'] ?? 0;
            
            // Occurrences modifiées
            $stmt = $db->query("SELECT COUNT(*) as modified FROM event_occurrences WHERE is_modified = 1");
            $modified = $stmt->fetch(\PDO::FETCH_ASSOC)['modified'] ?? 0;
            
            // Occurrences annulées
            $stmt = $db->query("SELECT COUNT(*) as cancelled FROM event_occurrences WHERE is_cancelled = 1");
            $cancelled = $stmt->fetch(\PDO::FETCH_ASSOC)['cancelled'] ?? 0;
            
            // Nombre d'événements récurrents
            $stmt = $db->query("SELECT COUNT(DISTINCT event_id) as recurring_events FROM event_occurrences");
            $recurringEvents = $stmt->fetch(\PDO::FETCH_ASSOC)['recurring_events'] ?? 0;
            
            return [
                'total_occurrences' => (int)$total,
                'in_window' => (int)$inWindow,
                'out_of_window' => (int)($total - $inWindow),
                'modified_occurrences' => (int)$modified,
                'cancelled_occurrences' => (int)$cancelled,
                'recurring_events' => (int)$recurringEvents,
                'window_start' => $sixMonthsAgo,
                'window_end' => $oneYearAhead
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
}
