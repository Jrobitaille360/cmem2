-- =========================================================== TRIGGERS =====

-- Suppression des triggers existants
DROP TRIGGER IF EXISTS add_memory_creator_relations;
DROP TRIGGER IF EXISTS trg_create_element_version;

DELIMITER $$

-- Trigger pour ajouter automatiquement le créateur d'une mémoire aux relations
CREATE TRIGGER add_memory_creator_relations AFTER INSERT ON memories FOR EACH ROW 
BEGIN
    INSERT INTO memory_user_relations (user_id, memory_id, created_at)
    VALUES (NEW.user_id, NEW.id, NOW());
END$$

-- Trigger pour créer automatiquement une version lors de la modification d'un élément
CREATE TRIGGER trg_create_element_version AFTER UPDATE ON elements
FOR EACH ROW
BEGIN
    INSERT INTO element_versions (element_id, version_number, title, content, media_type, visibility, created_by)
    SELECT
        OLD.id,
        COALESCE((SELECT MAX(version_number) FROM element_versions WHERE element_id = OLD.id), 0) + 1,
        OLD.title,
        OLD.content,
        OLD.media_type,
        OLD.visibility,
        OLD.owner_id;
END$$

