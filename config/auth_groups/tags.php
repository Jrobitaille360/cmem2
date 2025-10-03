<?php
/**
 * Configuration des tags pour le module auth_groups
 * Module: auth_groups - gestion des étiquettes et catégories
 */

// Configuration des tags
define('MAX_TAGS_PER_ITEM', (int)($_ENV['MAX_TAGS_PER_ITEM'] ?? 10));
define('MAX_TAG_LENGTH', (int)($_ENV['MAX_TAG_LENGTH'] ?? 50));
define('MIN_TAG_LENGTH', (int)($_ENV['MIN_TAG_LENGTH'] ?? 2));

// Configuration des catégories de tags
define('MAX_TAG_CATEGORIES', (int)($_ENV['MAX_TAG_CATEGORIES'] ?? 20));
define('MAX_CATEGORY_NAME_LENGTH', (int)($_ENV['MAX_CATEGORY_NAME_LENGTH'] ?? 30));

// Configuration des couleurs de tags
define('DEFAULT_TAG_COLORS', explode(',', $_ENV['DEFAULT_TAG_COLORS'] ?? '#007bff,#28a745,#ffc107,#dc3545,#6f42c1,#fd7e14,#20c997,#6c757d'));
define('ALLOW_CUSTOM_TAG_COLORS', filter_var($_ENV['ALLOW_CUSTOM_TAG_COLORS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Configuration des permissions de tags
define('ALLOW_USER_CREATE_TAGS', filter_var($_ENV['ALLOW_USER_CREATE_TAGS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('REQUIRE_TAG_APPROVAL', filter_var($_ENV['REQUIRE_TAG_APPROVAL'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
define('ALLOW_TAG_SUGGESTIONS', filter_var($_ENV['ALLOW_TAG_SUGGESTIONS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Configuration de la recherche de tags
define('TAG_SEARCH_MIN_LENGTH', (int)($_ENV['TAG_SEARCH_MIN_LENGTH'] ?? 1));
define('TAG_SEARCH_MAX_RESULTS', (int)($_ENV['TAG_SEARCH_MAX_RESULTS'] ?? 50));
define('ENABLE_TAG_AUTOCOMPLETE', filter_var($_ENV['ENABLE_TAG_AUTOCOMPLETE'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Configuration de la popularité des tags
define('TRACK_TAG_USAGE', filter_var($_ENV['TRACK_TAG_USAGE'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('TAG_TRENDING_PERIOD_DAYS', (int)($_ENV['TAG_TRENDING_PERIOD_DAYS'] ?? 30));
define('MAX_TRENDING_TAGS', (int)($_ENV['MAX_TRENDING_TAGS'] ?? 20));