<?php

use SebastianBergmann\CliParser\AmbiguousOptionException;
echo "\n==============================================\n";
define('IGNORE_NEW_BASE_USER_CREATION_TESTS', true);
define('IGNORE_ADMIN_USER_LOGIN', true);
// Configuration pour les tests (comme le frontend)
if (!defined('TMP_ASSETS_DIR')) {
    define('TMP_ASSETS_DIR', __DIR__ . '/../../tmp_assets/');
}

define('BASE_CMEM_URL', 'http://localhost/cmem2_API/');
//define('BASE_CMEM_URL', 'https://cmem2.journauxdebord.com/');


// Fonctions utilitaires partagées par tous les tests
$totalTests = 0;
$successTests = 0;
$failedTests = 0;
$failedTestsDetails = [];

function callNewApi($endpoint, $api_key = null, $method = 'POST', $data = [], $files = [], $goodCode = [200])
{
    $url = BASE_CMEM_URL . ltrim($endpoint, '/');
    
    // Pour les requêtes GET, ajouter les données comme paramètres de query string seulement si nécessaire
    if ($method === 'GET' && !empty($data))
    {
        $queryString = http_build_query($data);
        $url .= '?' . $queryString;
        $data = []; // Vider $data pour éviter de l'envoyer dans le body
    }

    $ch = curl_init($url);

    $headers = [ ];
    if($api_key)
    {
        $headers[] = "X-API-Key: {$api_key}";
    }

    $hasFiles = is_array($files) && count($files) > 0;
    if ($hasFiles)
    {
        $postFields = [];
        // Ajoute les champs de formulaire classiques
        foreach ($data as $k => $v)
        {
            $postFields[$k] = $v;
        }
        // Ajoute les fichiers (clé => chemin)
        foreach ($files as $field => $filePath)
        {
            if (file_exists($filePath))
            {
                $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
                $postFields[$field] = new CURLFile($filePath, $mimeType);
            }
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    }
    else if (!empty($data))
    {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    if (!empty($headers))
    {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = curl_getinfo($ch, CURLINFO_HEADER_OUT);
    
    return [
        'success' => in_array($httpCode, $goodCode),
        'body' => json_decode($result, true),

        'code' => $httpCode,
        'headers_sent' => $headers
    ];
}

function testNewResult($ok, $message)
{
    global $totalTests, $successTests, $failedTests;
    $totalTests++;
    $okColor = "\033[32m";
    $failColor = "\033[31m";
    $reset = "\033[0m";
    if ($ok)
    {
        $successTests++;
        echo $okColor . str_pad('[OK]', 8) . $reset . " $message\n";
    }
    else
    {
        $failedTests++;
        echo $failColor . str_pad('[FAIL]', 8) . $reset . " $message\n";
    }
}

function printNewSection($title)
{
    $line = str_repeat('=', 60);
    echo "\n$line\n$title\n$line\n";
}

function getAdminAPIKey()
{
    return "ag_live_73c4f1279c145c9aabd379b28be98b34951f3d4eee7fc7a3dc52bc84e1a0b6d8";
}

function getUserApiKey()
{
    return "ag_live_84b60f8d6ce94daf4eb5848eb2bda3ea49bcd0b171423f5f0370ee620b4ceefc";
}

function getSecretKey()
{
    return 'Etzwsge!1*dh6TKHukndF8uvZ0mGERy2Kh5n3FGGHT0YjSA4AhTHqBfq2cTC$WGP'; 
}

function createUserApiKey($email = 'user@cmem2.com', $password = 'Qwerty123456')
{
    $adminApiKey = getAdminAPIKey();
    // get user Id by email
    $resp = callNewApi('users',$adminApiKey, 'GET',['email' => $email], [], [200]);

    if (!$resp['success'])
    {
        throw new Exception("❌ Impossible de récupérer l'ID utilisateur pour $email : " . ($resp['body']['message'] ?? 'Utilisateur non trouvé'));
    }

    $userId = $resp['body']['data']['users'][0]['id'];
    $secretKey = getSecretKey();
    // get or create user API key
    $resp = callNewApi("secret-admin/api-keys", $adminApiKey, 'POST', [
        'admin_secret' => $secretKey,
        'user_id' => $userId,
        'name' => 'API Key pour tests automatisés',
        'scopes' => ['read', 'write'],
        'environment' => 'test',
        'rate_limit_per_minute' => 60,
        'rate_limit_per_hour' => 1000,
    ], [], [200]);

    if (!$resp['success'])
    {
        throw new Exception("❌ Impossible de créer la clé API pour $email : " . ($resp['body']['message'] ?? 'Erreur inconnue'));
    }

    return $resp['body']['data'];
}

function callNewTest($testDesc = 'no desc',$endpoint= 'NO ENDPOINT', $api_key = null, $method = 'POST', $data = [], $files = [], $goodCode = [200]){
    global $failedTestsDetails;
    //printNewSection($testDesc);
    $response = callNewApi($endpoint, $api_key, $method, $data, $files, $goodCode);
    testNewResult($response['success'], $testDesc . " (code $response[code])");
    if (!$response['success']) {
        echo "Response body: " . json_encode($response['body']) . "\n";
        $failedTestsDetails[] = [
            'test' => $testDesc,
            'response' => $response
        ];
    }
    return $response;
}

function callNewUploadICS($testDesc = 'no desc', $endpoint = 'NO ENDPOINT', $api_key = null, $method = 'POST', $data = [], $files = [], $goodCode = [200]){
    global $failedTestsDetails;
    //printNewSection($testDesc);

    $icsFilePath = __DIR__ . '/../tmp_assets/'.$data['icsfile'];

    if (!file_exists($icsFilePath)) {
        $response2['success'] = false;
        $response2['message'] = "Le fichier ICS spécifié n'existe pas : $icsFilePath";
        $response2['http_code'] = 'NONE';
        testNewResult($response2['success'], $testDesc . " (code $response2[http_code])");
        return $response2;
    }

    $data['icsfile'] = new CURLFile($icsFilePath, 'text/calendar');

    //upload ICS file avec curl_exec
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, BASE_CMEM_URL . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-API-Key: {$api_key}"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $response2 = json_decode($response, true);
    $response2['http_code'] = $httpCode;
    testNewResult($response2['success'], $testDesc . " (code $response2[http_code])");
    return $response2;
}

function callNewDownload($testDesc = 'no desc', $endpoint = 'NO ENDPOINT', $api_key = null,  $method = 'POST', $data = [], $files = [], $goodCode = [200], $compareFile = null)
{
    // Extraire le fileId de l'endpoint (assume format /files/{id} or /files/{id}/...)
    $fileId = null;
    if (preg_match('#/?files/(\d+)(?:/|$)#', $endpoint, $matches)) {
        $fileId = $matches[1];
    } else {
        $response2['success'] = false;
        $response2['message'] = "l'entrypoint doit avoir un File_id - format /files/{id}";
        $response2['http_code'] = 'NONE';
        testNewResult($response2['success'], $testDesc . " (code $response2[code])");
        return $response2;
     }
    // printNewSection($testDesc);
    // calling endpoint download
    $response = callNewApi($endpoint, $api_key, $method, $data, $files);
    if (!$response['success']) {
        testNewResult(in_array($response['code'], $goodCode), $testDesc . $response['body']['message'] . ": (code $response[code])");
        return $response;
    } 
    $downloadDir = 'C:\Users\escif\Proton Drive\jrobitaille04\My files\My_htdocs\cmem2_API\tmp_assets\downloads';
    if (!is_dir($downloadDir)) {
        mkdir($downloadDir, 0777, true);
    }
    // Définir le chemin du fichier téléchargé
    $downloadedFilePath = $downloadDir . '/downloaded_file_' . $fileId . '.jpg';
    // Appel direct avec cURL pour récupérer le fichier binaire
    $ch = curl_init(BASE_CMEM_URL . "files/$fileId");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-API-Key: {$api_key}"
    ]);
    $fileContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // Sauvegarder le fichier téléchargé
    if ($httpCode === 200) {
        file_put_contents($downloadedFilePath, $fileContent);
        $response2 = callNewApi("files/$fileId/info", $api_key, 'GET', [],[], [200]);
        $response2['message'] = "Fichier téléchargé avec succès dans $downloadedFilePath";
        // Vérification optionnelle: comparer le fichier téléchargé avec l'original
        if (file_exists($downloadedFilePath) && $compareFile) {
            $originalSize = filesize($compareFile);
            $downloadedSize = filesize($downloadedFilePath);
            $response2['original_size'] = $originalSize;
            $response2['downloaded_size'] = $downloadedSize;         
        }
    } else {
        $response2['success'] = false;
        $response2['message'] = "Erreur lors du téléchargement du fichier. Code HTTP: $httpCode";
        $response2['http_code'] = $httpCode;
    }
    testNewResult($response2['success'], $testDesc . " (code $response2[code])");
    return $response2;
}

function callNewDownloadICS($testDesc = 'no desc', $endpoint = 'NO ENDPOINT', $api_key = null,  $method = 'GET', $data = [], $files = [], $goodCode = [200], $compareFile = null)
{ 
    //printNewSection($testDesc);

    $url = BASE_CMEM_URL . ltrim($endpoint, '/');

    // Pour les requêtes GET, ajouter les données comme paramètres de query string seulement si nécessaire
    if ($method === 'GET' && !empty($data))
    {
        $queryString = http_build_query($data);
        $url .= '?' . $queryString;
        $data = []; // Vider $data pour éviter de l'envoyer dans le body
    }

    $ch = curl_init($url);

    $headers = [];
    if ($api_key)
    {
        $headers[] = "X-API-Key: {$api_key}";
    }

    if (!empty($data))
    {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HEADER, true); // Pour récupérer les headers de réponse
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);

    if (!empty($headers))
    {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        $response2 = [
            'success' => false,
            'message' => "Erreur lors du téléchargement du fichier ICS. Code HTTP: $httpCode",
            'code' => $httpCode,
            'txt' => null,
            'filename' => null
        ];
        testNewResult(in_array($httpCode, $goodCode) , $testDesc . " (code {$response2['code']})");
        return $response2;
    }
    
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    // Séparer les headers et le contenu
    $responseHeaders = substr($response, 0, $headerSize);
    $fileContent = substr($response, $headerSize);

    // Créer le dossier de téléchargement
    $downloadDir = 'C:\Users\escif\Proton Drive\jrobitaille04\My files\My_htdocs\cmem2_API\tmp_assets\downloads';
    if (!is_dir($downloadDir)) {
        mkdir($downloadDir, 0777, true);
    }

    // Extraire le nom de fichier des headers
    $filename = null;
    if (preg_match('/filename="([^"]+)"/', $responseHeaders, $matches)) {
        $filename = $matches[1];
    } else {
        // Générer un nom de fichier basé sur l'endpoint si pas trouvé
        $filename = 'calendar_' . date('Y-m-d_H-i-s') . '.ics';
    }
    
    echo "Filename extracted: $filename\n";
    
    // Définir le chemin complet du fichier téléchargé
    $downloadedFilePath = $downloadDir . '/' . $filename;

    // Vérifier le succès et traiter la réponse
    if (in_array($httpCode, $goodCode)) {
        // Sauvegarder le fichier téléchargé
        file_put_contents($downloadedFilePath, $fileContent);
        
        $response2 = [
            'success' => true,
            'message' => "Fichier téléchargé avec succès dans $downloadedFilePath",
            'txt' => $fileContent,
            'filename' => $filename,
            'code' => $httpCode,
            'path' => $downloadedFilePath
        ];
        
        // Vérification optionnelle: comparer le fichier téléchargé avec l'original
        if (file_exists($downloadedFilePath) && $compareFile) {
            $originalSize = filesize($compareFile);
            $downloadedSize = filesize($downloadedFilePath);
            $response2['original_size'] = $originalSize;
            $response2['downloaded_size'] = $downloadedSize;         
        }
    } else {
        $response2 = [
            'success' => false,
            'message' => "Erreur lors du téléchargement du fichier ICS. Code HTTP: $httpCode",
            'code' => $httpCode,
            'txt' => $fileContent,
            'filename' => null
        ];
    }
    
    testNewResult($response2['success'], $testDesc . " (code {$response2['code']})");
    return $response2;
}

function callNewAdminSecretProcedure($procedure, $parameters = [])
{
    $adminSecret = getSecretKey();
    $adminApiKey = getAdminAPIKey();

    $url = 'secret-admin/execute-procedure';
    
    $ch = curl_init(BASE_CMEM_URL . $url);
    
    $data = [
        'procedure' => $procedure
    ];
    
    if (!empty($parameters)) {
        $data['parameters'] = $parameters;
    }
    $data['admin_secret']= $adminSecret;
    $headers = [
        'Content-Type: application/json',
        "X-API-Key: {$adminApiKey}"
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    return [
        'success' => in_array($httpCode, [200, 201]),
        'body' => json_decode($result, true),
        'code' => $httpCode
    ];
}

function resetNewData(){
    return callNewAdminSecretProcedure('ResetAuthGroupsData', []);
}

function createNewApiKeyForce($email="user@example.com", $password="Qwerty123456"){

    require_once __DIR__ . '/../vendor/autoload.php';

    // Configuration modulaire (remplace config.php et database.php)
    require_once __DIR__ . '/../src/auth_groups/loader.php';
    
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $query = "SELECT * FROM users WHERE email = :email AND password_hash = :password_hash";

    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->prepare($query); 
    $stmt->bindParam(':password_hash', $password_hash);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return null;
    }

    $options = json_encode([
        'environment' => 'test',
        'scopes' => ['read', 'write', 'admin'],
        'rate_limit_per_minute' => 100,
        'rate_limit_per_hour' => 1000
    ]);
    
    $apiKey = bin2hex(random_bytes(32));
    $query = "INSERT INTO api_keys (user_id, api_key, options) VALUES (:user_id, :api_key, :options)";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':user_id', $user['id']);
    $stmt->bindParam(':api_key', $apiKey);
    $stmt->bindParam(':options', $options);
    $stmt->execute();

    return $apiKey;

}

function newRegisterUser($name,$email, $password, $role = 'UTILISATEUR') {
    $resp = callNewApi('users/register', null, 'POST', [
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'role' => $role
    ], [], [201]);

   if ($resp['success']) {
       return $resp['body']['data'];
   } else {
       throw new Exception("❌ Impossible de créer l'utilisateur $email : " . ($resp['body']['message'] ?? 'Erreur inconnue'));
   }
}

$adminEmail='jrobitaille04@pm.me';
$adminPassword='Zpcrtu142857!!';
//$adminPassword='1234567';
$adminApiKey = getAdminAPIKey();
$adminId = 1;
$userApiKey = getUserAPIKey();
$userEmail = "user@cmem2.com";
$userId = 2;
$userPassword = '1234567';

//login admin et user existants
if(!defined('IGNORE_ADMIN_USER_LOGIN')){
    $response = callNewTest("I. Login de l'utilisateur admin existant", 'users/login', $adminApiKey, 'POST', [ 'email' => $adminEmail, 'password' => $adminPassword ], [], [200]);
    $response = callNewTest("J. Login de l'utilisateur user existant", 'users/login', $userApiKey, 'POST', [ 'email' => $userEmail, 'password' => $userPassword ], [], [200]);
}



function userAdminiSetup(){
    global $adminEmail, $adminPassword, $adminApiKey, $adminId, $userApiKey, $userEmail, $userId;
    $adminEmail='jrobitaille04@pm.me';
    $adminPassword='Zpcrtu142857!!';
    $adminApiKey = getAdminAPIKey();
    $adminId = 1;
    $userApiKey = getUserAPIKey();
    $userEmail = "user@cmem2.com";
    $userId = 2;

    //login admin et user existants
    $response = callNewTest("I. Login de l'utilisateur admin existant", 'users/login', $adminApiKey, 'POST', [ 'email' => $adminEmail, 'password' => $adminPassword ], [], [200]);
    $response = callNewTest("J. Login de l'utilisateur user existant", 'users/login', $userApiKey, 'POST', [ 'email' => $userEmail, 'password' => 'Qwerty123456' ], [], [200]);
}

function userAdminLogout(){
    global $adminApiKey, $userApiKey;
    $response = callNewTest("K. Logout de l'utilisateur admin", 'users/logout', $adminApiKey, 'POST', [], [], [200]);
    $response = callNewTest("L. Logout de l'utilisateur user", 'users/logout', $userApiKey, 'POST', [], [], [200]);
}

if(!defined('IGNORE_NEW_BASE_USER_CREATION_TESTS')){
    $memberEmail = 'user_' . uniqid() . '@cmem2.com';
    $memberPassword = "Qwerty123456";
    $memberId= null;
    $memberApiKey = null;
    $nonMemberEmail = 'user_' . uniqid() . '@cmem2.com';
    $nonMemberPassword = "Qwerty123456";
    $nonMemberApiKey = null;
    $nonMemberId = null;
    $adminGroupEmail = 'user_' . uniqid() . '@cmem2.com';
    $adminGroupPassword = "Qwerty123456";
    $adminGroupApiKey = null;
    $adminGroupId = null;
    $nonMemberEmail2 = 'user_' . uniqid() . '@cmem2.com';
    $nonMemberPassword2 = "Qwerty123456";
    $nonMemberApiKey2 = null;
    $nonMemberId2 = null;

  
    // création de quatre utilisateurs pour les tests

    $response=callNewTest("A. Création d'un utilisateur avec authentification", 'users/register',null,'POST',[ "email"=> $memberEmail, "name"=> "User 2", "password"=> $memberPassword],[],[201]);
    $memberId = $response['body']['data']['user']['id'] ?? null;
    $memberApiKey = $response['body']['data']['api_key']['key'] ?? null;
    $verificationToken = $response['body']['data']['verification_token'] ?? null;
    $response = callNewTest('AA. verify email with token no apiKey', 'users/verify-email', null, 'POST', [ 'token' => $verificationToken ], [], [200]);

    $response=callNewTest("B. Création d'un utilisateur avec authentification", 'users/register',null,'POST',[ "email"=> $nonMemberEmail2, "name"=> "User 3", "password"=> $nonMemberPassword2],[],[201]);
    $nonMemberId2 = $response['body']['data']['user']['id'] ?? null;
    $nonMemberApiKey2 = $response['body']['data']['api_key']['key'] ?? null;
    $verificationToken = $response['body']['data']['verification_token'] ?? null;
    $response = callNewTest('BB. verify email with token no apiKey', 'users/verify-email', null, 'POST', [ 'token' => $verificationToken ], [], [200]);

    $response=callNewTest("C. Création d'un utilisateur avec authentification", 'users/register',null,'POST',[ "email"=> $adminGroupEmail, "name"=> "User 4", "password"=> $adminGroupPassword],[],[201]);
    $adminGroupId = $response['body']['data']['user']['id'] ?? null;
    $adminGroupApiKey = $response['body']['data']['api_key']['key'] ?? null;
    $verificationToken = $response['body']['data']['verification_token'] ?? null;
    $response = callNewTest('CC. verify email with token no apiKey', 'users/verify-email', null, 'POST', [ 'token' => $verificationToken ], [], [200]);

    $response=callNewTest("D. Création d'un utilisateur avec authentification", 'users/register',null,'POST',[ "email"=> $nonMemberEmail, "name"=> "User 5", "password"=> $nonMemberPassword],[],[201]);
    $nonMemberId = $response['body']['data']['user']['id'] ?? null;
    $nonMemberApiKey = $response['body']['data']['api_key']['key'] ?? null;
    $verificationToken = $response['body']['data']['verification_token'] ?? null;
    $response = callNewTest('DD. verify email with token no apiKey', 'users/verify-email', null, 'POST', [ 'token' => $verificationToken ], [], [200]); 


    //LOGINS
    $response = callNewTest("E. Login de l'utilisateur membre", 'users/login', $memberApiKey, 'POST', [ 'email' => $memberEmail, 'password' => $memberPassword ], [], [200]);
    $response = callNewTest("F. Login de l'utilisateur non-membre", 'users/login', $nonMemberApiKey, 'POST', [ 'email' => $nonMemberEmail, 'password' => $nonMemberPassword ], [], [200]);
    $response = callNewTest("G. Login de l'utilisateur admin-groupe", 'users/login', $adminGroupApiKey, 'POST', [ 'email' => $adminGroupEmail, 'password' => $adminGroupPassword ], [], [200]);
    $response = callNewTest("H. Login de l'utilisateur non-membre 2", 'users/login', $nonMemberApiKey2, 'POST', [ 'email' => $nonMemberEmail2, 'password' => $nonMemberPassword2 ], [], [200]);

 }

 function resume(){
    // Résumé
    global $successTests, $totalTests, $failedTests, $failedTestsDetails;
    $sep = str_repeat('-', 60);
    echo "\n$sep\nRÉSUMÉ DES TESTS\n$sep\n";
    echo "Logout user et admin\n";
    if(defined('IGNORE_ADMIN_USER_LOGIN') && !IGNORE_ADMIN_USER_LOGIN){
        userAdminLogout();
    }
    printf("%-20s : %d / %d\n", 'Succès', $successTests, $totalTests);
    printf("%-20s : %d / %d\n", 'Échecs', $failedTests, $totalTests);
    if ($totalTests > 0) {
        $percent = round(($successTests / $totalTests) * 100, 2);
        printf("%-20s : %s%%\n", 'Pourcentage de réussite', $percent);
    }

    if (!empty($failedTestsDetails)) {
        $sep = str_repeat('*', 60);
        echo "$sep\n";
        echo "\nDétails des échecs :\n";
        foreach ($failedTestsDetails as $failedTest) {
            echo "Test : " . $failedTest['test'] . "\n";
            echo "Réponse : " . json_encode($failedTest['response']) . "\n";
            echo "$sep\n";
        }
    }   // Fin de la fonction resume
}


// ============================================================
// Helpers spécifiques JWT
// ============================================================

/**
 * Appel API avec Authorization: Bearer <token> au lieu de X-API-Key.
 */
function callApiWithJWT(string $endpoint, ?string $jwtToken, string $method = 'GET', array $data = [], array $goodCode = [200]): array
{
    $url = BASE_CMEM_URL . ltrim($endpoint, '/');

    if ($method === 'GET' && !empty($data)) {
        $url .= '?' . http_build_query($data);
        $data = [];
    }

    $ch      = curl_init($url);
    $headers = ['Content-Type: application/json'];

    if ($jwtToken) {
        $headers[] = "Authorization: Bearer {$jwtToken}";
    }

    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    //curl_close($ch);

    return [
        'success' => in_array($httpCode, $goodCode),
        'body'    => json_decode($result, true),
        'code'    => $httpCode,
    ];
}

/**
 * Wrapper callNewTest pour les appels JWT.
 */
function callTestWithJWT(string $desc, string $endpoint, ?string $jwtToken, string $method = 'GET', array $data = [], array $goodCode = [200]): array
{
    global $failedTestsDetails;
    $response = callApiWithJWT($endpoint, $jwtToken, $method, $data, $goodCode);
    testNewResult($response['success'], "{$desc} (code {$response['code']})");
    if (!$response['success']) {
        echo '   Response: ' . json_encode($response['body']) . "\n";
        $failedTestsDetails[] = ['test' => $desc, 'response' => $response];
    }
    return $response;
}

/**
 * Récupère le code OTP directement en base (tests / développement uniquement).
 * Nécessite que APP_ENV=development et l'autoloader.
 */
function getOtpCodeFromDB(string $email): ?string
{
    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/../src/auth_groups/loader.php';

        $pdo  = \Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            "SELECT code_hash FROM otp_codes
              WHERE email = ? AND expires_at > NOW() AND used_at IS NULL
              ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        // On ne peut pas retrouver le code en clair depuis le hash bcrypt.
        // On retourne null — le test vérifiera le comportement avec un mauvais code.
        return null;
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * Insère un code OTP connu directement en base (tests / développement uniquement).
 * Retourne le code en clair pour pouvoir le tester.
 */
function injectOtpCode(string $email, string $code = '123456'): bool
{
    try {
        require_once __DIR__ . '/../../vendor/autoload.php';
        require_once __DIR__ . '/../../src/auth_groups/loader.php';

        $email = strtolower(trim($email));   // même normalisation que le serveur
        $pdo   = \Database::getInstance()->getConnection();
        $hash  = password_hash($code, PASSWORD_BCRYPT);

        $pdo->prepare("DELETE FROM otp_codes WHERE email = ?")->execute([$email]);
        // expires_at calculé par MySQL (DATE_ADD(NOW(),...)) pour éviter tout décalage de timezone PHP vs MySQL
        $pdo->prepare(
            "INSERT INTO otp_codes (email, code_hash, expires_at, attempts, max_attempts)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0, 5)"
        )->execute([$email, $hash]);

        $check = $pdo->prepare("SELECT id, expires_at FROM otp_codes WHERE email = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
        $check->execute([$email]);
        $row = $check->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            echo "   [injectOtpCode OK] id={$row['id']} expires={$row['expires_at']} email={$email}\n";
            return true;
        }
        echo "   [injectOtpCode WARN] INSERT OK mais expires_at > NOW() ne trouve rien (problème de timezone?)\n";
        return false;
    } catch (\Throwable $e) {
        echo "   [injectOtpCode ERROR] " . $e->getMessage() . "\n";
        return false;
    }
}
