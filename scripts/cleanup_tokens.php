#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/loader.php';

use Memories\Services\ValidTokenService;
use Memories\Services\LogService;

try {
    $deletedCount = ValidTokenService::cleanupExpiredTokens();
    
    LogService::info("Nettoyage automatique des tokens", [
        'deleted_count' => $deletedCount,
        'executed_by' => 'cron'
    ]);
    
    echo "Tokens expirés nettoyés: $deletedCount\n";
    
} catch (Exception $e) {
    LogService::error("Erreur lors du nettoyage automatique", [
        'error' => $e->getMessage(),
        'executed_by' => 'cron'
    ]);
    
    echo "Erreur: " . $e->getMessage() . "\n";
    exit(1);
}