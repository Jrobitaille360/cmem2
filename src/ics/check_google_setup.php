<?php
/**
 * Script de vérification de l'installation Google Calendar
 * 
 * Ce script vérifie que tous les prérequis sont en place avant la première synchronisation.
 * 
 * Usage : php check_google_setup.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

echo "\n╔════════════════════════════════════════════════╗\n";
echo "║    Vérification Installation Google Calendar   ║\n";
echo "╚════════════════════════════════════════════════╝\n\n";

$errors = [];
$warnings = [];

// 1. Vérifier que la bibliothèque Google API est installée
echo "1. Vérification de la bibliothèque Google API Client...\n";
if (class_exists('Google\Client')) {
    echo "   ✓ Google API Client installé\n";
    
    // Afficher la version
    $reflection = new ReflectionClass('Google\Client');
    $composerPath = dirname(dirname($reflection->getFileName())) . '/composer.json';
    if (file_exists($composerPath)) {
        $composerData = json_decode(file_get_contents($composerPath), true);
        echo "   ℹ Version: " . ($composerData['version'] ?? 'inconnue') . "\n";
    }
} else {
    $errors[] = "Google API Client n'est pas installé. Exécutez: composer require google/apiclient:^2.15";
}

// 2. Vérifier le fichier credentials.json
echo "\n2. Vérification du fichier credentials.json...\n";
$credentialsPath = __DIR__ . '/credentials.json';
if (file_exists($credentialsPath)) {
    echo "   ✓ Fichier credentials.json trouvé\n";
    
    $credentials = json_decode(file_get_contents($credentialsPath), true);
    if (isset($credentials['installed']) || isset($credentials['web'])) {
        echo "   ✓ Format credentials valide\n";
        
        $clientInfo = $credentials['installed'] ?? $credentials['web'];
        echo "   ℹ Project ID: " . ($clientInfo['project_id'] ?? 'non défini') . "\n";
        echo "   ℹ Client ID: " . substr($clientInfo['client_id'] ?? 'non défini', 0, 20) . "...\n";
    } else {
        $errors[] = "Le fichier credentials.json n'a pas le bon format";
    }
} else {
    $errors[] = "Fichier credentials.json introuvable dans src/ics/";
    echo "   ✗ Fichier introuvable: $credentialsPath\n";
    echo "   → Téléchargez-le depuis Google Cloud Console\n";
    echo "   → Consultez README_GOOGLE_SYNC.md pour les instructions\n";
}

// 3. Vérifier le token.json (autorisation)
echo "\n3. Vérification de l'autorisation Google...\n";
$tokenPath = __DIR__ . '/token.json';
if (file_exists($tokenPath)) {
    echo "   ✓ Token d'autorisation trouvé\n";
    
    $token = json_decode(file_get_contents($tokenPath), true);
    if (isset($token['access_token'])) {
        echo "   ✓ Token valide\n";
        
        if (isset($token['created'])) {
            $age = time() - $token['created'];
            $ageHours = floor($age / 3600);
            echo "   ℹ Âge du token: {$ageHours}h\n";
        }
        
        if (isset($token['expires_in'])) {
            echo "   ℹ Expire dans: " . floor($token['expires_in'] / 3600) . "h\n";
        }
    } else {
        $warnings[] = "Le token.json semble invalide";
    }
} else {
    $warnings[] = "Aucune autorisation trouvée. Vous devrez autoriser l'accès lors de la première exécution.";
    echo "   ⚠ Token non trouvé (normal pour première utilisation)\n";
    echo "   → Sera créé lors de la première exécution du script\n";
}

// 4. Vérifier les permissions des fichiers
echo "\n4. Vérification des permissions...\n";
$icsDir = __DIR__;
if (is_writable($icsDir)) {
    echo "   ✓ Répertoire src/ics/ accessible en écriture\n";
} else {
    $errors[] = "Le répertoire src/ics/ n'est pas accessible en écriture";
}

// 5. Vérifier la connexion à la base de données
echo "\n5. Vérification de la base de données...\n";
try {
    require_once __DIR__ . '/../auth_groups/loader.php';
    $db = \Database::getInstance()->getConnection();
    echo "   ✓ Connexion à la base de données OK\n";
    
    // Vérifier la table calendar_events
    $stmt = $db->query("SHOW TABLES LIKE 'calendar_events'");
    if ($stmt->rowCount() > 0) {
        echo "   ✓ Table calendar_events existe\n";
        
        // Compter les événements
        $stmt = $db->query("SELECT COUNT(*) as count FROM calendar_events");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        echo "   ℹ Nombre d'événements: $count\n";
    } else {
        $errors[] = "Table calendar_events introuvable";
    }
} catch (Exception $e) {
    $errors[] = "Erreur de connexion à la base de données: " . $e->getMessage();
}

// 6. Vérifier le fichier de configuration du script
echo "\n6. Vérification de la configuration...\n";
$syncScript = __DIR__ . '/sync_google_calendar.php';
if (file_exists($syncScript)) {
    echo "   ✓ Script sync_google_calendar.php trouvé\n";
    
    $content = file_get_contents($syncScript);
    
    // Vérifier les constantes importantes
    if (preg_match('/const GOOGLE_CALENDAR_ID = [\'"](.+?)[\'"]/', $content, $matches)) {
        echo "   ℹ Google Calendar ID: {$matches[1]}\n";
    }
    
    if (preg_match('/const LOCAL_CALENDAR_ID = (\d+)/', $content, $matches)) {
        echo "   ℹ Local Calendar ID: {$matches[1]}\n";
    }
    
    if (preg_match('/const TIMEZONE = [\'"](.+?)[\'"]/', $content, $matches)) {
        echo "   ℹ Timezone: {$matches[1]}\n";
    }
} else {
    $errors[] = "Script sync_google_calendar.php introuvable";
}

// 7. Vérifier les extensions PHP requises
echo "\n7. Vérification des extensions PHP...\n";
$requiredExtensions = ['curl', 'json', 'pdo', 'pdo_mysql'];
foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        echo "   ✓ Extension $ext installée\n";
    } else {
        $errors[] = "Extension PHP '$ext' manquante";
    }
}

// Résumé
echo "\n╔════════════════════════════════════════════════╗\n";
echo "║                    RÉSUMÉ                      ║\n";
echo "╚════════════════════════════════════════════════╝\n\n";

if (empty($errors) && empty($warnings)) {
    echo "✅ TOUT EST PRÊT !\n\n";
    echo "Prochaines étapes :\n";
    echo "1. Vérifiez la configuration dans sync_google_calendar.php\n";
    echo "2. Exécutez: php src/ics/sync_google_calendar.php\n";
    echo "3. Autorisez l'accès Google dans votre navigateur\n";
    echo "4. Configurez la synchronisation automatique (cron/Task Scheduler)\n\n";
    exit(0);
} else {
    if (!empty($errors)) {
        echo "❌ ERREURS DÉTECTÉES :\n";
        foreach ($errors as $error) {
            echo "   • $error\n";
        }
        echo "\n";
    }
    
    if (!empty($warnings)) {
        echo "⚠️  AVERTISSEMENTS :\n";
        foreach ($warnings as $warning) {
            echo "   • $warning\n";
        }
        echo "\n";
    }
    
    echo "Consultez README_GOOGLE_SYNC.md pour les instructions de configuration.\n\n";
    exit(1);
}
