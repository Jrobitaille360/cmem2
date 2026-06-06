<?php
/**
 * Autoloader pour le plugin Traque
 * Module: traque — RPG AR mobile, combat AD&D, monstres, joueurs
 */

spl_autoload_register(function ($className) {
    if (strpos($className, 'Core\\') !== 0) {
        return;
    }
    $classFile = __DIR__ . '/../Core/' . str_replace('\\', '/', substr($className, 5)) . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
    }
});

spl_autoload_register(function ($className) {
    if (strpos($className, 'Traque\\') !== 0) {
        return;
    }
    $relative  = str_replace('\\', '/', substr($className, 7));
    $classFile = __DIR__ . '/' . $relative . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
    }
});
