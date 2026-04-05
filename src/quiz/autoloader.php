<?php
/**
 * Autoloader pour le plugin Quiz
 * Module: quiz — Quiz interactifs en temps réel (type Kahoot)
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

// Autoloader pour le namespace Quiz
spl_autoload_register(function ($className) {
    if (strpos($className, 'Quiz\\') !== 0) {
        return;
    }

    // Quiz\QuizPlugin              → src/quiz/QuizPlugin.php
    // Quiz\Routing\...             → src/quiz/Routing/....php
    // Quiz\Controllers\...         → src/quiz/Controllers/....php
    // Quiz\Models\...              → src/quiz/Models/....php
    // Quiz\Validators\...          → src/quiz/Validators/....php
    // Quiz\Services\...            → src/quiz/Services/....php
    $relative  = str_replace('\\', '/', substr($className, 5));
    $classFile = __DIR__ . '/' . $relative . '.php';

    if (file_exists($classFile)) {
        require_once $classFile;
    }
});
