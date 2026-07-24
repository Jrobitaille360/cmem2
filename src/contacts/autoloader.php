<?php
/**
 * Autoloader pour le plugin Contacts
 * Module: contacts — fiches contacts, vCard 4.0, import CSV
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
    if (strpos($className, 'Contacts\\') !== 0) {
        return;
    }

    // Contacts\ContactsPlugin   → src/contacts/ContactsPlugin.php
    // Contacts\Routing\...      → src/contacts/Routing/....php
    // Contacts\Controllers\...  → src/contacts/Controllers/....php
    // Contacts\Models\...       → src/contacts/Models/....php
    // Contacts\Services\...     → src/contacts/Services/....php
    $relative  = str_replace('\\', '/', substr($className, 9));
    $classFile = __DIR__ . '/' . $relative . '.php';

    if (file_exists($classFile)) {
        require_once $classFile;
    }
});
