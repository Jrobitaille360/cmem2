-- ============================================================
-- COLLECTIVE MEMORIES — Structure de la base de données
-- ============================================================
-- Ce fichier contient UNIQUEMENT la structure (DDL).
-- Les données de démarrage sensibles (utilisateurs) sont dans
-- docs/seed_users.sql (ignoré par git).
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- ============================================================
-- VUES — Doublures de structure (requises avant les vraies vues)
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
  `sequence` int(11) DEFAULT 0 COMMENT 'Numéro de séquence CalDAV',
  `last_modified` timestamp NULL DEFAULT NULL COMMENT 'Dernière modification CalDAV'
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
  `permission` enum('read','write') DEFAULT 'read',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
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
  `media_type` enum('text','audio','video','image','gpx','summary','event','todo','document') DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
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
-- plans + données de référence (non sensibles)
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
(2, 'bronze',  'Plan Bronze',  'Plan bronze avec fonctionnalités essentielles',    9.99,  'EUR', 30, 100,  '{"scopes":["read","write"],"max_requests_per_day":10000,"expires_in_days":null,"email_support":true,"priority_support":false}', 1, '2025-12-04 13:49:57', '2025-12-04 13:49:57'),
(3, 'argent',  'Plan Argent',  'Plan argent avec fonctionnalités avancées',        19.99, 'EUR', 30, 300,  '{"scopes":["read","write","delete"],"max_requests_per_day":50000,"expires_in_days":null,"email_support":true,"priority_support":true,"webhook_support":true}', 1, '2025-12-04 13:49:57', '2025-12-04 13:49:57'),
(4, 'platine', 'Plan Platine', 'Plan platine avec toutes les fonctionnalités premium', 49.99, 'EUR', 30, 1000, '{"scopes":["read","write","delete","admin"],"max_requests_per_day":"unlimited","expires_in_days":null,"email_support":true,"priority_support":true,"webhook_support":true,"custom_integrations":true,"dedicated_support":true}', 1, '2025-12-04 13:49:57', '2025-12-04 13:49:57');

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
  `table_associate` enum('groups','memories','elements','files','all') DEFAULT NULL,
  `color` varchar(7) DEFAULT '#3498db',
  `tag_owner` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- users  (structure seulement — données dans docs/seed_users.sql)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('ADMINISTRATEUR','UTILISATEUR') NOT NULL DEFAULT 'UTILISATEUR',
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
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
-- VUES RÉELLES
-- ============================================================

DROP TABLE IF EXISTS `active_user_sessions`;
DROP VIEW IF EXISTS `active_user_sessions`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `active_user_sessions` AS
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
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `user_sessions_stats` AS
  SELECT COUNT(0) AS `total_active_sessions`,
    COUNT(DISTINCT `active_user_sessions`.`user_id`) AS `unique_users_online`,
    AVG(TIMESTAMPDIFF(MINUTE, `active_user_sessions`.`login_at`, IFNULL(`active_user_sessions`.`logout_at`, CURRENT_TIMESTAMP()))) AS `avg_session_duration_minutes`,
    COUNT(CASE WHEN `active_user_sessions`.`last_activity_at` > CURRENT_TIMESTAMP() - INTERVAL 5 MINUTE THEN 1 END) AS `active_last_5min`,
    COUNT(CASE WHEN `active_user_sessions`.`last_activity_at` > CURRENT_TIMESTAMP() - INTERVAL 30 MINUTE THEN 1 END) AS `active_last_30min`,
    COUNT(CASE WHEN `active_user_sessions`.`login_at` > CURRENT_TIMESTAMP() - INTERVAL 1 DAY THEN 1 END) AS `sessions_today`
  FROM `active_user_sessions`;

DROP TABLE IF EXISTS `v_admin_dashboard`;
DROP VIEW IF EXISTS `v_admin_dashboard`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_admin_dashboard` AS
  SELECT
    (SELECT COUNT(0) FROM `users` WHERE `deleted_at` IS NULL) AS `total_users`,
    (SELECT COUNT(0) FROM `users` WHERE `deleted_at` IS NULL AND `last_login` >= CURRENT_TIMESTAMP() - INTERVAL 7 DAY) AS `active_users_7d`,
    (SELECT COUNT(0) FROM `groups` WHERE `deleted_at` IS NULL) AS `total_groups`,
    (SELECT COUNT(0) FROM `tags` WHERE `deleted_at` IS NULL) AS `total_tags`,
    (SELECT COUNT(0) FROM `files`) AS `total_files`,
    (SELECT ROUND(COALESCE(SUM(`file_size`), 0) / 1024 / 1024, 2) FROM `files`) AS `total_storage_mb`,
    (SELECT COUNT(0) FROM `group_invitations` WHERE `status` = 'pending' AND (`expires_at` IS NULL OR `expires_at` > CURRENT_TIMESTAMP())) AS `pending_invitations`;

-- ============================================================
-- CONTRAINTES (CLÉS ÉTRANGÈRES)
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
  ADD CONSTRAINT `calendar_shares_ibfk_2` FOREIGN KEY (`shared_with_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

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
    `code_hash`    VARCHAR(255) NOT NULL COMMENT 'bcrypt du code à 6 chiffres',
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
    `device_id`    VARCHAR(128) NOT NULL COMMENT 'UUID stable côté client',
    `device_name`  VARCHAR(255) NOT NULL DEFAULT 'Appareil inconnu',
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
    INDEX `idx_device_expires`   (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- jwt_blacklist
CREATE TABLE IF NOT EXISTS `jwt_blacklist` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `jti`        VARCHAR(36)  NOT NULL COMMENT 'UUID v4 du token révoqué',
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
-- PROCÉDURES STOCKÉES
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

-- ===== Procédure pour générer les statistiques globales =====
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

-- ===== Procédure pour générer les statistiques par groupe =====
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

-- ===== Procédure pour générer les statistiques par utilisateur =====
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

-- ===== Procédure de nettoyage des anciennes statistiques =====
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
    
    SELECT 'Nettoyage des anciennes statistiques terminé' as message, NOW() as cleaned_at;
END$$

CREATE OR REPLACE PROCEDURE CleanupExpiredSessions()
BEGIN
    -- Marquer les sessions expirées comme inactives
    UPDATE user_sessions 
    SET is_active = 0, logout_at = NOW()
    WHERE is_active = 1 
      AND expires_at < NOW();
      
    -- Supprimer les anciennes sessions (plus de 30 jours)
    DELETE FROM user_sessions 
    WHERE logout_at < NOW() - INTERVAL 30 DAY
       OR (is_active = 0 AND login_at < NOW() - INTERVAL 30 DAY);
       
    SELECT ROW_COUNT() as cleaned_sessions;
END //
DELIMITER ;


-- Phase 2 — Propriétés VEVENT manquantes
-- Items : 2.1 CATEGORIES, 2.2 PRIORITY, 2.3 CLASS, 2.4 TRANSP,
--          2.5 URL/GEO, 2.6 ATTACH
-- Cible : table calendar_events

ALTER TABLE calendar_events
    -- 2.2 PRIORITY : RFC 5545 §3.8.1.9 — 0=non défini, 1=haute, 5=normale, 9=basse
    ADD COLUMN priority TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'RFC 5545 PRIORITY: 0=non défini, 1=haute, 5=normale, 9=basse'
        AFTER uid,

    -- 2.3 CLASS : confidentialité de l'événement
    ADD COLUMN class ENUM('PUBLIC','PRIVATE','CONFIDENTIAL') NOT NULL DEFAULT 'PUBLIC'
        COMMENT 'RFC 5545 CLASS'
        AFTER priority,

    -- 2.4 TRANSP : indique si l'événement bloque le temps libre
    ADD COLUMN transp ENUM('OPAQUE','TRANSPARENT') NOT NULL DEFAULT 'OPAQUE'
        COMMENT 'RFC 5545 TRANSP — OPAQUE bloque le temps libre'
        AFTER class,

    -- 2.1 CATEGORIES : tableau JSON de chaînes (ex. ["Travail","Réunion"])
    ADD COLUMN categories JSON NULL
        COMMENT 'RFC 5545 CATEGORIES — tableau de chaînes'
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
        COMMENT 'RFC 5545 ATTACH — [{url, mime_type}] ou [{data_base64, mime_type}]'
        AFTER geo_lng;


-- Phase 3 — ATTENDEE & ORGANIZER complets
-- Item 3.2 : colonnes ORGANIZER optionnelles sur calendar_events
-- Permet de surcharger l'organisateur déduit du user_id de l'événement.

ALTER TABLE calendar_events
    -- Adresse email de l'organisateur (override de l'email du user_id propriétaire)
    ADD COLUMN organizer_email VARCHAR(255) NULL
        COMMENT 'RFC 5545 ORGANIZER — email (override du user_id propriétaire)'
        AFTER attachments,

    -- Nom affiché de l'organisateur (CN)
    ADD COLUMN organizer_name VARCHAR(255) NULL
        COMMENT 'RFC 5545 ORGANIZER CN — nom affiché'
        AFTER organizer_email;

-- Phase 4 — Récurrence avancée & VALARM
-- Items 4.2 (RDATE), 4.3 (RELATED-TO), 4.5 (DURATION)
-- Note : VALARM (4.4) est dérivé de la colonne notifications existante — aucune colonne supplémentaire.
-- Note : EXDATE (4.1) est dérivé de event_occurrences.is_cancelled — aucune colonne supplémentaire.

ALTER TABLE calendar_events

    -- 4.2 — RDATE : dates additionnelles (ISO datetimes locales séparées par virgule)
    ADD COLUMN rdate TEXT NULL
        COMMENT 'RFC 5545 RDATE — dates additionnelles (ISO datetimes CSV, ex: 2026-04-15 14:00:00,2026-04-22 14:00:00)'
        AFTER organizer_name,

    -- 4.3 — RELATED-TO : UID de l'événement parent (lien hiérarchique RFC 5545 §3.8.4.5)
    ADD COLUMN related_to VARCHAR(255) NULL
        COMMENT 'RFC 5545 RELATED-TO — UID de l événement parent'
        AFTER rdate,

    -- 4.5 — DURATION : durée ISO 8601, exclusif avec DTEND (RFC 5545 §3.8.2.5)
    ADD COLUMN duration VARCHAR(20) NULL
        COMMENT 'RFC 5545 DURATION — format ISO 8601 (ex: PT1H30M) — si défini, DTEND n est pas exporté'
        AFTER related_to;

-- Phase 5 — Composants CalDAV additionnels : VTODO + VJOURNAL
-- Date : 2026-04-01
-- Référence plan : items 5.1 et 5.2

-- ============================================================
-- 5.1 — VTODO
-- ============================================================
CREATE TABLE IF NOT EXISTS calendar_todos (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    calendar_id     INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    uid             VARCHAR(255) NOT NULL UNIQUE COMMENT 'RFC 5545 §3.8.4.7 — UUID v4',
    title           VARCHAR(255) NOT NULL,
    description     TEXT,
    due             DATETIME      DEFAULT NULL COMMENT 'DUE : date limite',
    dtstart         DATETIME      DEFAULT NULL COMMENT 'DTSTART optionnel',
    completed       DATETIME      DEFAULT NULL COMMENT 'COMPLETED : horodatage complétion',
    status          ENUM('NEEDS-ACTION','IN-PROCESS','COMPLETED','CANCELLED')
                    NOT NULL DEFAULT 'NEEDS-ACTION',
    priority        TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '0=indéfini 1=haute 5=normale 9=basse',
    percent_complete TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100',
    location        VARCHAR(255)  DEFAULT NULL,
    categories      JSON          DEFAULT NULL,
    url             VARCHAR(2083) DEFAULT NULL,
    related_to      VARCHAR(255)  DEFAULT NULL COMMENT 'UID parent',
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
    INDEX idx_calendar_todos_deleted_at   (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5.2 — VJOURNAL
-- ============================================================
CREATE TABLE IF NOT EXISTS calendar_journals (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    calendar_id     INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    uid             VARCHAR(255) NOT NULL UNIQUE COMMENT 'RFC 5545 §3.8.4.7 — UUID v4',
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
    INDEX idx_calendar_journals_deleted_at  (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
