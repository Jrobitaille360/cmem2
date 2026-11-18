<?php
/**
 * Autoloader pour le plugin jesaisquand
 * Module : jesaisquand — paquets de cartes chronologiques, multijoueur au tour par tour
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

// Jesaisquand\Routing\X → src/jesaisquand/Routing/X.php
spl_autoload_register(function ($className) {
    if (strpos($className, 'Jesaisquand\\') !== 0) {
        return;
    }
    $classFile = __DIR__ . '/' . str_replace('\\', '/', substr($className, 12)) . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
    }
});
