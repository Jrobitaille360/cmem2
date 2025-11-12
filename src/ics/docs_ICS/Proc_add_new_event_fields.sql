-- Migration pour ajouter les nouveaux champs aux événements
-- Date: 2025-11-12
-- Description: Ajout de timezone, meeting_link, notifications et color

DROP PROCEDURE IF EXISTS AddNewEventFields;
DELIMITER //

CREATE PROCEDURE AddNewEventFields()
BEGIN
    -- Vérifier et ajouter la colonne timezone
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'calendar_events' 
        AND COLUMN_NAME = 'timezone'
    ) THEN
        ALTER TABLE calendar_events 
        ADD COLUMN timezone VARCHAR(100) DEFAULT 'America/Montreal' AFTER status;
    END IF;

    -- Vérifier et ajouter la colonne meeting_link
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'calendar_events' 
        AND COLUMN_NAME = 'meeting_link'
    ) THEN
        ALTER TABLE calendar_events 
        ADD COLUMN meeting_link TEXT AFTER timezone;
    END IF;

    -- Vérifier et ajouter la colonne notifications
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'calendar_events' 
        AND COLUMN_NAME = 'notifications'
    ) THEN
        ALTER TABLE calendar_events 
        ADD COLUMN notifications JSON AFTER meeting_link;
    END IF;

    -- Vérifier et ajouter la colonne color
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'calendar_events' 
        AND COLUMN_NAME = 'color'
    ) THEN
        ALTER TABLE calendar_events 
        ADD COLUMN color VARCHAR(7) AFTER notifications;
    END IF;

    SELECT 'Migration terminée : nouveaux champs ajoutés à calendar_events' AS Result;
END //

DELIMITER ;

-- Exécuter la procédure
CALL AddNewEventFields();

-- Supprimer la procédure après exécution
DROP PROCEDURE IF EXISTS AddNewEventFields;
