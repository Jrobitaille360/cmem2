<?php
/**
 * Script de décompression et conversion d'un backup ZIP protégé par mot de passe
 * Usage: php backup_to_json.php <nom_fichier.zip>
 */

require_once __DIR__ . '/auth_groups/loader.php';

function respond(array $payload, int $statusCode = 200): void {
    $isCli = (PHP_SAPI === 'cli');
    if ($isCli) {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

try {
    // Récupérer le nom du fichier
    $zipFilename = null;
    
    if (PHP_SAPI === 'cli') {
        // Mode CLI : argument de ligne de commande
        if ($argc < 2) {
            throw new RuntimeException('Usage: php backup_to_json.php <nom_fichier.zip>');
        }
        $zipFilename = $argv[1];
    } else {
        // Mode HTTP : paramètre GET ou POST
        $zipFilename = $_GET['file'] ?? $_POST['file'] ?? null;
        if (empty($zipFilename)) {
            throw new RuntimeException('Paramètre "file" manquant');
        }
    }
    
    // Construire le chemin complet
    $baseDir = defined('TMP_ASSETS_DIR') ? TMP_ASSETS_DIR : (__DIR__ . '/../tmp_assets/');
    $downloadDir = rtrim($baseDir, '/\\') . '/downloads/';
    
    // Si le nom de fichier ne contient pas de chemin complet, chercher dans downloads/
    if (strpos($zipFilename, '/') === false && strpos($zipFilename, '\\') === false) {
        $zipPath = $downloadDir . $zipFilename;
    } else {
        $zipPath = $zipFilename;
    }
    
    // Vérifier que le fichier existe
    if (!file_exists($zipPath)) {
        throw new RuntimeException("Fichier ZIP introuvable : $zipPath");
    }
    
    // Récupérer le mot de passe de la base de données
    $password = $_ENV['DB_PASS'] ?? '';
    if ($password === '') {
        throw new RuntimeException('DB_PASS est vide, impossible de déchiffrer le ZIP');
    }
    
    // Ouvrir le ZIP avec le mot de passe
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException("Impossible d'ouvrir le fichier ZIP");
    }
    
    // Définir le mot de passe
    $zip->setPassword($password);
    
    // Lister les fichiers dans l'archive
    $numFiles = $zip->numFiles;
    if ($numFiles === 0) {
        $zip->close();
        throw new RuntimeException("Le fichier ZIP est vide");
    }
    
    // Extraire le premier fichier JSON trouvé
    $jsonContent = null;
    $extractedFilename = null;
    
    for ($i = 0; $i < $numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $filename = $stat['name'];
        
        if (pathinfo($filename, PATHINFO_EXTENSION) === 'json') {
            $jsonContent = $zip->getFromIndex($i);
            $extractedFilename = $filename;
            break;
        }
    }
    
    $zip->close();
    
    if ($jsonContent === false || $jsonContent === null) {
        throw new RuntimeException("Impossible d'extraire le fichier JSON (mot de passe incorrect ou fichier corrompu)");
    }
    
    // Sauvegarder le JSON extrait
    $outputJsonPath = $downloadDir . pathinfo($zipFilename, PATHINFO_FILENAME) . '_extracted.json';
    if (file_put_contents($outputJsonPath, $jsonContent) === false) {
        throw new RuntimeException("Impossible d'écrire le fichier JSON extrait");
    }
    
    // Parser le JSON pour vérification
    $data = json_decode($jsonContent, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Le contenu extrait n'est pas un JSON valide : " . json_last_error_msg());
    }
    
    respond([
        'ok' => true,
        'message' => 'Fichier ZIP déchiffré et converti avec succès',
        'zip_file' => $zipPath,
        'extracted_json' => $outputJsonPath,
        'tables_count' => isset($data['tables']) ? count($data['tables']) : null,
        'exported_at' => $data['exported_at'] ?? null
    ]);
    
} catch (Throwable $e) {
    respond([
        'ok' => false,
        'error' => $e->getMessage()
    ], 500);
}
