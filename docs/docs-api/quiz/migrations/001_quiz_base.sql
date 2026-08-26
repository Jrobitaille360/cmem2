-- Migration 001 : tables de base du plugin Quiz
-- Phase 1 — MVP REST

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- -------------------------------------------------------
-- quiz_quizzes
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_quizzes` (
    `id`          INT           NOT NULL AUTO_INCREMENT,
    `user_id`     INT           NOT NULL COMMENT 'FK users.id (hôte)',
    `title`       VARCHAR(255)  NOT NULL,
    `description` TEXT          NULL,
    `status`      ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- quiz_questions
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_questions` (
    `id`             INT       NOT NULL AUTO_INCREMENT,
    `quiz_id`        INT       NOT NULL COMMENT 'FK quiz_quizzes.id',
    `position`       SMALLINT  NOT NULL DEFAULT 0,
    `type`           ENUM('mcq','truefalse','numerical') NOT NULL,
    `content`        JSON      NOT NULL COMMENT '{text, latex, image_url}',
    `points`         INT       NOT NULL DEFAULT 100,
    `time_limit_sec` INT       NOT NULL DEFAULT 30,
    `created_at`     DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_quiz_id` (`quiz_id`),
    CONSTRAINT `fk_questions_quiz` FOREIGN KEY (`quiz_id`)
        REFERENCES `quiz_quizzes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- quiz_choices
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_choices` (
    `id`          INT      NOT NULL AUTO_INCREMENT,
    `question_id` INT      NOT NULL COMMENT 'FK quiz_questions.id',
    `position`    SMALLINT NOT NULL DEFAULT 0,
    `content`     JSON     NOT NULL COMMENT '{text, latex}',
    `is_correct`  TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    INDEX `idx_question_id` (`question_id`),
    CONSTRAINT `fk_choices_question` FOREIGN KEY (`question_id`)
        REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- quiz_sessions
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_sessions` (
    `id`                   INT          NOT NULL AUTO_INCREMENT,
    `quiz_id`              INT          NOT NULL COMMENT 'FK quiz_quizzes.id',
    `host_user_id`         INT          NOT NULL COMMENT 'FK users.id',
    `session_code`         VARCHAR(8)   NOT NULL,
    `status`               ENUM('waiting','active','reviewing','ended') NOT NULL DEFAULT 'waiting',
    `current_question_idx` INT          NOT NULL DEFAULT -1,
    `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `started_at`           DATETIME     NULL,
    `ended_at`             DATETIME     NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_session_code` (`session_code`),
    INDEX `idx_quiz_id` (`quiz_id`),
    INDEX `idx_host_user_id` (`host_user_id`),
    CONSTRAINT `fk_sessions_quiz` FOREIGN KEY (`quiz_id`)
        REFERENCES `quiz_quizzes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- quiz_participants
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_participants` (
    `id`                INT          NOT NULL AUTO_INCREMENT,
    `session_id`        INT          NOT NULL COMMENT 'FK quiz_sessions.id',
    `display_name`      VARCHAR(64)  NOT NULL,
    `device_id`         VARCHAR(36)  NOT NULL,
    `participant_token` VARCHAR(64)  NOT NULL,
    `score`             INT          NOT NULL DEFAULT 0,
    `rank`              INT          NULL,
    `joined_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_participant_token` (`participant_token`),
    INDEX `idx_session_id` (`session_id`),
    CONSTRAINT `fk_participants_session` FOREIGN KEY (`session_id`)
        REFERENCES `quiz_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- quiz_participant_answers
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_participant_answers` (
    `id`               INT      NOT NULL AUTO_INCREMENT,
    `participant_id`   INT      NOT NULL COMMENT 'FK quiz_participants.id',
    `session_id`       INT      NOT NULL,
    `question_id`      INT      NOT NULL,
    `value`            TEXT     NOT NULL COMMENT 'choice_id ou valeur numérique',
    `is_correct`       TINYINT(1) NOT NULL DEFAULT 0,
    `points_earned`    INT      NOT NULL DEFAULT 0,
    `response_time_ms` INT      NOT NULL DEFAULT 0,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_participant_question` (`participant_id`, `question_id`),
    INDEX `idx_session_question` (`session_id`, `question_id`),
    CONSTRAINT `fk_answers_participant` FOREIGN KEY (`participant_id`)
        REFERENCES `quiz_participants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
