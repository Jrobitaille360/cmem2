-- Création de la table user_sessions pour le suivi des sessions utilisateur
-- Remplace le système complexe JWT par un système simple de sessions avec API Keys

CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `api_key_id` INT NOT NULL,
    `login_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `logout_time` TIMESTAMP NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `is_active` BOOLEAN DEFAULT TRUE,
    
    -- Index pour les requêtes fréquentes
    INDEX `idx_user_sessions_user_id` (`user_id`),
    INDEX `idx_user_sessions_api_key_id` (`api_key_id`),
    INDEX `idx_user_sessions_active` (`is_active`, `expires_at`),
    INDEX `idx_user_sessions_user_api_active` (`user_id`, `api_key_id`, `is_active`),
    
    -- Contraintes de clés étrangères
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`api_key_id`) REFERENCES `api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Commentaires sur les colonnes
ALTER TABLE `user_sessions` 
    MODIFY COLUMN `user_id` INT NOT NULL COMMENT 'ID de l\'utilisateur connecté',
    MODIFY COLUMN `api_key_id` INT NOT NULL COMMENT 'ID de la clé API utilisée pour la connexion',
    MODIFY COLUMN `login_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp de la connexion',
    MODIFY COLUMN `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Dernière activité de la session',
    MODIFY COLUMN `logout_time` TIMESTAMP NULL COMMENT 'Timestamp de la déconnexion (NULL si encore connecté)',
    MODIFY COLUMN `expires_at` TIMESTAMP NOT NULL COMMENT 'Timestamp d\'expiration de la session',
    MODIFY COLUMN `ip_address` VARCHAR(45) COMMENT 'Adresse IP du client (IPv4 ou IPv6)',
    MODIFY COLUMN `user_agent` TEXT COMMENT 'User-Agent du navigateur/client',
    MODIFY COLUMN `is_active` BOOLEAN DEFAULT TRUE COMMENT 'Statut actif de la session';