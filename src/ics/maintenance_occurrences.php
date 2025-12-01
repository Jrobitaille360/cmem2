<?php
/**
 * Script de maintenance des occurrences d'événements récurrents
 * 
 * Ce script doit être exécuté régulièrement (ex: quotidiennement via cron)
 * pour régénérer toutes les occurrences jusqu'en 2099-12-31
 * 
 * Usage:
 *   php maintenance_occurrences.php [--stats] [--check]
 * 
 * Options:
 *   --stats         Afficher les statistiques
 *   --check         Vérifier le nombre d'occurrences stockées
 */

// Charger l'autoloader
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../auth_groups/loader.php';
require_once __DIR__ . '/autoloader.php';

use ICS\Services\OccurrenceMaintenanceService;

// Parse command line arguments
$options = getopt('', ['stats', 'check']);

$timezone = date_default_timezone_get();
$date = date('Y-m-d H:i:s')." (".$timezone.")\n";
echo $date;

try {
    // Vérification seulement
    if (isset($options['check'])) {
        echo "Vérification des occurrences stockées...\n";
        $stats = OccurrenceMaintenanceService::getStatistics();
        
        if (isset($stats['error'])) {
            echo "✗ Erreur : " . $stats['error'] . "\n";
            exit(1);
        }
        
        echo "✓ Total d'occurrences : " . $stats['total_occurrences'] . "\n";
        echo "✓ Événements récurrents : " . $stats['recurring_events'] . "\n";
        echo "✓ Plage de dates : " . $stats['date_range_start'] . " → " . $stats['date_range_end'] . "\n";
        exit(0);
    }
    
    // Statistiques seulement
    if (isset($options['stats'])) {
        echo "Statistiques des occurrences :\n";
        echo "------------------------------\n";
        $stats = OccurrenceMaintenanceService::getStatistics();
        
        if (isset($stats['error'])) {
            echo "✗ Erreur : " . $stats['error'] . "\n";
            exit(1);
        }
        
        echo "✓ Total d'occurrences           : " . $stats['total_occurrences'] . "\n";
        echo "✓ Occurrences modifiées         : " . $stats['modified_occurrences'] . "\n";
        echo "✓ Occurrences annulées          : " . $stats['cancelled_occurrences'] . "\n";
        echo "✓ Événements récurrents         : " . $stats['recurring_events'] . "\n";
        echo "✓ Plage de dates : " . $stats['date_range_start'] . " → " . $stats['date_range_end'] . "\n";
        exit(0);
    }
    
    // Maintenance complète (par défaut)
    $stats = OccurrenceMaintenanceService::performMaintenance();
    
    echo "✓ " . $stats['regenerated_events'] . " événement(s) récurrent(s) traité(s)\n";
    
    if (!empty($stats['errors'])) {
        echo "\n⚠ Erreurs rencontrées :\n";
        foreach ($stats['errors'] as $error) {
            echo "  - " . $error . "\n";
        }
        exit(1);
    }
    
    // Afficher les statistiques finales
    $finalStats = OccurrenceMaintenanceService::getStatistics();
    echo "✓ Total d'occurrences : " . $finalStats['total_occurrences'] . "\n";
    echo "✓ Événements récurrents : " . $finalStats['recurring_events'] . "\n";
    
    exit(0);
    
} catch (\Exception $e) {
    echo "\n✗ Erreur fatale : " . $e->getMessage() . "\n";
    echo "Stack trace :\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
