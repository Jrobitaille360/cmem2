-- Migration 001 : tables de base du plugin Puzzle
-- Phases 1–4 (auth appareil, banque d'images, thèmes, sauvegarde, partagés)

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- -------------------------------------------------------
-- puzzle_devices
-- Authentification sans compte : un enregistrement par appareil
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `puzzle_devices` (
    `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `device_uuid`        VARCHAR(100)  NOT NULL COMMENT 'UUID généré côté app, unique, non modifiable',
    `device_token`       VARCHAR(64)   NOT NULL COMMENT 'Token opaque 64 chars (bin2hex 32 bytes)',
    `pseudonym`          VARCHAR(50)   NULL     COMMENT 'Choisi à la première utilisation du partage',
    `is_premium`         TINYINT(1)    NOT NULL DEFAULT 0,
    `purchase_token`     VARCHAR(500)  NULL     COMMENT 'Dernier token Google Play validé',
    `product_id`         VARCHAR(50)   NULL     COMMENT 'premium_monthly ou premium_yearly',
    `premium_expires_at` DATETIME      NULL,
    `last_replaced_at`   DATE          NULL     COMMENT 'Date du dernier replace-one (gratuit, 1/jour)',
    `backup_json`        LONGTEXT      NULL     COMMENT 'Blob opaque de sauvegarde locale',
    `token_expires_at`   DATETIME      NOT NULL,
    `last_seen_at`       DATETIME      NULL,
    `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device_uuid`  (`device_uuid`),
    UNIQUE KEY `uq_device_token` (`device_token`),
    UNIQUE KEY `uq_pseudonym`    (`pseudonym`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- puzzle_images
-- Banque d'images servies à l'app (labels dans puzzle_image_translations)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `puzzle_images` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `uid`          VARCHAR(36)   NOT NULL COMMENT 'UUID exposé à l\'app',
    `thumb_path`   VARCHAR(500)  NOT NULL COMMENT 'Chemin relatif depuis PUZZLE_UPLOAD_DIR',
    `full_path`    VARCHAR(500)  NOT NULL COMMENT 'Chemin relatif depuis PUZZLE_UPLOAD_DIR',
    `is_carousel`  TINYINT(1)    NOT NULL DEFAULT 1,
    `sort_order`   INT           NOT NULL DEFAULT 0,
    `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_image_uid` (`uid`),
    INDEX `idx_carousel_status` (`is_carousel`, `status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- puzzle_image_translations
-- Labels multilingues des images (fr obligatoire, repli sur fr)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `puzzle_image_translations` (
    `image_id` INT UNSIGNED NOT NULL,
    `lang`     ENUM('fr','en','es') NOT NULL,
    `label`    VARCHAR(255) NOT NULL,
    PRIMARY KEY (`image_id`, `lang`),
    CONSTRAINT `fk_img_trans_image`
        FOREIGN KEY (`image_id`) REFERENCES `puzzle_images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- puzzle_themes
-- Thèmes associés aux images (labels dans puzzle_theme_translations)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `puzzle_themes` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug`       VARCHAR(100) NOT NULL,
    `thumb_path` VARCHAR(500) NOT NULL COMMENT 'Chemin relatif depuis PUZZLE_UPLOAD_DIR',
    `sort_order` INT          NOT NULL DEFAULT 0,
    `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_theme_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- puzzle_theme_translations
-- Labels multilingues des thèmes
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `puzzle_theme_translations` (
    `theme_id` INT UNSIGNED NOT NULL,
    `lang`     ENUM('fr','en','es') NOT NULL,
    `label`    VARCHAR(255) NOT NULL,
    PRIMARY KEY (`theme_id`, `lang`),
    CONSTRAINT `fk_theme_trans_theme`
        FOREIGN KEY (`theme_id`) REFERENCES `puzzle_themes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- puzzle_image_themes
-- Association many-to-many image ↔ thème
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `puzzle_image_themes` (
    `image_id` INT UNSIGNED NOT NULL,
    `theme_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`image_id`, `theme_id`),
    CONSTRAINT `fk_imgtheme_image`
        FOREIGN KEY (`image_id`) REFERENCES `puzzle_images` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_imgtheme_theme`
        FOREIGN KEY (`theme_id`) REFERENCES `puzzle_themes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- puzzle_shared
-- Casse-têtes partagés entre deux appareils abonnés
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `puzzle_shared` (
    `id`               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `shared_uid`       VARCHAR(36)    NOT NULL COMMENT 'UUID exposé à l\'app',
    `image_id`         INT UNSIGNED   NOT NULL,
    `piece_count`      SMALLINT UNSIGNED NOT NULL,
    `seed`             INT UNSIGNED   NULL     COMMENT 'NULL si initial_pieces fourni',
    `creator_id`       INT UNSIGNED   NOT NULL COMMENT 'FK puzzle_devices.id',
    `partner_id`       INT UNSIGNED   NOT NULL COMMENT 'FK puzzle_devices.id',
    `completion`       TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Pourcentage 0–100',
    `status`           ENUM('active','archived') NOT NULL DEFAULT 'active',
    `created_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_activity_at` DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_shared_uid` (`shared_uid`),
    INDEX `idx_creator`    (`creator_id`, `status`),
    INDEX `idx_partner`    (`partner_id`, `status`),
    CONSTRAINT `fk_shared_image`
        FOREIGN KEY (`image_id`)   REFERENCES `puzzle_images`  (`id`),
    CONSTRAINT `fk_shared_creator`
        FOREIGN KEY (`creator_id`) REFERENCES `puzzle_devices` (`id`),
    CONSTRAINT `fk_shared_partner`
        FOREIGN KEY (`partner_id`) REFERENCES `puzzle_devices` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- puzzle_shared_pieces
-- État courant de chaque pièce (une ligne par pièce, mise à jour à chaque move)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `puzzle_shared_pieces` (
    `shared_id` INT UNSIGNED    NOT NULL,
    `piece_id`  SMALLINT UNSIGNED NOT NULL,
    `x`         FLOAT           NOT NULL DEFAULT 0,
    `y`         FLOAT           NOT NULL DEFAULT 0,
    `rotation`  SMALLINT        NOT NULL DEFAULT 0 COMMENT '0, 90, 180 ou 270',
    `locked`    TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (`shared_id`, `piece_id`),
    CONSTRAINT `fk_pieces_shared`
        FOREIGN KEY (`shared_id`) REFERENCES `puzzle_shared` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- puzzle_shared_events
-- Journal des mouvements pour le polling ; purgé après PUZZLE_EVENT_RETENTION_HOURS
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `puzzle_shared_events` (
    `id`        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `shared_id` INT UNSIGNED    NOT NULL,
    `device_id` INT UNSIGNED    NOT NULL COMMENT 'Appareil auteur du mouvement',
    `piece_id`  SMALLINT UNSIGNED NOT NULL,
    `x`         FLOAT           NOT NULL,
    `y`         FLOAT           NOT NULL,
    `rotation`  SMALLINT        NOT NULL,
    `locked`    TINYINT(1)      NOT NULL DEFAULT 0,
    `created_at` DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_events_poll` (`shared_id`, `id`),
    CONSTRAINT `fk_events_shared`
        FOREIGN KEY (`shared_id`) REFERENCES `puzzle_shared` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
