<?php
/**
 * Restaure un backup ZIP chiffre en inserant les donnees dans l'ordre requis.
 * Usage CLI: php restore_data.php <nom_fichier.zip>
 * Usage HTTP: GET/POST ?file=nom_fichier.zip
 */
require_once __DIR__ . '/auth_groups/loader.php';

function respond(array $payload, int $statusCode = 200): void {
    $isCli = (PHP_SAPI === 'cli');
    if ($isCli) {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        return;
    }

    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function resolveZipPath(string $zipFilename): string {
    $baseDir = defined('TMP_ASSETS_DIR') ? TMP_ASSETS_DIR : (__DIR__ . '/../tmp_assets/');
    $downloadDir = rtrim($baseDir, '/\\') . '/downloads/';

    if (strpos($zipFilename, '/') === false && strpos($zipFilename, '\\') === false) {
        return $downloadDir . $zipFilename;
    }

    return $zipFilename;
}

function normalizeTableName(string $table): ?string {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return null;
    }
    return $table;
}

function shouldSkipRow(string $table, array $row, array $skipRules): bool {
    if (!isset($skipRules[$table])) {
        return false;
    }
    foreach ($skipRules[$table] as $rule) {
        $column = $rule['column'];
        if (array_key_exists($column, $row) && in_array($row[$column], $rule['values'], true)) {
            return true;
        }
    }
    return false;
}

try {
    $zipFilename = null;
    if (PHP_SAPI === 'cli') {
        if ($argc < 2) {
            throw new RuntimeException('Usage: php restore_data.php <nom_fichier.zip>');
        }
        $zipFilename = $argv[1];
    } else {
        $zipFilename = $_GET['file'] ?? $_POST['file'] ?? null;
        if (empty($zipFilename)) {
            throw new RuntimeException('Parametre "file" manquant');
        }
    }

    $zipPath = resolveZipPath($zipFilename);
    if (!file_exists($zipPath)) {
        throw new RuntimeException("Fichier ZIP introuvable : $zipPath");
    }

    $password = $_ENV['DB_PASS'] ?? '';
    if ($password === '') {
        throw new RuntimeException('DB_PASS est vide, impossible de dechiffrer le ZIP');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException("Impossible d'ouvrir le fichier ZIP");
    }

    $zip->setPassword($password);
    $jsonContent = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $filename = $stat['name'];
        if (pathinfo($filename, PATHINFO_EXTENSION) === 'json') {
            $jsonContent = $zip->getFromIndex($i);
            break;
        }
    }
    $zip->close();

    if ($jsonContent === false || $jsonContent === null) {
        throw new RuntimeException('Impossible d\'extraire le JSON (mot de passe incorrect ou fichier corrompu)');
    }

    $payload = json_decode($jsonContent, true);
    if (!is_array($payload) || !isset($payload['tables']) || !is_array($payload['tables'])) {
        throw new RuntimeException('Format JSON invalide: "tables" manquant');
    }

    $restoreOrder = [
        'plans',
        'users',
        'tags',
        'groups',
        'files',
        'api_keys',
        'user_sessions',
        'user_app_setup',
        'login_codes',
        'password_resets',
        'email_verifications',
        'notifications',
        'group_members',
        'group_invitations',
        'group_tag_relations',
        'file_tag_relations',
        'user_plan_history',
        'plan_invitations',
        'group_stats_snapshot',
        'user_stats_snapshot',
        'platform_stats'
    ];

    $skipRules = [
        'plans' => [
            [
                'column' => 'name',
                'values' => ['free', 'bronze', 'argent', 'platine']
            ]
        ],
        'users' => [
            [
                'column' => 'email',
                'values' => ['jrobitaille04@pm.me', 'user@cmem2.com']
            ]
        ],
        'api_keys' => [
            [
                'column' => 'key_hash',
                'values' => [
                    'f9281a209030ab51f15c66e56ff6f55bb556fab82032919505ee3ea20fe589c4',
                    '36577224f257ec561c9f0f7330420f2c6996308e199bc115a24f91b9659f9f0c'
                ]
            ]
        ]
    ];

    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();

    $results = [];
    $stmtCache = [];

    foreach ($restoreOrder as $table) {
        if (!isset($payload['tables'][$table]) || !is_array($payload['tables'][$table])) {
            continue;
        }

        $safeTable = normalizeTableName($table);
        if ($safeTable === null) {
            continue;
        }

        $rows = $payload['tables'][$table];
        $inserted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (shouldSkipRow($table, $row, $skipRules)) {
                $skipped++;
                continue;
            }

            $columns = array_keys($row);
            if (empty($columns)) {
                continue;
            }

            $colsKey = implode('|', $columns);
            if (!isset($stmtCache[$table][$colsKey])) {
                $escapedCols = array_map(static function ($col) {
                    return '`' . str_replace('`', '``', $col) . '`';
                }, $columns);
                $placeholders = rtrim(str_repeat('?,', count($columns)), ',');
                $sql = 'INSERT IGNORE INTO `' . $safeTable . '` (' . implode(',', $escapedCols) . ') VALUES (' . $placeholders . ')';
                $stmtCache[$table][$colsKey] = $db->prepare($sql);
            }

            $values = [];
            foreach ($columns as $col) {
                $value = $row[$col];
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                $values[] = $value;
            }

            $stmtCache[$table][$colsKey]->execute($values);
            if ($stmtCache[$table][$colsKey]->rowCount() > 0) {
                $inserted++;
            } else {
                $skipped++;
            }
        }

        $results[$table] = [
            'inserted' => $inserted,
            'skipped' => $skipped
        ];
    }

    $db->commit();

    respond([
        'ok' => true,
        'zip_file' => $zipPath,
        'tables_processed' => array_keys($results),
        'results' => $results
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    respond([
        'ok' => false,
        'error' => $e->getMessage()
    ], 500);
}
