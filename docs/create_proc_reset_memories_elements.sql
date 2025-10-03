DROP PROCEDURE IF EXISTS ResetMemoriesElements;
DELIMITER //

CREATE PROCEDURE ResetMemoriesElements()
BEGIN
-- Procédure pour réinitialiser les tables liées aux mémoires et éléments
-- Cette procédure gère : memories, elements et toutes leurs relations

-- === SUPPRESSION DES VUES ET TABLES (ordre inverse des dépendances) ===
DROP VIEW IF EXISTS memory_statistics;
DROP TABLE IF EXISTS element_versions;
DROP TABLE IF EXISTS element_file_relations;
DROP TABLE IF EXISTS memory_element_relations;
DROP TABLE IF EXISTS element_tag_relations;
DROP TABLE IF EXISTS memory_user_relations;
DROP TABLE IF EXISTS memory_tag_relations;
DROP TABLE IF EXISTS memory_group_relations;
DROP TABLE IF EXISTS elements;
DROP TABLE IF EXISTS memories;

-- ===== TABLE : Mémoires =====
CREATE TABLE memories (
    id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11),
    title VARCHAR(255),
    content TEXT,
    visibility ENUM('private','shared','public') DEFAULT 'private',
	time_start timestamp NULL DEFAULT NULL,
    time_end timestamp NULL DEFAULT NULL,
	location varchar(255),
	latitude decimal(10,8),
	longitude decimal(11,8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	deleted_at datetime DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	KEY idx_memory_user_id (user_id),
	KEY idx_memory_time_start (time_start),
	KEY idx_memory_time_end (time_end),
	KEY idx_memory_location (location),
	KEY idx_memory_visibility (visibility),
	KEY idx_memory_created_at (created_at),
	KEY idx_memory_coordinates (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Relations entre mémoires et tags =====
CREATE TABLE memory_tag_relations (
	memory_id int(11) NOT NULL,
	tag_id int(11) NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	deleted_at datetime DEFAULT NULL,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	FOREIGN KEY (memory_id) REFERENCES memories(id) ON DELETE CASCADE,
	FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
	PRIMARY KEY (memory_id, tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Relations entre mémoires et utilisateurs =====
CREATE TABLE memory_user_relations (
	memory_id INT(11),
	user_id INT(11),
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	deleted_at datetime DEFAULT NULL,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	FOREIGN KEY (memory_id) REFERENCES memories(id) ON DELETE CASCADE,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	PRIMARY KEY (memory_id, user_id),
	KEY idx_memory_user_relations_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Éléments =====
CREATE TABLE elements (
    id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	title VARCHAR(255),
	filename VARCHAR(255),
	owner_id INT(11),
    content TEXT,
    media_type ENUM('text','audio','video','image','gpx','summary','event','todo','document'),
    visibility ENUM('private','shared','public') DEFAULT 'private',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	deleted_at datetime DEFAULT NULL,
	FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
	KEY idx_elements_owner_id (owner_id),
	KEY idx_elements_media_type (media_type),
	KEY idx_elements_visibility (visibility),
	KEY idx_elements_created_at (created_at),
	KEY idx_elements_deleted_at (deleted_at),
	KEY idx_elements_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Relations entre éléments et tags =====
CREATE TABLE element_tag_relations (
	element_id int(11) NOT NULL,
	tag_id int(11) NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	deleted_at datetime DEFAULT NULL,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	FOREIGN KEY (element_id) REFERENCES elements(id) ON DELETE CASCADE,
	FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
	PRIMARY KEY (element_id, tag_id),
	KEY idx_element_tag_relations_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Relations entre mémoires et éléments =====
CREATE TABLE memory_element_relations (
	memory_id INT(11),
	element_id INT(11),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	deleted_at datetime DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (memory_id) REFERENCES memories(id) ON DELETE CASCADE,
    FOREIGN KEY (element_id) REFERENCES elements(id) ON DELETE CASCADE,
	PRIMARY KEY (memory_id,element_id),
	KEY idx_memory_element_relations_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Relations entre éléments et fichiers =====
CREATE TABLE element_file_relations (
	element_id INT(11),
	file_id INT(11),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	deleted_at datetime DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (element_id) REFERENCES elements(id) ON DELETE CASCADE,
	PRIMARY KEY (element_id, file_id),
	KEY idx_element_file_relations_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Versions d'éléments =====
CREATE TABLE element_versions (
    id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    element_id INT(11) NOT NULL,
    version_number INT(11) NOT NULL,
    title VARCHAR(255),
    content TEXT,
    media_type ENUM('text','audio','video','image','gpx','summary','event','todo','document'),
    visibility ENUM('private','shared','public') DEFAULT 'private',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT(11),
    FOREIGN KEY (element_id) REFERENCES elements(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
	UNIQUE KEY unique_version_per_element (element_id, version_number),
	KEY idx_element_versions_created_by (created_by),
	KEY idx_element_versions_media_type (media_type),
	KEY idx_element_versions_visibility (visibility),
	KEY idx_element_versions_element_id (element_id),
	KEY idx_element_versions_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== TABLE : Relations entre mémoires et groupes =====
CREATE TABLE memory_group_relations (
    memory_id INT(11),
	group_id INT(11),
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	deleted_at datetime DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (memory_id) REFERENCES memories(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
	PRIMARY KEY (group_id, memory_id),
	KEY idx_memory_group_relations_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== VUE : Statistiques de mémoire =====
CREATE VIEW memory_statistics AS
SELECT
	m.id,
	m.title,
	DATE(m.created_at) AS memory_date,
	m.location,
	m.visibility,
	u.name AS creator_name,
	(SELECT COUNT(*) FROM memory_element_relations mer WHERE mer.memory_id = m.id AND mer.deleted_at IS NULL) AS elements_count,
	(SELECT COUNT(*) FROM files f
		JOIN element_file_relations efr ON efr.file_id = f.id
		JOIN memory_element_relations mer2 ON mer2.element_id = efr.element_id
		WHERE mer2.memory_id = m.id AND mer2.deleted_at IS NULL AND efr.deleted_at IS NULL) AS files_count,
	(SELECT IFNULL(SUM(f.file_size),0) FROM files f
		JOIN element_file_relations efr ON efr.file_id = f.id
		JOIN memory_element_relations mer2 ON mer2.element_id = efr.element_id
		WHERE mer2.memory_id = m.id AND mer2.deleted_at IS NULL AND efr.deleted_at IS NULL) AS total_file_size,
	(SELECT GROUP_CONCAT(DISTINCT g.name) FROM memory_group_relations mgr
		JOIN groups g ON g.id = mgr.group_id
		WHERE mgr.memory_id = m.id AND mgr.deleted_at IS NULL AND g.deleted_at IS NULL) AS groups_count,
	(SELECT COUNT(*) FROM memory_tag_relations mtr WHERE mtr.memory_id = m.id AND mtr.deleted_at IS NULL) AS tags_count,
	(SELECT GROUP_CONCAT(t.name) FROM memory_tag_relations mtr
		JOIN tags t ON t.id = mtr.tag_id
		WHERE mtr.memory_id = m.id AND mtr.deleted_at IS NULL) AS all_tags,
	m.created_at,
	DATEDIFF(CURRENT_DATE, DATE(m.created_at)) AS days_since_memory
FROM memories m
LEFT JOIN users u ON u.id = m.user_id
WHERE m.deleted_at IS NULL;
END//

DELIMITER ;

-- ===== PROCÉDURES STOCKÉES POUR LES STATISTIQUES (MÉMOIRES ET ÉLÉMENTS) =====
DROP PROCEDURE IF EXISTS UpdateMemoryElementStats;
DROP PROCEDURE IF EXISTS GenerateAllStats;

-- ===== Ajout des colonnes pour les mémoires et éléments dans les tables de statistiques =====
-- Ajouter les colonnes manquantes dans platform_stats
ALTER TABLE platform_stats 
ADD COLUMN IF NOT EXISTS total_memories int(11) DEFAULT 0,
ADD COLUMN IF NOT EXISTS total_elements int(11) DEFAULT 0;

-- Ajouter les colonnes manquantes dans group_stats_snapshot
ALTER TABLE group_stats_snapshot 
ADD COLUMN IF NOT EXISTS memory_count int(11) DEFAULT 0,
ADD COLUMN IF NOT EXISTS element_count int(11) DEFAULT 0;

-- Ajouter les colonnes manquantes dans user_stats_snapshot
ALTER TABLE user_stats_snapshot 
ADD COLUMN IF NOT EXISTS memories_created int(11) DEFAULT 0,
ADD COLUMN IF NOT EXISTS elements_created int(11) DEFAULT 0;

DELIMITER $$

-- ===== Procédure pour mettre à jour les statistiques des mémoires et éléments =====
CREATE PROCEDURE UpdateMemoryElementStats()
BEGIN
    -- Mettre à jour les colonnes liées aux mémoires et éléments dans platform_stats
    UPDATE platform_stats 
    SET 
        total_memories = (SELECT COUNT(*) FROM memories WHERE deleted_at IS NULL),
        total_elements = (SELECT COUNT(*) FROM elements WHERE deleted_at IS NULL)
    WHERE id = (SELECT MAX(id) FROM platform_stats);
    
    -- Mettre à jour les statistiques des groupes avec les mémoires et éléments
    UPDATE group_stats_snapshot gss
    SET 
        memory_count = COALESCE((
            SELECT COUNT(DISTINCT m.id)
            FROM memory_group_relations mgr
            JOIN memories m ON m.id = mgr.memory_id
            WHERE mgr.group_id = gss.group_id 
            AND mgr.deleted_at IS NULL 
            AND m.deleted_at IS NULL
        ), 0),
        element_count = COALESCE((
            SELECT COUNT(DISTINCT e.id)
            FROM memory_group_relations mgr
            JOIN memory_element_relations mer ON mer.memory_id = mgr.memory_id
            JOIN elements e ON e.id = mer.element_id
            WHERE mgr.group_id = gss.group_id
            AND mgr.deleted_at IS NULL 
            AND mer.deleted_at IS NULL 
            AND e.deleted_at IS NULL
        ), 0),
        file_count = COALESCE((
            SELECT COUNT(DISTINCT f.id)
            FROM memory_group_relations mgr
            JOIN memory_element_relations mer ON mer.memory_id = mgr.memory_id
            JOIN element_file_relations efr ON efr.element_id = mer.element_id
            JOIN files f ON f.id = efr.file_id
            WHERE mgr.group_id = gss.group_id
            AND mgr.deleted_at IS NULL 
            AND mer.deleted_at IS NULL 
            AND efr.deleted_at IS NULL
        ), 0),
        storage_mb = COALESCE((
            SELECT ROUND(COALESCE(SUM(f.file_size), 0) / 1024 / 1024, 2)
            FROM memory_group_relations mgr
            JOIN memory_element_relations mer ON mer.memory_id = mgr.memory_id
            JOIN element_file_relations efr ON efr.element_id = mer.element_id
            JOIN files f ON f.id = efr.file_id
            WHERE mgr.group_id = gss.group_id
            AND mgr.deleted_at IS NULL 
            AND mer.deleted_at IS NULL 
            AND efr.deleted_at IS NULL
        ), 0),
        last_activity_date = (
            SELECT MAX(m.updated_at)
            FROM memory_group_relations mgr
            JOIN memories m ON m.id = mgr.memory_id
            WHERE mgr.group_id = gss.group_id
            AND mgr.deleted_at IS NULL 
            AND m.deleted_at IS NULL
        )
    WHERE gss.generated_at = (SELECT MAX(generated_at) FROM group_stats_snapshot);
    
    -- Mettre à jour les statistiques des utilisateurs avec les mémoires et éléments
    UPDATE user_stats_snapshot uss
    SET 
        memories_created = COALESCE((
            SELECT COUNT(*) 
            FROM memories 
            WHERE user_id = uss.user_id 
            AND deleted_at IS NULL
        ), 0),
        elements_created = COALESCE((
            SELECT COUNT(*) 
            FROM elements 
            WHERE owner_id = uss.user_id 
            AND deleted_at IS NULL
        ), 0)
    WHERE uss.generated_at = (SELECT MAX(generated_at) FROM user_stats_snapshot);
    
    SELECT 'Statistiques des mémoires et éléments mises à jour' as message, NOW() as updated_at;
END$$

-- ===== Procédure pour générer toutes les statistiques =====
CREATE PROCEDURE GenerateAllStats()
BEGIN
    CALL GeneratePlatformStats();
    CALL GenerateGroupStats();
    CALL GenerateUserStats();
    CALL UpdateMemoryElementStats();
    
    SELECT 'Statistiques générées avec succès' as message, NOW() as generated_at;
END$$

DELIMITER ;