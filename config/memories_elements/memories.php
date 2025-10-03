<?php
/**
 * Configuration des mémoires pour le module memories_elements
 * Module: memories_elements - gestion des souvenirs et contenus
 */

// Configuration des mémoires
define('MAX_MEMORIES_PER_USER', (int)($_ENV['MAX_MEMORIES_PER_USER'] ?? 1000));
define('MAX_MEMORY_TITLE_LENGTH', (int)($_ENV['MAX_MEMORY_TITLE_LENGTH'] ?? 200));
define('MAX_MEMORY_DESCRIPTION_LENGTH', (int)($_ENV['MAX_MEMORY_DESCRIPTION_LENGTH'] ?? 5000));

// Configuration des éléments par mémoire
define('MAX_ELEMENTS_PER_MEMORY', (int)($_ENV['MAX_ELEMENTS_PER_MEMORY'] ?? 50));
define('MAX_ELEMENT_TITLE_LENGTH', (int)($_ENV['MAX_ELEMENT_TITLE_LENGTH'] ?? 100));
define('MAX_ELEMENT_DESCRIPTION_LENGTH', (int)($_ENV['MAX_ELEMENT_DESCRIPTION_LENGTH'] ?? 2000));

// Configuration de la visibilité des mémoires
define('DEFAULT_MEMORY_VISIBILITY', $_ENV['DEFAULT_MEMORY_VISIBILITY'] ?? 'private'); // private, group, public
define('ALLOW_PUBLIC_MEMORIES', filter_var($_ENV['ALLOW_PUBLIC_MEMORIES'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('REQUIRE_MEMORY_APPROVAL', filter_var($_ENV['REQUIRE_MEMORY_APPROVAL'] ?? 'false', FILTER_VALIDATE_BOOLEAN));

// Configuration des dates de mémoires
define('ALLOW_FUTURE_MEMORY_DATES', filter_var($_ENV['ALLOW_FUTURE_MEMORY_DATES'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
define('MIN_MEMORY_YEAR', (int)($_ENV['MIN_MEMORY_YEAR'] ?? 1900));
define('MAX_MEMORY_YEAR', (int)($_ENV['MAX_MEMORY_YEAR'] ?? date('Y') + 1));

// Configuration de la géolocalisation
define('ENABLE_MEMORY_GEOLOCATION', filter_var($_ENV['ENABLE_MEMORY_GEOLOCATION'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('GEOLOCATION_PRECISION', (int)($_ENV['GEOLOCATION_PRECISION'] ?? 6)); // Nombre de décimales pour lat/lng

// Configuration de la recherche de mémoires
define('MEMORY_SEARCH_MIN_LENGTH', (int)($_ENV['MEMORY_SEARCH_MIN_LENGTH'] ?? 3));
define('MEMORY_SEARCH_MAX_RESULTS', (int)($_ENV['MEMORY_SEARCH_MAX_RESULTS'] ?? 100));
define('ENABLE_MEMORY_FULLTEXT_SEARCH', filter_var($_ENV['ENABLE_MEMORY_FULLTEXT_SEARCH'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Configuration des statistiques de mémoires
define('TRACK_MEMORY_VIEWS', filter_var($_ENV['TRACK_MEMORY_VIEWS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('TRACK_MEMORY_LIKES', filter_var($_ENV['TRACK_MEMORY_LIKES'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('ENABLE_MEMORY_COMMENTS', filter_var($_ENV['ENABLE_MEMORY_COMMENTS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));