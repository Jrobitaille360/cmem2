-- Migration pour ajouter le système de plans et gérer les API keys limitées lors de l'inscription

-- 1. Table des plans de paiement
CREATE TABLE IF NOT EXISTS `plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT 'Nom unique du plan (free, bronze, argent, platine)',
  `display_name` varchar(100) NOT NULL COMMENT 'Nom d affichage du plan',
  `description` text COMMENT 'Description du plan',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Prix en devise spécifiée',
  `currency` varchar(3) NOT NULL DEFAULT 'EUR' COMMENT 'Code devise (EUR, USD, etc.)',
  `duration_days` int(11) DEFAULT NULL COMMENT 'Durée en jours (NULL = illimité)',
  `api_rate_limit` int(11) NOT NULL DEFAULT '60' COMMENT 'Limite de requêtes par minute',
  `features` json DEFAULT NULL COMMENT 'Fonctionnalités et limites en JSON',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Plan actif ou non',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Ajouter colonne plan_id à la table users (si elle n'existe pas déjà)
ALTER TABLE `users` 
ADD COLUMN `plan_id` int(11) DEFAULT NULL COMMENT 'Plan actuel de l utilisateur' AFTER `role`,
ADD COLUMN `plan_expires_at` timestamp NULL DEFAULT NULL COMMENT 'Date d expiration du plan' AFTER `plan_id`,
ADD COLUMN `plan_auto_renew` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Renouvellement automatique' AFTER `plan_expires_at`;

-- Ajouter une clé étrangère vers la table plans
ALTER TABLE `users` 
ADD CONSTRAINT `fk_users_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL;

-- 3. Table pour gérer l'historique des souscriptions de plans
CREATE TABLE IF NOT EXISTS `user_plan_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT NULL,
  `auto_renewed` tinyint(1) NOT NULL DEFAULT '0',
  `payment_method` varchar(50) DEFAULT NULL COMMENT 'stripe, paypal, manual, etc.',
  `payment_reference` varchar(255) DEFAULT NULL COMMENT 'Référence du paiement',
  `amount_paid` decimal(10,2) DEFAULT NULL,
  `currency` varchar(3) DEFAULT 'EUR',
  `status` enum('active','expired','cancelled','refunded') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `plan_id` (`plan_id`),
  KEY `status` (`status`),
  KEY `expires_at` (`expires_at`),
  CONSTRAINT `fk_plan_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_plan_history_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Ajouter des colonnes à la table api_keys pour mieux gérer les limites par plan
ALTER TABLE `api_keys` 
ADD COLUMN `plan_id` int(11) DEFAULT NULL COMMENT 'Plan associé à cette API key' AFTER `user_id`,
ADD COLUMN `plan_limited` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'API key limitée par plan' AFTER `plan_id`;

-- Ajouter une clé étrangère vers la table plans
ALTER TABLE `api_keys` 
ADD CONSTRAINT `fk_api_keys_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL;

-- 5. Insérer les plans par défaut
INSERT INTO `plans` (`name`, `display_name`, `description`, `price`, `currency`, `duration_days`, `api_rate_limit`, `features`, `is_active`) VALUES
('free', 'Plan Gratuit', 'Plan gratuit avec limitations pour tester l\'API', 0.00, 'EUR', 30, 10, '{"scopes":["read"],"max_requests_per_day":1000,"expires_in_days":7,"email_support":false,"priority_support":false}', 1),
('bronze', 'Plan Bronze', 'Plan bronze avec fonctionnalités essentielles', 9.99, 'EUR', 30, 100, '{"scopes":["read","write"],"max_requests_per_day":10000,"expires_in_days":null,"email_support":true,"priority_support":false}', 1),
('argent', 'Plan Argent', 'Plan argent avec fonctionnalités avancées', 19.99, 'EUR', 30, 300, '{"scopes":["read","write","delete"],"max_requests_per_day":50000,"expires_in_days":null,"email_support":true,"priority_support":true,"webhook_support":true}', 1),
('platine', 'Plan Platine', 'Plan platine avec toutes les fonctionnalités premium', 49.99, 'EUR', 30, 1000, '{"scopes":["read","write","delete","admin"],"max_requests_per_day":"unlimited","expires_in_days":null,"email_support":true,"priority_support":true,"webhook_support":true,"custom_integrations":true,"dedicated_support":true}', 1)
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

-- 6. Mettre à jour les utilisateurs existants pour leur assigner le plan gratuit par défaut
UPDATE `users` 
SET `plan_id` = (SELECT `id` FROM `plans` WHERE `name` = 'free' LIMIT 1)
WHERE `plan_id` IS NULL;

-- 7. Créer des index pour améliorer les performances
CREATE INDEX `idx_users_plan_expires` ON `users` (`plan_id`, `plan_expires_at`);
CREATE INDEX `idx_api_keys_plan_limited` ON `api_keys` (`plan_id`, `plan_limited`);
CREATE INDEX `idx_plan_history_user_status` ON `user_plan_history` (`user_id`, `status`);

-- 8. Ajouter une table pour gérer les invitations à choisir un plan
CREATE TABLE IF NOT EXISTS `plan_invitations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `invitation_token` varchar(64) NOT NULL COMMENT 'Token unique pour l invitation',
  `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL COMMENT 'Date d expiration de l invitation',
  `clicked_at` timestamp NULL DEFAULT NULL COMMENT 'Date du premier clic',
  `selected_plan` varchar(50) DEFAULT NULL COMMENT 'Plan sélectionné (si applicable)',
  `selected_at` timestamp NULL DEFAULT NULL COMMENT 'Date de sélection du plan',
  `status` enum('pending','clicked','selected','expired') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invitation_token` (`invitation_token`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `expires_at` (`expires_at`),
  CONSTRAINT `fk_plan_invitations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;