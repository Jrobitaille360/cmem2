-- Migration 001 : table pomo_engagements
-- Phase 1A — Engagement MVP (waitlist + sondage)
-- Plugin Pomo v1.0.0

CREATE TABLE IF NOT EXISTS pomo_engagements (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    type             ENUM('waitlist', 'survey')                             NOT NULL,
    device_id        VARCHAR(36)                                            NOT NULL,
    email            VARCHAR(254)                                           NULL,
    responses        JSON                                                   NULL,
    suggestion       TEXT                                                   NULL,
    platform         ENUM('android','ios','web','windows','macos','linux')  NULL,
    language         VARCHAR(16)                                            NULL,
    app_version      VARCHAR(32)                                            NULL,
    build_number     VARCHAR(32)                                            NULL,
    session_duration INT                                                    NULL,
    network_status   ENUM('online','offline')                               NULL,
    timestamp_utc    DATETIME                                               NOT NULL,
    created_at       DATETIME                                               NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pomo_eng_type      (type),
    INDEX idx_pomo_eng_device_id (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note : unicité courriel pour waitlist gérée en application
-- MySQL ne supporte pas les partial unique indexes (WHERE type = 'waitlist')
