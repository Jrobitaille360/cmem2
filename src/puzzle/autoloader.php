<?php
/**
 * Autoloader pour le plugin Puzzle
 * Module: puzzle — Carrousel d'images, thèmes, sauvegarde en ligne, casse-têtes partagés
 */

// Autoloader pour le namespace Core
spl_autoload_register(function ($className) {
    if (strpos($className, 'Core\\') !== 0) {
        return;
    }
    $classFile = __DIR__ . '/../Core/' . substr($className, 5) . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
    }
});

// Autoloader pour le namespace Puzzle
spl_autoload_register(function ($className) {
    if (strpos($className, 'Puzzle\\') !== 0) {
        return;
    }

    // Puzzle\PuzzlePlugin              → src/puzzle/PuzzlePlugin.php
    // Puzzle\Routing\...               → src/puzzle/Routing/....php
    // Puzzle\Controllers\...           → src/puzzle/Controllers/....php
    // Puzzle\Middleware\...            → src/puzzle/Middleware/....php
    // Puzzle\Models\...                → src/puzzle/Models/....php
    // Puzzle\Services\...              → src/puzzle/Services/....php
    $relative  = str_replace('\\', '/', substr($className, 7));
    $classFile = __DIR__ . '/' . $relative . '.php';

    if (file_exists($classFile)) {
        require_once $classFile;
    }
});

// Charger la configuration du module Puzzle
$puzzleConfigFile = __DIR__ . '/config/puzzle_config.php';
if (file_exists($puzzleConfigFile)) {
    require_once $puzzleConfigFile;
}
