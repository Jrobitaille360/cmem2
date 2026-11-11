-- ============================================================
-- Migration 2026-11-09 — Caps de ressources à trois niveaux
-- Plan accès sur approbation, Phase 3 bis (task cmem #293)
--
-- Résolution (EntitlementService::checkQuota) :
--   défaut (src/access/Config/ResourceCaps.php)
--   → surcharge par groupe       : group_resource_caps (clé max_group_members seulement)
--   → surcharge par utilisateur  : user_resource_caps  (toutes les autres clés)
-- Présence d'une ligne = surcharge ; `value` NULL = illimité explicite.
-- cap_key en varchar (validé côté PHP par ResourceCaps) : ajout d'un cap sans ALTER.
-- user_id / group_id / set_by en int(11) SIGNÉ comme users.id / groups.id (sinon errno 150).
-- Aucune donnée migrée : tous les comptes passent au défaut (= ancien monthly, plus large que free).
-- Idempotent (CREATE TABLE IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS `user_resource_caps` (
  `user_id`    int(11) NOT NULL,
  `cap_key`    varchar(64) NOT NULL,
  `value`      int(11) DEFAULT NULL,
  `set_by`     int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`, `cap_key`),
  KEY `idx_urc_set_by` (`set_by`),
  CONSTRAINT `fk_urc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_urc_set_by` FOREIGN KEY (`set_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `group_resource_caps` (
  `group_id`   int(11) NOT NULL,
  `cap_key`    varchar(64) NOT NULL,
  `value`      int(11) DEFAULT NULL,
  `set_by`     int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`group_id`, `cap_key`),
  KEY `idx_grc_set_by` (`set_by`),
  CONSTRAINT `fk_grc_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grc_set_by` FOREIGN KEY (`set_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
