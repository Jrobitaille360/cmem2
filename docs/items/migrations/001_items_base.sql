-- Migration 001 : tables de base du plugin Items
-- Gestionnaire générique d'items avec contrôle d'accès (private/public/share)

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- -------------------------------------------------------
-- items
-- Item principal appartenant à un utilisateur JWT
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `items` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner_user_id` INT(11)      NOT NULL                  COMMENT 'FK vers users.id — propriétaire de l\'item',
    `access`        ENUM('private','public','share')
                    NOT NULL DEFAULT 'private'             COMMENT 'Visibilité : private=owner+admin, public=tous, share=liste explicite',
    `categories`    JSON         NULL                      COMMENT 'Tableau JSON de chaînes ex. ["a","b"]',
    `json_item`     LONGTEXT     NULL                      COMMENT 'Blob JSON arbitraire fourni par le client',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`    DATETIME     NULL                      COMMENT 'Soft-delete — NULL = actif',
    PRIMARY KEY (`id`),
    INDEX `idx_items_owner`   (`owner_user_id`),
    INDEX `idx_items_access`  (`access`),
    INDEX `idx_items_deleted` (`deleted_at`),
    CONSTRAINT `fk_items_owner`
        FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- item_user_access
-- Partages explicites pour access='share'
-- Peut aussi surcharger des droits pour access='public'
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `item_user_access` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `item_id`    INT UNSIGNED NOT NULL                    COMMENT 'FK vers items.id',
    `user_id`    INT(11)      NOT NULL                    COMMENT 'FK vers users.id — utilisateur ayant accès',
    `can_update` TINYINT(1)   NOT NULL DEFAULT 0          COMMENT '0=lecture seule, 1=lecture+écriture',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_item_user` (`item_id`, `user_id`),
    INDEX `idx_iua_user` (`user_id`),
    CONSTRAINT `fk_iua_item`
        FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_iua_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
