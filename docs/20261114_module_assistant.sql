-- =====================================================================
-- Module Assistant — socle + chat (tâche racine cmem #515, plan docs/PLAN_module-assistant.md)
-- Tâches : #251 (squelette), #260 (module coché), #257 (chat), #258 (outils),
--          #263 (usage), #264 (budget).
--
-- 1. Clé `assistant` dans les ENUM module_key (tenant_modules, user_module_grants)
--    — alignée sur Access\Config\CmemModules::KEYS.
-- 2. ai_usage.feature : ventilation summarize / assistant (budget toujours commun).
-- 3. Tables du module : consentement Loi 25, conversations, messages, journal d'actions.
--
-- FK : users.id est int(11) SIGNÉ — les colonnes user_id restent int(11) signées (errno 150 sinon).
-- Idempotent pour les CREATE ; les ALTER ENUM sont rejouables sans effet.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. ENUM module_key
-- ---------------------------------------------------------------------
ALTER TABLE `tenant_modules`
  MODIFY `module_key` ENUM('projet','contacts','crm','ged','ia','caldav','booking','push_avance','assistant') NOT NULL;

ALTER TABLE `user_module_grants`
  MODIFY `module_key` ENUM('projet','contacts','crm','ged','ia','caldav','booking','push_avance','assistant') NOT NULL;

-- ---------------------------------------------------------------------
-- 2. ai_usage.feature
-- ---------------------------------------------------------------------
ALTER TABLE `ai_usage`
  ADD COLUMN IF NOT EXISTS `feature` varchar(32) NOT NULL DEFAULT 'summarize'
    COMMENT 'Usage IA : summarize | assistant' AFTER `model`;

-- ---------------------------------------------------------------------
-- 3. Tables du module Assistant
-- ---------------------------------------------------------------------

-- Consentement explicite (Loi 25) avant tout envoi de données à Claude.
-- Une ligne par usager ; revoked_at non NULL = consentement retiré.
CREATE TABLE IF NOT EXISTS `assistant_consents` (
  `user_id`       int(11) NOT NULL,
  `version`       varchar(16) NOT NULL DEFAULT '1' COMMENT 'Version du texte de consentement acceptée',
  `consented_at`  datetime NOT NULL DEFAULT current_timestamp(),
  `revoked_at`    datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_assistant_consents_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assistant_conversations` (
  `id`          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`     int(11) NOT NULL,
  `title`       varchar(255) DEFAULT NULL,
  `created_at`  datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_assistant_conv_user_updated` (`user_id`, `updated_at`),
  CONSTRAINT `fk_assistant_conv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique affichable : texte de l'usager et réponse finale de l'assistant
-- (les blocs d'outils d'un tour ne sont pas rejoués ; les actions sont dans assistant_actions).
CREATE TABLE IF NOT EXISTS `assistant_messages` (
  `id`               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id`  bigint(20) unsigned NOT NULL,
  `role`             enum('user','assistant') NOT NULL,
  `content`          mediumtext NOT NULL,
  `created_at`       datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_assistant_msg_conv` (`conversation_id`, `id`),
  CONSTRAINT `fk_assistant_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `assistant_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des actions effectuées (ou proposées) par l'assistant.
-- PENDING = en attente de confirmation de l'usager (suppressions) ;
-- DONE = exécutée directement ; CONFIRMED / REJECTED = issue d'une PENDING ; FAILED = erreur.
CREATE TABLE IF NOT EXISTS `assistant_actions` (
  `id`               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`          int(11) NOT NULL,
  `conversation_id`  bigint(20) unsigned DEFAULT NULL,
  `tool`             varchar(64) NOT NULL,
  `input`            longtext DEFAULT NULL COMMENT 'JSON : entrée de l''outil',
  `result`           longtext DEFAULT NULL COMMENT 'JSON : résultat',
  `status`           enum('DONE','PENDING','CONFIRMED','REJECTED','FAILED') NOT NULL,
  `created_at`       datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at`      datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_assistant_actions_user_status` (`user_id`, `status`),
  KEY `idx_assistant_actions_conv` (`conversation_id`),
  CONSTRAINT `fk_assistant_actions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assistant_actions_conv` FOREIGN KEY (`conversation_id`) REFERENCES `assistant_conversations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_assistant_actions_input` CHECK (`input` IS NULL OR json_valid(`input`)),
  CONSTRAINT `chk_assistant_actions_result` CHECK (`result` IS NULL OR json_valid(`result`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
