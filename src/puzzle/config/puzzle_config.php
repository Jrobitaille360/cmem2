<?php
/**
 * Configuration du module Puzzle
 * Plugin: Puzzle — Carrousel d'images, thèmes, sauvegarde en ligne, casse-têtes partagés
 */

// ============================================================================
// URL DE BASE API
// ============================================================================

if (!defined('API_BASE_URL')) {
    $apiBaseUrl = (defined('BASE_URL') ? \BASE_URL : '') . (defined('BASE_PATH') ? \BASE_PATH : '');
    define('API_BASE_URL', $apiBaseUrl);
}

// ============================================================================
// CONFIGURATION FICHIERS
// ============================================================================

if (!defined('PUZZLE_UPLOAD_DIR')) {
    define('PUZZLE_UPLOAD_DIR', $_ENV['PUZZLE_UPLOAD_DIR'] ?? __DIR__ . '/../../../uploads/puzzle');
}

// ============================================================================
// CONFIGURATION APPAREIL
// ============================================================================

if (!defined('PUZZLE_DEVICE_TOKEN_DAYS')) {
    define('PUZZLE_DEVICE_TOKEN_DAYS', (int) ($_ENV['PUZZLE_DEVICE_TOKEN_DAYS'] ?? 365));
}

// ============================================================================
// CONFIGURATION CASSE-TÊTES PARTAGÉS
// ============================================================================

if (!defined('PUZZLE_POLL_ACTIVE_WINDOW_SECONDS')) {
    define('PUZZLE_POLL_ACTIVE_WINDOW_SECONDS', (int) ($_ENV['PUZZLE_POLL_ACTIVE_WINDOW_SECONDS'] ?? 10));
}

if (!defined('PUZZLE_EVENT_RETENTION_HOURS')) {
    define('PUZZLE_EVENT_RETENTION_HOURS', (int) ($_ENV['PUZZLE_EVENT_RETENTION_HOURS'] ?? 24));
}

// ============================================================================
// CONFIGURATION GOOGLE PLAY
// ============================================================================

if (!defined('PUZZLE_GOOGLE_PLAY_PACKAGE')) {
    define('PUZZLE_GOOGLE_PLAY_PACKAGE', $_ENV['PUZZLE_GOOGLE_PLAY_PACKAGE'] ?? '');
}

if (!defined('PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON')) {
    define('PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON', $_ENV['PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON'] ?? '');
}
