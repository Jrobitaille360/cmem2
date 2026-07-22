<?php
/**
 * Script de maintenance des occurrences d'événements récurrents
 * 
 * Ce script doit être exécuté régulièrement (ex: quotidiennement via cron)
 * pour régénérer les occurrences jusqu'en 2099-12-31
 * Par défaut, seuls les événements modifiés depuis la dernière maintenance sont traités
 * 
 * Usage:
 *   php maintenance_occurrences.php [--stats] [--check] [--force]
 * 
 * Options:
 *   --stats         Afficher les statistiques
 *   --check         Vérifier le nombre d'occurrences stockées
 *   --force         Forcer la régénération complète de tous les événements
 */

// Charger l'autoloader
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../auth_groups/loader.php';
require_once __DIR__ . '/autoloader.php';

use ICS\Services\OccurrenceMaintenanceService;

// Parse command line arguments
$options = getopt('', ['stats', 'check', 'force']);

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
    
    // DÉPRÉCIÉ — la matérialisation des occurrences est abandonnée (expansion à la volée
    // via /occurrences/expand). performMaintenance() est un no-op ; retirer l'entrée
    // crontab qui appelle ce script. --stats / --check restent disponibles pour inspection.
    echo "⚠ DÉPRÉCIÉ : matérialisation des occurrences abandonnée. Aucune régénération.\n";
    echo "  L'expansion se fait à la volée (GET /calendars/{id}/events/occurrences/expand).\n";
    echo "  → Retirer l'entrée crontab appelant ce script.\n";

    $forceAll = isset($options['force']);
    $stats = OccurrenceMaintenanceService::performMaintenance($forceAll);
    
    echo "✓ " . $stats['regenerated_events'] . " événement(s) récurrent(s) traité(s)\n";
    
    if (isset($stats['skipped_events']) && $stats['skipped_events'] > 0) {
        echo "  " . $stats['skipped_events'] . " événement(s) non modifié(s) ignoré(s)\n";
    }
    
    if (isset($stats['last_maintenance'])) {
        echo "  Dernière maintenance: " . $stats['last_maintenance'] . "\n";
    }
    
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
