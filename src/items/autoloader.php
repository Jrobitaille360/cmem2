<?php
/**
 * Autoloader pour le plugin Items
 */

// Namespace Core (PluginInterface, AbstractPlugin, PluginManager)
spl_autoload_register(function ($className) {
    if (strpos($className, 'Core\\') !== 0) {
        return;
    }
    $classFile = __DIR__ . '/../Core/' . substr($className, 5) . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
    }
});

// Namespace Items
spl_autoload_register(function ($className) {
    if (strpos($className, 'Items\\') !== 0) {
        return;
    }
    // Items\ItemsPlugin          → src/items/ItemsPlugin.php
    // Items\Routing\...          → src/items/Routing/....php
    // Items\Controllers\...      → src/items/Controllers/....php
    // Items\Models\...           → src/items/Models/....php
    // Items\Services\...         → src/items/Services/....php
    $relative  = str_replace('\\', '/', substr($className, 6));
    $classFile = __DIR__ . '/' . $relative . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
    }
});
