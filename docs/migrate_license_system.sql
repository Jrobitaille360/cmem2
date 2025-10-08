-- ============================================
-- Migration: Ajout du système de licences
-- Version: 1.0.0
-- Date: 2025-10-08
-- Description: Ajoute les colonnes nécessaires pour gérer les licences payantes
-- ============================================

USE cmem2_db;

-- ============================================
-- 1. Ajouter les colonnes à la table users
-- ============================================

ALTER TABLE users 
ADD COLUMN IF NOT EXISTS payment_status ENUM('pending', 'paid', 'expired') DEFAULT 'pending' COMMENT 'Statut du paiement de l\'utilisateur',
ADD COLUMN IF NOT EXISTS license_expires_at DATETIME NULL COMMENT 'Date d\'expiration de la licence (NULL = lifetime)',
ADD COLUMN IF NOT EXISTS payment_plan ENUM('basic', 'standard', 'premium', 'lifetime') DEFAULT 'basic' COMMENT 'Plan de paiement choisi',
ADD COLUMN IF NOT EXISTS payment_date DATETIME NULL COMMENT 'Date du dernier paiement';

-- ============================================
-- 2. Ajouter des index pour optimiser les requêtes
-- ============================================

-- Index sur payment_status pour les requêtes de filtrage
ALTER TABLE users ADD INDEX idx_payment_status (payment_status);

-- Index sur license_expires_at pour trouver les licences expirées
ALTER TABLE users ADD INDEX idx_license_expires (license_expires_at);

-- Index composé pour les requêtes courantes
ALTER TABLE users ADD INDEX idx_payment_status_expires (payment_status, license_expires_at);

-- ============================================
-- 3. Créer une vue pour les utilisateurs avec licence active
-- ============================================

CREATE OR REPLACE VIEW active_licenses AS
SELECT 
    u.user_id,
    u.name,
    u.email,
    u.payment_status,
    u.payment_plan,
    u.payment_date,
    u.license_expires_at,
    CASE 
        WHEN u.license_expires_at IS NULL THEN 'Lifetime'
        WHEN u.license_expires_at > NOW() THEN 'Active'
        ELSE 'Expired'
    END as license_status,
    DATEDIFF(u.license_expires_at, NOW()) as days_remaining,
    COUNT(ak.id) as active_api_keys
FROM users u
LEFT JOIN api_keys ak ON u.user_id = ak.user_id 
    AND ak.revoked_at IS NULL 
    AND ak.environment = 'production'
WHERE u.payment_status = 'paid'
GROUP BY u.user_id;

-- ============================================
-- 4. Créer une procédure pour nettoyer les licences expirées
-- ============================================

DELIMITER $$

DROP PROCEDURE IF EXISTS cleanup_expired_licenses$$

CREATE PROCEDURE cleanup_expired_licenses()
BEGIN
    DECLARE affected_rows INT DEFAULT 0;
    
    -- Commencer une transaction
    START TRANSACTION;
    
    -- Mettre à jour le statut des utilisateurs avec licence expirée
    UPDATE users 
    SET payment_status = 'expired'
    WHERE payment_status = 'paid'
      AND license_expires_at IS NOT NULL
      AND license_expires_at < NOW();
    
    SET affected_rows = ROW_COUNT();
    
    -- Révoquer les API keys des licences expirées
    UPDATE api_keys ak
    JOIN users u ON ak.user_id = u.user_id
    SET ak.revoked_at = NOW(),
        ak.revoked_reason = 'License expired'
    WHERE u.payment_status = 'expired'
      AND ak.revoked_at IS NULL
      AND ak.environment = 'production';
    
    -- Valider la transaction
    COMMIT;
    
    -- Retourner le nombre d'utilisateurs affectés
    SELECT 
        affected_rows as users_expired,
        ROW_COUNT() as keys_revoked,
        NOW() as cleanup_date;
        
END$$

DELIMITER ;

-- ============================================
-- 5. Créer une procédure pour obtenir le statut de licence
-- ============================================

DELIMITER $$

DROP PROCEDURE IF EXISTS get_license_status$$

CREATE PROCEDURE get_license_status(IN p_user_id INT)
BEGIN
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.payment_status,
        u.payment_plan,
        u.payment_date,
        u.license_expires_at,
        CASE 
            WHEN u.license_expires_at IS NULL THEN 'Lifetime'
            WHEN u.license_expires_at > NOW() THEN 'Active'
            ELSE 'Expired'
        END as license_status,
        DATEDIFF(u.license_expires_at, NOW()) as days_remaining,
        (SELECT COUNT(*) 
         FROM api_keys 
         WHERE user_id = p_user_id 
           AND revoked_at IS NULL 
           AND environment = 'production') as active_api_keys,
        (SELECT api_key 
         FROM api_keys 
         WHERE user_id = p_user_id 
           AND revoked_at IS NULL 
           AND environment = 'production'
         ORDER BY created_at DESC 
         LIMIT 1) as current_api_key_prefix
    FROM users u
    WHERE u.user_id = p_user_id;
END$$

DELIMITER ;

-- ============================================
-- 6. Créer un événement pour nettoyer automatiquement les licences expirées
-- ============================================

-- Activer le scheduler d'événements si nécessaire
SET GLOBAL event_scheduler = ON;

-- Créer l'événement (s'exécute tous les jours à 2h du matin)
DROP EVENT IF EXISTS daily_license_cleanup;

CREATE EVENT daily_license_cleanup
ON SCHEDULE EVERY 1 DAY
STARTS CONCAT(CURDATE() + INTERVAL 1 DAY, ' 02:00:00')
DO
    CALL cleanup_expired_licenses();

-- ============================================
-- 7. Créer une table de logs pour les changements de licence
-- ============================================

CREATE TABLE IF NOT EXISTS license_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action ENUM('created', 'renewed', 'expired', 'revoked', 'upgraded', 'downgraded') NOT NULL,
    old_plan VARCHAR(50),
    new_plan VARCHAR(50),
    old_expires_at DATETIME,
    new_expires_at DATETIME,
    reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. Créer un trigger pour logger les changements de licence
-- ============================================

DELIMITER $$

DROP TRIGGER IF EXISTS log_license_update$$

CREATE TRIGGER log_license_update
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    -- Logger si le plan a changé
    IF OLD.payment_plan != NEW.payment_plan OR OLD.license_expires_at != NEW.license_expires_at THEN
        INSERT INTO license_logs (
            user_id, 
            action, 
            old_plan, 
            new_plan, 
            old_expires_at, 
            new_expires_at,
            reason
        ) VALUES (
            NEW.user_id,
            CASE 
                WHEN NEW.payment_status = 'paid' AND OLD.payment_status != 'paid' THEN 'created'
                WHEN NEW.payment_status = 'paid' AND OLD.payment_status = 'paid' THEN 'renewed'
                WHEN NEW.payment_status = 'expired' THEN 'expired'
                ELSE 'renewed'
            END,
            OLD.payment_plan,
            NEW.payment_plan,
            OLD.license_expires_at,
            NEW.license_expires_at,
            'Automatic log from trigger'
        );
    END IF;
END$$

DELIMITER ;

-- ============================================
-- 9. Requêtes utiles (commentées)
-- ============================================

/*
-- Voir toutes les licences actives
SELECT * FROM active_licenses;

-- Voir les licences qui expirent bientôt (dans 7 jours)
SELECT * FROM active_licenses 
WHERE days_remaining IS NOT NULL 
  AND days_remaining <= 7 
  AND days_remaining > 0
ORDER BY days_remaining ASC;

-- Obtenir le statut d'un utilisateur
CALL get_license_status(1);

-- Nettoyer manuellement les licences expirées
CALL cleanup_expired_licenses();

-- Voir l'historique des changements de licence d'un utilisateur
SELECT * FROM license_logs 
WHERE user_id = 1 
ORDER BY created_at DESC;

-- Statistiques globales
SELECT 
    payment_plan,
    COUNT(*) as total_users,
    COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as active_users,
    COUNT(CASE WHEN payment_status = 'expired' THEN 1 END) as expired_users
FROM users
GROUP BY payment_plan;
*/

-- ============================================
-- 10. Vérification de la migration
-- ============================================

SELECT 'Migration completed successfully!' as message;

-- Vérifier les nouvelles colonnes
DESCRIBE users;

-- Vérifier les index
SHOW INDEX FROM users WHERE Key_name LIKE 'idx_%';

-- Vérifier les vues
SHOW FULL TABLES WHERE TABLE_TYPE LIKE 'VIEW';

-- Vérifier les procédures
SHOW PROCEDURE STATUS WHERE Db = 'cmem2_db';

-- Vérifier les événements
SHOW EVENTS;

-- Vérifier les triggers
SHOW TRIGGERS WHERE `Trigger` LIKE 'log_license_%';
