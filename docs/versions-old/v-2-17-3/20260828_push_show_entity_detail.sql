-- ============================================================
-- Push : préférence opt-in show_entity_detail (task cmem #199)
-- Titre réel de l'entité dans le push au lieu du générique, par kind.
-- Défaut OFF (0) : comportement générique actuel inchangé.
-- ============================================================
ALTER TABLE notification_prefs
    ADD COLUMN show_entity_detail TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Opt-in : titre réel de l''entité dans le push au lieu du générique'
        AFTER enabled;
