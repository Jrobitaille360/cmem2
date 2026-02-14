<?php
/**
 * Export des tables MySQL en JSON puis ZIP chiffre.
 * Usage CLI: php backup_data.php [chemin_absolu_sortie]
 * Usage HTTP: GET/POST ?dir=C:\chemin\absolu\sortie
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


try {
	
    $outputDir = $_ENV['BACKUP_DIR'] ?? null;
    if ($outputDir == null || $outputDir === '') {
		throw new RuntimeException(message: 'Le parametre dir est requis.');
    }

    $password = $_ENV['BACKUP_PASSPHRASE'] ?? 'NO PHRASE';
    if ($password === '') {
		throw new RuntimeException(message: 'BACKUP_PASSPHRASE is empty; cannot protect ZIP.');
	}

	$db = Database::getInstance()->getConnection();
	$tablesStmt = $db->query('SHOW TABLES');
	$tables = [];
	foreach ($tablesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$tables[] = array_values($row)[0];
	}

	$export = [
		'exported_at' => date('c'),
		'database' => $_ENV['DB_NAME'] ?? null,
		'tables' => []
	];

	foreach ($tables as $table) {
		$stmt = $db->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
		$export['tables'][$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	$downloadDir = rtrim($outputDir, '/\\') . '/';
	
	if (!is_dir($downloadDir)) {
		mkdir($downloadDir, 0755, true);
	}

	$timestamp = date('Ymd_His');
	$jsonPath = $downloadDir . 'db_backup_' . $timestamp . '.json';
	$zipPath = $downloadDir . 'db_backup_' . $timestamp . '.zip';

	$json = json_encode($export, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
	if ($json === false) {
		throw new RuntimeException('JSON encode failed: ' . json_last_error_msg());
	}

	if (file_put_contents($jsonPath, $json) === false) {
		throw new RuntimeException('Failed to write JSON file.');
	}

	$zip = new ZipArchive();
	if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
		throw new RuntimeException('Failed to create ZIP archive.');
	}

	$zip->setPassword($password);
	$zip->addFile($jsonPath, basename($jsonPath));
	if (!method_exists($zip, 'setEncryptionName')) {
		$zip->close();
		throw new RuntimeException('Zip encryption is not supported by this PHP build.');
	}

	if (!$zip->setEncryptionName(basename($jsonPath), ZipArchive::EM_AES_256)) {
		$zip->close();
		throw new RuntimeException('Failed to encrypt ZIP entry.');
	}

	$zip->close();

	unlink($jsonPath);

	respond([
		'ok' => true,
        'export' => [
            'exported_at' => $export['exported_at'],
            'database' => $export['database'],
            'tables_count' => count($export['tables'])
        ],
        'argv' => $argv,
        'argc' => $argc,
        'output_dir' => $outputDir,
        'download_dir' => $downloadDir,
        'zip_path' => $zipPath,
		'tables_count' => count($tables)
	]);
} catch (Throwable $e) {
	respond([
		'ok' => false,
		'error' => $e->getMessage()
	], 500);
}
