-- =============================================================
-- Migration traque — init (2026-06-05)
-- Plugin traque : monstres, combat, joueurs, achievements, push
-- =============================================================

-- ------------------------------------------------------------
-- monsters
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `monsters` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`             VARCHAR(100) NOT NULL,
  `asset_key`        VARCHAR(80)  NOT NULL,
  `lore`             TEXT         NULL,
  `level_base`       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `lat`              DECIMAL(10,7) NOT NULL,
  `lng`              DECIMAL(10,7) NOT NULL,
  `hp_max`           SMALLINT UNSIGNED NOT NULL,
  `hp_current`       SMALLINT UNSIGNED NOT NULL,
  `ac`               TINYINT UNSIGNED NOT NULL DEFAULT 10,
  `damage_dice`      VARCHAR(20)  NOT NULL DEFAULT '1d6',
  `xp_reward`        SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  `is_alive`         TINYINT(1)   NOT NULL DEFAULT 1,
  `respawn_at`       DATETIME     NULL,
  `behavior_type`    ENUM('static','patrol','roam','elusive') NOT NULL DEFAULT 'static',
  `move_radius_m`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `last_move_at`     DATETIME     NULL,
  `spawn_hour_start` TINYINT UNSIGNED NULL COMMENT 'NULL = toujours actif',
  `spawn_hour_end`   TINYINT UNSIGNED NULL COMMENT 'NULL = toujours actif',
  `biome`            ENUM('urban','forest','mountain','water','cemetery','worship','industrial') NOT NULL DEFAULT 'urban',
  `is_boss`          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'boss MJ : niveau fixe, ignore scaling',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- traque_combat_sessions
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `traque_combat_sessions` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `player_id`        INT(11) NOT NULL,
  `monster_id`       INT UNSIGNED NOT NULL,
  `status`           ENUM('active','victory','defeat','fled') NOT NULL DEFAULT 'active',
  `turn`             TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `player_hp_start`  SMALLINT UNSIGNED NOT NULL,
  `monster_hp_start` SMALLINT UNSIGNED NOT NULL,
  `monster_level`    TINYINT UNSIGNED NOT NULL COMMENT 'niveau effectif au moment du start',
  `started_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at`         DATETIME NULL,
  CONSTRAINT `fk_cs_player` FOREIGN KEY (`player_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cs_monster` FOREIGN KEY (`monster_id`) REFERENCES `monsters`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- traque_combat_log
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `traque_combat_log` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT UNSIGNED NOT NULL,
  `turn`       TINYINT UNSIGNED NOT NULL,
  `actor`      ENUM('player','monster') NOT NULL,
  `action`     ENUM('attack','miss','damage','save','flee','victory','defeat') NOT NULL,
  `roll`       TINYINT UNSIGNED NULL COMMENT 'valeur brute du dé',
  `modifier`   TINYINT NULL,
  `result`     SMALLINT NULL COMMENT 'dégâts infligés ou résultat sauvegarde',
  `log_text`   VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_cl_session` FOREIGN KEY (`session_id`) REFERENCES `traque_combat_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- traque_player_journal
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `traque_player_journal` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `player_id`    INT(11) NOT NULL,
  `session_id`   INT UNSIGNED NOT NULL,
  `monster_id`   INT UNSIGNED NOT NULL,
  `monster_name` VARCHAR(100) NOT NULL COMMENT 'snapshot nom au moment du combat',
  `monster_level` TINYINT UNSIGNED NOT NULL,
  `outcome`      ENUM('victory','defeat','fled') NOT NULL,
  `xp_earned`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `occurred_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_pj_player`  FOREIGN KEY (`player_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pj_session` FOREIGN KEY (`session_id`) REFERENCES `traque_combat_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- traque_achievements
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `traque_achievements` (
  `id`               SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug`             VARCHAR(60) NOT NULL UNIQUE,
  `title_fr`         VARCHAR(100) NOT NULL,
  `description_fr`   VARCHAR(255) NOT NULL,
  `icon_key`         VARCHAR(60) NOT NULL,
  `condition_type`   ENUM(
    'first_kill','kills_total','kills_biome','level_reached',
    'monsters_unique','nocturnal_kills','elusive_kills','flee_count'
  ) NOT NULL,
  `condition_value`  INT UNSIGNED NOT NULL DEFAULT 1,
  `condition_meta`   VARCHAR(100) NULL COMMENT 'ex: biome=forest pour kills_biome'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- traque_player_achievements
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `traque_player_achievements` (
  `player_id`      INT(11) NOT NULL,
  `achievement_id` SMALLINT UNSIGNED NOT NULL,
  `unlocked_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`player_id`, `achievement_id`),
  CONSTRAINT `fk_pa_player`      FOREIGN KEY (`player_id`)      REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pa_achievement` FOREIGN KEY (`achievement_id`) REFERENCES `traque_achievements`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- traque_players
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `traque_players` (
  `player_id`              INT(11) NOT NULL PRIMARY KEY,
  `class`                  ENUM('warrior','mage','ranger','cleric','rogue') NOT NULL,
  `race`                   ENUM('human','elf','dwarf','half_orc') NOT NULL,
  `level`                  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `xp`                     INT UNSIGNED NOT NULL DEFAULT 0,
  `hp_max`                 SMALLINT UNSIGNED NOT NULL,
  `hp_current`             SMALLINT UNSIGNED NOT NULL,
  `stat_for`               TINYINT UNSIGNED NOT NULL DEFAULT 10,
  `stat_dex`               TINYINT UNSIGNED NOT NULL DEFAULT 10,
  `stat_con`               TINYINT UNSIGNED NOT NULL DEFAULT 10,
  `stat_int`               TINYINT UNSIGNED NOT NULL DEFAULT 10,
  `stat_sag`               TINYINT UNSIGNED NOT NULL DEFAULT 10,
  `stat_cha`               TINYINT UNSIGNED NOT NULL DEFAULT 10,
  `skill_points_available` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `gems`                   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `equipped_weapon_id`     INT UNSIGNED NULL,
  `equipped_armor_id`      INT UNSIGNED NULL,
  `location_visibility`    ENUM('all','group','friends') NOT NULL DEFAULT 'friends',
  `pvp_enabled`            TINYINT(1) NOT NULL DEFAULT 0,
  `gps_consent`            TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'consentement GPS explicite GDPR',
  `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_tp_player` FOREIGN KEY (`player_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- traque_player_bestiary
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `traque_player_bestiary` (
  `player_id`    INT(11) NOT NULL,
  `monster_id`   INT UNSIGNED NOT NULL,
  `kills`        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `first_kill_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_kill_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`player_id`, `monster_id`),
  CONSTRAINT `fk_pb_player`  FOREIGN KEY (`player_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pb_monster` FOREIGN KEY (`monster_id`) REFERENCES `monsters`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- traque_push_tokens
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `traque_push_tokens` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `player_id`  INT(11) NOT NULL,
  `fcm_token`  VARCHAR(255) NOT NULL,
  `device_id`  VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_player_device` (`player_id`, `device_id`),
  CONSTRAINT `fk_pt_player` FOREIGN KEY (`player_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Seed : achievements MVP
-- ============================================================
INSERT IGNORE INTO `traque_achievements`
  (`slug`, `title_fr`, `description_fr`, `icon_key`, `condition_type`, `condition_value`, `condition_meta`)
VALUES
  ('first_blood',    'Premier sang',       'Vaincre votre premier monstre.',            'sword_red',    'first_kill',    1, NULL),
  ('warrior_10',     'Guerrier aguerri',   'Vaincre 10 monstres.',                      'shield_gold',  'kills_total',   10, NULL),
  ('forest_hunter',  'Chasseur des bois',  'Vaincre 5 monstres dans la forêt.',         'bow_green',    'kills_biome',   5,  'biome=forest'),
  ('night_stalker',  'Rôdeur nocturne',    'Vaincre 3 monstres la nuit.',               'moon_silver',  'nocturnal_kills', 3, NULL),
  ('shadow_tracker', 'Traqueur de l''ombre', 'Vaincre un monstre élusif.',              'shadow_blade', 'elusive_kills', 1, NULL),
  ('level_5',        'Aventurier',         'Atteindre le niveau 5.',                    'star_bronze',  'level_reached', 5, NULL),
  ('level_10',       'Héros',              'Atteindre le niveau 10.',                   'star_gold',    'level_reached', 10, NULL);

-- ============================================================
-- Seed : 5 monstres de test (zone Montréal ≈ 45.50, -73.60)
-- ============================================================
INSERT IGNORE INTO `monsters`
  (`id`, `name`, `asset_key`, `lore`, `level_base`, `lat`, `lng`, `hp_max`, `hp_current`, `ac`, `damage_dice`, `xp_reward`, `is_alive`, `behavior_type`, `move_radius_m`, `biome`)
VALUES
  (1, 'Gobelin des rues',  'goblin',       'Une vermine urbaine au couteau rouillé.',                     1,  45.5012300, -73.6234500, 8,  8,  9, '1d6',   20,  1, 'static',  0,   'urban'),
  (2, 'Loup-garou',        'werewolf',     'Créature nocturne issue des forêts maudites du nord.',        5,  45.5067800, -73.6189000, 52, 52, 13, '2d6+2', 150, 1, 'elusive', 300, 'forest'),
  (3, 'Spectre',           'specter',      'Âme tourmentée condamnée à errer entre les tombes.',          3,  45.4998700, -73.6301200, 22, 22, 12, '1d8+1', 75,  1, 'patrol',  150, 'cemetery'),
  (4, 'Golem de pierre',   'stone_golem',  'Automate de granit forgé dans les entrailles de l''usine.',  8,  45.5089400, -73.6145600, 88, 88, 16, '2d8+3', 280, 1, 'static',  0,   'industrial'),
  (5, 'Dragon juvénile',   'young_dragon', 'Un dragon en pleine croissance, déjà redoutable.',            12, 45.4967300, -73.6267800, 144, 144, 18, '3d8+4', 600, 1, 'roam',    500, 'mountain');
