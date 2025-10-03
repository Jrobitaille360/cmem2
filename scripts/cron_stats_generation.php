<?php
/**
 * Script Cron pour génération automatique des statistiques
 * À exécuter quotidiennement via cron job
 */

require_once __DIR__ . '/../config/loader.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Configuration des logs pour le cron
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/cron-stats.log');

function logMessage($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message\n";
    
    echo $logEntry;
    error_log($logEntry, 3, __DIR__ . '/../logs/cron-stats.log');
}

try {
    logMessage("=== Début génération automatique des statistiques ===");
    
    // Connexion à la base de données
    $database = Database::getInstance();
    $pdo = $database->getConnection();
    
    // Vérifier si les statistiques ont déjà été générées aujourd'hui
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM platform_stats 
        WHERE DATE(generated_at) = CURDATE()
    ");
    $alreadyGenerated = $stmt->fetch()['count'];
    
    if ($alreadyGenerated > 0) {
        logMessage("Statistiques déjà générées aujourd'hui, arrêt du script");
        exit(0);
    }
    
    // Générer les statistiques via la procédure stockée
    logMessage("Génération des statistiques en cours...");
    $stmt = $pdo->query("CALL GenerateAllStats()");
    logMessage("✅ Statistiques générées avec succès");
    
    // Nettoyer les anciennes statistiques (garde 30 jours)
    logMessage("Nettoyage des anciennes statistiques...");
    $stmt = $pdo->query("CALL CleanupOldStats()");
    logMessage("✅ Nettoyage terminé");
    
    // Vérifier les résultats
    $stmt = $pdo->query("SELECT * FROM v_admin_dashboard");
    $dashboard = $stmt->fetch(PDO::FETCH_ASSOC);
    
    logMessage("📊 Résultats:");
    logMessage("   - Utilisateurs: {$dashboard['total_users']}");
    logMessage("   - Groupes: {$dashboard['total_groups']}");
    logMessage("   - Mémoires: {$dashboard['total_memories']}");
    logMessage("   - Stockage: {$dashboard['total_storage_mb']} MB");
    
    // Optionnel: Envoyer un email de rapport (si EmailService configuré)
    /*
    try {
        $emailService = new \App\Services\EmailService();
        $emailService->sendStatsReport($dashboard);
        logMessage("📧 Rapport envoyé par email");
    } catch (Exception $e) {
        logMessage("⚠️ Échec envoi email: " . $e->getMessage(), 'WARNING');
    }
    */
    
    logMessage("=== Génération automatique terminée avec succès ===");
    
} catch (Exception $e) {
    logMessage("❌ ERREUR: " . $e->getMessage(), 'ERROR');
    logMessage("Trace: " . $e->getTraceAsString(), 'ERROR');
    exit(1);
}
