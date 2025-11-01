
-- =========================================================== TRIGGERS =====

DELIMITER $$

-- Trigger pour ajouter automatiquement le créateur d'un groupe comme admin
CREATE OR REPLACE TRIGGER add_group_creator_as_admin AFTER INSERT ON groups FOR EACH ROW 
BEGIN
    INSERT INTO group_members (group_id, user_id, invited_by, role, joined_at)
    VALUES (NEW.id, NEW.owner_id, NEW.owner_id, 'admin', NOW());
END$$

DELIMITER ;

