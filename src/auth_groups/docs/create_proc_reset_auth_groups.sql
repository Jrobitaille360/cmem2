DROP PROCEDURE IF EXISTS ResetAuthenticationGroups;
DELIMITER //

CREATE PROCEDURE ResetAuthenticationGroups()
BEGIN
-- Procédure pour réinitialiser les tables liées à l'authentification, groupes et fichiers
-- Cette procédure gère : users, groups, files et toutes leurs relations et systèmes

-- === SUPPRESSION DES VUES ET TABLES (ordre correct des dépendances) ===

-- Désactiver temporairement les vérifications de clés étrangères
SET FOREIGN_KEY_CHECKS = 0;

-- 1. SUPPRESSION DES VUES EN PREMIER
DROP VIEW IF EXISTS api_keys_stats_by_user;
DROP VIEW IF EXISTS active_api_keys;
DROP VIEW IF EXISTS active_user_sessions;
DROP VIEW IF EXISTS user_sessions_stats;
DROP VIEW IF EXISTS v_online_users_stats;
DROP VIEW IF EXISTS v_active_sessions;
DROP VIEW IF EXISTS v_admin_dashboard;
DROP VIEW IF EXISTS v_group_dashboard;
DROP VIEW IF EXISTS group_statistics;
DROP VIEW IF EXISTS v_active_users;

-- 2. SUPPRESSION DES TABLES DE RELATIONS ET STATISTIQUES
DROP TABLE IF EXISTS file_tag_relations;
DROP TABLE IF EXISTS group_tag_relations;
DROP TABLE IF EXISTS group_invitations;
DROP TABLE IF EXISTS group_members;
DROP TABLE IF EXISTS user_stats_snapshot;
DROP TABLE IF EXISTS group_stats_snapshot;
DROP TABLE IF EXISTS platform_stats;
DROP TABLE IF EXISTS email_verifications;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS login_codes;
DROP TABLE IF EXISTS user_app_setup;

-- 3. SUPPRESSION DES TABLES LIÉES AUX SESSIONS ET API KEYS
DROP TABLE IF EXISTS user_sessions;
DROP TABLE IF EXISTS api_keys;

-- 4. SUPPRESSION DES TABLES LIÉES AUX PLANS
DROP TABLE IF EXISTS plan_invitations;
DROP TABLE IF EXISTS user_plan_history;

-- 5. SUPPRESSION DES TABLES DÉPENDANTES
DROP TABLE IF EXISTS files;

-- 6. SUPPRESSION DES TABLES AVEC RÉFÉRENCES CROISÉES
DROP TABLE IF EXISTS groups;
DROP TABLE IF EXISTS tags;

-- 7. SUPPRESSION DES TABLES DES PLANS
DROP TABLE IF EXISTS plans;

-- 8. SUPPRESSION DES TABLES PRINCIPALES
DROP TABLE IF EXISTS users;

-- Réactiver les vérifications de clés étrangères
SET FOREIGN_KEY_CHECKS = 1;

-- ===== TABLE : Tags =====
CREATE TABLE tags (
	id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	name varchar(100) NOT NULL,
    table_associate enum('groups','memories','elements','files','all') DEFAULT NULL,
	color varchar(7) DEFAULT '#3498db',
    tag_owner int(11) NOT NULL,
	created_at timestamp NOT NULL DEFAULT current_timestamp,
	deleted_at datetime DEFAULT NULL,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	-- Contraintes et index
	UNIQUE KEY name (name, table_associate),
	KEY idx_tag_name (name),
    KEY idx_tag_owner (tag_owner),
	KEY idx_tag_table_associate (table_associate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Utilisateurs =====
CREATE TABLE users (
	id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	name varchar(255) NOT NULL,
	email varchar(255) NOT NULL,
	password_hash varchar(255) NOT NULL,
	role enum('ADMINISTRATEUR','UTILISATEUR') NOT NULL DEFAULT 'UTILISATEUR',
	profile_image varchar(500) DEFAULT NULL,
	bio text DEFAULT NULL,
	phone varchar(20) DEFAULT NULL,
	date_of_birth date DEFAULT NULL,
	location varchar(255) DEFAULT NULL,
	email_verified tinyint(1) NOT NULL DEFAULT 0,
	last_login timestamp NULL DEFAULT NULL,
    payment_status ENUM('pending', 'paid', 'expired') DEFAULT 'pending',
    license_expires_at DATETIME NULL,
    payment_plan ENUM('basic', 'standard', 'premium', 'lifetime') DEFAULT 'basic',
    payment_date DATETIME NULL,
	created_at timestamp NOT NULL DEFAULT current_timestamp(),
	deleted_at datetime DEFAULT NULL,
	updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
	UNIQUE KEY email (email),
	KEY idx_users_email (email),
	KEY idx_users_role (role),
	KEY idx_users_deleted_at (deleted_at),
	KEY idx_users_created_at (created_at),
	KEY idx_users_last_login (last_login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Configuration des applications utilisateur =====
CREATE TABLE user_app_setup (
	id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	user_id int(11) NOT NULL,
	app_id varchar(255) NOT NULL,
	json_data JSON DEFAULT NULL,
	created_at timestamp NOT NULL DEFAULT current_timestamp(),
	deleted_at datetime DEFAULT NULL,
	updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY unique_user_app (user_id, app_id),
	KEY idx_user_app_setup_user_id (user_id),
	KEY idx_user_app_setup_app_id (app_id),
	KEY idx_user_app_setup_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajout de la contrainte de clé étrangère pour tag_owner maintenant que la table users existe
ALTER TABLE tags ADD FOREIGN KEY (tag_owner) REFERENCES users(id) ON DELETE CASCADE;

-- ===== VUE : Utilisateurs actifs =====
CREATE VIEW v_active_users AS
SELECT id, name, email, role, last_login, created_at
FROM users
WHERE deleted_at IS NULL;

-- ===== TABLE : Codes de connexion =====
CREATE TABLE login_codes (
	id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	user_id INT(11) NOT NULL,
	code VARCHAR(10) NOT NULL,
	expires_at DATETIME NULL DEFAULT NULL,
	used_at TIMESTAMP NULL DEFAULT NULL,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	KEY idx_user_login_codes_user_id (user_id),
	KEY idx_user_login_codes_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Groupes =====
CREATE TABLE groups (
    id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255),
    description VARCHAR(1000), -- Nouvelle colonne pour la description des groupes
    owner_id int(11),
    max_members int(11),
    visibility ENUM('private','shared','public') DEFAULT 'private',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at datetime DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
	KEY idx_group_owner_id (owner_id),
	KEY idx_group_visibility (visibility),
	KEY idx_group_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Relations entre groupes et tags =====
CREATE TABLE group_tag_relations (
	group_id int(11) NOT NULL,
	tag_id int(11) NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	deleted_at datetime DEFAULT NULL,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
	FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
	PRIMARY KEY (group_id, tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Membres de groupe =====
CREATE TABLE group_members (
    id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id INT(11),
    user_id INT(11),
	invited_by INT(11),
    role ENUM('admin','moderator','member') NOT NULL DEFAULT 'member',
	joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	deleted_at datetime DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY unique_group_user (group_id,user_id),
	KEY idx_group_member_invited_by (invited_by),
	KEY idx_group_member_group_id (group_id),
	KEY idx_group_member_user_id (user_id),
	KEY idx_group_member_role (role),
	KEY idx_group_member_joined_at (joined_at),
	KEY idx_group_member_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Invitations de groupe =====
CREATE TABLE group_invitations (
    id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	group_id int(11) NOT NULL,
	invited_email varchar(255) NOT NULL,
    invited_role ENUM('admin','moderator','member') NOT NULL DEFAULT 'member',
	invited_by int(11) NOT NULL,
	invitation_token varchar(100) NOT NULL,
	status enum('pending','accepted','declined','expired') NOT NULL DEFAULT 'pending',
	expires_at DATETIME NULL DEFAULT NULL,
	created_at timestamp NOT NULL DEFAULT current_timestamp(),
	deleted_at datetime DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	responded_at timestamp NULL DEFAULT NULL,
	FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
	FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY invitation_token (invitation_token),
	KEY idx_group_invitation_invited_by (invited_by),
	KEY idx_group_invitation_group_id (group_id),
	KEY idx_group_invitation_invited_email (invited_email),
	KEY idx_group_invitation_invitation_token (invitation_token),
	KEY idx_group_invitation_status (status),
	KEY idx_group_invitation_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Fichiers =====
CREATE TABLE files (
    id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    original_name varchar(255) NOT NULL,
	description text DEFAULT NULL,
    upload_ip varchar(45) NOT NULL,
	file_path varchar(500) NOT NULL,
	file_name varchar(255) NOT NULL,
	file_size int(11) NOT NULL,
	mime_type varchar(100) NOT NULL,
    media_type ENUM('text','audio','video','image','gpx','summary','event','todo','document'),
	uploaded_by int(11) NOT NULL,
	uploaded_at timestamp NOT NULL DEFAULT current_timestamp(),
    download_count int(11) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at datetime DEFAULT NULL,
	KEY idx_file_uploaded_by (uploaded_by),	
	KEY idx_file_uploaded_at (uploaded_at),
	KEY idx_file_mime_type (mime_type)	
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Relations entre fichiers et tags =====
CREATE TABLE file_tag_relations (
	file_id int(11) NOT NULL,
	tag_id int(11) NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	deleted_at datetime DEFAULT NULL,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
	FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
	PRIMARY KEY (file_id, tag_id),
	KEY idx_file_tag_relations_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== VUE : Statistiques de groupe =====
CREATE VIEW group_statistics AS
SELECT
    g.id,
    g.name,
    g.description,
    g.visibility,
    u.name AS creator_name,
    COUNT(DISTINCT gm.user_id) AS members_count,
    COUNT(DISTINCT ftr.file_id) AS files_count,
    IFNULL(SUM(f.file_size), 0) AS total_file_size,
    g.created_at,
    DATEDIFF(CURRENT_DATE, DATE(g.created_at)) AS days_since_creation
FROM groups g
LEFT JOIN users u ON u.id = g.owner_id
LEFT JOIN group_members gm ON gm.group_id = g.id AND gm.deleted_at IS NULL
LEFT JOIN file_tag_relations ftr ON EXISTS (
    SELECT 1 FROM group_tag_relations gtr 
    WHERE gtr.group_id = g.id AND gtr.tag_id = ftr.tag_id AND gtr.deleted_at IS NULL
)
LEFT JOIN files f ON f.id = ftr.file_id
WHERE g.deleted_at IS NULL
GROUP BY g.id;

-- ===== TABLE : Notifications =====
CREATE TABLE notifications (
    id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
	extra_payload JSON DEFAULT NULL,
    type ENUM('invitation','memory_update','group_event','system','reminder') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ,
	KEY idx_notifications_user_id (user_id),
    KEY idx_notifications_type (type),
    KEY idx_notifications_extra_payload (extra_payload),
    KEY idx_notifications_is_read (is_read),
    KEY idx_notifications_created_at (created_at)	
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== VUE : Tableau de bord de groupe =====
CREATE VIEW v_group_dashboard AS
SELECT g.id AS group_id, 
       g.name,  
       COUNT(DISTINCT gm.user_id) AS member_count,
       COUNT(DISTINCT gtr.tag_id) AS tag_count,
       MAX(g.updated_at) AS last_group_update
FROM groups g
LEFT JOIN group_members gm ON gm.group_id = g.id AND gm.deleted_at IS NULL
LEFT JOIN group_tag_relations gtr ON gtr.group_id = g.id AND gtr.deleted_at IS NULL
WHERE g.deleted_at IS NULL
GROUP BY g.id;

-- ===== TABLE : Réinitialisation de mot de passe =====
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    expires_at DATETIME NULL DEFAULT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at)
);

-- ===== TABLE : Vérifications d'email =====
CREATE TABLE IF NOT EXISTS email_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    deleted_at TIMESTAMP NULL,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_deleted_att (deleted_at)
);

-- =========================================================== TABLES : Statistiques =====

-- ===== TABLE : Statistiques globales =====
CREATE TABLE platform_stats (
	id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	total_users int(11) DEFAULT 0,
	active_users_7d int(11) DEFAULT 0,
	active_users_30d int(11) DEFAULT 0,
	total_groups int(11) DEFAULT 0,
	total_tags int(11) DEFAULT 0,
	total_files int(11) DEFAULT 0,
	total_storage_mb decimal(12,2) DEFAULT 0,
	pending_invitations int(11) DEFAULT 0,
	avg_group_size decimal(5,2) DEFAULT 0,
	generated_at timestamp DEFAULT current_timestamp(),
	KEY idx_platform_stats_generated_at (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Statistiques par groupe =====
CREATE TABLE group_stats_snapshot (
	id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	group_id int(11) NOT NULL,
	group_name varchar(255),
	visibility enum('private','shared','public'),
	member_count int(11) DEFAULT 0,
	tag_count int(11) DEFAULT 0,
	file_count int(11) DEFAULT 0,
	storage_mb decimal(10,2) DEFAULT 0,
	last_activity_date timestamp NULL,
	days_since_creation int(11) DEFAULT 0,
	generated_at timestamp DEFAULT current_timestamp(),
	FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
	KEY idx_group_stats_group_id (group_id),
	KEY idx_group_stats_generated_at (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Statistiques par utilisateur =====
CREATE TABLE user_stats_snapshot (
	id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	user_id int(11) NOT NULL,
	user_name varchar(255),
	role enum('ADMINISTRATEUR','UTILISATEUR'),
	last_login timestamp NULL,
	groups_created int(11) DEFAULT 0,
	groups_joined int(11) DEFAULT 0,
	tags_created int(11) DEFAULT 0,
	files_uploaded int(11) DEFAULT 0,
	storage_used_mb decimal(10,2) DEFAULT 0,
	invitations_sent int(11) DEFAULT 0,
	days_since_registration int(11) DEFAULT 0,
	generated_at timestamp DEFAULT current_timestamp(),
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	KEY idx_user_stats_user_id (user_id),
	KEY idx_user_stats_role (role),
	KEY idx_user_stats_generated_at (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== VUE : Tableau de bord administrateur =====
CREATE VIEW v_admin_dashboard AS
SELECT 
    (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) as total_users,
    (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as active_users_7d,
    (SELECT COUNT(*) FROM groups WHERE deleted_at IS NULL) as total_groups,
    (SELECT COUNT(*) FROM tags WHERE deleted_at IS NULL) as total_tags,
    (SELECT COUNT(*) FROM files) as total_files,
    (SELECT ROUND(COALESCE(SUM(file_size), 0) / 1024 / 1024, 2) FROM files) as total_storage_mb,
        (SELECT COUNT(*) FROM group_invitations WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())) as pending_invitations;


CREATE TABLE IF NOT EXISTS api_keys (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    
    -- Informations de la clé
    name VARCHAR(255) NOT NULL COMMENT 'Nom descriptif de la clé API',
    key_prefix VARCHAR(10) NOT NULL DEFAULT 'ag_live' COMMENT 'Préfixe de la clé (ag_live, ag_test)',
    key_hash VARCHAR(255) NOT NULL COMMENT 'Hash SHA-256 de la clé complète',
    last_4 VARCHAR(4) NOT NULL COMMENT '4 derniers caractères visibles de la clé',
    
    -- Permissions et configuration
    scopes JSON DEFAULT NULL COMMENT 'Permissions/scopes de la clé (JSON array)',
    environment ENUM('production', 'test') NOT NULL DEFAULT 'production' COMMENT 'Environnement (production/test)',
    
    -- Rate limiting
    rate_limit_per_minute INT(11) DEFAULT 60 COMMENT 'Nombre max de requêtes par minute',
    rate_limit_per_hour INT(11) DEFAULT 3600 COMMENT 'Nombre max de requêtes par heure',
    
    -- Statistiques d'utilisation
    total_requests INT(11) DEFAULT 0 COMMENT 'Nombre total de requêtes effectuées',
    last_used_at DATETIME DEFAULT NULL COMMENT 'Dernière utilisation de la clé',
    last_used_ip VARCHAR(45) DEFAULT NULL COMMENT 'Dernière IP ayant utilisé la clé',
    
    -- Expiration et révocation
    expires_at DATETIME DEFAULT NULL COMMENT 'Date expiration de la clé (NULL = jamais)',
    revoked_at DATETIME DEFAULT NULL COMMENT 'Date de révocation (NULL = active)',
    revoked_reason VARCHAR(255) DEFAULT NULL COMMENT 'Raison de la révocation',
    
    -- Métadonnées
    metadata JSON DEFAULT NULL COMMENT 'Métadonnées additionnelles (JSON object)',
    notes TEXT DEFAULT NULL COMMENT 'Notes internes sur la clé',
    
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    -- Index et contraintes
    UNIQUE KEY unique_key_hash (key_hash),
    INDEX idx_user_id (user_id),
    INDEX idx_key_prefix (key_prefix),
    INDEX idx_environment (environment),
    INDEX idx_expires_at (expires_at),
    INDEX idx_revoked_at (revoked_at),
    INDEX idx_created_at (created_at),
    INDEX idx_last_used_at (last_used_at),
    
    -- Clé étrangère
    CONSTRAINT fk_api_keys_user_id 
        FOREIGN KEY (user_id) 
        REFERENCES users(id) 
        ON DELETE CASCADE
        ON UPDATE CASCADE
        
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stockage des clés API pour authentification';


-- Vue pour voir uniquement les clés actives
CREATE OR REPLACE VIEW active_api_keys AS
SELECT 
    id,
    user_id,
    name,
    key_prefix,
    last_4,
    scopes,
    environment,
    rate_limit_per_minute,
    rate_limit_per_hour,
    total_requests,
    last_used_at,
    last_used_ip,
    expires_at,
    metadata,
    created_at,
    updated_at,
    CASE 
        WHEN expires_at IS NOT NULL AND expires_at < NOW() THEN TRUE
        ELSE FALSE 
    END AS is_expired
FROM api_keys
WHERE revoked_at IS NULL
  AND (expires_at IS NULL OR expires_at > NOW());

-- Vue pour statistiques par utilisateur
CREATE OR REPLACE VIEW api_keys_stats_by_user AS
SELECT 
    user_id,
    COUNT(*) AS total_keys,
    SUM(CASE WHEN revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) THEN 1 ELSE 0 END) AS active_keys,
    SUM(CASE WHEN revoked_at IS NOT NULL THEN 1 ELSE 0 END) AS revoked_keys,
    SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < NOW() AND revoked_at IS NULL THEN 1 ELSE 0 END) AS expired_keys,
    SUM(total_requests) AS total_requests_all_keys,
    MAX(last_used_at) AS most_recent_usage,
    MAX(created_at) AS most_recent_key_created
FROM api_keys
GROUP BY user_id;

-- Table simplifiée pour le suivi des sessions utilisateurs

CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `api_key_id` int(11) NOT NULL,
  `login_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `logout_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `session_data` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_api_key_id` (`api_key_id`),
  KEY `idx_active_sessions` (`user_id`, `is_active`, `expires_at`),
  KEY `idx_cleanup` (`expires_at`, `is_active`),
  CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_sessions_api_key` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Vue pour les sessions actives
CREATE OR REPLACE VIEW `active_user_sessions` AS
SELECT 
    us.*,
    u.email,
    u.name as username,
    u.role,
    ak.name as api_key_name,
    ak.environment,
    TIMESTAMPDIFF(MINUTE, us.last_activity_at, NOW()) as minutes_since_activity,
    TIMESTAMPDIFF(MINUTE, us.login_at, IFNULL(us.logout_at, NOW())) as session_duration_minutes
FROM user_sessions us
JOIN users u ON us.user_id = u.id
JOIN api_keys ak ON us.api_key_id = ak.id
WHERE us.is_active = 1 
  AND us.expires_at > NOW()
  AND u.deleted_at IS NULL;

-- Vue pour les statistiques
CREATE OR REPLACE VIEW `user_sessions_stats` AS
SELECT 
    COUNT(*) as total_active_sessions,
    COUNT(DISTINCT user_id) as unique_users_online,
    AVG(TIMESTAMPDIFF(MINUTE, login_at, IFNULL(logout_at, NOW()))) as avg_session_duration_minutes,
    COUNT(CASE WHEN last_activity_at > NOW() - INTERVAL 5 MINUTE THEN 1 END) as active_last_5min,
    COUNT(CASE WHEN last_activity_at > NOW() - INTERVAL 30 MINUTE THEN 1 END) as active_last_30min,
    COUNT(CASE WHEN login_at > NOW() - INTERVAL 1 DAY THEN 1 END) as sessions_today
FROM active_user_sessions;


-- Migration pour ajouter le système de plans et gérer les API keys limitées lors de l'inscription

-- 1. Table des plans de paiement
CREATE TABLE IF NOT EXISTS `plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT 'Nom unique du plan (free, bronze, argent, platine)',
  `display_name` varchar(100) NOT NULL COMMENT 'Nom d affichage du plan',
  `description` text COMMENT 'Description du plan',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Prix en devise spécifiée',
  `currency` varchar(3) NOT NULL DEFAULT 'EUR' COMMENT 'Code devise (EUR, USD, etc.)',
  `duration_days` int(11) DEFAULT NULL COMMENT 'Durée en jours (NULL = illimité)',
  `api_rate_limit` int(11) NOT NULL DEFAULT '60' COMMENT 'Limite de requêtes par minute',
  `features` json DEFAULT NULL COMMENT 'Fonctionnalités et limites en JSON',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Plan actif ou non',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Ajouter colonne plan_id à la table users (si elle n'existe pas déjà)
ALTER TABLE `users` 
ADD COLUMN `plan_id` int(11) DEFAULT NULL COMMENT 'Plan actuel de l utilisateur' AFTER `role`,
ADD COLUMN `plan_expires_at` timestamp NULL DEFAULT NULL COMMENT 'Date d expiration du plan' AFTER `plan_id`,
ADD COLUMN `plan_auto_renew` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Renouvellement automatique' AFTER `plan_expires_at`;

-- Ajouter une clé étrangère vers la table plans
ALTER TABLE `users` 
ADD CONSTRAINT `fk_users_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL;

-- 3. Table pour gérer l'historique des souscriptions de plans
CREATE TABLE IF NOT EXISTS `user_plan_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT NULL,
  `auto_renewed` tinyint(1) NOT NULL DEFAULT '0',
  `payment_method` varchar(50) DEFAULT NULL COMMENT 'stripe, paypal, manual, etc.',
  `payment_reference` varchar(255) DEFAULT NULL COMMENT 'Référence du paiement',
  `amount_paid` decimal(10,2) DEFAULT NULL,
  `currency` varchar(3) DEFAULT 'EUR',
  `status` enum('active','expired','cancelled','refunded') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `plan_id` (`plan_id`),
  KEY `status` (`status`),
  KEY `expires_at` (`expires_at`),
  CONSTRAINT `fk_plan_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_plan_history_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Ajouter des colonnes à la table api_keys pour mieux gérer les limites par plan
ALTER TABLE `api_keys` 
ADD COLUMN `plan_id` int(11) DEFAULT NULL COMMENT 'Plan associé à cette API key' AFTER `user_id`,
ADD COLUMN `plan_limited` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'API key limitée par plan' AFTER `plan_id`;

-- Ajouter une clé étrangère vers la table plans
ALTER TABLE `api_keys` 
ADD CONSTRAINT `fk_api_keys_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL;


-- 5. Insérer les plans par défaut
INSERT INTO `plans` (`name`, `display_name`, `description`, `price`, `currency`, `duration_days`, `api_rate_limit`, `features`, `is_active`) VALUES
('free', 'Plan Gratuit', 'Plan gratuit avec limitations pour tester l\'API', 0.00, 'EUR', 30, 10, '{"scopes":["read"],"max_requests_per_day":1000,"expires_in_days":7,"email_support":false,"priority_support":false}', 1),
('bronze', 'Plan Bronze', 'Plan bronze avec fonctionnalités essentielles', 9.99, 'EUR', 30, 100, '{"scopes":["read","write"],"max_requests_per_day":10000,"expires_in_days":null,"email_support":true,"priority_support":false}', 1),
('argent', 'Plan Argent', 'Plan argent avec fonctionnalités avancées', 19.99, 'EUR', 30, 300, '{"scopes":["read","write","delete"],"max_requests_per_day":50000,"expires_in_days":null,"email_support":true,"priority_support":true,"webhook_support":true}', 1),
('platine', 'Plan Platine', 'Plan platine avec toutes les fonctionnalités premium', 49.99, 'EUR', 30, 1000, '{"scopes":["read","write","delete","admin"],"max_requests_per_day":"unlimited","expires_in_days":null,"email_support":true,"priority_support":true,"webhook_support":true,"custom_integrations":true,"dedicated_support":true}', 1)
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

-- 6. Mettre à jour les utilisateurs existants pour leur assigner le plan gratuit par défaut
UPDATE `users` 
SET `plan_id` = (SELECT `id` FROM `plans` WHERE `name` = 'free' LIMIT 1)
WHERE `plan_id` IS NULL;

-- 7. Créer des index pour améliorer les performances
CREATE INDEX `idx_users_plan_expires` ON `users` (`plan_id`, `plan_expires_at`);
CREATE INDEX `idx_api_keys_plan_limited` ON `api_keys` (`plan_id`, `plan_limited`);
CREATE INDEX `idx_plan_history_user_status` ON `user_plan_history` (`user_id`, `status`);

-- 8. Ajouter une table pour gérer les invitations à choisir un plan
CREATE TABLE IF NOT EXISTS `plan_invitations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `invitation_token` varchar(64) NOT NULL COMMENT 'Token unique pour l invitation',
  `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL COMMENT 'Date d expiration de l invitation',
  `clicked_at` timestamp NULL DEFAULT NULL COMMENT 'Date du premier clic',
  `selected_plan` varchar(50) DEFAULT NULL COMMENT 'Plan sélectionné (si applicable)',
  `selected_at` timestamp NULL DEFAULT NULL COMMENT 'Date de sélection du plan',
  `status` enum('pending','clicked','selected','expired') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invitation_token` (`invitation_token`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `expires_at` (`expires_at`),
  CONSTRAINT `fk_plan_invitations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` ( `name`, `email`, `password_hash`, `role`, `plan_id`, `plan_expires_at`, `plan_auto_renew`, `profile_image`, `bio`, `phone`, `date_of_birth`, `location`, `email_verified`, `last_login`, `payment_status`, `license_expires_at`, `payment_plan`, `payment_date`, `created_at`, `deleted_at`, `updated_at`) VALUES
( 'Super Administrator', 'jrobitaille04@pm.me', '$2y$10$DpigB/HxAjN/IKOtkSOLz.4gJ2baJUMlUg2hibvSzKbadjm.xVM4S', 'ADMINISTRATEUR', 1, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1, '2025-10-28 20:16:28', 'pending', NULL, 'basic', NULL, '2025-10-27 19:37:01', NULL, '2025-10-30 02:32:36'),
( 'Utilisateur Test', 'user@cmem2.com', '$2y$10$ySaVqxDEwZLH0hCtXPOltuER2D6exPPCqz2QSn3v/rxMFVXHlLgMS', 'UTILISATEUR', 1, NULL, 0, 'default.jpg', NULL, NULL, NULL, NULL, 1, '2025-10-29 00:07:33', 'pending', NULL, 'basic', NULL, '2025-10-27 23:56:04', NULL, '2025-10-30 02:25:26');

INSERT INTO `api_keys` ( `user_id`, `plan_id`, `plan_limited`, `name`, `key_prefix`, `key_hash`, `last_4`, `scopes`, `environment`, `rate_limit_per_minute`, `rate_limit_per_hour`, `total_requests`, `last_used_at`, `last_used_ip`, `expires_at`, `revoked_at`, `revoked_reason`, `metadata`, `notes`, `created_at`, `updated_at`, `deleted_at`) VALUES
( 1, NULL, 0, 'Bootstrap API Key', 'ag_live', 'f9281a209030ab51f15c66e56ff6f55bb556fab82032919505ee3ea20fe589c4', 'b6d8', '[\"read\",\"write\"]', 'production', 100, 5000, 0, NULL, NULL, NULL, NULL, NULL, NULL, 'API key créée par le script de bootstrap', '2025-10-30 02:32:40', '2025-10-30 02:32:40', NULL),
( 2, NULL, 0, 'Bootstrap API Key', 'ag_live', '36577224f257ec561c9f0f7330420f2c6996308e199bc115a24f91b9659f9f0c', 'eefc', '[\"read\",\"write\"]', 'production', 100, 5000, 0, NULL, NULL, NULL, NULL, NULL, NULL, 'API key créée par le script de bootstrap', '2025-10-30 02:32:44', '2025-10-30 02:32:44', NULL);

END //

DELIMITER ;

-- ===== PROCÉDURES STOCKÉES POUR LES STATISTIQUES (AUTHENTIFICATION ET GROUPES) =====
DROP PROCEDURE IF EXISTS GeneratePlatformStats;
DROP PROCEDURE IF EXISTS GenerateGroupStats;
DROP PROCEDURE IF EXISTS GenerateUserStats;
DROP PROCEDURE IF EXISTS CleanupOldStats;
DROP PROCEDURE IF EXISTS cleanup_expired_api_keys;

DELIMITER $$

-- ===== Procédure pour générer les statistiques globales =====
CREATE PROCEDURE GeneratePlatformStats()
BEGIN
    INSERT INTO platform_stats (
        total_users, active_users_7d, active_users_30d, total_groups, 
        total_tags, total_files, total_storage_mb, pending_invitations, avg_group_size
    )
    SELECT 
        (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) as total_users,
        (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as active_users_7d,
        (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as active_users_30d,
        (SELECT COUNT(*) FROM groups WHERE deleted_at IS NULL) as total_groups,
        (SELECT COUNT(*) FROM tags WHERE deleted_at IS NULL) as total_tags,
        (SELECT COUNT(*) FROM files) as total_files,
        (SELECT ROUND(COALESCE(SUM(file_size), 0) / 1024 / 1024, 2) FROM files) as total_storage_mb,
        (SELECT COUNT(*) FROM group_invitations WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())) as pending_invitations,
        (SELECT ROUND(AVG(member_count), 2) FROM (
            SELECT COUNT(gm.user_id) as member_count 
            FROM groups g 
            LEFT JOIN group_members gm ON g.id = gm.group_id AND gm.deleted_at IS NULL 
            WHERE g.deleted_at IS NULL 
            GROUP BY g.id
        ) as group_sizes) as avg_group_size;
END$$

-- ===== Procédure pour générer les statistiques par groupe =====
CREATE PROCEDURE GenerateGroupStats()
BEGIN
    -- Supprimer les anciens snapshots (garder seulement les 30 derniers jours)
    DELETE FROM group_stats_snapshot WHERE generated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    INSERT INTO group_stats_snapshot (
        group_id, group_name, visibility, member_count, tag_count, days_since_creation
    )
    SELECT 
        g.id,
        g.name,
        g.visibility,
        COALESCE(gm_count.member_count, 0),
        COALESCE(gt_count.tag_count, 0),
        DATEDIFF(NOW(), g.created_at) as days_since_creation
    FROM groups g
    LEFT JOIN (
        SELECT group_id, COUNT(*) as member_count 
        FROM group_members 
        WHERE deleted_at IS NULL 
        GROUP BY group_id
    ) gm_count ON g.id = gm_count.group_id
    LEFT JOIN (
        SELECT group_id, COUNT(*) as tag_count 
        FROM group_tag_relations 
        WHERE deleted_at IS NULL 
        GROUP BY group_id
    ) gt_count ON g.id = gt_count.group_id
    WHERE g.deleted_at IS NULL;
END$$

-- ===== Procédure pour générer les statistiques par utilisateur =====
CREATE PROCEDURE GenerateUserStats()
BEGIN
    -- Supprimer les anciens snapshots (garder seulement les 30 derniers jours)
    DELETE FROM user_stats_snapshot WHERE generated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    INSERT INTO user_stats_snapshot (
        user_id, user_name, role, last_login, groups_created, groups_joined,
        tags_created, files_uploaded, storage_used_mb, invitations_sent, days_since_registration
    )
    SELECT 
        u.id,
        u.name,
        u.role,
        u.last_login,
        COALESCE(groups_created.count, 0),
        COALESCE(groups_joined.count, 0),
        COALESCE(tags_created.count, 0),
        COALESCE(files_uploaded.count, 0),
        COALESCE(storage_used.storage_mb, 0),
        COALESCE(invitations_sent.count, 0),
        DATEDIFF(NOW(), u.created_at) as days_since_registration
    FROM users u
    LEFT JOIN (
        SELECT owner_id, COUNT(*) as count 
        FROM groups 
        WHERE deleted_at IS NULL 
        GROUP BY owner_id
    ) groups_created ON u.id = groups_created.owner_id
    LEFT JOIN (
        SELECT user_id, COUNT(*) as count 
        FROM group_members 
        WHERE deleted_at IS NULL 
        GROUP BY user_id
    ) groups_joined ON u.id = groups_joined.user_id
    LEFT JOIN (
        SELECT tag_owner, COUNT(*) as count 
        FROM tags 
        WHERE deleted_at IS NULL 
        GROUP BY tag_owner
    ) tags_created ON u.id = tags_created.tag_owner
    LEFT JOIN (
        SELECT uploaded_by, COUNT(*) as count 
        FROM files 
        GROUP BY uploaded_by
    ) files_uploaded ON u.id = files_uploaded.uploaded_by
    LEFT JOIN (
        SELECT uploaded_by, ROUND(COALESCE(SUM(file_size), 0) / 1024 / 1024, 2) as storage_mb
        FROM files 
        GROUP BY uploaded_by
    ) storage_used ON u.id = storage_used.uploaded_by
    LEFT JOIN (
        SELECT invited_by, COUNT(*) as count 
        FROM group_invitations 
        GROUP BY invited_by
    ) invitations_sent ON u.id = invitations_sent.invited_by
    WHERE u.deleted_at IS NULL;
END$$

-- ===== Procédure de nettoyage des anciennes statistiques =====
CREATE PROCEDURE CleanupOldStats()
BEGIN
    -- Garder seulement les 100 derniers snapshots de statistiques globales
    DELETE FROM platform_stats 
    WHERE id NOT IN (
        SELECT id FROM (
            SELECT id FROM platform_stats 
            ORDER BY generated_at DESC 
            LIMIT 100
        ) as keep_stats
    );
    
    -- Nettoyer les snapshots de plus de 30 jours
    DELETE FROM group_stats_snapshot WHERE generated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    DELETE FROM user_stats_snapshot WHERE generated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    SELECT 'Nettoyage des anciennes statistiques terminé' as message, NOW() as cleaned_at;
END$$

CREATE PROCEDURE IF NOT EXISTS cleanup_expired_api_keys()
BEGIN
    -- Marquer comme révoquées les clés expirées non encore révoquées
    UPDATE api_keys 
    SET revoked_at = NOW(),
        revoked_reason = 'Expired automatically'
    WHERE expires_at IS NOT NULL 
      AND expires_at < NOW() 
      AND revoked_at IS NULL;
    
    SELECT ROW_COUNT() AS keys_auto_revoked;
END$$

DELIMITER ;

DELIMITER //
CREATE OR REPLACE PROCEDURE CleanupExpiredSessions()
BEGIN
    -- Marquer les sessions expirées comme inactives
    UPDATE user_sessions 
    SET is_active = 0, logout_at = NOW()
    WHERE is_active = 1 
      AND expires_at < NOW();
      
    -- Supprimer les anciennes sessions (plus de 30 jours)
    DELETE FROM user_sessions 
    WHERE logout_at < NOW() - INTERVAL 30 DAY
       OR (is_active = 0 AND login_at < NOW() - INTERVAL 30 DAY);
       
    SELECT ROW_COUNT() as cleaned_sessions;
END //
DELIMITER ;

call ResetAuthenticationGroups;

-- =========================================================== TRIGGERS =====

DELIMITER $$

-- Trigger pour ajouter automatiquement le créateur d'un groupe comme admin
CREATE OR REPLACE TRIGGER add_group_creator_as_admin AFTER INSERT ON groups FOR EACH ROW 
BEGIN
    INSERT INTO group_members (group_id, user_id, invited_by, role, joined_at)
    VALUES (NEW.id, NEW.owner_id, NEW.owner_id, 'admin', NOW());
END$$

DELIMITER ;
