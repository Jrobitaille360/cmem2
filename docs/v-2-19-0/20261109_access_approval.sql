-- ============================================================
-- Migration 2026-11-09 — Accès sur approbation : statut + invitations
-- Plan accès sur approbation, Phase 2 (task cmem #279)
--
-- users.access_status : pending / approved / suspended.
--   Ajoutée avec DEFAULT 'approved' pour que tous les comptes existants
--   soient approuvés, puis défaut basculé à 'pending' pour les nouveaux comptes.
--
-- access_invitations : liste blanche de courriels posée par un admin.
--   Un compte créé ou connecté avec un courriel invité (invitation non expirée,
--   non acceptée) passe en approved avec le budget IA de l'invitation.
--   modules : JSON stocké, appliqué en Phase 3 (user_module_grants, task #288).
--   invited_by en ON DELETE SET NULL. users.id est int(11) SIGNÉ (sinon errno 150).
-- ============================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `access_status` enum('pending','approved','suspended') NOT NULL DEFAULT 'approved'
    COMMENT 'Accès sur approbation : pending (en attente), approved, suspended'
    AFTER `ai_budget_usd_monthly`;

ALTER TABLE `users`
  ALTER COLUMN `access_status` SET DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS `access_invitations` (
  `id`            int(11) unsigned NOT NULL AUTO_INCREMENT,
  `email`         varchar(255) NOT NULL COMMENT 'Courriel invité, en minuscules',
  `invited_by`    int(11) DEFAULT NULL,
  `modules`       longtext DEFAULT NULL COMMENT 'JSON : codes CmemModules (appliqués en Phase 3)',
  `ai_budget_usd` decimal(10,2) DEFAULT NULL COMMENT 'Budget IA mensuel appliqué à l''acceptation (NULL = défaut)',
  `expires_at`    datetime NOT NULL,
  `accepted_at`   datetime DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_access_invitations_email` (`email`),
  CONSTRAINT `fk_access_invitations_invited_by` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_access_invitations_modules` CHECK (`modules` IS NULL OR json_valid(`modules`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
