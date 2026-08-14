<?php
/**
 * Autoloader pour le plugin Booking
 * Module: booking — Réservation publique
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

// Autoloader pour le namespace Booking
spl_autoload_register(function ($className) {
    if (strpos($className, 'Booking\\') !== 0) {
        return;
    }

    // Booking\BookingPlugin       → src/booking/BookingPlugin.php
    // Booking\Routing\...         → src/booking/Routing/....php
    // Booking\Controllers\...     → src/booking/Controllers/....php
    // Booking\Models\...          → src/booking/Models/....php
    // Booking\Services\...        → src/booking/Services/....php
    $relative = str_replace('\\', '/', substr($className, 8));
    $classFile = __DIR__ . '/' . $relative . '.php';

    if (file_exists($classFile)) {
        require_once $classFile;
    }
});
