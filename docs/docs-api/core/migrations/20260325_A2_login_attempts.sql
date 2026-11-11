-- ============================================================
-- MIGRATION A2 : Rate limiting — table login_attempts
-- À exécuter UNE seule fois sur la base de données.
-- ============================================================

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(255)  NOT NULL  COMMENT 'Email utilisé lors de la tentative',
    `ip_address` VARCHAR(45)   NOT NULL  COMMENT 'IPv4 ou IPv6 du client',
    `endpoint`   VARCHAR(20)   NOT NULL  DEFAULT 'login'  COMMENT 'login | send-code',
    `created_at` DATETIME      NOT NULL  DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_rate_limit` (`email`, `ip_address`, `endpoint`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
