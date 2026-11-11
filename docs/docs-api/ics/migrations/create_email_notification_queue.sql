-- Migration : Système de notifications email
-- Date : 2026-03-22
-- Description : Crée la table de queue des notifications email et ajoute
--               les préférences de notification aux utilisateurs.

-- ============================================================
-- Table : email_notification_queue
-- ============================================================
CREATE TABLE IF NOT EXISTS `email_notification_queue` (
    `id`              INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT          NOT NULL COMMENT 'FK users.id',
    `event_id`        INT          NOT NULL COMMENT 'FK calendar_events.id',
    `calendar_id`     INT          NOT NULL COMMENT 'FK calendars.id',
    `occurrence_key`  VARCHAR(120) NOT NULL COMMENT 'Format : eventId_recurrIdx_date (ex: 17_0_2026-03-25)',
    `fire_at`         DATETIME     NOT NULL COMMENT 'Heure d\'envoi en UTC',
    `minutes_before`  INT          NOT NULL,
    `recipient_email` VARCHAR(255) NOT NULL COMMENT 'Snapshot email au moment de la planification',
    `status`          ENUM('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
    `sent_at`         DATETIME     NULL,
    `attempt_count`   INT          NOT NULL DEFAULT 0,
    `error`           TEXT         NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_enq_user     (`user_id`),
    INDEX idx_enq_event    (`event_id`),
    INDEX idx_enq_fire_at  (`fire_at`),
    INDEX idx_enq_status   (`status`),
    -- Index composite utilisé par le cron : récupérer les notifications pendantes à envoyer
    INDEX idx_enq_pending  (`status`, `fire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Colonnes de préférences de notifications dans la table users
-- ============================================================
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `email_notifications_enabled`
        TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = notifications email activées, 0 = suspendues (R4)',
    ADD COLUMN IF NOT EXISTS `notification_email`
        VARCHAR(255) NULL
        COMMENT 'Email alternatif pour les rappels (null = utiliser users.email)';
