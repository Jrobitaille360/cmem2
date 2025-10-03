<?php
/**
 * Configuration de l'authentification et des groupes
 * Module: auth_groups
 */

// Configuration JWT
define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-this-in-production');
define('JWT_ALGORITHM', $_ENV['JWT_ALGORITHM'] ?? 'HS256');
define('JWT_EXPIRATION', (int)($_ENV['JWT_EXPIRATION'] ?? 86400)); // 24 heures par défaut

// Configuration de l'authentification
define('AUTH_AUTO_LOGOUT_BEFORE_LOGIN', filter_var($_ENV['AUTH_AUTO_LOGOUT_BEFORE_LOGIN'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('AUTH_AUTO_LOGOUT_LOG_LEVEL', $_ENV['AUTH_AUTO_LOGOUT_LOG_LEVEL'] ?? 'info');
define('AUTH_AUTO_LOGOUT_ALL_TOKENS', filter_var($_ENV['AUTH_AUTO_LOGOUT_ALL_TOKENS'] ?? 'false', FILTER_VALIDATE_BOOLEAN));

// Configuration des tokens de validation
define('VALID_TOKEN_EXPIRATION', (int)($_ENV['VALID_TOKEN_EXPIRATION'] ?? 3600)); // 1 heure par défaut
define('VALID_TOKEN_CLEANUP_INTERVAL', (int)($_ENV['VALID_TOKEN_CLEANUP_INTERVAL'] ?? 1800)); // 30 minutes

// Configuration des invitations de groupes
define('GROUP_INVITATION_EXPIRATION', (int)($_ENV['GROUP_INVITATION_EXPIRATION'] ?? 604800)); // 7 jours par défaut
define('MAX_GROUP_MEMBERS', (int)($_ENV['MAX_GROUP_MEMBERS'] ?? 50));
define('MAX_GROUPS_PER_USER', (int)($_ENV['MAX_GROUPS_PER_USER'] ?? 10));

// Configuration des utilisateurs
define('MAX_USERNAME_LENGTH', (int)($_ENV['MAX_USERNAME_LENGTH'] ?? 50));
define('MIN_PASSWORD_LENGTH', (int)($_ENV['MIN_PASSWORD_LENGTH'] ?? 8));
define('REQUIRE_EMAIL_VERIFICATION', filter_var($_ENV['REQUIRE_EMAIL_VERIFICATION'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// Configuration des sessions utilisateur
define('MAX_CONCURRENT_SESSIONS', (int)($_ENV['MAX_CONCURRENT_SESSIONS'] ?? 5));
define('SESSION_TIMEOUT', (int)($_ENV['SESSION_TIMEOUT'] ?? 3600)); // 1 heure par défaut