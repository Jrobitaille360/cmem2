<?php
/**
 * Tests accessibility des fichiers — production cmem2.journauxdebord.com
 *
 * Usage : php private/tests/test_files_accessibility.php
 *
 * Couvre :
 *  10. POST /files — paramètre accessibility (public / private / invalide)
 *  11. GET  /files/{id}      — download selon accessibility (403 si private + non-proprio)
 *  12. GET  /files/{id}/info — métadonnées selon accessibility
 *  13. PATCH /files/{id}/accessibility — changement par proprio / admin / non-proprio
 */

// URL cible : utilise la valeur de test_new_base.php (localhost par défaut).
// Pour cibler la production, décommenter la ligne suivante AVANT l'include :
 define('BASE_CMEM_URL', 'https://cmem2.journauxdebord.com/');

include_once __DIR__ . '/test_new_base.php';

// ============================================================
// Helper upload multipart (copié de test_files.php)
// ============================================================
function accUpload(string $endpoint, ?string $jwt, array $fields, string $filePath): array
{
    $ch = curl_init(BASE_CMEM_URL . ltrim($endpoint, '/'));
    $postFields = $fields;
    $postFields['file'] = new CURLFile(
        $filePath,
        mime_content_type($filePath) ?: 'text/plain',
        basename($filePath)
    );
    $headers = $jwt ? ["Authorization: Bearer {$jwt}"] : [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ['success' => in_array($code, [200, 201]), 'body' => json_decode($body, true), 'code' => $code];
}

// ============================================================
// Credentials production
// ============================================================
$adminEmail    = 'jrobitaille04@pm.me';
$adminPassword = 'Zpcrtu142857!!';
$userEmail     = 'support@journauxdebord.com';
$userPassword  = '1234567!';
$adminJwt      = null;
$userJwt       = null;
$adminId       = null;
$userId        = null;

// ============================================================
// 0. Authentification
// ============================================================
printNewSection('0. Authentification');

$resp = callNewTest('0.1 Login admin', 'auth/login', null, 'POST',
    ['email' => $adminEmail, 'password' => $adminPassword], [], [200]);
if ($resp['success']) {
    $adminJwt = $resp['body']['data']['token'] ?? null;
    $adminId  = $resp['body']['data']['user']['id'] ?? null;
    echo "   Admin JWT : " . substr($adminJwt ?? '', 0, 30) . "... (ID={$adminId})\n";
}

$resp = callNewTest('0.2 Login user', 'auth/login', null, 'POST',
    ['email' => $userEmail, 'password' => $userPassword], [], [200]);
if ($resp['success']) {
    $userJwt = $resp['body']['data']['token'] ?? null;
    $userId  = $resp['body']['data']['user']['id'] ?? null;
    echo "   User JWT  : " . substr($userJwt ?? '', 0, 30) . "... (ID={$userId})\n";
}

if (!$adminJwt || !$userJwt) {
    echo "\n[ABORT] Authentification échouée — tests interrompus.\n";
    resume();
    exit(1);
}

// ============================================================
// Fichiers temporaires
// ============================================================
$tmpA = tempnam(sys_get_temp_dir(), 'acc_a_') . '.txt';
$tmpB = tempnam(sys_get_temp_dir(), 'acc_b_') . '.txt';
$tmpC = tempnam(sys_get_temp_dir(), 'acc_c_') . '.txt';
$tmpD = tempnam(sys_get_temp_dir(), 'acc_d_') . '.txt';
$tmpI = tempnam(sys_get_temp_dir(), 'acc_inv_') . '.txt';
file_put_contents($tmpA, 'acc test A ' . uniqid());
file_put_contents($tmpB, 'acc test B ' . uniqid());
file_put_contents($tmpC, 'acc test C ' . uniqid());
file_put_contents($tmpD, 'acc test D ' . uniqid());
file_put_contents($tmpI, 'acc test invalid ' . uniqid());

$accDefaultFileId      = null; // admin — sans paramètre accessibility
$accPublicFileId       = null; // admin — accessibility=public
$accPrivateAdminFileId = null; // admin — accessibility=private
$accPrivateUserFileId  = null; // user  — accessibility=private

// ============================================================
// SECTION 10 : POST /files — paramètre accessibility
// ============================================================
printNewSection('10. POST /files — paramètre accessibility');

// A1 — sans paramètre → private par défaut
$resp = accUpload('files', $adminJwt, ['description' => 'A1 défaut'], $tmpA);
testNewResult($resp['success'], 'A1 Upload sans accessibility → 201 (code ' . $resp['code'] . ')');
if ($resp['success']) {
    $accDefaultFileId = $resp['body']['data']['file']['id'] ?? null;
    $acc = $resp['body']['data']['file']['accessibility'] ?? null;
    testNewResult($acc === 'private', "   → accessibility=private par défaut (reçu: {$acc})");
}

// A2 — accessibility=public
$resp = accUpload('files', $adminJwt, ['description' => 'A2 public', 'accessibility' => 'public'], $tmpB);
testNewResult($resp['success'], 'A2 Upload accessibility=public → 201 (code ' . $resp['code'] . ')');
if ($resp['success']) {
    $accPublicFileId = $resp['body']['data']['file']['id'] ?? null;
    $acc = $resp['body']['data']['file']['accessibility'] ?? null;
    testNewResult($acc === 'public', "   → accessibility=public (reçu: {$acc})");
    echo "   Fichier public ID={$accPublicFileId}\n";
}

// A3 — accessibility=private (admin)
$resp = accUpload('files', $adminJwt, ['description' => 'A3 private admin', 'accessibility' => 'private'], $tmpC);
testNewResult($resp['success'], 'A3 Upload accessibility=private (admin) → 201 (code ' . $resp['code'] . ')');
if ($resp['success']) {
    $accPrivateAdminFileId = $resp['body']['data']['file']['id'] ?? null;
    $acc = $resp['body']['data']['file']['accessibility'] ?? null;
    testNewResult($acc === 'private', "   → accessibility=private (reçu: {$acc})");
    echo "   Fichier private admin ID={$accPrivateAdminFileId}\n";
}

// A3b — accessibility=private (user)
$resp = accUpload('files', $userJwt, ['description' => 'A3b private user', 'accessibility' => 'private'], $tmpD);
testNewResult($resp['success'], 'A3b Upload accessibility=private (user) → 201 (code ' . $resp['code'] . ')');
if ($resp['success']) {
    $accPrivateUserFileId = $resp['body']['data']['file']['id'] ?? null;
    $acc = $resp['body']['data']['file']['accessibility'] ?? null;
    testNewResult($acc === 'private', "   → accessibility=private (reçu: {$acc})");
    echo "   Fichier private user ID={$accPrivateUserFileId}\n";
}

// A4 — valeur invalide → 422
$resp = accUpload('files', $adminJwt, ['accessibility' => 'secret'], $tmpI);
testNewResult(in_array($resp['code'], [422, 400]), 'A4 Upload accessibility=secret → 422 (code ' . $resp['code'] . ')');

// ============================================================
// SECTION 11 : GET /files/{id} — download selon accessibility
// ============================================================
printNewSection('11. GET /files/{id} — download selon accessibility');

$sslOpts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0];

// B1 — fichier public, non-propriétaire (user) → 200
if ($accPublicFileId) {
    $ch = curl_init(BASE_CMEM_URL . "files/{$accPublicFileId}");
    curl_setopt_array($ch, $sslOpts + [CURLOPT_HTTPHEADER => ["Authorization: Bearer {$userJwt}"]]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    testNewResult($code === 200, "B1 Fichier public — non-propriétaire → 200 (code {$code})");
    if ($code === 200) testNewResult(strlen($body) > 0, '   → contenu non vide');
}

// B2 — fichier private (admin), non-propriétaire (user) → 403
if ($accPrivateAdminFileId) {
    $ch = curl_init(BASE_CMEM_URL . "files/{$accPrivateAdminFileId}");
    curl_setopt_array($ch, $sslOpts + [CURLOPT_HTTPHEADER => ["Authorization: Bearer {$userJwt}"]]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    testNewResult($code === 403, "B2 Fichier private — non-propriétaire → 403 (code {$code})");
}

// B3 — fichier private (admin), propriétaire (admin) → 200
if ($accPrivateAdminFileId) {
    $ch = curl_init(BASE_CMEM_URL . "files/{$accPrivateAdminFileId}");
    curl_setopt_array($ch, $sslOpts + [CURLOPT_HTTPHEADER => ["Authorization: Bearer {$adminJwt}"]]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    testNewResult($code === 200, "B3 Fichier private — propriétaire → 200 (code {$code})");
}

// B4 — fichier private (user), admin → 200
if ($accPrivateUserFileId) {
    $ch = curl_init(BASE_CMEM_URL . "files/{$accPrivateUserFileId}");
    curl_setopt_array($ch, $sslOpts + [CURLOPT_HTTPHEADER => ["Authorization: Bearer {$adminJwt}"]]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    testNewResult($code === 200, "B4 Fichier private (user) — admin → 200 (code {$code})");
}

// B5 — fichier public, sans auth → 401/403
if ($accPublicFileId) {
    $ch = curl_init(BASE_CMEM_URL . "files/{$accPublicFileId}");
    curl_setopt_array($ch, $sslOpts);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    testNewResult(in_array($code, [401, 403]), "B5 Fichier public — sans auth → 401/403 (code {$code})");
}

// ============================================================
// SECTION 12 : GET /files/{id}/info — visibilité selon accessibility
// ============================================================
printNewSection('12. GET /files/{id}/info — visibilité selon accessibility');

// C1 — fichier public, non-propriétaire → 200 + accessibility=public
if ($accPublicFileId) {
    $resp = callTestWithJWT("C1 GET /files/{$accPublicFileId}/info par user → 200",
        "files/{$accPublicFileId}/info", $userJwt, 'GET', [], [200]);
    if ($resp['success']) {
        $acc = $resp['body']['data']['file']['accessibility'] ?? null;
        testNewResult($acc === 'public', "   → accessibility=public dans les métadonnées (reçu: {$acc})");
    }
}

// C2 — fichier private (admin), non-propriétaire → 403
if ($accPrivateAdminFileId) {
    $resp = callTestWithJWT("C2 GET /files/{$accPrivateAdminFileId}/info par user → 403",
        "files/{$accPrivateAdminFileId}/info", $userJwt, 'GET', [], [403]);
    testNewResult($resp['code'] === 403, '   → 403 confirmé (code ' . $resp['code'] . ')');
}

// C3 — fichier private (admin), propriétaire → 200 + accessibility=private
if ($accPrivateAdminFileId) {
    $resp = callTestWithJWT("C3 GET /files/{$accPrivateAdminFileId}/info par propriétaire → 200",
        "files/{$accPrivateAdminFileId}/info", $adminJwt, 'GET', [], [200]);
    if ($resp['success']) {
        $acc = $resp['body']['data']['file']['accessibility'] ?? null;
        testNewResult($acc === 'private', "   → accessibility=private dans les métadonnées (reçu: {$acc})");
    }
}

// ============================================================
// SECTION 13 : PATCH /files/{id}/accessibility
// ============================================================
printNewSection('13. PATCH /files/{id}/accessibility');

// D1 — propriétaire : public → private
if ($accPublicFileId) {
    $resp = callTestWithJWT("D1 PATCH /files/{$accPublicFileId}/accessibility (proprio public→private) → 200",
        "files/{$accPublicFileId}/accessibility", $adminJwt, 'PATCH',
        ['accessibility' => 'private'], [200]);
    if ($resp['success']) {
        $acc = $resp['body']['data']['accessibility'] ?? null;
        testNewResult($acc === 'private', "   → accessibility=private confirmé (reçu: {$acc})");
    }
}

// D2 — propriétaire : private → public
if ($accPublicFileId) {
    $resp = callTestWithJWT("D2 PATCH /files/{$accPublicFileId}/accessibility (proprio private→public) → 200",
        "files/{$accPublicFileId}/accessibility", $adminJwt, 'PATCH',
        ['accessibility' => 'public'], [200]);
    if ($resp['success']) {
        $acc = $resp['body']['data']['accessibility'] ?? null;
        testNewResult($acc === 'public', "   → accessibility=public confirmé (reçu: {$acc})");
    }
}

// D3 — non-propriétaire → 403
if ($accPrivateAdminFileId) {
    $resp = callTestWithJWT("D3 PATCH /files/{$accPrivateAdminFileId}/accessibility par non-propriétaire → 403",
        "files/{$accPrivateAdminFileId}/accessibility", $userJwt, 'PATCH',
        ['accessibility' => 'public'], [403]);
    testNewResult($resp['code'] === 403, '   → 403 confirmé (code ' . $resp['code'] . ')');
}

// D4 — admin modifie le fichier d'un autre utilisateur → 200
if ($accPrivateUserFileId) {
    $resp = callTestWithJWT("D4 PATCH /files/{$accPrivateUserFileId}/accessibility par admin → 200",
        "files/{$accPrivateUserFileId}/accessibility", $adminJwt, 'PATCH',
        ['accessibility' => 'public'], [200]);
    testNewResult($resp['success'], '   → admin peut modifier (code ' . $resp['code'] . ')');
}

// D5 — valeur invalide → 422
if ($accPublicFileId) {
    $resp = callTestWithJWT("D5 PATCH /files/{$accPublicFileId}/accessibility valeur invalide → 422",
        "files/{$accPublicFileId}/accessibility", $adminJwt, 'PATCH',
        ['accessibility' => 'top-secret'], [422]);
    testNewResult(in_array($resp['code'], [422, 400]), '   → 422 confirmé (code ' . $resp['code'] . ')');
}

// D6 — sans auth → 401
if ($accPublicFileId) {
    $resp = callTestWithJWT("D6 PATCH /files/{$accPublicFileId}/accessibility sans auth → 401",
        "files/{$accPublicFileId}/accessibility", null, 'PATCH',
        ['accessibility' => 'private'], [401]);
    testNewResult(in_array($resp['code'], [401, 403]), '   → 401/403 confirmé (code ' . $resp['code'] . ')');
}

// ============================================================
// NETTOYAGE
// ============================================================
printNewSection('NETTOYAGE');

foreach ([
    [$accDefaultFileId,      $adminJwt],
    [$accPublicFileId,       $adminJwt],
    [$accPrivateAdminFileId, $adminJwt],
    [$accPrivateUserFileId,  $adminJwt],
] as [$fid, $jwt]) {
    if ($fid) {
        callTestWithJWT("CLEAN file {$fid}", "files/{$fid}", $jwt, 'DELETE', ['force_delete' => true], [200, 404]);
        echo "   Fichier ID={$fid} supprimé\n";
    }
}

foreach ([$tmpA, $tmpB, $tmpC, $tmpD, $tmpI] as $f) {
    if ($f && file_exists($f)) unlink($f);
}

// ============================================================
resume();
