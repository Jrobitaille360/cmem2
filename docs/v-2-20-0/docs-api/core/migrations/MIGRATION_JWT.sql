-- ============================================================
-- MIGRATION : Passage de l'auth par API Key vers JWT
-- À exécuter UNE seule fois sur la base de données distante.
-- ============================================================

-- ------------------------------------------------------------
-- 1. Table otp_codes  (codes de connexion par email)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_codes` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `email`        VARCHAR(255)    NOT NULL,
    `code_hash`    VARCHAR(255)    NOT NULL            COMMENT 'bcrypt du code à 6 chiffres',
    `expires_at`   DATETIME        NOT NULL,
    `attempts`     TINYINT         NOT NULL DEFAULT 0  COMMENT 'tentatives de vérification',
    `max_attempts` TINYINT         NOT NULL DEFAULT 5,
    `used_at`      DATETIME        NULL     DEFAULT NULL,
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_otp_email`      (`email`),
    INDEX `idx_otp_expires`    (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Rendre api_key_id nullable dans user_sessions
--    (les sessions JWT n'ont pas de clé associée)
-- ------------------------------------------------------------

-- 2a. Supprimer la contrainte FK d'abord (obligatoire avant MODIFY)
ALTER TABLE `user_sessions`
    DROP FOREIGN KEY `fk_user_sessions_api_key`;

-- 2b. Rendre la colonne nullable
ALTER TABLE `user_sessions`
    MODIFY COLUMN `api_key_id` INT UNSIGNED NULL DEFAULT NULL;

-- 2c. (Optionnel) Rétablir la FK en autorisant NULL
--     Décommentez si vous souhaitez conserver l'intégrité référentielle
-- ALTER TABLE `user_sessions`
--     ADD CONSTRAINT `fk_user_sessions_api_key`
--     FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`)
--     ON DELETE SET NULL ON UPDATE CASCADE;

-- ------------------------------------------------------------
-- 3. Table device_tokens  (JWT associé à un appareil)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `device_tokens` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED    NOT NULL,
    `device_id`    VARCHAR(128)    NOT NULL  COMMENT 'UUID stable généré côté client',
    `device_name`  VARCHAR(255)    NOT NULL  DEFAULT 'Appareil inconnu',
    `token_hash`   VARCHAR(64)     NOT NULL  COMMENT 'SHA-256 du token en clair',
    `expires_at`   DATETIME        NOT NULL,
    `revoked_at`   DATETIME        NULL      DEFAULT NULL,
    `last_used_at` DATETIME        NULL      DEFAULT NULL,
    `last_ip`      VARCHAR(45)     NULL      DEFAULT NULL,
    `last_ua`      VARCHAR(512)    NULL      DEFAULT NULL,
    `created_at`   DATETIME        NOT NULL  DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY  `uq_device_token_hash`   (`token_hash`),
    INDEX       `idx_device_user`        (`user_id`),
    INDEX       `idx_device_device_id`   (`device_id`),
    INDEX       `idx_device_expires`     (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. (Optionnel) Révoquer toutes les API keys existantes
--    si vous voulez forcer la reconnexion via JWT
-- ------------------------------------------------------------
UPDATE `api_keys` SET `revoked_at` = NOW(), `revoked_reason` = 'Migration JWT' WHERE `revoked_at` IS NULL;

-- ------------------------------------------------------------
-- 4. (Optionnel) Invalider les sessions actives existantes
-- ------------------------------------------------------------
UPDATE `user_sessions` SET `is_active` = 0, `logout_at` = NOW() WHERE `is_active` = 1;
