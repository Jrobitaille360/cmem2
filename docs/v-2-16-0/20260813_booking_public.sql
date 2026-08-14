-- Migration pendante — module booking (réservation publique)
-- Directive inter-projet 20260813_163000_cmem_web_vers_cmem2_API__booking-public.md
-- Voir docs/PLAN_booking-public.md — Phase 1.
-- ENUM tenant_modules.module_key contient déjà 'booking' (docs/v-2-15-0/build_DB-v-2.15.0.sql).

CREATE TABLE IF NOT EXISTS `booking_pages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `app_id` varchar(64) NOT NULL DEFAULT 'puzzle',
  `calendar_id` int(11) NOT NULL,
  `slug` varchar(128) NOT NULL,
  `duration_minutes` smallint(6) NOT NULL,
  `buffer_before_minutes` smallint(6) NOT NULL DEFAULT 0,
  `buffer_after_minutes` smallint(6) NOT NULL DEFAULT 0,
  `timezone` varchar(64) NOT NULL,
  `horizon_days` smallint(6) NOT NULL DEFAULT 30,
  `availability_windows` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
    CHECK (json_valid(`availability_windows`)),
  `event_title_template` varchar(255) NOT NULL DEFAULT 'Rendez-vous : {guest_name}',
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_pages_owner_app` (`owner_id`, `app_id`),
  UNIQUE KEY `uq_booking_pages_app_slug` (`app_id`, `slug`),
  KEY `idx_booking_pages_calendar` (`calendar_id`),
  CONSTRAINT `chk_booking_pages_horizon` CHECK (`horizon_days` <= 90),
  CONSTRAINT `fk_booking_pages_calendar` FOREIGN KEY (`calendar_id`)
    REFERENCES `calendars` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_slots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_page_id` int(11) NOT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `reserved` tinyint(1) NOT NULL DEFAULT 0,
  `guest_name` varchar(255) DEFAULT NULL,
  `guest_email` varchar(255) DEFAULT NULL,
  `guest_timezone` varchar(64) DEFAULT NULL,
  `event_id` int(11) DEFAULT NULL,
  `cancel_token` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_slots_cancel_token` (`cancel_token`),
  KEY `idx_booking_slots_page_reserved_start` (`booking_page_id`, `reserved`, `start_datetime`),
  CONSTRAINT `fk_booking_slots_page` FOREIGN KEY (`booking_page_id`)
    REFERENCES `booking_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_slots_event` FOREIGN KEY (`event_id`)
    REFERENCES `calendar_events` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
