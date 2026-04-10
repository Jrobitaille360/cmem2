php <?php

/**
 * Sauvegarde — Module Quiz (Kayoot)
 *
 * Exporte les 6 tables du module Quiz dans l'ordre des clés étrangères.
 * Effectue un ménage des sessions terminées depuis plus de 90 jours.
 *
 * Usage :
 *   php backup_quiz.php [/chemin/destination/]
 *   Si absent, utilise BACKUP_DIR du .env
 *
 * Sortie (une seule ligne) :
 *   [YYYY-MM-DD HH:MM:SS] backup_quiz OK | 6 tables | N lignes | N Ko | Ns
 *   [YYYY-MM-DD HH:MM:SS] backup_quiz ERREUR | message
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_export.php';

[$pdo, $destDir, $rootDir] = backupBootstrap($argv);

$module    = 'quiz';
$startTime = microtime(true);
$date      = date('Y-m-d H:i:s');
$stamp     = date('Ymd_His');
$db        = $_ENV['DB_NAME'] ?? 'cmem2';
$outFile   = "{$destDir}/cmem2_{$module}_{$stamp}.sql";

// Tables dans l'ordre FK (parents avant enfants)
$tables = [
    'quiz_quizzes',
    'quiz_questions',
    'quiz_choices',
    'quiz_sessions',
    'quiz_participants',
    'quiz_participant_answers',
];

try {
    // --- 1. Ménage pré-backup ---
    // Les enfants (participants, answers) sont supprimés en cascade
    $cleaned = cleanupTable($pdo, "DELETE FROM `quiz_sessions` WHERE ended_at < NOW() - INTERVAL 90 DAY");

    // --- 2. Export SQL ---
    $fh = fopen($outFile, 'w');
    if ($fh === false) {
        throw new RuntimeException("Impossible d'ouvrir le fichier : {$outFile}");
    }

    writeSqlHeader($fh, $module, $db, $tables, $date);

    $totalRows = 0;
    foreach ($tables as $table) {
        $totalRows += exportTable($pdo, $fh, $table);
    }

    writeSqlFooter($fh);
    fclose($fh);

    // --- 3. Résumé ---
    $elapsed = round(microtime(true) - $startTime, 1);
    $sizeKo  = round(filesize($outFile) / 1024);
    echo "[{$date}] backup_{$module} OK | " . count($tables) . " tables | {$totalRows} lignes | {$cleaned} supprimés | {$sizeKo} Ko | {$elapsed}s\n";

} catch (\Throwable $e) {
    if (isset($fh) && is_resource($fh)) {
        fclose($fh);
    }
    echo "[{$date}] backup_{$module} ERREUR | {$e->getMessage()}\n";
    exit(1);
}
