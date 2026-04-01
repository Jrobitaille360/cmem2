-- Phase 5 — Composants CalDAV additionnels : VTODO + VJOURNAL
-- Date : 2026-04-01
-- Référence plan : items 5.1 et 5.2

-- ============================================================
-- 5.1 — VTODO
-- ============================================================
CREATE TABLE IF NOT EXISTS calendar_todos (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    calendar_id     INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    uid             VARCHAR(255) NOT NULL UNIQUE COMMENT 'RFC 5545 §3.8.4.7 — UUID v4',
    title           VARCHAR(255) NOT NULL,
    description     TEXT,
    due             DATETIME      DEFAULT NULL COMMENT 'DUE : date limite',
    dtstart         DATETIME      DEFAULT NULL COMMENT 'DTSTART optionnel',
    completed       DATETIME      DEFAULT NULL COMMENT 'COMPLETED : horodatage complétion',
    status          ENUM('NEEDS-ACTION','IN-PROCESS','COMPLETED','CANCELLED')
                    NOT NULL DEFAULT 'NEEDS-ACTION',
    priority        TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '0=indéfini 1=haute 5=normale 9=basse',
    percent_complete TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100',
    location        VARCHAR(255)  DEFAULT NULL,
    categories      JSON          DEFAULT NULL,
    url             VARCHAR(2083) DEFAULT NULL,
    related_to      VARCHAR(255)  DEFAULT NULL COMMENT 'UID parent',
    organizer_email VARCHAR(255)  DEFAULT NULL,
    organizer_name  VARCHAR(255)  DEFAULT NULL,
    attendees       JSON          DEFAULT NULL,
    sequence        INT UNSIGNED  NOT NULL DEFAULT 0,
    timezone        VARCHAR(50)   NOT NULL DEFAULT 'America/Montreal',
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP     NULL DEFAULT NULL,
    INDEX idx_calendar_todos_calendar_id  (calendar_id),
    INDEX idx_calendar_todos_user_id      (user_id),
    INDEX idx_calendar_todos_due          (due),
    INDEX idx_calendar_todos_status       (status),
    INDEX idx_calendar_todos_deleted_at   (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5.2 — VJOURNAL
-- ============================================================
CREATE TABLE IF NOT EXISTS calendar_journals (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    calendar_id     INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    uid             VARCHAR(255) NOT NULL UNIQUE COMMENT 'RFC 5545 §3.8.4.7 — UUID v4',
    summary         VARCHAR(255) NOT NULL,
    description     TEXT,
    dtstart         DATETIME      DEFAULT NULL COMMENT 'Date du journal',
    status          ENUM('DRAFT','FINAL','CANCELLED')
                    NOT NULL DEFAULT 'DRAFT',
    categories      JSON          DEFAULT NULL,
    url             VARCHAR(2083) DEFAULT NULL,
    related_to      VARCHAR(255)  DEFAULT NULL,
    organizer_email VARCHAR(255)  DEFAULT NULL,
    organizer_name  VARCHAR(255)  DEFAULT NULL,
    sequence        INT UNSIGNED  NOT NULL DEFAULT 0,
    timezone        VARCHAR(50)   NOT NULL DEFAULT 'America/Montreal',
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP     NULL DEFAULT NULL,
    INDEX idx_calendar_journals_calendar_id (calendar_id),
    INDEX idx_calendar_journals_user_id     (user_id),
    INDEX idx_calendar_journals_dtstart     (dtstart),
    INDEX idx_calendar_journals_deleted_at  (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
