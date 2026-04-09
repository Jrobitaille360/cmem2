-- ============================================================
-- MIGRATION A1 : Blacklist JWT (claim jti)
-- À exécuter UNE seule fois sur la base de données.
-- ============================================================

CREATE TABLE IF NOT EXISTS `jwt_blacklist` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `jti`        VARCHAR(36)     NOT NULL  COMMENT 'UUID v4 unique du token révoqué',
    `user_id`    INT UNSIGNED    NOT NULL  COMMENT 'Propriétaire du token',
    `expires_at` DATETIME        NOT NULL  COMMENT 'Date d\'expiration du token original (pour nettoyage)',
    `created_at` DATETIME        NOT NULL  DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY  `uq_jti`         (`jti`),
    INDEX       `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
