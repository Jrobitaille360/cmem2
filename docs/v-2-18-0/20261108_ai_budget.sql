-- ============================================================
-- Migration 2026-11-08 — Budget IA mensuel en dollars
-- Plan accès sur approbation, Phase 1 (task cmem #273)
--
-- ai_usage : une ligne par appel IA (réservée avant l'appel avec le coût estimé,
--            ajustée au coût réel après). Somme du mois = dépense de l'usager ;
--            somme de tous les usagers = dépense globale (AI_GLOBAL_BUDGET_USD_MONTHLY).
-- users.ai_budget_usd_monthly : budget personnel ; NULL = AI_DEFAULT_BUDGET_USD (.env).
--
-- user_id en ON DELETE SET NULL (pas CASCADE) : supprimer un compte ne doit pas
-- effacer sa dépense du total global du mois.
-- users.id est int(11) SIGNÉ : user_id doit l'être aussi (sinon errno 150).
-- ============================================================

CREATE TABLE IF NOT EXISTS `ai_usage` (
  `id`            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`       int(11) DEFAULT NULL,
  `model`         varchar(64) NOT NULL,
  `input_tokens`  int(11) NOT NULL DEFAULT 0,
  `output_tokens` int(11) NOT NULL DEFAULT 0,
  `cost_usd`      decimal(12,6) NOT NULL DEFAULT 0.000000 COMMENT 'Coût en dollars US (estimé à la réservation, réel après l''appel)',
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_usage_user_created` (`user_id`, `created_at`),
  KEY `idx_ai_usage_created` (`created_at`),
  CONSTRAINT `fk_ai_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `ai_budget_usd_monthly` decimal(10,2) DEFAULT NULL
    COMMENT 'Budget IA mensuel en dollars (NULL = AI_DEFAULT_BUDGET_USD)'
    AFTER `cmem_plan_override`;
