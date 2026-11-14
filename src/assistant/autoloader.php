<?php
/**
 * Autoloader pour le plugin Assistant
 * Module: assistant — chat avec Claude, outils tâches/rappels
 */

// Autoloader pour le namespace Core (PluginInterface, AbstractPlugin, PluginManager)
spl_autoload_register(function ($className) {
    if (strpos($className, 'Core\\') !== 0) {
        return;
    }

    $classFile = __DIR__ . '/../Core/' . str_replace('\\', '/', substr($className, 5)) . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
    }
});

// Assistant\Xxx → src/assistant/Xxx.php
spl_autoload_register(function ($className) {
    if (strpos($className, 'Assistant\\') !== 0) {
        return;
    }

    $relative  = str_replace('\\', '/', substr($className, 10));
    $classFile = __DIR__ . '/' . $relative . '.php';

    if (file_exists($classFile)) {
        require_once $classFile;
    }
});
