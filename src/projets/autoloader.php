<?php
/**
 * Autoloader pour le plugin Projets
 * Module: projets — gestion de projet (tâches, hiérarchie, dépendances)
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
    if (strpos($className, 'Projets\\') !== 0) {
        return;
    }

    // Projets\ProjetsPlugin     → src/projets/ProjetsPlugin.php
    // Projets\Routing\...       → src/projets/Routing/....php
    // Projets\Controllers\...   → src/projets/Controllers/....php
    // Projets\Models\...        → src/projets/Models/....php
    // Projets\Services\...      → src/projets/Services/....php
    // Projets\Ical\...          → src/projets/Ical/....php
    $relative  = str_replace('\\', '/', substr($className, 8));
    $classFile = __DIR__ . '/' . $relative . '.php';

    if (file_exists($classFile)) {
        require_once $classFile;
    }
});
