<?php

/**
 * Bootstrap minimal pour les scripts de sauvegarde
 *
 * Fournit :
 *   - Vérification CLI uniquement
 *   - Chargement du .env racine
 *   - Connexion PDO légère (sans charger les plugins)
 *   - Résolution du répertoire de destination
 *
 * Usage dans chaque script de backup :
 *   require_once __DIR__ . '/_bootstrap.php';
 *   [$pdo, $destDir, $rootDir] = backupBootstrap($argv);
 */

if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REMOTE_ADDR'])) {
    http_response_code(403);
    exit('Accès refusé — script CLI uniquement.' . PHP_EOL);
}

function backupLoadEnv(string $rootDir): void
{
    $envFile = $rootDir . '/.env';
    if (!file_exists($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $_ENV[trim($parts[0])] = trim($parts[1]);
        }
    }
}

/**
 * @param  array<int,string> $argv
 * @return array{PDO, string, string}   [$pdo, $destDir, $rootDir]
 */
function backupBootstrap(array $argv): array
{
    // Racine du projet : src/cron/backup/ → 3 niveaux au-dessus
    $rootDir = dirname(__DIR__, 3);

    backupLoadEnv($rootDir);

    date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Montreal');

    // Répertoire de destination : argv[1] ou BACKUP_DIR du .env
    $destDir = isset($argv[1]) && $argv[1] !== ''
        ? rtrim($argv[1], '/')
        : rtrim($_ENV['BACKUP_DIR'] ?? '', '/');

    if ($destDir === '') {
        fwrite(STDERR, "ERREUR : répertoire de destination manquant (argv[1] ou BACKUP_DIR dans .env)\n");
        exit(1);
    }

    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
        fwrite(STDERR, "ERREUR : impossible de créer le répertoire : {$destDir}\n");
        exit(1);
    }

    // Connexion PDO directe sans charger les plugins
    try {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'] ?? 'localhost',
            $_ENV['DB_NAME'] ?? ''
        );
        $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? 'root', $_ENV['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
        $pdo->exec("SET time_zone = '" . ($_ENV['DB_TIMEZONE'] ?? '+00:00') . "'");
    } catch (PDOException $e) {
        fwrite(STDERR, "ERREUR PDO : " . $e->getMessage() . "\n");
        exit(1);
    }

    return [$pdo, $destDir, $rootDir];
}
