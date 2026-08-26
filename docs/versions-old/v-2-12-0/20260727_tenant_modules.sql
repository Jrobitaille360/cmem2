-- ============================================================
-- 20260727_tenant_modules.sql
-- Registre de modules activables — directive cmem_web 20260727_144926.
--
-- Trois états séparés :
--   disponible → plan Stripe (mapping serveur Stripe\Config\CmemModules)
--   activé     → choix de l'usager (colonne `enabled`)
--   quota      → serveur, modules à coût variable (quota_used / quota_reset_at)
--
-- L'absence de ligne = état par défaut du module (voir CmemModules::defaultEnabled()).
-- Aucun backfill : les 4 pans déjà livrés (projet/contacts/crm/ged) sont enabled=true
-- par défaut, donc aucun compte existant ne perd l'accès à la bascule.
-- ============================================================

CREATE TABLE IF NOT EXISTS `tenant_modules` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `app_id`          VARCHAR(64)     NOT NULL DEFAULT 'puzzle',
    `owner_id`        INT(11)         NULL,
    `group_id`        INT(11)         NULL,
    `module_key`      ENUM('projet','contacts','crm','ged','ia','caldav','booking','push_avance') NOT NULL,
    `enabled`         TINYINT(1)      NOT NULL DEFAULT 0,
    `quota_used`      INT(11)         NOT NULL DEFAULT 0,
    `quota_reset_at`  DATETIME        NULL,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_owner_module` (`owner_id`, `module_key`),
    UNIQUE KEY `uq_group_module` (`group_id`, `module_key`),
    KEY `idx_app_owner` (`app_id`, `owner_id`),
    CONSTRAINT `fk_tenant_modules_owner`
        FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tenant_modules_group`
        FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
    -- Exactement un des deux porteurs : usager OU groupe.
    CONSTRAINT `chk_tenant_modules_owner_xor_group`
        CHECK ((`owner_id` IS NULL) <> (`group_id` IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
