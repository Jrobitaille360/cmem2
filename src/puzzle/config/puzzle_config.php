<?php
/**
 * Configuration du module Puzzle
 * Plugin: Puzzle — Carrousel d'images, thèmes, sauvegarde en ligne, casse-têtes partagés
 */

// ============================================================================
// URL DE BASE API
// ============================================================================

if (!defined('API_BASE_URL')) {
    define('API_BASE_URL', defined('BASE_URL') ? \BASE_URL : '');
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
// DEBUG — Bypass abonnement (dev uniquement)
// ============================================================================

if (!defined('PUZZLE_DEBUG_PREMIUM')) {
    define('PUZZLE_DEBUG_PREMIUM', filter_var($_ENV['PUZZLE_DEBUG_PREMIUM'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
}

// ============================================================================
// CONFIGURATION CASSE-TÊTES PARTAGÉS — MÉCANIQUE DE JEU
// ============================================================================

/** Tolérance snap : fraction de la largeur d'une pièce (ex. 0.15 = 15 %) */
if (!defined('PUZZLE_SNAP_TOLERANCE')) {
    define('PUZZLE_SNAP_TOLERANCE', (float) ($_ENV['PUZZLE_SNAP_TOLERANCE'] ?? 0.15));
}

/** TTL pièce tenue (secondes) avant relâchement automatique */
if (!defined('PUZZLE_HELD_TTL_SECONDS')) {
    define('PUZZLE_HELD_TTL_SECONDS', (int) ($_ENV['PUZZLE_HELD_TTL_SECONDS'] ?? 30));
}

// ============================================================================
// CONFIGURATION GOOGLE PLAY
// ============================================================================

/** Identifiant fixe du package Android pour ce déploiement du plugin Puzzle. */
if (!defined('PUZZLE_GOOGLE_PLAY_PACKAGE')) {
    define('PUZZLE_GOOGLE_PLAY_PACKAGE', 'com.journauxdebord.puzzle');
}

/**
 * Chemin vers le fichier JSON du service account Google Play.
 * Obligatoire en production — à définir dans .env.
 * Chemin relatif → résolu depuis la racine du projet.
 * Absent ou fichier inexistant → validation Google Play désactivée silencieusement.
 */
if (!defined('PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON')) {
    $sa = $_ENV['PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON'] ?? '';
    if ($sa !== '' && $sa[0] !== '/' && !(strlen($sa) > 1 && $sa[1] === ':')) {
        $sa = dirname(__DIR__, 3) . '/' . $sa;
    }
    define('PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON', $sa);
    unset($sa);
}
