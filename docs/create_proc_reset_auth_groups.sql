DROP PROCEDURE IF EXISTS ResetAuthenticationGroups;
DELIMITER //

CREATE PROCEDURE ResetAuthenticationGroups()
BEGIN
-- Procédure pour réinitialiser les tables liées à l'authentification, groupes et fichiers
-- Cette procédure gère : users, groups, files et toutes leurs relations et systèmes

-- === SUPPRESSION DES VUES ET TABLES (ordre correct des dépendances) ===

-- 1. SUPPRESSION DES VUES EN PREMIER
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
DROP TABLE IF EXISTS valid_tokens;
DROP TABLE IF EXISTS user_stats_snapshot;
DROP TABLE IF EXISTS group_stats_snapshot;
DROP TABLE IF EXISTS platform_stats;
DROP TABLE IF EXISTS email_verifications;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS login_codes;

-- 3. SUPPRESSION DES TABLES DÉPENDANTES
DROP TABLE IF EXISTS files;

-- 4. SUPPRESSION DES TABLES AVEC RÉFÉRENCES CROISÉES
DROP TABLE IF EXISTS groups;
DROP TABLE IF EXISTS tags;

-- 5. SUPPRESSION DES TABLES PRINCIPALES
DROP TABLE IF EXISTS users;

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

INSERT INTO users (id, name, email, password_hash, role,  email_verified) VALUES
    (1, 'TEMPORARY ADMINISTRATOR', 'TMP_admin@cmem1.com', '$2y$10$GxWCcbHdnPY3PmBrmLwPCeQ/nKokme.bKhmhcpKSfvIJhrzj0pQ/.', 'ADMINISTRATEUR', 1);


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

-- Table pour gérer les tokens JWT valides (sessions actives)
-- Cette table permet de :
-- 1. Invalider les tokens au logout (DELETE)
-- 2. Connaître le nombre d'utilisateurs connectés en temps réel
-- 3. Générer des statistiques d'usage
-- 4. Avoir un contrôle total sur les sessions actives

CREATE TABLE IF NOT EXISTS valid_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_hash VARCHAR(64) NOT NULL UNIQUE COMMENT 'Hash SHA256 du token pour sécurité',
    user_id INT NOT NULL,
    user_agent TEXT COMMENT 'User-Agent du client pour identification',
    ip_address VARCHAR(45) COMMENT 'Adresse IP du client (IPv4/IPv6)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Quand le token a été créé',
    last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Dernière utilisation du token',
    expires_at DATETIME NULL DEFAULT NULL COMMENT 'Quand le token expire naturellement',
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_token_hash (token_hash),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at),
    INDEX idx_last_used_at (last_used_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Table des tokens JWT valides pour sessions actives';

-- Vue pour les utilisateurs actuellement connectés
CREATE VIEW v_active_sessions AS
SELECT 
    vt.id,
    vt.user_id,
    u.name as user_name,
    u.email as user_email,
    vt.ip_address,
    vt.user_agent,
    vt.created_at as login_time,
    vt.last_used_at,
    vt.expires_at,
    TIMESTAMPDIFF(MINUTE, vt.last_used_at, NOW()) as minutes_inactive
FROM valid_tokens vt
JOIN users u ON vt.user_id = u.id
WHERE vt.expires_at IS NULL OR vt.expires_at > NOW()
ORDER BY vt.last_used_at DESC;

-- Vue pour les statistiques d'utilisateurs en ligne
CREATE VIEW v_online_users_stats AS
SELECT 
    COUNT(DISTINCT vt.user_id) as users_online,
    COUNT(vt.id) as total_sessions,
    AVG(TIMESTAMPDIFF(MINUTE, vt.created_at, NOW())) as avg_session_duration_minutes,
    COUNT(CASE WHEN TIMESTAMPDIFF(MINUTE, vt.last_used_at, NOW()) <= 5 THEN 1 END) as active_last_5min,
    COUNT(CASE WHEN TIMESTAMPDIFF(MINUTE, vt.last_used_at, NOW()) <= 30 THEN 1 END) as active_last_30min
FROM valid_tokens vt
WHERE vt.expires_at IS NULL OR vt.expires_at > NOW();


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

END //

DELIMITER ;

-- ===== PROCÉDURES STOCKÉES POUR LES STATISTIQUES (AUTHENTIFICATION ET GROUPES) =====
DROP PROCEDURE IF EXISTS GeneratePlatformStats;
DROP PROCEDURE IF EXISTS GenerateGroupStats;
DROP PROCEDURE IF EXISTS GenerateUserStats;
DROP PROCEDURE IF EXISTS CleanupOldStats;

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

DELIMITER ;