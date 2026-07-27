<?php
/**
 * Autoloader pour le plugin Push
 * Module: push — Web Push (VAPID), subscriptions, préférences, cron d'envoi
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
    if (strpos($className, 'Push\\') !== 0) {
        return;
    }

    // Push\PushPlugin        → src/push/PushPlugin.php
    // Push\Routing\...       → src/push/Routing/....php
    // Push\Controllers\...   → src/push/Controllers/....php
    // Push\Models\...        → src/push/Models/....php
    // Push\Services\...      → src/push/Services/....php
    $relative  = str_replace('\\', '/', substr($className, 5));
    $classFile = __DIR__ . '/' . $relative . '.php';

    if (file_exists($classFile)) {
        require_once $classFile;
    }
});
