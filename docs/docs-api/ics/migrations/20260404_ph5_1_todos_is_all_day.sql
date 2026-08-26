-- Migration : ajout du champ is_all_day sur calendar_todos
-- Date : 2026-04-04
-- Référence : Phase 5.1 — VTODO journée entière

ALTER TABLE calendar_todos
    ADD COLUMN is_all_day TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Journée entière : 1 = oui, 0 = non'
    AFTER timezone;
