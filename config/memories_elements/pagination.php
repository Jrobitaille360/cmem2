<?php
/**
 * Configuration de la pagination pour le module memories_elements
 * Module: memories_elements - gestion de l'affichage des contenus
 */

// Configuration de pagination pour les mémoires
define('MEMORIES_DEFAULT_PAGE_SIZE', (int)($_ENV['MEMORIES_DEFAULT_PAGE_SIZE'] ?? 12));
define('MEMORIES_MAX_PAGE_SIZE', (int)($_ENV['MEMORIES_MAX_PAGE_SIZE'] ?? 50));
define('MEMORIES_MIN_PAGE_SIZE', (int)($_ENV['MEMORIES_MIN_PAGE_SIZE'] ?? 6));

// Configuration de pagination pour les éléments
define('ELEMENTS_DEFAULT_PAGE_SIZE', (int)($_ENV['ELEMENTS_DEFAULT_PAGE_SIZE'] ?? 20));
define('ELEMENTS_MAX_PAGE_SIZE', (int)($_ENV['ELEMENTS_MAX_PAGE_SIZE'] ?? 100));
define('ELEMENTS_MIN_PAGE_SIZE', (int)($_ENV['ELEMENTS_MIN_PAGE_SIZE'] ?? 5));

// Configuration de pagination pour les commentaires
define('COMMENTS_DEFAULT_PAGE_SIZE', (int)($_ENV['COMMENTS_DEFAULT_PAGE_SIZE'] ?? 10));
define('COMMENTS_MAX_PAGE_SIZE', (int)($_ENV['COMMENTS_MAX_PAGE_SIZE'] ?? 50));

// Configuration de l'affichage en grille
define('MEMORY_GRID_COLS_MOBILE', (int)($_ENV['MEMORY_GRID_COLS_MOBILE'] ?? 1));
define('MEMORY_GRID_COLS_TABLET', (int)($_ENV['MEMORY_GRID_COLS_TABLET'] ?? 2));
define('MEMORY_GRID_COLS_DESKTOP', (int)($_ENV['MEMORY_GRID_COLS_DESKTOP'] ?? 3));

// Configuration du lazy loading
define('ENABLE_LAZY_LOADING', filter_var($_ENV['ENABLE_LAZY_LOADING'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('LAZY_LOADING_THRESHOLD', (int)($_ENV['LAZY_LOADING_THRESHOLD'] ?? 200)); // pixels avant le viewport

// Configuration du cache de pagination
define('ENABLE_PAGINATION_CACHE', filter_var($_ENV['ENABLE_PAGINATION_CACHE'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('PAGINATION_CACHE_DURATION', (int)($_ENV['PAGINATION_CACHE_DURATION'] ?? 300)); // 5 minutes

// Configuration des filtres d'affichage
define('ENABLE_MEMORY_FILTERS', filter_var($_ENV['ENABLE_MEMORY_FILTERS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('AVAILABLE_MEMORY_SORTS', explode(',', $_ENV['AVAILABLE_MEMORY_SORTS'] ?? 'date_desc,date_asc,title_asc,views_desc,likes_desc'));
define('DEFAULT_MEMORY_SORT', $_ENV['DEFAULT_MEMORY_SORT'] ?? 'date_desc');

// Configuration des vues d'affichage
define('AVAILABLE_MEMORY_VIEWS', explode(',', $_ENV['AVAILABLE_MEMORY_VIEWS'] ?? 'grid,list,timeline,map'));
define('DEFAULT_MEMORY_VIEW', $_ENV['DEFAULT_MEMORY_VIEW'] ?? 'grid');