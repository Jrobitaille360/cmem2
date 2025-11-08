<?php
/**
 * Proxy CalDAV simple pour ajouter automatiquement l'API Key
 * 
 * Ce proxy permet d'utiliser votre API CalDAV avec des clients qui ne supportent
 * pas les headers personnalisés (comme Thunderbird, Apple Calendar, etc.)
 * 
 * Usage :
 *   1. Configurer l'API Key ci-dessous
 *   2. Démarrer : php -S localhost:8888 caldav_proxy.php
 *   3. Dans votre client CalDAV, utiliser : http://localhost:8888/
 */

// ============================================
// CONFIGURATION
// ============================================

// URL de votre API CalDAV
const TARGET_URL = 'http://localhost/cmem2_API/caldav';

// Votre API Key (à générer via votre système)
const API_KEY = 'ag_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

// Mode debug (affiche les requêtes dans la console)
const DEBUG = true;

// ============================================
// CODE DU PROXY
// ============================================

// Fonction de log pour le debug
function debugLog($message, $data = null) {
    if (!DEBUG) return;
    
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $message";
    
    if ($data !== null) {
        echo ": " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
    echo "\n";
}

// Récupérer les informations de la requête
$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$query = parse_url($requestUri, PHP_URL_QUERY);

// Construire l'URL cible
$targetUrl = TARGET_URL . $path;
if ($query) {
    $targetUrl .= '?' . $query;
}

debugLog("Requête reçue", [
    'method' => $method,
    'path' => $path,
    'target' => $targetUrl
]);

// Récupérer les headers de la requête
$requestHeaders = [];
foreach (getallheaders() as $key => $value) {
    // Ne pas transférer certains headers
    if (in_array(strtolower($key), ['host', 'connection'])) {
        continue;
    }
    $requestHeaders[] = "$key: $value";
}

// Ajouter l'API Key
$requestHeaders[] = "X-API-Key: " . API_KEY;

debugLog("Headers envoyés", array_map(function($h) {
    // Masquer l'API Key dans les logs
    if (strpos($h, 'X-API-Key:') === 0) {
        return 'X-API-Key: [REDACTED]';
    }
    return $h;
}, $requestHeaders));

// Récupérer le corps de la requête
$requestBody = file_get_contents('php://input');

if ($requestBody && DEBUG) {
    debugLog("Corps de la requête", strlen($requestBody) . " octets");
}

// Initialiser cURL
$ch = curl_init($targetUrl);

// Configurer cURL
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $requestHeaders,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_TIMEOUT => 60,
]);

// Ajouter le corps si nécessaire
if ($requestBody) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
}

// Exécuter la requête
$response = curl_exec($ch);

// Vérifier les erreurs cURL
if (curl_errno($ch)) {
    $error = curl_error($ch);
    curl_close($ch);
    
    debugLog("Erreur cURL", $error);
    
    http_response_code(502);
    header('Content-Type: text/plain');
    echo "Erreur de connexion au serveur CalDAV: $error";
    exit;
}

// Récupérer les informations de la réponse
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

curl_close($ch);

// Séparer headers et body
$responseHeaders = substr($response, 0, $headerSize);
$responseBody = substr($response, $headerSize);

debugLog("Réponse reçue", [
    'status' => $httpCode,
    'body_size' => strlen($responseBody) . ' octets'
]);

// Parser et retransmettre les headers de la réponse
$headerLines = explode("\r\n", $responseHeaders);
foreach ($headerLines as $header) {
    $header = trim($header);
    
    // Ignorer la ligne de statut HTTP et les headers vides
    if (empty($header) || strpos($header, 'HTTP/') === 0) {
        continue;
    }
    
    // Ignorer certains headers problématiques
    if (preg_match('/^(Transfer-Encoding|Connection):/i', $header)) {
        continue;
    }
    
    header($header);
}

// Définir le code de statut HTTP
http_response_code($httpCode);

// Retourner le corps de la réponse
echo $responseBody;

debugLog("Réponse envoyée", [
    'status' => $httpCode,
    'content_length' => strlen($responseBody)
]);
