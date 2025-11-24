-- Migration pour ajouter le support CalDAV
-- Cette procédure ajoute les colonnes nécessaires pour CalDAV/WebDAV

DROP PROCEDURE IF EXISTS AddCalDAVSupport;
DELIMITER //

CREATE PROCEDURE AddCalDAVSupport()
BEGIN
    -- Ajouter les colonnes pour CalDAV dans la table calendars
    IF NOT EXISTS (
        SELECT * FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'calendars' 
        AND COLUMN_NAME = 'ctag'
    ) THEN
        ALTER TABLE calendars 
        ADD COLUMN ctag VARCHAR(64) NULL COMMENT 'Collection Tag pour la synchronisation CalDAV',
        ADD COLUMN sync_token VARCHAR(64) NULL COMMENT 'Token de synchronisation pour les changements',
        ADD INDEX idx_ctag (ctag);
        
        -- Initialiser les ctags pour les calendriers existants
        UPDATE calendars 
        SET ctag = MD5(CONCAT(id, UNIX_TIMESTAMP(updated_at))),
            sync_token = MD5(CONCAT(id, UNIX_TIMESTAMP(updated_at), 'sync'))
        WHERE ctag IS NULL;
    END IF;

    -- Ajouter les colonnes pour CalDAV dans la table calendar_events
    IF NOT EXISTS (
        SELECT * FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'calendar_events' 
        AND COLUMN_NAME = 'etag'
    ) THEN
        ALTER TABLE calendar_events 
        ADD COLUMN etag VARCHAR(64) NULL COMMENT 'Entity Tag pour la synchronisation CalDAV',
        ADD COLUMN uid VARCHAR(255) NULL COMMENT "UID unique de l'événement iCalendar",
        ADD COLUMN sequence INT DEFAULT 0 COMMENT 'Numéro de séquence pour les mises à jour',
        ADD COLUMN last_modified TIMESTAMP NULL COMMENT 'Dernière modification pour CalDAV',
        ADD INDEX idx_etag (etag),
        ADD INDEX idx_uid (uid)
        ;
        
        -- Initialiser les données pour les événements existants
        UPDATE calendar_events 
        SET etag = MD5(CONCAT(id, UNIX_TIMESTAMP(updated_at))),
            uid = CONCAT('event-', id, '@cmem2'),
            sequence = 0,
            last_modified = updated_at
        WHERE etag IS NULL;
    END IF;

    -- Créer la table pour les journaux de synchronisation CalDAV
    CREATE TABLE IF NOT EXISTS caldav_sync_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        calendar_id INT NOT NULL,
        event_id INT NULL,
        change_type ENUM('created', 'updated', 'deleted') NOT NULL,
        sync_token VARCHAR(64) NOT NULL,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        user_id INT NULL,
        user_agent VARCHAR(255) NULL,
        FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
        FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE SET NULL,
        INDEX idx_calendar_sync (calendar_id, sync_token),
        INDEX idx_changed_at (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Créer la table pour les verrous WebDAV (nécessaire pour CalDAV)
    CREATE TABLE IF NOT EXISTS caldav_locks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        resource_path VARCHAR(500) NOT NULL,
        lock_token VARCHAR(255) NOT NULL UNIQUE,
        lock_scope ENUM('exclusive', 'shared') DEFAULT 'exclusive',
        lock_type ENUM('write') DEFAULT 'write',
        lock_owner VARCHAR(500) NULL,
        depth ENUM('0', 'infinity') DEFAULT '0',
        timeout INT DEFAULT 3600,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NOT NULL,
        calendar_id INT NULL,
        event_id INT NULL,
        FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
        FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
        INDEX idx_resource_path (resource_path),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    SELECT 'Support CalDAV ajouté avec succès!' AS result;
END //

DELIMITER ;

-- Exécuter la procédure pour créer les colonnes et tables
CALL AddCalDAVSupport();

-- Maintenant créer les triggers (APRÈS la procédure et l'exécution)
DROP TRIGGER IF EXISTS calendar_events_update_etag;

DELIMITER //
CREATE TRIGGER calendar_events_update_etag
BEFORE UPDATE ON calendar_events
FOR EACH ROW
BEGIN
    -- Mettre à jour l'etag de l'événement
    SET NEW.etag = MD5(CONCAT(NEW.id, UNIX_TIMESTAMP(NOW()), NEW.title));
    SET NEW.sequence = OLD.sequence + 1;
    SET NEW.last_modified = NOW();
    
    -- Mettre à jour le ctag du calendrier parent
    UPDATE calendars 
    SET ctag = MD5(CONCAT(id, UNIX_TIMESTAMP(NOW()))),
        sync_token = MD5(CONCAT(id, UNIX_TIMESTAMP(NOW()), 'sync'))
    WHERE id = NEW.calendar_id;
END //
DELIMITER ;

-- Créer un trigger pour les insertions
DROP TRIGGER IF EXISTS calendar_events_insert_etag;

DELIMITER //
CREATE TRIGGER calendar_events_insert_etag
BEFORE INSERT ON calendar_events
FOR EACH ROW
BEGIN
    -- Générer un UID unique si non fourni
    IF NEW.uid IS NULL THEN
        SET NEW.uid = CONCAT('event-', UUID(), '@cmem2');
    END IF;
    
    -- Générer l'etag initial
    SET NEW.etag = MD5(CONCAT(UUID(), UNIX_TIMESTAMP(NOW())));
    SET NEW.sequence = 0;
    SET NEW.last_modified = NOW();
END //
DELIMITER ;

-- Créer un trigger pour mettre à jour le ctag après insertion
DROP TRIGGER IF EXISTS calendar_events_after_insert;

DELIMITER //
CREATE TRIGGER calendar_events_after_insert
AFTER INSERT ON calendar_events
FOR EACH ROW
BEGIN
    -- Mettre à jour le ctag du calendrier parent
    UPDATE calendars 
    SET ctag = MD5(CONCAT(id, UNIX_TIMESTAMP(NOW()))),
        sync_token = MD5(CONCAT(id, UNIX_TIMESTAMP(NOW()), 'sync'))
    WHERE id = NEW.calendar_id;
END //
DELIMITER ;

-- Créer un trigger pour mettre à jour le ctag après suppression
DROP TRIGGER IF EXISTS calendar_events_after_delete;

DELIMITER //
CREATE TRIGGER calendar_events_after_delete
AFTER UPDATE ON calendar_events
FOR EACH ROW
BEGIN
    IF NEW.deleted_at IS NOT NULL AND OLD.deleted_at IS NULL THEN
        -- Mettre à jour le ctag du calendrier parent
        UPDATE calendars 
        SET ctag = MD5(CONCAT(id, UNIX_TIMESTAMP(NOW()))),
            sync_token = MD5(CONCAT(id, UNIX_TIMESTAMP(NOW()), 'sync'))
        WHERE id = NEW.calendar_id;
    END IF;
END //
DELIMITER ;

    SELECT 'Support CalDAV ajouté avec succès!' AS result;
END //
DELIMITER ;

-- Exécuter la procédure
CALL AddCalDAVSupport();
