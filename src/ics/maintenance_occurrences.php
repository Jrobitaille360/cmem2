<?php
/**
 * Script de maintenance des occurrences d'événements récurrents
 * 
 * Ce script doit être exécuté régulièrement (ex: quotidiennement via cron)
 * pour maintenir la fenêtre glissante des occurrences (-6 mois à +1 an)
 * 
 * Usage:
 *   php maintenance_occurrences.php [--cleanup-only] [--stats] [--check]
 * 
 * Options:
 *   --cleanup-only  Nettoyer uniquement sans régénération
 *   --stats         Afficher les statistiques
 *   --check         Vérifier si maintenance nécessaire
 */

// Charger l'autoloader
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/autoloader.php';

use ICS\Services\OccurrenceMaintenanceService;

// Parse command line arguments
$options = getopt('', ['cleanup-only', 'stats', 'check']);

echo "========================================\n";
echo "Maintenance des Occurrences d'Événements\n";
echo "========================================\n\n";

try {
    // Vérification seulement
    if (isset($options['check'])) {
        echo "Vérification si maintenance nécessaire...\n";
        $needs = OccurrenceMaintenanceService::needsMaintenance();
        
        if ($needs) {
            echo "✗ Maintenance nécessaire : des occurrences sont en dehors de la fenêtre\n";
            exit(1);
        } else {
            echo "✓ Pas de maintenance nécessaire\n";
            exit(0);
        }
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
        
        echo "Total d'occurrences           : " . $stats['total_occurrences'] . "\n";
        echo "Dans la fenêtre glissante     : " . $stats['in_window'] . "\n";
        echo "Hors de la fenêtre            : " . $stats['out_of_window'] . "\n";
        echo "Occurrences modifiées         : " . $stats['modified_occurrences'] . "\n";
        echo "Occurrences annulées          : " . $stats['cancelled_occurrences'] . "\n";
        echo "Événements récurrents         : " . $stats['recurring_events'] . "\n";
        echo "Fenêtre : " . $stats['window_start'] . " → " . $stats['window_end'] . "\n";
        exit(0);
    }
    
    // Nettoyage seulement
    if (isset($options['cleanup-only'])) {
        echo "Nettoyage des occurrences périmées...\n";
        $count = OccurrenceMaintenanceService::cleanupOnly();
        echo "✓ " . $count . " occurrence(s) supprimée(s)\n";
        exit(0);
    }
    
    // Maintenance complète (par défaut)
    echo "Démarrage de la maintenance complète...\n\n";
    
    echo "Étape 1/2 : Nettoyage des occurrences périmées...\n";
    $stats = OccurrenceMaintenanceService::performMaintenance();
    
    echo "✓ " . $stats['cleaned_count'] . " occurrence(s) périmée(s) supprimée(s)\n";
    
    echo "\nÉtape 2/2 : Régénération des occurrences...\n";
    echo "✓ " . $stats['regenerated_events'] . " événement(s) récurrent(s) traité(s)\n";
    
    if (!empty($stats['errors'])) {
        echo "\n⚠ Erreurs rencontrées :\n";
        foreach ($stats['errors'] as $error) {
            echo "  - " . $error . "\n";
        }
        exit(1);
    }
    
    echo "\n✓ Maintenance terminée avec succès\n";
    
    // Afficher les statistiques finales
    echo "\nStatistiques finales :\n";
    echo "----------------------\n";
    $finalStats = OccurrenceMaintenanceService::getStatistics();
    echo "Total d'occurrences : " . $finalStats['total_occurrences'] . "\n";
    echo "Événements récurrents : " . $finalStats['recurring_events'] . "\n";
    
    exit(0);
    
} catch (\Exception $e) {
    echo "\n✗ Erreur fatale : " . $e->getMessage() . "\n";
    echo "Stack trace :\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
