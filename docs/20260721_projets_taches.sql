-- Migration pendante : plugin `projets` — gestion de projet + iCalendar
-- Réf. docs/PLAN_gestion_projet_icalendar.md §4, §12 (API-Phase 0)
-- À intégrer dans le prochain build_DB-v-x-x-x.sql puis déplacer dans docs/v-x-x-x/.

-- ============================================================
-- 1. Table projects
-- ============================================================
CREATE TABLE IF NOT EXISTS projects (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     INT(11) NOT NULL,
    calendar_id INT(11) NOT NULL COMMENT 'Calendrier caché 1:1, provisionné à la création du projet',
    name        VARCHAR(255) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_projects_calendar (calendar_id),
    INDEX idx_projects_user (user_id),
    CONSTRAINT fk_projects_user     FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
    CONSTRAINT fk_projects_calendar FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. Extension de calendar_todos (§4) — les tâches de projet sont des VTODO
-- ============================================================
ALTER TABLE calendar_todos
    ADD COLUMN project_id INT UNSIGNED NULL AFTER calendar_id,
    ADD COLUMN parent_id  INT UNSIGNED NULL AFTER project_id,
    ADD COLUMN all_day    TINYINT(1) NOT NULL DEFAULT 0 AFTER due,
    ADD COLUMN assignee   VARCHAR(255) NULL AFTER organizer_name,
    ADD COLUMN remind_minutes_before INT UNSIGNED NULL AFTER attendees;

ALTER TABLE calendar_todos
    ADD INDEX idx_calendar_todos_project_id (project_id),
    ADD INDEX idx_calendar_todos_parent_id  (parent_id),
    ADD CONSTRAINT fk_calendar_todos_project FOREIGN KEY (project_id) REFERENCES projects(id)      ON DELETE CASCADE,
    ADD CONSTRAINT fk_calendar_todos_parent  FOREIGN KEY (parent_id)  REFERENCES calendar_todos(id) ON DELETE SET NULL;

-- ============================================================
-- 3. Table task_dependencies (§1.2, §4) — plusieurs-à-plusieurs
-- ============================================================
CREATE TABLE IF NOT EXISTS task_dependencies (
    task_id       INT UNSIGNED NOT NULL,
    depends_on_id INT UNSIGNED NOT NULL,
    type          ENUM('FS','SS','FF','SF') NOT NULL DEFAULT 'FS',
    lag_days      INT NOT NULL DEFAULT 0,
    PRIMARY KEY (task_id, depends_on_id),
    CONSTRAINT fk_taskdeps_task FOREIGN KEY (task_id)       REFERENCES calendar_todos(id) ON DELETE CASCADE,
    CONSTRAINT fk_taskdeps_dep  FOREIGN KEY (depends_on_id) REFERENCES calendar_todos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
