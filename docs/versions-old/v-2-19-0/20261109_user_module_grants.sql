-- ============================================================
-- Migration 2026-11-09 — Modules cochés par personne
-- Plan accès sur approbation, Phase 3 (task cmem #286)
--
-- user_module_grants : un module « disponible » pour un compte = une ligne ici.
--   Remplace le plan (Stripe / cmem_plan_override) pour la disponibilité.
--   « activé » reste tenant_modules.enabled (trois états conservés).
--   En groupe : disponibilité = grants du propriétaire du groupe (groups.owner_id).
--
-- Grants des comptes existants dérivés de leur plan effectif actuel (aucun module retiré) :
--   - tous les comptes            : projet, contacts, crm, ged (plan free)
--   - Stripe actif cmemweb/cmem   : + ia, booking (monthly / yearly / team)
--   - propriétaire d'un groupe
--     avec abonnement team actif  : + ia, booking (le groupe garde ses modules)
--   - cmem_plan_override = 'ami'  : les 8 modules
-- user_id en int(11) SIGNÉ comme users.id (sinon errno 150). Idempotent (INSERT IGNORE).
-- ============================================================

CREATE TABLE IF NOT EXISTS `user_module_grants` (
  `user_id`    int(11) NOT NULL,
  `module_key` enum('projet','contacts','crm','ged','ia','caldav','booking','push_avance') NOT NULL,
  `granted_by` int(11) DEFAULT NULL,
  `granted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`, `module_key`),
  KEY `idx_umg_granted_by` (`granted_by`),
  CONSTRAINT `fk_umg_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_umg_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `user_module_grants` (`user_id`, `module_key`)
SELECT u.id, k.module_key
  FROM `users` u
  JOIN (SELECT 'projet' AS module_key UNION ALL SELECT 'contacts' UNION ALL SELECT 'crm' UNION ALL SELECT 'ged') k;

INSERT IGNORE INTO `user_module_grants` (`user_id`, `module_key`)
SELECT DISTINCT s.user_id, k.module_key
  FROM `stripe_subscriptions` s
  JOIN `users` u ON u.id = s.user_id
  JOIN (SELECT 'ia' AS module_key UNION ALL SELECT 'booking') k
 WHERE s.app_id IN ('cmemweb', 'cmem')
   AND s.status IN ('trialing', 'active', 'past_due');

INSERT IGNORE INTO `user_module_grants` (`user_id`, `module_key`)
SELECT DISTINCT g.owner_id, k.module_key
  FROM `stripe_subscriptions` s
  JOIN `groups` g ON g.id = s.group_id AND g.deleted_at IS NULL
  JOIN `users` u ON u.id = g.owner_id
  JOIN (SELECT 'ia' AS module_key UNION ALL SELECT 'booking') k
 WHERE s.app_id IN ('cmemweb', 'cmem')
   AND s.status IN ('trialing', 'active', 'past_due');

INSERT IGNORE INTO `user_module_grants` (`user_id`, `module_key`)
SELECT u.id, k.module_key
  FROM `users` u
  JOIN (SELECT 'projet' AS module_key UNION ALL SELECT 'contacts' UNION ALL SELECT 'crm' UNION ALL SELECT 'ged'
        UNION ALL SELECT 'ia' UNION ALL SELECT 'caldav' UNION ALL SELECT 'booking' UNION ALL SELECT 'push_avance') k
 WHERE u.cmem_plan_override = 'ami';
