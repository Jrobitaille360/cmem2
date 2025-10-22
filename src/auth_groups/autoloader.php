<?php

/**
 * Autoloader pour le module AuthGroups
 * 
 * Cet autoloader gère le chargement automatique des classes du module AuthGroups
 * selon la structure PSR-4.
 */

spl_autoload_register(function ($className) {
    // Préfixe de namespace pour AuthGroups
    $prefix = 'AuthGroups\\';
    
    // Répertoire de base pour le namespace
    $baseDir = __DIR__ . '/';
    
    // Vérifier si la classe utilise le namespace AuthGroups
    $len = strlen($prefix);
    if (strncmp($prefix, $className, $len) !== 0) {
        // Pas notre namespace, laisser d'autres autoloaders essayer
        return;
    }
    
    // Obtenir le nom de classe relatif
    $relativeClass = substr($className, $len);
    
    // Remplacer les séparateurs de namespace par des séparateurs de répertoire
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    // Si le fichier existe, l'inclure
    if (file_exists($file)) {
        require_once $file;
    }
});

// Confirmation du chargement de l'autoloader
if (!defined('AUTH_GROUPS_AUTOLOADER_LOADED')) {
    define('AUTH_GROUPS_AUTOLOADER_LOADED', true);
}

?>