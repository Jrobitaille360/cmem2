<?php
/**
 * Autoloader pour le plugin Pomo
 * Module: pomo — Pomodoro (engagement MVP, support, sync cloud, Stripe)
 */

// Autoloader pour le namespace Core (PluginInterface, AbstractPlugin, PluginManager)
spl_autoload_register(function ($className) {
    if (strpos($className, 'Core\\') !== 0) {
        return;
    }

    $classFile = __DIR__ . '/../Core/' . substr($className, 5) . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
    }
});

// Autoloader pour le namespace Pomo
spl_autoload_register(function ($className) {
    if (strpos($className, 'Pomo\\') !== 0) {
        return;
    }

    // Pomo\PomoPlugin         → src/pomo/PomoPlugin.php
    // Pomo\Routing\...        → src/pomo/Routing/....php
    // Pomo\Controllers\...    → src/pomo/Controllers/....php
    // Pomo\Models\...         → src/pomo/Models/....php
    // Pomo\Validators\...     → src/pomo/Validators/....php
    // Pomo\Services\...       → src/pomo/Services/....php
    $relative = str_replace('\\', '/', substr($className, 5));
    $classFile = __DIR__ . '/' . $relative . '.php';

    if (file_exists($classFile)) {
        require_once $classFile;
    }
});
