-- Migration 2026-08-04 — rôles de jeu Traque
-- Directive : 20260605_161757_traque_vers_cmem2_API__table-traque-roles-et-endpoints-admin-gm.md
--
-- Table orthogonale aux rôles CMEM2 (ADMINISTRATEUR / UTILISATEUR) : un même users.id
-- peut être joueur (traque_players) et MJ ou admin du jeu (traque_roles).
-- Toute vérification de rôle doit filtrer revoked_at IS NULL.

CREATE TABLE IF NOT EXISTS `traque_roles` (
  `id`         INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT(11) NOT NULL,
  `role`       ENUM('gm', 'traque_admin') NOT NULL,
  `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `granted_by` INT(11) NOT NULL,
  `revoked_at` DATETIME NULL,
  UNIQUE KEY `uq_user_role` (`user_id`, `role`),
  KEY `idx_traque_roles_granted_by` (`granted_by`),
  CONSTRAINT `fk_traque_roles_user`       FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_traque_roles_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
