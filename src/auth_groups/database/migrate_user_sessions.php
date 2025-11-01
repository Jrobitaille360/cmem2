<?php
/**
 * Script de migration pour créer la table user_sessions
 * À exécuter sur le serveur externe pour créer la nouvelle table
 */

require_once __DIR__ . '/../../autoloader.php';

try {
    echo "=== Migration: Création de la table user_sessions ===\n";
    
    // Connexion à la base de données
    $pdo = \Database::getInstance()->getConnection();
    
    // Lire le fichier SQL
    $sqlFile = __DIR__ . '/create_table_user_sessions.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Fichier SQL introuvable: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new Exception("Impossible de lire le fichier SQL");
    }
    
    echo "Lecture du fichier SQL: OK\n";
    
    // Vérifier si la table existe déjà
    $checkTable = $pdo->query("SHOW TABLES LIKE 'user_sessions'");
    if ($checkTable->rowCount() > 0) {
        echo "La table user_sessions existe déjà.\n";
        echo "Voulez-vous la recréer ? (y/N): ";
        $response = trim(fgets(STDIN));
        if (strtolower($response) !== 'y') {
            echo "Migration annulée.\n";
            exit(0);
        }
        
        // Supprimer la table existante
        $pdo->exec("DROP TABLE IF EXISTS user_sessions");
        echo "Table existante supprimée.\n";
    }
    
    // Exécuter le SQL par blocs (séparé par ;)
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            echo "Exécution: " . substr($statement, 0, 50) . "...\n";
            $pdo->exec($statement);
        }
    }
    
    // Vérifier que la table a été créée
    $checkTable = $pdo->query("SHOW TABLES LIKE 'user_sessions'");
    if ($checkTable->rowCount() === 0) {
        throw new Exception("La table user_sessions n'a pas été créée");
    }
    
    // Afficher la structure de la table
    echo "\n=== Structure de la table user_sessions ===\n";
    $describe = $pdo->query("DESCRIBE user_sessions");
    while ($row = $describe->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("%-15s %-20s %-10s %-10s %-10s %s\n", 
            $row['Field'], 
            $row['Type'], 
            $row['Null'], 
            $row['Key'], 
            $row['Default'], 
            $row['Extra']
        );
    }
    
    echo "\n=== Migration terminée avec succès ===\n";
    echo "La table user_sessions a été créée et est prête à être utilisée.\n";
    echo "Vous pouvez maintenant tester le système d'authentification.\n";
    
} catch (Exception $e) {
    echo "\n❌ ERREUR: " . $e->getMessage() . "\n";
    echo "Fichier: " . $e->getFile() . " (ligne " . $e->getLine() . ")\n";
    exit(1);
}