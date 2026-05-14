<?php

/**
 * Orchestrateur — Sauvegarde complète cmem2
 *
 * Appelle dans l'ordre :
 *   1. maintenance_occurrences.php  (régénère les occurrences ICS)
 *   2. backup_core.php
 *   3. backup_ics.php
 *   4. backup_pomo.php
 *   5. backup_quiz.php
 *   6. backup_puzzle.php
 *   7. backup_uploads.php
 *   8. cleanup_logs.php
 *   9. cleanup_backups.php
 *
 * Usage :
 *   php run_all.php [/chemin/destination/]
 *   Si absent, utilise BACKUP_DIR du .env
 *
 * Sortie : une ligne par script + résumé final sur une ligne :
 *   [YYYY-MM-DD HH:MM:SS] run_all DONE | N modules OK | N erreur(s) | Ns
 */

if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REMOTE_ADDR'])) {
    http_response_code(403);
    exit('Accès refusé — script CLI uniquement.' . PHP_EOL);
}

require_once __DIR__ . '/_bootstrap.php';

$rootDir = dirname(__DIR__, 3);
backupLoadEnv($rootDir);

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Montreal');

$date      = date('Y-m-d H:i:s');
$startTime = microtime(true);

// Répertoire de destination transmis aux scripts enfants
$destArg = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : ($_ENV['BACKUP_DIR'] ?? '');
$phpBin  = PHP_BINARY;
$backDir = __DIR__;
$icsDir  = $rootDir . '/src/ics';

// Séquence de scripts : [chemin, description, passe destArg ?, timeout secondes]
$scripts = [
    [$icsDir  . '/maintenance_occurrences.php', 'maintenance_occurrences', false, 300],
    [$backDir . '/backup_core.php',             'backup_core',             true,  120],
    [$backDir . '/backup_ics.php',              'backup_ics',              true,  120],
    [$backDir . '/backup_pomo.php',             'backup_pomo',             true,   60],
    [$backDir . '/backup_quiz.php',             'backup_quiz',             true,   60],
    [$backDir . '/backup_puzzle.php',           'backup_puzzle',           true,   60],
    [$backDir . '/backup_uploads.php',          'backup_uploads',          true,  600],
    [$backDir . '/cleanup_logs.php',            'cleanup_logs',            false,  30],
    [$backDir . '/cleanup_backups.php',         'cleanup_backups',         true,   30],
];

$errors  = 0;
$modules = 0;
$log     = [];

/** Affiche une ligne et l'ajoute au journal email. */
function logLine(string $msg): void
{
    echo "$msg\n";
    flush();
    $GLOBALS['log'][] = $msg;
}

foreach ($scripts as [$script, $label, $passDir, $timeout]) {
    if (!file_exists($script)) {
        logLine("[{$date}] {$label} IGNORÉ | fichier introuvable : {$script}");
        continue;
    }

    $cmd = $passDir && $destArg !== ''
        ? escapeshellcmd($phpBin) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($destArg)
        : escapeshellcmd($phpBin) . ' ' . escapeshellarg($script);

    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $desc, $pipes);

    if (!is_resource($proc)) {
        logLine("[{$date}] {$label} ERREUR | impossible de démarrer le processus");
        $errors++;
        continue;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $deadline = time() + $timeout;
    $code     = null;

    while (true) {
        foreach ([$pipes[1], $pipes[2]] as $pipe) {
            while (($line = fgets($pipe)) !== false) {
                $trimmed = rtrim($line);
                echo "$trimmed\n";
                flush();
                $log[] = $trimmed;
            }
        }

        $status = proc_get_status($proc);
        if (!$status['running']) {
            $code = $status['exitcode'];
            break;
        }

        if (time() > $deadline) {
            proc_terminate($proc);
            logLine("[{$date}] {$label} ERREUR | timeout ({$timeout}s) dépassé");
            $code = 1;
            break;
        }

        usleep(100000); // 100ms
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    if ($code !== 0) {
        $errors++;
    } else {
        $modules++;
    }
}

$elapsed     = round(microtime(true) - $startTime, 1);
$summaryLine = "[{$date}] run_all DONE | {$modules} scripts OK | {$errors} erreur(s) | {$elapsed}s";
logLine($summaryLine);

// ── Envoi du rapport par email (uniquement en cas d'erreur) ──────────────────
if ($errors > 0) {
    sendBackupReport($log, $errors, $modules, $elapsed, $rootDir);
}

if ($errors > 0) {
    exit(1);
}

// ── Fonction d'envoi ──────────────────────────────────────────────────────────

function sendBackupReport(array $log, int $errors, int $modules, float $elapsed, string $rootDir): void
{
    $autoload = "$rootDir/vendor/autoload.php";
    if (!file_exists($autoload)) {
        fwrite(STDERR, "sendBackupReport: vendor/autoload.php introuvable\n");
        return;
    }
    require_once $autoload;

    $smtpHost = $_ENV['SMTP_HOST']         ?? $_ENV['MAIL_HOST']         ?? '';
    $smtpPort = (int)($_ENV['SMTP_PORT']   ?? $_ENV['MAIL_PORT']         ?? 587);
    $smtpUser = $_ENV['SMTP_USERNAME']     ?? $_ENV['MAIL_USERNAME']     ?? '';
    $smtpPass = $_ENV['SMTP_PASSWORD']     ?? $_ENV['MAIL_PASSWORD']     ?? '';
    $from     = $_ENV['MAIL_FROM_ADDRESS'] ?? $_ENV['MAIL_FROM']         ?? 'noreply@cmem2.journauxdebord.com';
    $fromName = $_ENV['MAIL_FROM_NAME']    ?? 'cmem2 API';
    $to       = 'support@journauxdebord.com';

    if ($smtpHost === '') {
        fwrite(STDERR, "sendBackupReport: SMTP non configuré — email non envoyé\n");
        return;
    }

    $statusLabel = $errors === 0 ? 'SUCCÈS' : "{$errors} ERREUR(S)";
    $statusColor = $errors === 0 ? '#2e7d32' : '#c62828';
    $now         = date('Y-m-d H:i');
    $subject     = "[cmem2 backup] $statusLabel — $now";

    $logHtml = implode("\n", array_map('htmlspecialchars', $log));
    $logText = implode("\n", $log);

    $body = "<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'></head>
<body style='font-family:Arial,sans-serif;background:#f4f4f4;padding:20px;margin:0'>
<div style='max-width:700px;margin:auto;background:#fff;border-radius:8px;overflow:hidden'>
  <div style='background:{$statusColor};color:#fff;padding:20px 24px'>
    <h2 style='margin:0'>{$statusLabel} — Backup cmem2</h2>
    <p style='margin:4px 0 0;opacity:.85'>" . date('Y-m-d H:i:s') . "</p>
  </div>
  <div style='padding:24px'>
    <table style='border-collapse:collapse;width:100%;margin-bottom:24px'>
      <tr style='background:#f5f5f5'>
        <td style='padding:8px 14px;font-weight:bold'>Scripts OK</td>
        <td style='padding:8px 14px'>{$modules}</td>
      </tr>
      <tr>
        <td style='padding:8px 14px;font-weight:bold'>Erreurs</td>
        <td style='padding:8px 14px;color:{$statusColor};font-weight:bold'>{$errors}</td>
      </tr>
      <tr style='background:#f5f5f5'>
        <td style='padding:8px 14px;font-weight:bold'>Durée totale</td>
        <td style='padding:8px 14px'>{$elapsed}s</td>
      </tr>
    </table>
    <h3 style='margin:0 0 8px;color:#333'>Journal d'exécution</h3>
    <pre style='background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;font-size:12px;overflow-x:auto;white-space:pre-wrap'>{$logHtml}</pre>
  </div>
</div>
</body>
</html>";

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = $smtpUser !== '';
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->Port       = $smtpPort;
        $mail->SMTPSecure = $smtpPort === 465
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $logText;
        $mail->send();
        echo '[' . date('Y-m-d H:i:s') . "] email rapport envoyé à {$to}\n";
    } catch (\Exception $e) {
        fwrite(STDERR, 'sendBackupReport ERREUR : ' . $e->getMessage() . "\n");
    }
}
