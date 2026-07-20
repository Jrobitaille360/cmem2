-- ============================================================
-- COLLECTIVE MEMORIES â€” Structure de la base de donnÃ©es
-- Version : 2.7.0 â€” 2026-05-29
-- ============================================================
-- Ce fichier contient UNIQUEMENT la structure (DDL).
-- Les donnÃ©es de dÃ©marrage sensibles (utilisateurs) sont dans
-- docs/seed_users.sql (ignorÃ© par git).
-- Migrations intégrées :
--   20260523_v270_migration.sql     (android_devices, web_devices, app_user_settings,
--                                    playstore_subscriptions, stripe_subscriptions,
--                                    refonte puzzle_shared + pieces + events)
--   20260524_playstore_subscriptions_device_uuid.sql
--   20260529_backup_json_devices.sql
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- ============================================================
-- VUES â€” Doublures de structure (requises avant les vraies vues)
-- ============================================================

DROP VIEW IF EXISTS `active_user_sessions`;
CREATE TABLE `active_user_sessions` (
`id` int(11)
,`user_id` int(11)
,`login_at` timestamp
,`last_activity_at` timestamp
,`logout_at` timestamp
,`expires_at` timestamp
,`ip_address` varchar(45)
,`user_agent` text
,`is_active` tinyint(1)
,`session_data` longtext
,`email` varchar(255)
,`username` varchar(255)
,`role` enum('ADMINISTRATEUR','UTILISATEUR')
,`minutes_since_activity` bigint(21)
,`session_duration_minutes` bigint(21)
);

DROP VIEW IF EXISTS `user_sessions_stats`;
CREATE TABLE `user_sessions_stats` (
`total_active_sessions` bigint(21)
,`unique_users_online` bigint(21)
,`avg_session_duration_minutes` decimal(24,4)
,`active_last_5min` bigint(21)
,`active_last_30min` bigint(21)
,`sessions_today` bigint(21)
);

DROP VIEW IF EXISTS `v_admin_dashboard`;
CREATE TABLE `v_admin_dashboard` (
`total_users` bigint(21)
,`active_users_7d` bigint(21)
,`total_groups` bigint(21)
,`total_tags` bigint(21)
,`total_files` bigint(21)
,`total_storage_mb` decimal(35,2)
,`pending_invitations` bigint(21)
);

-- ============================================================
-- TABLES
-- ============================================================

-- ------------------------------------------------------------
-- caldav_locks
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `caldav_locks`;
CREATE TABLE `caldav_locks` (
  `id` int(11) NOT NULL,
  `resource_path` varchar(500) NOT NULL,
  `lock_token` varchar(255) NOT NULL,
  `lock_scope` enum('exclusive','shared') DEFAULT 'exclusive',
  `lock_type` enum('write') DEFAULT 'write',
  `lock_owner` varchar(500) DEFAULT NULL,
  `depth` enum('0','infinity') DEFAULT '0',
  `timeout` int(11) DEFAULT 3600,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `calendar_id` int(11) DEFAULT NULL,
  `event_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- caldav_sync_log
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `caldav_sync_log`;
CREATE TABLE `caldav_sync_log` (
  `id` int(11) NOT NULL,
  `calendar_id` int(11) NOT NULL,
  `event_id` int(11) DEFAULT NULL,
  `change_type` enum('created','updated','deleted') NOT NULL,
  `sync_token` varchar(64) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- calendars
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `calendars`;
CREATE TABLE `calendars` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `timezone` varchar(50) DEFAULT 'America/Montreal',
  `color` varchar(7) DEFAULT '#3174ad',
  `visibility` enum('public','private') DEFAULT 'private',
  `max_members` int(11) DEFAULT 100,
  `share_token` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `ctag` varchar(64) DEFAULT NULL COMMENT 'Collection Tag pour CalDAV',
  `sync_token` varchar(64) DEFAULT NULL COMMENT 'Token de synchronisation CalDAV'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- calendar_events
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `calendar_events`;
CREATE TABLE `calendar_events` (
  `id` int(11) NOT NULL,
  `calendar_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `all_day` tinyint(1) DEFAULT 0,
  `location` varchar(255) DEFAULT NULL,
  `attendees` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attendees`)),
  `recurrence_rule` varchar(255) DEFAULT NULL,
  `status` enum('confirmed','tentative','cancelled') DEFAULT 'confirmed',
  `timezone` varchar(100) DEFAULT 'America/Montreal',
  `meeting_link` text DEFAULT NULL,
  `notifications` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`notifications`)),
  `color` varchar(7) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `etag` varchar(64) DEFAULT NULL COMMENT 'Entity Tag pour CalDAV',
  `uid` varchar(255) DEFAULT NULL COMMENT 'UID unique iCalendar',
  `sequence` int(11) DEFAULT 0 COMMENT 'NumÃ©ro de sÃ©quence CalDAV',
  `last_modified` timestamp NULL DEFAULT NULL COMMENT 'DerniÃ¨re modification CalDAV'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- calendar_shares
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `calendar_shares`;
CREATE TABLE `calendar_shares` (
  `id` int(11) NOT NULL,
  `calendar_id` int(11) NOT NULL,
  `shared_with_user_id` int(11) DEFAULT NULL,
  `shared_with_email` varchar(255) DEFAULT NULL,
  `shared_with_group_id` int(11) DEFAULT NULL,
  `permission` enum('read','write') DEFAULT 'read',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- calendar_tags
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `calendar_tags`;
CREATE TABLE `calendar_tags` (
  `id` int(11) NOT NULL,
  `calendar_id` int(11) NOT NULL,
  `name` varchar(191) NOT NULL,
  `color` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  UNIQUE KEY `uniq_calendar_tag_name` (`calendar_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- email_verifications
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `email_verifications`;
CREATE TABLE `email_verifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- ------------------------------------------------------------
-- event_occurrences
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `event_occurrences`;
CREATE TABLE `event_occurrences` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `calendar_id` int(11) NOT NULL,
  `occurrence_date` date NOT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `recurrence_index` int(11) NOT NULL DEFAULT 0,
  `is_modified` tinyint(1) DEFAULT 0,
  `is_cancelled` tinyint(1) DEFAULT 0,
  `modified_title` varchar(255) DEFAULT NULL,
  `modified_description` text DEFAULT NULL,
  `modified_location` varchar(255) DEFAULT NULL,
  `modified_start_datetime` datetime DEFAULT NULL,
  `modified_end_datetime` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- files
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `files`;
CREATE TABLE `files` (
  `id` int(11) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `upload_ip` varchar(45) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `media_type` enum('text','audio','video','image','gpx','summary','event','todo','document','executable') DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `accessibility` enum('public','private','grand-public') NOT NULL DEFAULT 'private',
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `download_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- file_tag_relations
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `file_tag_relations`;
CREATE TABLE `file_tag_relations` (
  `file_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- groups
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `groups`;
CREATE TABLE `groups` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `max_members` int(11) DEFAULT NULL,
  `visibility` enum('private','shared','public') DEFAULT 'private',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS `add_group_creator_as_admin`;
DELIMITER $$
CREATE TRIGGER `add_group_creator_as_admin` AFTER INSERT ON `groups` FOR EACH ROW BEGIN
    INSERT INTO group_members (group_id, user_id, invited_by, role, joined_at)
    VALUES (NEW.id, NEW.owner_id, NEW.owner_id, 'admin', NOW());
END
$$
DELIMITER ;

-- ------------------------------------------------------------
-- group_invitations
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `group_invitations`;
CREATE TABLE `group_invitations` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `invited_email` varchar(255) NOT NULL,
  `invited_role` enum('admin','moderator','member') NOT NULL DEFAULT 'member',
  `invited_by` int(11) NOT NULL,
  `invitation_token` varchar(100) NOT NULL,
  `status` enum('pending','accepted','declined','expired') NOT NULL DEFAULT 'pending',
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `responded_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- group_members
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `group_members`;
CREATE TABLE `group_members` (
  `id` int(11) NOT NULL,
  `group_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `invited_by` int(11) DEFAULT NULL,
  `role` enum('admin','moderator','member') NOT NULL DEFAULT 'member',
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- group_stats_snapshot
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `group_stats_snapshot`;
CREATE TABLE `group_stats_snapshot` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `group_name` varchar(255) DEFAULT NULL,
  `visibility` enum('private','shared','public') DEFAULT NULL,
  `member_count` int(11) DEFAULT 0,
  `tag_count` int(11) DEFAULT 0,
  `file_count` int(11) DEFAULT 0,
  `storage_mb` decimal(10,2) DEFAULT 0.00,
  `last_activity_date` timestamp NULL DEFAULT NULL,
  `days_since_creation` int(11) DEFAULT 0,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- group_tag_relations
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `group_tag_relations`;
CREATE TABLE `group_tag_relations` (
  `group_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- notifications
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `extra_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extra_payload`)),
  `type` enum('invitation','memory_update','group_event','system','reminder') NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- password_resets
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `used_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- ------------------------------------------------------------
-- plans + donnÃ©es de rÃ©fÃ©rence (non sensibles)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `plans`;
CREATE TABLE `plans` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) NOT NULL DEFAULT 'EUR',
  `duration_days` int(11) DEFAULT NULL,
  `api_rate_limit` int(11) NOT NULL DEFAULT 60,
  `features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `plans` (`id`, `name`, `display_name`, `description`, `price`, `currency`, `duration_days`, `api_rate_limit`, `features`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'free',    'Plan Gratuit', 'Plan gratuit avec limitations pour tester l API',  0.00,  'EUR', 30, 10,   '{"scopes":["read"],"max_requests_per_day":1000,"expires_in_days":7,"email_support":false,"priority_support":false}', 1, '2025-12-04 13:49:57', '2025-12-04 13:49:57'),
(2, 'bronze',  'Plan Bronze',  'Plan bronze avec fonctionnalitÃ©s essentielles',    9.99,  'EUR', 30, 100,  '{"scopes":["read","write"],"max_requests_per_day":10000,"expires_in_days":null,"email_support":true,"priority_support":false}', 1, '2025-12-04 13:49:57', '2025-12-04 13:49:57'),
(3, 'argent',  'Plan Argent',  'Plan argent avec fonctionnalitÃ©s avancÃ©es',        19.99, 'EUR', 30, 300,  '{"scopes":["read","write","delete"],"max_requests_per_day":50000,"expires_in_days":null,"email_support":true,"priority_support":true,"webhook_support":true}', 1, '2025-12-04 13:49:57', '2025-12-04 13:49:57'),
(4, 'platine', 'Plan Platine', 'Plan platine avec toutes les fonctionnalitÃ©s premium', 49.99, 'EUR', 30, 1000, '{"scopes":["read","write","delete","admin"],"max_requests_per_day":"unlimited","expires_in_days":null,"email_support":true,"priority_support":true,"webhook_support":true,"custom_integrations":true,"dedicated_support":true}', 1, '2025-12-04 13:49:57', '2025-12-04 13:49:57');

-- ------------------------------------------------------------
-- plan_invitations
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `plan_invitations`;
CREATE TABLE `plan_invitations` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `invitation_token` varchar(64) NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `clicked_at` timestamp NULL DEFAULT NULL,
  `selected_plan` varchar(50) DEFAULT NULL,
  `selected_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','clicked','selected','expired') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- platform_stats
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `platform_stats`;
CREATE TABLE `platform_stats` (
  `id` int(11) NOT NULL,
  `total_users` int(11) DEFAULT 0,
  `active_users_7d` int(11) DEFAULT 0,
  `active_users_30d` int(11) DEFAULT 0,
  `total_groups` int(11) DEFAULT 0,
  `total_tags` int(11) DEFAULT 0,
  `total_files` int(11) DEFAULT 0,
  `total_storage_mb` decimal(12,2) DEFAULT 0.00,
  `pending_invitations` int(11) DEFAULT 0,
  `avg_group_size` decimal(5,2) DEFAULT 0.00,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- tags
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `tags`;
CREATE TABLE `tags` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `table_associate` enum('groups','memories','elements','files','all','quiz_questions') DEFAULT NULL,
  `color` varchar(7) DEFAULT '#3498db',
  `tag_owner` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- users  (structure seulement â€” donnÃ©es dans docs/seed_users.sql)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('SUPERADMINISTRATEUR','ADMINISTRATEUR','UTILISATEUR') NOT NULL DEFAULT 'UTILISATEUR',
  `plan_id` int(11) DEFAULT NULL,
  `plan_expires_at` timestamp NULL DEFAULT NULL,
  `plan_auto_renew` tinyint(1) NOT NULL DEFAULT 0,
  `profile_image` varchar(500) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `last_login` timestamp NULL DEFAULT NULL,
  `payment_status` enum('pending','paid','expired') DEFAULT 'pending',
  `license_expires_at` datetime DEFAULT NULL,
  `payment_plan` enum('basic','standard','premium','lifetime') DEFAULT 'basic',
  `payment_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `cmem_plan_override` varchar(20) DEFAULT NULL COMMENT 'Override manuel plan cmem (ex. ami), hors flux Stripe'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- user_app_setup
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `user_app_setup`;
CREATE TABLE `user_app_setup` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `app_id` varchar(255) NOT NULL,
  `json_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`json_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- user_sessions
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `login_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `logout_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `session_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`session_data`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- user_stats_snapshot
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `user_stats_snapshot`;
CREATE TABLE `user_stats_snapshot` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `role` enum('ADMINISTRATEUR','UTILISATEUR') DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `groups_created` int(11) DEFAULT 0,
  `groups_joined` int(11) DEFAULT 0,
  `tags_created` int(11) DEFAULT 0,
  `files_uploaded` int(11) DEFAULT 0,
  `storage_used_mb` decimal(10,2) DEFAULT 0.00,
  `invitations_sent` int(11) DEFAULT 0,
  `days_since_registration` int(11) DEFAULT 0,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- INDEX
-- ============================================================

ALTER TABLE `caldav_locks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `lock_token` (`lock_token`),
  ADD KEY `calendar_id` (`calendar_id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `idx_resource_path` (`resource_path`),
  ADD KEY `idx_expires` (`expires_at`);

ALTER TABLE `caldav_sync_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `idx_calendar_sync` (`calendar_id`,`sync_token`),
  ADD KEY `idx_changed_at` (`changed_at`);

ALTER TABLE `calendars`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `share_token` (`share_token`),
  ADD KEY `idx_user_calendars` (`user_id`),
  ADD KEY `idx_share_token` (`share_token`),
  ADD KEY `idx_ctag` (`ctag`);

ALTER TABLE `calendar_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_calendar_events` (`calendar_id`),
  ADD KEY `idx_user_events` (`user_id`),
  ADD KEY `idx_event_dates` (`start_datetime`,`end_datetime`),
  ADD KEY `idx_etag` (`etag`),
  ADD KEY `idx_uid` (`uid`);

ALTER TABLE `calendar_shares`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_share` (`calendar_id`,`shared_with_user_id`),
  ADD UNIQUE KEY `unique_email_share` (`calendar_id`,`shared_with_email`),
  ADD KEY `shared_with_user_id` (`shared_with_user_id`);

ALTER TABLE `calendar_tags`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_calendar_tags_calendar_id` (`calendar_id`);

ALTER TABLE `email_verifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_deleted_att` (`deleted_at`);

ALTER TABLE `event_occurrences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_event_occurrence` (`event_id`,`occurrence_date`,`recurrence_index`),
  ADD KEY `idx_event_occurrences` (`event_id`),
  ADD KEY `idx_calendar_occurrences` (`calendar_id`),
  ADD KEY `idx_occurrence_dates` (`occurrence_date`),
  ADD KEY `idx_date_range` (`start_datetime`,`end_datetime`),
  ADD KEY `idx_calendar_dates` (`calendar_id`,`start_datetime`,`end_datetime`);

ALTER TABLE `files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_file_uploaded_by` (`uploaded_by`),
  ADD KEY `idx_file_uploaded_at` (`uploaded_at`),
  ADD KEY `idx_file_mime_type` (`mime_type`);

ALTER TABLE `file_tag_relations`
  ADD PRIMARY KEY (`file_id`,`tag_id`),
  ADD KEY `tag_id` (`tag_id`),
  ADD KEY `idx_file_tag_relations_created_at` (`created_at`);

ALTER TABLE `groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_group_owner_id` (`owner_id`),
  ADD KEY `idx_group_visibility` (`visibility`),
  ADD KEY `idx_group_created_at` (`created_at`);

ALTER TABLE `group_invitations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invitation_token` (`invitation_token`),
  ADD KEY `idx_group_invitation_invited_by` (`invited_by`),
  ADD KEY `idx_group_invitation_group_id` (`group_id`),
  ADD KEY `idx_group_invitation_invited_email` (`invited_email`),
  ADD KEY `idx_group_invitation_invitation_token` (`invitation_token`),
  ADD KEY `idx_group_invitation_status` (`status`),
  ADD KEY `idx_group_invitation_expires_at` (`expires_at`);

ALTER TABLE `group_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_group_user` (`group_id`,`user_id`),
  ADD KEY `idx_group_member_invited_by` (`invited_by`),
  ADD KEY `idx_group_member_group_id` (`group_id`),
  ADD KEY `idx_group_member_user_id` (`user_id`),
  ADD KEY `idx_group_member_role` (`role`),
  ADD KEY `idx_group_member_joined_at` (`joined_at`),
  ADD KEY `idx_group_member_deleted_at` (`deleted_at`);

ALTER TABLE `group_stats_snapshot`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_group_stats_group_id` (`group_id`),
  ADD KEY `idx_group_stats_generated_at` (`generated_at`);

ALTER TABLE `group_tag_relations`
  ADD PRIMARY KEY (`group_id`,`tag_id`),
  ADD KEY `tag_id` (`tag_id`);

ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_user_id` (`user_id`),
  ADD KEY `idx_notifications_type` (`type`),
  ADD KEY `idx_notifications_extra_payload` (`extra_payload`(768)),
  ADD KEY `idx_notifications_is_read` (`is_read`),
  ADD KEY `idx_notifications_created_at` (`created_at`);

ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_expires_at` (`expires_at`);

ALTER TABLE `plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `is_active` (`is_active`);

ALTER TABLE `plan_invitations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invitation_token` (`invitation_token`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `expires_at` (`expires_at`);

ALTER TABLE `platform_stats`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_platform_stats_generated_at` (`generated_at`);

ALTER TABLE `tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`,`table_associate`),
  ADD KEY `idx_tag_name` (`name`),
  ADD KEY `idx_tag_owner` (`tag_owner`),
  ADD KEY `idx_tag_table_associate` (`table_associate`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_email` (`email`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_deleted_at` (`deleted_at`),
  ADD KEY `idx_users_created_at` (`created_at`),
  ADD KEY `idx_users_last_login` (`last_login`),
  ADD KEY `idx_users_plan_expires` (`plan_id`,`plan_expires_at`);

ALTER TABLE `user_app_setup`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_app` (`user_id`,`app_id`),
  ADD KEY `idx_user_app_setup_user_id` (`user_id`),
  ADD KEY `idx_user_app_setup_app_id` (`app_id`),
  ADD KEY `idx_user_app_setup_deleted_at` (`deleted_at`);

ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_active_sessions` (`user_id`,`is_active`,`expires_at`),
  ADD KEY `idx_cleanup` (`expires_at`,`is_active`);

ALTER TABLE `user_stats_snapshot`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_stats_user_id` (`user_id`),
  ADD KEY `idx_user_stats_role` (`role`),
  ADD KEY `idx_user_stats_generated_at` (`generated_at`);

-- ============================================================
-- AUTO_INCREMENT
-- ============================================================

ALTER TABLE `caldav_locks`        MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `caldav_sync_log`     MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `calendars`           MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `calendar_events`     MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `calendar_shares`     MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `calendar_tags`       MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `email_verifications` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `event_occurrences`   MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `files`               MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `groups`              MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `group_invitations`   MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `group_members`       MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `group_stats_snapshot` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `notifications`       MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `password_resets`     MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `plans`               MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
ALTER TABLE `plan_invitations`    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `platform_stats`      MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `tags`                MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `users`               MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `user_app_setup`      MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `user_sessions`       MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `user_stats_snapshot` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ============================================================
-- VUES RÃ‰ELLES
-- ============================================================

DROP TABLE IF EXISTS `active_user_sessions`;
DROP VIEW IF EXISTS `active_user_sessions`;
CREATE VIEW `active_user_sessions` AS
  SELECT `us`.`id`, `us`.`user_id`, `us`.`login_at`, `us`.`last_activity_at`,
    `us`.`logout_at`, `us`.`expires_at`, `us`.`ip_address`, `us`.`user_agent`, `us`.`is_active`,
    `us`.`session_data`, `u`.`email`, `u`.`name` AS `username`, `u`.`role`,
    TIMESTAMPDIFF(MINUTE, `us`.`last_activity_at`, CURRENT_TIMESTAMP()) AS `minutes_since_activity`,
    TIMESTAMPDIFF(MINUTE, `us`.`login_at`, IFNULL(`us`.`logout_at`, CURRENT_TIMESTAMP())) AS `session_duration_minutes`
  FROM `user_sessions` `us`
    JOIN `users` `u` ON `us`.`user_id` = `u`.`id`
  WHERE `us`.`is_active` = 1
    AND `us`.`expires_at` > CURRENT_TIMESTAMP()
    AND `u`.`deleted_at` IS NULL;

DROP TABLE IF EXISTS `user_sessions_stats`;
DROP VIEW IF EXISTS `user_sessions_stats`;
CREATE VIEW `user_sessions_stats` AS
  SELECT COUNT(0) AS `total_active_sessions`,
    COUNT(DISTINCT `active_user_sessions`.`user_id`) AS `unique_users_online`,
    AVG(TIMESTAMPDIFF(MINUTE, `active_user_sessions`.`login_at`, IFNULL(`active_user_sessions`.`logout_at`, CURRENT_TIMESTAMP()))) AS `avg_session_duration_minutes`,
    COUNT(CASE WHEN `active_user_sessions`.`last_activity_at` > CURRENT_TIMESTAMP() - INTERVAL 5 MINUTE THEN 1 END) AS `active_last_5min`,
    COUNT(CASE WHEN `active_user_sessions`.`last_activity_at` > CURRENT_TIMESTAMP() - INTERVAL 30 MINUTE THEN 1 END) AS `active_last_30min`,
    COUNT(CASE WHEN `active_user_sessions`.`login_at` > CURRENT_TIMESTAMP() - INTERVAL 1 DAY THEN 1 END) AS `sessions_today`
  FROM `active_user_sessions`;

DROP TABLE IF EXISTS `v_admin_dashboard`;
DROP VIEW IF EXISTS `v_admin_dashboard`;
CREATE VIEW `v_admin_dashboard` AS
  SELECT
    (SELECT COUNT(0) FROM `users` WHERE `deleted_at` IS NULL) AS `total_users`,
    (SELECT COUNT(0) FROM `users` WHERE `deleted_at` IS NULL AND `last_login` >= CURRENT_TIMESTAMP() - INTERVAL 7 DAY) AS `active_users_7d`,
    (SELECT COUNT(0) FROM `groups` WHERE `deleted_at` IS NULL) AS `total_groups`,
    (SELECT COUNT(0) FROM `tags` WHERE `deleted_at` IS NULL) AS `total_tags`,
    (SELECT COUNT(0) FROM `files`) AS `total_files`,
    (SELECT ROUND(COALESCE(SUM(`file_size`), 0) / 1024 / 1024, 2) FROM `files`) AS `total_storage_mb`,
    (SELECT COUNT(0) FROM `group_invitations` WHERE `status` = 'pending' AND (`expires_at` IS NULL OR `expires_at` > CURRENT_TIMESTAMP())) AS `pending_invitations`;

-- ============================================================
-- CONTRAINTES (CLÃ‰S Ã‰TRANGÃˆRES)
-- ============================================================

ALTER TABLE `caldav_locks`
  ADD CONSTRAINT `caldav_locks_ibfk_1` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `caldav_locks_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE;

ALTER TABLE `caldav_sync_log`
  ADD CONSTRAINT `caldav_sync_log_ibfk_1` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `caldav_sync_log_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE SET NULL;

ALTER TABLE `calendars`
  ADD CONSTRAINT `calendars_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `calendar_events`
  ADD CONSTRAINT `calendar_events_ibfk_1` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `calendar_events_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `calendar_shares`
  ADD CONSTRAINT `calendar_shares_ibfk_1` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `calendar_shares_ibfk_2` FOREIGN KEY (`shared_with_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_calendar_shares_group` FOREIGN KEY (`shared_with_group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

ALTER TABLE `calendar_tags`
  ADD CONSTRAINT `fk_calendar_tags_calendar` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE CASCADE;

ALTER TABLE `email_verifications`
  ADD CONSTRAINT `email_verifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `event_occurrences`
  ADD CONSTRAINT `event_occurrences_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `event_occurrences_ibfk_2` FOREIGN KEY (`calendar_id`) REFERENCES `calendars` (`id`) ON DELETE CASCADE;

ALTER TABLE `file_tag_relations`
  ADD CONSTRAINT `file_tag_relations_ibfk_1` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `file_tag_relations_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE;

ALTER TABLE `groups`
  ADD CONSTRAINT `groups_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `group_invitations`
  ADD CONSTRAINT `group_invitations_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_invitations_ibfk_2` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `group_members`
  ADD CONSTRAINT `group_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_members_ibfk_3` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `group_stats_snapshot`
  ADD CONSTRAINT `group_stats_snapshot_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

ALTER TABLE `group_tag_relations`
  ADD CONSTRAINT `group_tag_relations_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_tag_relations_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE;

ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `plan_invitations`
  ADD CONSTRAINT `fk_plan_invitations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `tags`
  ADD CONSTRAINT `tags_ibfk_1` FOREIGN KEY (`tag_owner`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL;

ALTER TABLE `user_app_setup`
  ADD CONSTRAINT `user_app_setup_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `user_sessions`
  ADD CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `user_stats_snapshot`
  ADD CONSTRAINT `user_stats_snapshot_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;


-- otp_codes
CREATE TABLE IF NOT EXISTS `otp_codes` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`        VARCHAR(255) NOT NULL,
    `code_hash`    VARCHAR(255) NOT NULL COMMENT 'bcrypt du code Ã  6 chiffres',
    `expires_at`   DATETIME     NOT NULL,
    `attempts`     TINYINT      NOT NULL DEFAULT 0,
    `max_attempts` TINYINT      NOT NULL DEFAULT 5,
    `used_at`      DATETIME     NULL DEFAULT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_otp_email`   (`email`),
    INDEX `idx_otp_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- device_tokens
CREATE TABLE IF NOT EXISTS `device_tokens` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED NOT NULL,
    `device_id`    VARCHAR(128) NOT NULL COMMENT 'UUID stable cÃ´tÃ© client',
    `device_name`  VARCHAR(255) NOT NULL DEFAULT 'Appareil inconnu',
    `family_id`    VARCHAR(36)  NULL COMMENT 'UUID de la famille de rotation — tokens issus d un meme appareil partagent le meme family_id',
    `token_hash`   VARCHAR(64)  NOT NULL COMMENT 'SHA-256 du token en clair',
    `expires_at`   DATETIME     NOT NULL,
    `revoked_at`   DATETIME     NULL DEFAULT NULL,
    `last_used_at` DATETIME     NULL DEFAULT NULL,
    `last_ip`      VARCHAR(45)  NULL DEFAULT NULL,
    `last_ua`      VARCHAR(512) NULL DEFAULT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device_token_hash` (`token_hash`),
    INDEX `idx_device_user`      (`user_id`),
    INDEX `idx_device_device_id` (`device_id`),
    INDEX `idx_device_expires`   (`expires_at`),
    INDEX `idx_device_family`    (`family_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- jwt_blacklist
CREATE TABLE IF NOT EXISTS `jwt_blacklist` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `jti`        VARCHAR(36)  NOT NULL COMMENT 'UUID v4 du token rÃ©voquÃ©',
    `user_id`    INT UNSIGNED NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_jti`          (`jti`),
    INDEX       `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- login_attempts
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(255) NOT NULL,
    `ip_address` VARCHAR(45)  NOT NULL,
    `endpoint`   VARCHAR(20)  NOT NULL DEFAULT 'login' COMMENT 'login | send-code',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_rate_limit` (`email`, `ip_address`, `endpoint`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
COMMIT;

-- ============================================================
-- PROCÃ‰DURES STOCKÃ‰ES
-- ============================================================
DELIMITER $$

DROP PROCEDURE IF EXISTS `CleanupExpiredSessions`$$
$$
DROP PROCEDURE IF EXISTS `CleanupOldStats`$$
$$
DROP PROCEDURE IF EXISTS `GenerateGroupStats`$$
$$
DROP PROCEDURE IF EXISTS `GeneratePlatformStats`$$
$$
DROP PROCEDURE IF EXISTS `GenerateUserStats`$$
$$

DELIMITER ;

DELIMITER $$

-- ===== ProcÃ©dure pour gÃ©nÃ©rer les statistiques globales =====
CREATE PROCEDURE GeneratePlatformStats()
BEGIN
    INSERT INTO platform_stats (
        total_users, active_users_7d, active_users_30d, total_groups, 
        total_tags, total_files, total_storage_mb, pending_invitations, avg_group_size
    )
    SELECT 
        (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) as total_users,
        (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as active_users_7d,
        (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as active_users_30d,
        (SELECT COUNT(*) FROM groups WHERE deleted_at IS NULL) as total_groups,
        (SELECT COUNT(*) FROM tags WHERE deleted_at IS NULL) as total_tags,
        (SELECT COUNT(*) FROM files) as total_files,
        (SELECT ROUND(COALESCE(SUM(file_size), 0) / 1024 / 1024, 2) FROM files) as total_storage_mb,
        (SELECT COUNT(*) FROM group_invitations WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())) as pending_invitations,
        (SELECT ROUND(AVG(member_count), 2) FROM (
            SELECT COUNT(gm.user_id) as member_count 
            FROM groups g 
            LEFT JOIN group_members gm ON g.id = gm.group_id AND gm.deleted_at IS NULL 
            WHERE g.deleted_at IS NULL 
            GROUP BY g.id
        ) as group_sizes) as avg_group_size;
END$$

-- ===== ProcÃ©dure pour gÃ©nÃ©rer les statistiques par groupe =====
CREATE PROCEDURE GenerateGroupStats()
BEGIN
    -- Supprimer les anciens snapshots (garder seulement les 30 derniers jours)
    DELETE FROM group_stats_snapshot WHERE generated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    INSERT INTO group_stats_snapshot (
        group_id, group_name, visibility, member_count, tag_count, days_since_creation
    )
    SELECT 
        g.id,
        g.name,
        g.visibility,
        COALESCE(gm_count.member_count, 0),
        COALESCE(gt_count.tag_count, 0),
        DATEDIFF(NOW(), g.created_at) as days_since_creation
    FROM groups g
    LEFT JOIN (
        SELECT group_id, COUNT(*) as member_count 
        FROM group_members 
        WHERE deleted_at IS NULL 
        GROUP BY group_id
    ) gm_count ON g.id = gm_count.group_id
    LEFT JOIN (
        SELECT group_id, COUNT(*) as tag_count 
        FROM group_tag_relations 
        WHERE deleted_at IS NULL 
        GROUP BY group_id
    ) gt_count ON g.id = gt_count.group_id
    WHERE g.deleted_at IS NULL;
END$$

-- ===== ProcÃ©dure pour gÃ©nÃ©rer les statistiques par utilisateur =====
CREATE PROCEDURE GenerateUserStats()
BEGIN
    -- Supprimer les anciens snapshots (garder seulement les 30 derniers jours)
    DELETE FROM user_stats_snapshot WHERE generated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    INSERT INTO user_stats_snapshot (
        user_id, user_name, role, last_login, groups_created, groups_joined,
        tags_created, files_uploaded, storage_used_mb, invitations_sent, days_since_registration
    )
    SELECT 
        u.id,
        u.name,
        u.role,
        u.last_login,
        COALESCE(groups_created.count, 0),
        COALESCE(groups_joined.count, 0),
        COALESCE(tags_created.count, 0),
        COALESCE(files_uploaded.count, 0),
        COALESCE(storage_used.storage_mb, 0),
        COALESCE(invitations_sent.count, 0),
        DATEDIFF(NOW(), u.created_at) as days_since_registration
    FROM users u
    LEFT JOIN (
        SELECT owner_id, COUNT(*) as count 
        FROM groups 
        WHERE deleted_at IS NULL 
        GROUP BY owner_id
    ) groups_created ON u.id = groups_created.owner_id
    LEFT JOIN (
        SELECT user_id, COUNT(*) as count 
        FROM group_members 
        WHERE deleted_at IS NULL 
        GROUP BY user_id
    ) groups_joined ON u.id = groups_joined.user_id
    LEFT JOIN (
        SELECT tag_owner, COUNT(*) as count 
        FROM tags 
        WHERE deleted_at IS NULL 
        GROUP BY tag_owner
    ) tags_created ON u.id = tags_created.tag_owner
    LEFT JOIN (
        SELECT uploaded_by, COUNT(*) as count 
        FROM files 
        GROUP BY uploaded_by
    ) files_uploaded ON u.id = files_uploaded.uploaded_by
    LEFT JOIN (
        SELECT uploaded_by, ROUND(COALESCE(SUM(file_size), 0) / 1024 / 1024, 2) as storage_mb
        FROM files 
        GROUP BY uploaded_by
    ) storage_used ON u.id = storage_used.uploaded_by
    LEFT JOIN (
        SELECT invited_by, COUNT(*) as count 
        FROM group_invitations 
        GROUP BY invited_by
    ) invitations_sent ON u.id = invitations_sent.invited_by
    WHERE u.deleted_at IS NULL;
END$$

-- ===== ProcÃ©dure de nettoyage des anciennes statistiques =====
CREATE PROCEDURE CleanupOldStats()
BEGIN
    -- Garder seulement les 100 derniers snapshots de statistiques globales
    DELETE FROM platform_stats 
    WHERE id NOT IN (
        SELECT id FROM (
            SELECT id FROM platform_stats 
            ORDER BY generated_at DESC 
            LIMIT 100
        ) as keep_stats
    );
    
    -- Nettoyer les snapshots de plus de 30 jours
    DELETE FROM group_stats_snapshot WHERE generated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    DELETE FROM user_stats_snapshot WHERE generated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    SELECT 'Nettoyage des anciennes statistiques terminÃ©' as message, NOW() as cleaned_at;
END$$

CREATE PROCEDURE CleanupExpiredSessions()
BEGIN
    -- Marquer les sessions expirÃ©es comme inactives
    UPDATE user_sessions 
    SET is_active = 0, logout_at = NOW()
    WHERE is_active = 1 
      AND expires_at < NOW();
      
    -- Supprimer les anciennes sessions (plus de 30 jours)
    DELETE FROM user_sessions 
    WHERE logout_at < NOW() - INTERVAL 30 DAY
       OR (is_active = 0 AND login_at < NOW() - INTERVAL 30 DAY);
       
    SELECT ROW_COUNT() as cleaned_sessions;
END$$
DELIMITER ;


-- Phase 2 â€” PropriÃ©tÃ©s VEVENT manquantes
-- Items : 2.1 CATEGORIES, 2.2 PRIORITY, 2.3 CLASS, 2.4 TRANSP,
--          2.5 URL/GEO, 2.6 ATTACH
-- Cible : table calendar_events

ALTER TABLE calendar_events
    -- 2.2 PRIORITY : RFC 5545 Â§3.8.1.9 â€” 0=non dÃ©fini, 1=haute, 5=normale, 9=basse
    ADD COLUMN priority TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'RFC 5545 PRIORITY: 0=non dÃ©fini, 1=haute, 5=normale, 9=basse'
        AFTER uid,

    -- 2.3 CLASS : confidentialitÃ© de l'Ã©vÃ©nement
    ADD COLUMN class ENUM('PUBLIC','PRIVATE','CONFIDENTIAL') NOT NULL DEFAULT 'PUBLIC'
        COMMENT 'RFC 5545 CLASS'
        AFTER priority,

    -- 2.4 TRANSP : indique si l'Ã©vÃ©nement bloque le temps libre
    ADD COLUMN transp ENUM('OPAQUE','TRANSPARENT') NOT NULL DEFAULT 'OPAQUE'
        COMMENT 'RFC 5545 TRANSP â€” OPAQUE bloque le temps libre'
        AFTER class,

    -- 2.1 CATEGORIES : tableau JSON de chaÃ®nes (ex. ["Travail","RÃ©union"])
    ADD COLUMN categories JSON NULL
        COMMENT 'RFC 5545 CATEGORIES â€” tableau de chaÃ®nes'
        AFTER transp,

    -- 2.5 GEO latitude
    ADD COLUMN geo_lat DECIMAL(10,7) NULL
        COMMENT 'RFC 5545 GEO latitude'
        AFTER categories,

    -- 2.5 GEO longitude
    ADD COLUMN geo_lng DECIMAL(10,7) NULL
        COMMENT 'RFC 5545 GEO longitude'
        AFTER geo_lat,

    -- 2.6 ATTACH : [{url, encoding, mime_type}] ou [{data_base64, mime_type}]
    ADD COLUMN attachments JSON NULL
        COMMENT 'RFC 5545 ATTACH â€” [{url, mime_type}] ou [{data_base64, mime_type}]'
        AFTER geo_lng;


-- Phase 3 â€” ATTENDEE & ORGANIZER complets
-- Item 3.2 : colonnes ORGANIZER optionnelles sur calendar_events
-- Permet de surcharger l'organisateur dÃ©duit du user_id de l'Ã©vÃ©nement.

ALTER TABLE calendar_events
    -- Adresse email de l'organisateur (override de l'email du user_id propriÃ©taire)
    ADD COLUMN organizer_email VARCHAR(255) NULL
        COMMENT 'RFC 5545 ORGANIZER â€” email (override du user_id propriÃ©taire)'
        AFTER attachments,

    -- Nom affichÃ© de l'organisateur (CN)
    ADD COLUMN organizer_name VARCHAR(255) NULL
        COMMENT 'RFC 5545 ORGANIZER CN â€” nom affichÃ©'
        AFTER organizer_email;

-- Phase 4 â€” RÃ©currence avancÃ©e & VALARM
-- Items 4.2 (RDATE), 4.3 (RELATED-TO), 4.5 (DURATION)
-- Note : VALARM (4.4) est dÃ©rivÃ© de la colonne notifications existante â€” aucune colonne supplÃ©mentaire.
-- Note : EXDATE (4.1) est dÃ©rivÃ© de event_occurrences.is_cancelled â€” aucune colonne supplÃ©mentaire.

ALTER TABLE calendar_events

    -- 4.2 â€” RDATE : dates additionnelles (ISO datetimes locales sÃ©parÃ©es par virgule)
    ADD COLUMN rdate TEXT NULL
        COMMENT 'RFC 5545 RDATE â€” dates additionnelles (ISO datetimes CSV, ex: 2026-04-15 14:00:00,2026-04-22 14:00:00)'
        AFTER organizer_name,

    -- 4.3 â€” RELATED-TO : UID de l'Ã©vÃ©nement parent (lien hiÃ©rarchique RFC 5545 Â§3.8.4.5)
    ADD COLUMN related_to VARCHAR(255) NULL
        COMMENT 'RFC 5545 RELATED-TO â€” UID de l Ã©vÃ©nement parent'
        AFTER rdate,

    -- 4.5 â€” DURATION : durÃ©e ISO 8601, exclusif avec DTEND (RFC 5545 Â§3.8.2.5)
    ADD COLUMN duration VARCHAR(20) NULL
        COMMENT 'RFC 5545 DURATION â€” format ISO 8601 (ex: PT1H30M) â€” si dÃ©fini, DTEND n est pas exportÃ©'
        AFTER related_to;

-- Phase 5 â€” Composants CalDAV additionnels : VTODO + VJOURNAL
-- Date : 2026-04-01
-- RÃ©fÃ©rence plan : items 5.1 et 5.2

-- ============================================================
-- 5.1 â€” VTODO
-- ============================================================
CREATE TABLE IF NOT EXISTS calendar_todos (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    calendar_id     INT(11) NOT NULL,
    user_id         INT(11) NOT NULL,
    uid             VARCHAR(255) NOT NULL UNIQUE COMMENT 'RFC 5545 Â§3.8.4.7 â€” UUID v4',
    title           VARCHAR(255) NOT NULL,
    description     TEXT,
    due             DATETIME      DEFAULT NULL COMMENT 'DUE : date limite',
    dtstart         DATETIME      DEFAULT NULL COMMENT 'DTSTART optionnel',
    completed       DATETIME      DEFAULT NULL COMMENT 'COMPLETED : horodatage complÃ©tion',
    status          ENUM('NEEDS-ACTION','IN-PROCESS','COMPLETED','CANCELLED')
                    NOT NULL DEFAULT 'NEEDS-ACTION',
    priority        TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '0=indÃ©fini 1=haute 5=normale 9=basse',
    percent_complete TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100',
    location        VARCHAR(255)  DEFAULT NULL,
    categories      JSON          DEFAULT NULL,
    url             VARCHAR(2083) DEFAULT NULL,
    related_to      VARCHAR(255)  DEFAULT NULL COMMENT 'UID parent',
    recurrence_rule VARCHAR(255)  DEFAULT NULL COMMENT 'RRULE RFC 5545 Â§3.8.5.4',
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
    INDEX idx_calendar_todos_deleted_at   (deleted_at),
    CONSTRAINT fk_calendar_todos_calendar FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
    CONSTRAINT fk_calendar_todos_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5.2 â€” VJOURNAL
-- ============================================================
CREATE TABLE IF NOT EXISTS calendar_journals (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    calendar_id     INT(11) NOT NULL,
    user_id         INT(11) NOT NULL,
    uid             VARCHAR(255) NOT NULL UNIQUE COMMENT 'RFC 5545 Â§3.8.4.7 â€” UUID v4',
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
    INDEX idx_calendar_journals_deleted_at  (deleted_at),
    CONSTRAINT fk_calendar_journals_calendar FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
    CONSTRAINT fk_calendar_journals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Migration 001 : table pomo_engagements
-- Phase 1A â€” Engagement MVP (waitlist + sondage)
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

-- Note : unicitÃ© courriel pour waitlist gÃ©rÃ©e en application
-- MySQL ne supporte pas les partial unique indexes (WHERE type = 'waitlist')


-- Migration : ajout du champ is_all_day sur calendar_todos
-- Date : 2026-04-04
-- RÃ©fÃ©rence : Phase 5.1 â€” VTODO journÃ©e entiÃ¨re

ALTER TABLE calendar_todos
    ADD COLUMN is_all_day TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'JournÃ©e entiÃ¨re : 1 = oui, 0 = non'
    AFTER timezone;

-- Migration 001 : tables de base du plugin Quiz
-- Phase 1 â€” MVP REST

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- -------------------------------------------------------
-- quiz_quizzes
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_quizzes` (
    `id`          INT           NOT NULL AUTO_INCREMENT,
    `user_id`     INT           NOT NULL COMMENT 'FK users.id (hÃ´te)',
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
    `value`            TEXT     NOT NULL COMMENT 'choice_id ou valeur numÃ©rique',
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

-- Migration : paramÃ¨tres de quiz (visibilitÃ© rÃ©sultats + mode temps)
-- Date : 2026-04-05

ALTER TABLE `quiz_quizzes`
    ADD COLUMN `result_visibility` ENUM('immediate','simultaneous','end_only')
        NOT NULL DEFAULT 'immediate'
        COMMENT 'Quand les joueurs voient leur rÃ©sultat par question',
    ADD COLUMN `time_mode` ENUM('per_question','total','unlimited')
        NOT NULL DEFAULT 'per_question'
        COMMENT 'Mode de gestion du temps : par question, total quiz, ou illimitÃ©',
    ADD COLUMN `total_time_sec` INT NULL DEFAULT NULL
        COMMENT 'DurÃ©e totale du quiz en secondes (time_mode=total uniquement)',
    ADD COLUMN `show_leaderboard` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = afficher classement aux joueurs en fin de partie',
    ADD COLUMN `show_question_to_player` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = afficher la question au joueur (sinon vue hÃ´te uniquement)';


-- Migration 001 : tables de base du plugin Puzzle
-- Phases 1â€“4 (auth appareil, banque d'images, thÃ¨mes, sauvegarde, partagÃ©s)

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- -------------------------------------------------------
-- android_devices
-- Devices Android par app (v2.7.0)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `android_devices` (
    `id`               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`          INT(11)          NULL,
    `app_id`           VARCHAR(64)      NOT NULL,
    `device_uuid`      VARCHAR(64)      NOT NULL,
    `device_token`     VARCHAR(256)     NOT NULL,
    `token_expires_at` DATETIME         NOT NULL,
    `last_seen_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_replaced_at` DATE             NULL DEFAULT NULL,
    `backup_json`      MEDIUMTEXT       NULL,
    `backup_saved_at`  DATETIME         NULL,
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device` (`app_id`, `device_uuid`),
    KEY `idx_user_app` (`user_id`, `app_id`),
    CONSTRAINT `fk_ad_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- web_devices
-- Devices Web/Windows par app (v2.7.0)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `web_devices` (
    `id`               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`          INT(11)          NULL,
    `app_id`           VARCHAR(64)      NOT NULL,
    `device_uuid`      VARCHAR(64)      NOT NULL,
    `device_token`     VARCHAR(256)     NOT NULL,
    `token_expires_at` DATETIME         NOT NULL,
    `last_seen_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_replaced_at` DATE             NULL DEFAULT NULL,
    `backup_json`      MEDIUMTEXT       NULL,
    `backup_saved_at`  DATETIME         NULL,
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wd_device` (`app_id`, `device_uuid`),
    KEY `idx_wd_user_app` (`user_id`, `app_id`),
    CONSTRAINT `fk_wd_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- app_user_settings
-- Pseudonymes par (user_id, app_id) (v2.7.0)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_user_settings` (
    `user_id`   INT(11)      NOT NULL,
    `app_id`    VARCHAR(64)  NOT NULL,
    `pseudonym` VARCHAR(64)  NULL,
    PRIMARY KEY (`user_id`, `app_id`),
    UNIQUE KEY `uq_pseudo_app` (`app_id`, `pseudonym`),
    CONSTRAINT `fk_aus_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- -------------------------------------------------------
-- puzzle_images
-- Banque d'images servies Ã  l'app (labels dans puzzle_image_translations)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `puzzle_images` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `uid`          VARCHAR(36)   NOT NULL COMMENT 'UUID exposÃ© Ã  l\'app',
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
-- ThÃ¨mes associÃ©s aux images (labels dans puzzle_theme_translations)
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
-- Labels multilingues des thÃ¨mes
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
-- Association many-to-many image â†” thÃ¨me
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
-- Casse-tetes partages entre deux utilisateurs abonnes (v2.7.0)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `puzzle_shared` (
    `id`               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `shared_uid`       VARCHAR(36)       NOT NULL COMMENT 'UUID expose a l''app',
    `image_id`         INT UNSIGNED      NOT NULL,
    `piece_count`      SMALLINT UNSIGNED NOT NULL,
    `seed`             INT UNSIGNED      NULL     COMMENT 'NULL si initial_pieces fourni',
    `creator_id`       INT(11)           NOT NULL COMMENT 'FK users.id',
    `partner_id`       INT(11)           NOT NULL COMMENT 'FK users.id',
    `completion`       TINYINT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Pourcentage 0-100',
    `status`           ENUM('active','archived','complete') NOT NULL DEFAULT 'active',
    `created_at`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_activity_at` DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_shared_uid` (`shared_uid`),
    INDEX `idx_creator` (`creator_id`, `status`),
    INDEX `idx_partner` (`partner_id`, `status`),
    CONSTRAINT `fk_shared_image`
        FOREIGN KEY (`image_id`)   REFERENCES `puzzle_images` (`id`),
    CONSTRAINT `fk_shared_creator`
        FOREIGN KEY (`creator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_shared_partner`
        FOREIGN KEY (`partner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- -------------------------------------------------------
-- puzzle_shared_pieces
-- Etat courant de chaque piece (v2.7.0)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `puzzle_shared_pieces` (
    `shared_id`  INT UNSIGNED      NOT NULL,
    `piece_id`   SMALLINT UNSIGNED NOT NULL,
    `state`      ENUM('tray','floating','locked','held') NOT NULL DEFAULT 'tray',
    `x`          FLOAT             NULL DEFAULT NULL,
    `y`          FLOAT             NULL DEFAULT NULL,
    `rotation`   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-3 quarts de tour',
    `held_by_id` INT(11)           NULL DEFAULT NULL COMMENT 'FK users.id',
    `prev_state` ENUM('tray','floating') NOT NULL DEFAULT 'tray',
    `held_at`    DATETIME          NULL DEFAULT NULL,
    `by_id`      INT(11)           NULL DEFAULT NULL COMMENT 'FK users.id',
    PRIMARY KEY (`shared_id`, `piece_id`),
    CONSTRAINT `fk_pieces_shared`
        FOREIGN KEY (`shared_id`) REFERENCES `puzzle_shared` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pieces_held_by`
        FOREIGN KEY (`held_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_pieces_by`
        FOREIGN KEY (`by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- -------------------------------------------------------
-- puzzle_shared_events
-- Journal des mouvements pour le polling (v2.7.0)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `puzzle_shared_events` (
    `id`         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `shared_id`  INT UNSIGNED      NOT NULL,
    `device_id`  INT(11)           NULL DEFAULT NULL COMMENT 'user_id (users.id) - nomme device_id pour compat. historique',
    `piece_id`   SMALLINT UNSIGNED NOT NULL,
    `state`      ENUM('tray','floating','locked','held') NOT NULL DEFAULT 'floating',
    `x`          FLOAT             NULL DEFAULT NULL,
    `y`          FLOAT             NULL DEFAULT NULL,
    `rotation`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `held_by_id` INT(11)           NULL DEFAULT NULL,
    `by_id`      INT(11)           NULL DEFAULT NULL,
    `created_at` DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_events_poll` (`shared_id`, `id`),
    CONSTRAINT `fk_events_shared`
        FOREIGN KEY (`shared_id`) REFERENCES `puzzle_shared` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_events_device`
        FOREIGN KEY (`device_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_events_held_by`
        FOREIGN KEY (`held_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_events_by`
        FOREIGN KEY (`by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET foreign_key_checks = 1;

-- playstore_subscriptions -- v2.7.0
-- device_uuid = obfuscatedExternalAccountId Google Play
CREATE TABLE IF NOT EXISTS `playstore_subscriptions` (
    `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `device_uuid`    VARCHAR(64)      NOT NULL,
    `app_id`         VARCHAR(64)      NOT NULL,
    `purchase_token` VARCHAR(512)     NOT NULL,
    `product_id`     VARCHAR(128)     NOT NULL,
    `status`         ENUM('active','expired','cancelled','pending') NOT NULL DEFAULT 'pending',
    `expires_at`     DATETIME         NULL,
    `verified_at`    DATETIME         NULL,
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device_app`      (`device_uuid`, `app_id`),
    KEY        `idx_purchase_token` (`purchase_token`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- stripe_subscriptions -- v2.7.0
CREATE TABLE IF NOT EXISTS `stripe_subscriptions` (
    `id`                     BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`                INT(11)          NOT NULL,
    `app_id`                 VARCHAR(64)      NOT NULL,
    `stripe_customer_id`     VARCHAR(64)      NOT NULL,
    `stripe_subscription_id` VARCHAR(64)      NULL,
    `plan`                   VARCHAR(64)      NOT NULL,
    `status`                 ENUM('active','trialing','past_due','cancelled','expired') NOT NULL DEFAULT 'active',
    `is_trial`               TINYINT(1)       NOT NULL DEFAULT 0,
    `trial_end`              DATETIME         NULL,
    `expires_at`             DATETIME         NULL,
    `cancel_at_period_end`   TINYINT(1)       NOT NULL DEFAULT 0,
    `created_at`             DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_app`     (`user_id`, `app_id`),
    KEY        `idx_stripe_sub`  (`stripe_subscription_id`),
    KEY        `idx_stripe_cust` (`stripe_customer_id`),
    CONSTRAINT `fk_ss_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Migration : SystÃ¨me de notifications email
-- Date : 2026-03-22
-- Description : CrÃ©e la table de queue des notifications email et ajoute
--               les prÃ©fÃ©rences de notification aux utilisateurs.

-- ============================================================
-- Table : email_notification_queue
-- ============================================================
CREATE TABLE IF NOT EXISTS `email_notification_queue` (
    `id`              INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT          NOT NULL COMMENT 'FK users.id',
    `event_id`        INT          NOT NULL COMMENT 'FK calendar_events.id',
    `calendar_id`     INT          NOT NULL COMMENT 'FK calendars.id',
    `occurrence_key`  VARCHAR(120) NOT NULL COMMENT 'Format : eventId_recurrIdx_date (ex: 17_0_2026-03-25)',
    `fire_at`         DATETIME     NOT NULL COMMENT 'Heure d envoi en UTC',
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
    -- Index composite utilisÃ© par le cron : rÃ©cupÃ©rer les notifications pendantes Ã  envoyer
    INDEX idx_enq_pending  (`status`, `fire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Colonnes de prÃ©fÃ©rences de notifications dans la table users
-- ============================================================
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `email_notifications_enabled`
        TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = notifications email activÃ©es, 0 = suspendues (R4)',
    ADD COLUMN IF NOT EXISTS `notification_email`
        VARCHAR(255) NULL
        COMMENT 'Email alternatif pour les rappels (null = utiliser users.email)';

-- ============================================================
-- v2.3.0 â€” Plugin Items Manager
-- ============================================================

CREATE TABLE IF NOT EXISTS `items` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner_user_id` INT(11)      NOT NULL                  COMMENT 'FK vers users.id',
    `access`        ENUM('private','public','share')
                    NOT NULL DEFAULT 'private'             COMMENT 'private=owner+admin, public=tous, share=liste explicite',
    `categories`    JSON         NULL                      COMMENT 'Tableau JSON de chaÃ®nes ex. ["a","b"]',
    `json_item`     LONGTEXT     NULL                      COMMENT 'Blob JSON arbitraire',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`    DATETIME     NULL                      COMMENT 'Soft-delete',
    PRIMARY KEY (`id`),
    INDEX `idx_items_owner`   (`owner_user_id`),
    INDEX `idx_items_access`  (`access`),
    INDEX `idx_items_deleted` (`deleted_at`),
    CONSTRAINT `fk_items_owner`
        FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `item_user_access` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `item_id`    INT UNSIGNED NOT NULL                    COMMENT 'FK vers items.id',
    `user_id`    INT(11)      NOT NULL                    COMMENT 'FK vers users.id',
    `can_update` TINYINT(1)   NOT NULL DEFAULT 0          COMMENT '0=lecture seule, 1=lecture+Ã©criture',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_item_user` (`item_id`, `user_id`),
    INDEX `idx_iua_user` (`user_id`),
    CONSTRAINT `fk_iua_item`
        FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_iua_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Migration 20260508 : Stripe webhook idempotency
-- ============================================================
CREATE TABLE IF NOT EXISTS stripe_processed_events (
    event_id     VARCHAR(255) NOT NULL,
    event_type   VARCHAR(100) NOT NULL,
    processed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- v2.8.0 — Module traque (gamification géolocalisée)
-- ============================================================

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
  `biome`            ENUM('forest','peak','water','cemetery','worship','industrial','urban') NOT NULL DEFAULT 'urban',
  `is_boss`          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'boss MJ : niveau fixe, ignore scaling',
  `special_attack`   ENUM('none','poison','spell') NOT NULL DEFAULT 'none',
  `save_dc`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `save_stat`        ENUM('con','sag') NOT NULL DEFAULT 'con',
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
  CONSTRAINT `fk_cs_player`  FOREIGN KEY (`player_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
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
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `player_id`     INT(11) NOT NULL,
  `session_id`    INT UNSIGNED NOT NULL,
  `monster_id`    INT UNSIGNED NOT NULL,
  `monster_name`  VARCHAR(100) NOT NULL COMMENT 'snapshot nom au moment du combat',
  `monster_level` TINYINT UNSIGNED NOT NULL,
  `outcome`       ENUM('victory','defeat','fled') NOT NULL,
  `xp_earned`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `occurred_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_pj_player`  FOREIGN KEY (`player_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pj_session` FOREIGN KEY (`session_id`) REFERENCES `traque_combat_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- traque_achievements
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `traque_achievements` (
  `id`              SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug`            VARCHAR(60) NOT NULL UNIQUE,
  `title_fr`        VARCHAR(100) NOT NULL,
  `description_fr`  VARCHAR(255) NOT NULL,
  `icon_key`        VARCHAR(60) NOT NULL,
  `condition_type`  ENUM(
    'first_kill','kills_total','kills_biome','level_reached',
    'monsters_unique','nocturnal_kills','elusive_kills','flee_count'
  ) NOT NULL,
  `condition_value` INT UNSIGNED NOT NULL DEFAULT 1,
  `condition_meta`  VARCHAR(100) NULL COMMENT 'ex: biome=forest pour kills_biome'
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
  `character_name`         VARCHAR(50) NOT NULL DEFAULT '',
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
  `rest_available_at`      DATETIME NULL DEFAULT NULL COMMENT 'Cooldown repos actif — NULL = disponible maintenant',
  `last_combat_at`         DATETIME NULL DEFAULT NULL COMMENT 'Timestamp du dernier combat pour régén passive 1 HP/5 min',
  `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_traque_players_character_name` (`character_name`),
  CONSTRAINT `fk_tp_player` FOREIGN KEY (`player_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- traque_player_bestiary
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `traque_player_bestiary` (
  `player_id`     INT(11) NOT NULL,
  `monster_id`    INT UNSIGNED NOT NULL,
  `kills`         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
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
-- Seed — traque_achievements
-- ============================================================
INSERT IGNORE INTO `traque_achievements`
  (`slug`, `title_fr`, `description_fr`, `icon_key`, `condition_type`, `condition_value`, `condition_meta`)
VALUES
  ('first_blood',    'Premier sang',          'Vaincre votre premier monstre.',         'sword_red',    'first_kill',     1,  NULL),
  ('warrior_10',     'Guerrier aguerri',       'Vaincre 10 monstres.',                   'shield_gold',  'kills_total',    10, NULL),
  ('forest_hunter',  'Chasseur des bois',      'Vaincre 5 monstres dans la forêt.',      'bow_green',    'kills_biome',    5,  'biome=forest'),
  ('night_stalker',  'Rôdeur nocturne',        'Vaincre 3 monstres la nuit.',            'moon_silver',  'nocturnal_kills',3,  NULL),
  ('shadow_tracker', 'Traqueur de l''ombre',  'Vaincre un monstre élusif.',             'shadow_blade', 'elusive_kills',  1,  NULL),
  ('level_5',        'Aventurier',             'Atteindre le niveau 5.',                 'star_bronze',  'level_reached',  5,  NULL),
  ('level_10',       'Héros',                  'Atteindre le niveau 10.',                'star_gold',    'level_reached',  10, NULL);

-- ============================================================
-- Seed — monsters (zone Montréal ≈ 45.50, -73.60)
-- ============================================================
INSERT IGNORE INTO `monsters`
  (`id`, `name`, `asset_key`, `lore`, `level_base`, `lat`, `lng`, `hp_max`, `hp_current`, `ac`, `damage_dice`, `xp_reward`, `is_alive`, `behavior_type`, `move_radius_m`, `biome`, `special_attack`, `save_dc`, `save_stat`)
VALUES
  (1, 'Gobelin des rues', 'goblin',      'Une vermine urbaine au couteau rouillé.',                     1,  45.5012300, -73.6234500,   8,   8,  9, '1d6',    20, 1, 'static',  0,   'urban',     'none', 0,  'con'),
  (2, 'Loup-garou',       'werewolf',    'Créature nocturne issue des forêts maudites du nord.',        5,  45.5067800, -73.6189000,  52,  52, 13, '2d6+2', 150, 1, 'elusive', 300, 'forest',    'none', 0,  'con'),
  (3, 'Spectre',          'specter',     'Âme tourmentée condamnée à errer entre les tombes.',          3,  45.4998700, -73.6301200,  22,  22, 12, '1d8+1',  75, 1, 'patrol',  150, 'cemetery',  'none', 0,  'con'),
  (4, 'Golem de pierre',  'stone_golem', 'Automate de granit forgé dans les entrailles de l''usine.',  8,  45.5089400, -73.6145600,  88,  88, 16, '2d8+3', 280, 1, 'static',  0,   'industrial','none', 0,  'con'),
  (5, 'Dragon juvénile',  'young_dragon','Un dragon en pleine croissance, déjà redoutable.',            12, 45.4967300, -73.6267800, 144, 144, 18, '3d8+4', 600, 1, 'roam',    500, 'peak',      'none', 0,  'con'),
  (6, 'Naga',             'naga',        'Serpent immortel dont la morsure empoisonne le sang.',        4,  45.5031200, -73.6201000,  28,  28, 13, '1d8',   120, 1, 'patrol',  200, 'water',     'poison',12,'con'),
  (7, 'Ratman',           'ratman',      'Hybride vermine-humain porteur de fièvres mortelles.',        2,  45.5055000, -73.6245000,  14,  14, 10, '1d6',    40, 1, 'roam',    100, 'urban',     'poison',10,'con'),
  (8, 'Liche',            'liche',       'Nécromancien ascendé, maître des sorts de détresse mentale.', 7, 45.4985000, -73.6188000,  56,  56, 15, '1d10',  250, 1, 'static',  0,   'cemetery',  'spell', 14,'sag');

-- ============================================================
-- v2.8.0 — Tags pour quiz_questions
-- ============================================================
CREATE TABLE IF NOT EXISTS `quiz_question_tag_relations` (
  `quiz_question_id` int(11) NOT NULL,
  `tag_id`           int(11) NOT NULL,
  `created_at`       timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at`       timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at`       datetime DEFAULT NULL,
  PRIMARY KEY (`quiz_question_id`, `tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `fk_qqtr_question` FOREIGN KEY (`quiz_question_id`) REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qqtr_tag`      FOREIGN KEY (`tag_id`)           REFERENCES `tags` (`id`)          ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
